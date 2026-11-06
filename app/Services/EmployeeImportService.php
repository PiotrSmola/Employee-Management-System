<?php

namespace App\Services;

use App\Exceptions\ImportException;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\EmployeeDataCache;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

/**
 * Import of new employees from a CSV or XLSX file in two steps:
 *
 *  1. {@see analyze()} reads and validates the file and keeps the result for the
 *     user for a while ({@see stage()}, token-based); nothing is written yet;
 *  2. {@see commit()} re-validates the rows against the current data and creates the
 *     employees with their current history (employees, dept_emp, titles, salaries:
 *     from the hire date, to_date 9999-01-01) in one transaction.
 *
 * Employee numbers are allocated inside that transaction after locking the highest
 * existing emp_no (SELECT ... FOR UPDATE), so concurrent imports - or any other code
 * that allocates numbers the same way ({@see allocateEmployeeNumbers()}) - never
 * hand out the same number.
 */
class EmployeeImportService
{
    public const CURRENT = '9999-01-01';

    /** Columns of the file (header row, any order, case-insensitive) => label. */
    public const COLUMNS = [
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'birth_date' => 'Birth date',
        'gender' => 'Gender',
        'hire_date' => 'Hire date',
        'department' => 'Department code',
        'title' => 'Job title',
        'salary' => 'Salary',
    ];

    /** Other accepted header names. */
    private const ALIASES = [
        'firstname' => 'first_name',
        'lastname' => 'last_name',
        'birthdate' => 'birth_date',
        'date_of_birth' => 'birth_date',
        'hiredate' => 'hire_date',
        'dept_no' => 'department',
        'department_code' => 'department',
        'job_title' => 'title',
    ];

    /** Lengths of the employees / titles columns. */
    private const MAX_LENGTH = ['first_name' => 14, 'last_name' => 16, 'title' => 50];

    private const MAX_SALARY = 2147483647;

    private const INSERT_CHUNK = 500;

    private const CACHE_PREFIX = 'employee-import:';

    // ------------------------------------------------------------------ analyze

    /**
     * Read and validate an uploaded file.
     *
     * @return array{
     *     file_errors: string[],
     *     rows: array<int, array{line: int, values: array<string, string|int|null>, errors: string[]}>,
     *     valid: int,
     *     invalid: int
     * }
     */
    public function analyze(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        try {
            [$header, $rows] = $this->read($file->getRealPath(), $extension === 'xlsx' ? 'xlsx' : 'csv');
        } catch (Throwable $e) {
            report($e);

            return $this->result([__('The file could not be read. Please upload a CSV or XLSX file based on the template.')], []);
        }

        if ($header === null) {
            return $this->result([__('The file is empty.')], []);
        }

        $map = $this->mapHeader($header);
        $missing = array_diff(array_keys(self::COLUMNS), array_values($map));

        if ($missing !== []) {
            return $this->result([__('Missing columns: :columns. Use the template.', ['columns' => implode(', ', $missing)])], []);
        }

        $maxRows = (int) config('data_tools.imports.max_rows', 5000);
        if (count($rows) > $maxRows) {
            return $this->result([__('The file has more than :max rows. Split it into several files.', ['max' => number_format($maxRows)])], []);
        }

        if ($rows === []) {
            return $this->result([__('The file contains no employees.')], []);
        }

        $records = [];
        foreach ($rows as $line => $cells) {
            $values = [];
            foreach ($map as $index => $column) {
                $values[$column] = $cells[$index] ?? null;
            }
            $records[] = ['line' => $line, 'values' => $values];
        }

        return $this->result([], $this->validate($records));
    }

    /**
     * @return array{0: array<int, mixed>|null, 1: array<int, array<int, mixed>>} header cells, data rows keyed by line number
     */
    private function read(string $path, string $type): array
    {
        $reader = $type === 'xlsx'
            ? new XlsxReader(new XlsxOptions(SHOULD_PRESERVE_EMPTY_ROWS: true))
            : new CsvReader(new CsvOptions(SHOULD_PRESERVE_EMPTY_ROWS: true, FIELD_DELIMITER: $this->csvDelimiter($path)));

        $maxRows = (int) config('data_tools.imports.max_rows', 5000);
        $header = null;
        $rows = [];

        $reader->open($path);
        try {
            foreach ($this->firstSheetRows($reader) as $line => $row) {
                $cells = array_map(fn ($cell) => $cell->getValue(), $row->cells);

                if ($header === null) {
                    if ($this->isBlank($cells)) {
                        continue;
                    }
                    $header = $cells;

                    continue;
                }

                if ($this->isBlank($cells)) {
                    continue;
                }

                $rows[$line] = $cells;

                // One more than allowed is enough to report the limit.
                if (count($rows) > $maxRows) {
                    break;
                }
            }
        } finally {
            $reader->close();
        }

        return [$header, $rows];
    }

    /**
     * @return iterable<int, Row> rows keyed by their 1-based line number
     */
    private function firstSheetRows(ReaderInterface $reader): iterable
    {
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $line => $row) {
                yield $line => $row;
            }

            return;
        }
    }

    /**
     * Spreadsheets saved with a comma decimal separator (e.g. Polish Excel) use ";" in CSV files.
     */
    private function csvDelimiter(string $path): string
    {
        $handle = fopen($path, 'r');
        $first = $handle === false ? '' : (string) fgets($handle);
        if ($handle !== false) {
            fclose($handle);
        }

        return substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
    }

    /**
     * @param  array<int, mixed>  $header
     * @return array<int, string> cell index => column
     */
    private function mapHeader(array $header): array
    {
        $map = [];
        foreach ($header as $index => $name) {
            $key = Str::snake(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $name)));
            $key = str_replace(['-', ' '], '_', strtolower($key));
            $key = self::ALIASES[$key] ?? $key;

            if (isset(self::COLUMNS[$key]) && ! in_array($key, $map, true)) {
                $map[$index] = $key;
            }
        }

        return $map;
    }

    /**
     * @param  array<int, mixed>  $cells
     */
    private function isBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== null && trim((string) ($cell instanceof DateTimeInterface ? 'date' : $cell)) !== '') {
                return false;
            }
        }

        return true;
    }

    // ----------------------------------------------------------------- validate

    /**
     * Normalize and validate rows; every row gets its list of errors (empty = valid).
     *
     * @param  array<int, array{line: int, values: array<string, mixed>}>  $records
     * @return array<int, array{line: int, values: array<string, string|int|null>, errors: string[]}>
     */
    public function validate(array $records): array
    {
        $departments = DB::table('departments')->pluck('dept_no')
            ->mapWithKeys(fn ($no) => [strtolower($no) => $no])->all();
        $titles = DB::table('titles')->distinct()->pluck('title')
            ->mapWithKeys(fn ($title) => [mb_strtolower($title) => $title])->all();
        $today = new DateTimeImmutable('today');

        $result = [];
        foreach ($records as $record) {
            [$values, $errors] = $this->validateRow($record['values'], $departments, $titles, $today);
            $result[] = ['line' => $record['line'], 'values' => $values, 'errors' => $errors];
        }

        $this->markDuplicates($result);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, string>  $departments  lower-case dept_no => dept_no
     * @param  array<string, string>  $titles  lower-case title => title
     * @return array{0: array<string, string|int|null>, 1: string[]}
     */
    private function validateRow(array $raw, array $departments, array $titles, DateTimeImmutable $today): array
    {
        $errors = [];
        $text = function (string $key) use ($raw): string {
            $value = $raw[$key] ?? null;

            return trim((string) ($value instanceof DateTimeInterface ? $value->format('Y-m-d') : $value));
        };

        $values = [];
        foreach (['first_name', 'last_name'] as $key) {
            $values[$key] = $text($key);
            if ($values[$key] === '') {
                $errors[] = __(':column is required.', ['column' => __(self::COLUMNS[$key])]);
            } elseif (mb_strlen($values[$key]) > self::MAX_LENGTH[$key]) {
                $errors[] = __(':column may have at most :max characters.', ['column' => __(self::COLUMNS[$key]), 'max' => self::MAX_LENGTH[$key]]);
            }
        }

        $birth = $this->date($raw['birth_date'] ?? null);
        $hire = $this->date($raw['hire_date'] ?? null);
        $values['birth_date'] = $birth?->format('Y-m-d') ?? $text('birth_date');
        $values['hire_date'] = $hire?->format('Y-m-d') ?? $text('hire_date');

        foreach (['birth_date' => $birth, 'hire_date' => $hire] as $key => $date) {
            if ($date === null) {
                $errors[] = $text($key) === ''
                    ? __(':column is required.', ['column' => __(self::COLUMNS[$key])])
                    : __(':column must be a valid date (YYYY-MM-DD).', ['column' => __(self::COLUMNS[$key])]);
            }
        }

        if ($birth !== null && $birth < new DateTimeImmutable('1900-01-01')) {
            $errors[] = __('Birth date must be after 1900-01-01.');
        }
        if ($birth !== null && $hire !== null && $birth->modify('+14 years') > $hire) {
            $errors[] = __('The employee must be at least 14 years old on the hire date.');
        }
        if ($hire !== null && $hire > $today->modify('+1 year')) {
            $errors[] = __('Hire date may be at most one year in the future.');
        }

        $values['gender'] = strtoupper($text('gender'));
        if (! in_array($values['gender'], ['M', 'F'], true)) {
            $errors[] = __('Gender must be M or F.');
        }

        $department = $text('department');
        $values['department'] = $departments[strtolower($department)] ?? $department;
        if ($department === '') {
            $errors[] = __(':column is required.', ['column' => __(self::COLUMNS['department'])]);
        } elseif (! isset($departments[strtolower($department)])) {
            $errors[] = __('Unknown department code ":code".', ['code' => $department]);
        }

        $title = $text('title');
        $values['title'] = $titles[mb_strtolower($title)] ?? $title;
        if ($title === '') {
            $errors[] = __(':column is required.', ['column' => __(self::COLUMNS['title'])]);
        } elseif (! isset($titles[mb_strtolower($title)])) {
            $errors[] = __('Unknown job title ":title".', ['title' => $title]);
        }

        $salary = $this->salary($raw['salary'] ?? null);
        $values['salary'] = $salary ?? $text('salary');
        if ($salary === null) {
            $errors[] = $text('salary') === ''
                ? __(':column is required.', ['column' => __(self::COLUMNS['salary'])])
                : __('Salary must be a whole number greater than 0.');
        }

        return [$values, $errors];
    }

    /**
     * Rows repeating another row of the file, or an existing employee, with the same
     * first name, last name and birth date (case-insensitive) are errors.
     *
     * @param  array<int, array{line: int, values: array<string, string|int|null>, errors: string[]}>  $rows
     */
    private function markDuplicates(array &$rows): void
    {
        $key = fn (array $values) => mb_strtolower($values['first_name'].'|'.$values['last_name'].'|'.$values['birth_date']);

        $seen = [];
        foreach ($rows as $i => $row) {
            if ($row['values']['first_name'] === '' || $row['values']['last_name'] === '' || $this->date($row['values']['birth_date']) === null) {
                continue;
            }

            $k = $key($row['values']);
            if (isset($seen[$k])) {
                $rows[$i]['errors'][] = __('Duplicate of row :line of the file.', ['line' => $seen[$k]]);
            } else {
                $seen[$k] = $row['line'];
            }
        }

        if ($seen === []) {
            return;
        }

        // One pass over the employees born on the dates of the file (no index on names).
        $dates = array_values(array_unique(array_map(fn ($k) => substr($k, strrpos($k, '|') + 1), array_keys($seen))));
        $existing = [];
        foreach (array_chunk($dates, 1000) as $chunk) {
            DB::table('employees')->whereIn('birth_date', $chunk)
                ->select('emp_no', 'first_name', 'last_name', 'birth_date')
                ->orderBy('emp_no')
                ->get()
                ->each(function ($employee) use (&$existing, $key) {
                    $existing[$key((array) $employee)] ??= $employee->emp_no;
                });
        }

        foreach ($rows as $i => $row) {
            if ($this->date($row['values']['birth_date']) === null) {
                continue;
            }
            $k = $key($row['values']);
            if (isset($existing[$k])) {
                $rows[$i]['errors'][] = __('An employee with this name and birth date already exists (#:emp_no).', ['emp_no' => $existing[$k]]);
            }
        }
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTime(0, 0);
        }

        $value = trim((string) $value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    private function salary(mixed $value): ?int
    {
        if (is_int($value) || (is_float($value) && floor($value) === $value)) {
            $amount = (int) $value;
        } elseif (is_string($value) && preg_match('/^\s*(\d{1,10})(?:[.,]0{1,2})?\s*$/', $value, $m)) {
            $amount = (int) $m[1];
        } else {
            return null;
        }

        return $amount > 0 && $amount <= self::MAX_SALARY ? $amount : null;
    }

    /**
     * @param  string[]  $fileErrors
     * @param  array<int, array{line: int, values: array<string, string|int|null>, errors: string[]}>  $rows
     */
    private function result(array $fileErrors, array $rows): array
    {
        $invalid = count(array_filter($rows, fn ($row) => $row['errors'] !== []));

        return [
            'file_errors' => $fileErrors,
            'rows' => $rows,
            'valid' => count($rows) - $invalid,
            'invalid' => $invalid,
        ];
    }

    // -------------------------------------------------------------------- stage

    /**
     * Keep an analysis for its user until it is confirmed or cancelled; returns the token.
     */
    public function stage(User $user, string $fileName, array $analysis): string
    {
        $token = (string) Str::uuid();

        Cache::put(self::CACHE_PREFIX.$token, [
            'user_id' => $user->getKey(),
            'file_name' => mb_substr($fileName, 0, 255),
            'analysis' => $analysis,
        ], now()->addMinutes((int) config('data_tools.imports.preview_ttl', 60)));

        return $token;
    }

    /**
     * The staged import of $user, or null (unknown, expired or another user's).
     *
     * @return array{user_id: int, file_name: string, analysis: array}|null
     */
    public function staged(User $user, string $token): ?array
    {
        $staged = Cache::get(self::CACHE_PREFIX.$token);

        return is_array($staged) && ($staged['user_id'] ?? null) === $user->getKey() ? $staged : null;
    }

    public function forget(string $token): void
    {
        Cache::forget(self::CACHE_PREFIX.$token);
    }

    // ------------------------------------------------------------------- commit

    /**
     * Create the staged employees. The rows are validated again against the current
     * data (departments, titles, duplicates - also against imports that ran meanwhile);
     * any error aborts the whole import.
     *
     * @return array{count: int, first_emp_no: int, last_emp_no: int}
     *
     * @throws ImportException with a user-facing message when the data is no longer valid
     */
    public function commit(User $user, string $token): array
    {
        $staged = $this->staged($user, $token);

        if ($staged === null) {
            throw new ImportException(__('This import has expired. Please upload the file again.'));
        }

        $analysis = $staged['analysis'];
        if ($analysis['file_errors'] !== [] || $analysis['invalid'] > 0 || $analysis['rows'] === []) {
            throw new ImportException(__('The file has errors. Correct them and upload it again.'));
        }

        $result = DB::transaction(function () use ($analysis, $staged, $user) {
            $next = $this->allocateEmployeeNumbers();

            // Re-check after taking the lock: concurrent imports are visible now.
            $rows = $this->validate(array_map(fn ($row) => ['line' => $row['line'], 'values' => $row['values']], $analysis['rows']));
            foreach ($rows as $row) {
                if ($row['errors'] !== []) {
                    throw new ImportException(__('Row :line is no longer valid: :error Please upload the file again.', [
                        'line' => $row['line'], 'error' => $row['errors'][0],
                    ]));
                }
            }

            $employees = $deptEmp = $titles = $salaries = [];
            $empNo = $next;
            foreach ($rows as $row) {
                $v = $row['values'];
                $employees[] = [
                    'emp_no' => $empNo, 'birth_date' => $v['birth_date'], 'first_name' => $v['first_name'],
                    'last_name' => $v['last_name'], 'gender' => $v['gender'], 'hire_date' => $v['hire_date'],
                ];
                $deptEmp[] = ['emp_no' => $empNo, 'dept_no' => $v['department'], 'from_date' => $v['hire_date'], 'to_date' => self::CURRENT];
                $titles[] = ['emp_no' => $empNo, 'title' => $v['title'], 'from_date' => $v['hire_date'], 'to_date' => self::CURRENT];
                $salaries[] = ['emp_no' => $empNo, 'salary' => $v['salary'], 'from_date' => $v['hire_date'], 'to_date' => self::CURRENT];
                $empNo++;
            }

            foreach (['employees' => $employees, 'dept_emp' => $deptEmp, 'titles' => $titles, 'salaries' => $salaries] as $table => $data) {
                foreach (array_chunk($data, self::INSERT_CHUNK) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
            }

            $result = ['count' => count($employees), 'first_emp_no' => $next, 'last_emp_no' => $empNo - 1];

            AuditLogger::log('data.imported', ActivityLog::SUBJECT_IMPORT, null, $result + [
                'file' => $staged['file_name'],
                'departments' => array_values(array_unique(array_column($deptEmp, 'dept_no'))),
            ], $user);

            return $result + ['departments' => array_values(array_unique(array_column($deptEmp, 'dept_no')))];
        });

        $this->forget($token);
        EmployeeDataCache::flush($result['departments']);

        return ['count' => $result['count'], 'first_emp_no' => $result['first_emp_no'], 'last_emp_no' => $result['last_emp_no']];
    }

    /**
     * The first free employee number; call inside a transaction. Locks the row with the
     * highest emp_no (and the gap above it) until the transaction ends, so concurrent
     * callers wait and then see the numbers taken meanwhile. New numbers start after
     * the dataset's first number (10001) at the earliest.
     */
    public function allocateEmployeeNumbers(): int
    {
        $last = DB::table('employees')->orderByDesc('emp_no')->lockForUpdate()->value('emp_no');

        return max((int) $last, 10000) + 1;
    }

    // ----------------------------------------------------------------- template

    /**
     * Header and one example row of the import file.
     *
     * @return array{0: string[], 1: array<int, string|int>}
     */
    public function templateRows(): array
    {
        $department = DB::table('departments')->orderBy('dept_no')->value('dept_no') ?? 'd001';
        $titles = DB::table('titles')->distinct()->orderBy('title')->pluck('title');
        $title = $titles->contains('Engineer') ? 'Engineer' : ($titles->first() ?? 'Engineer');

        return [
            array_keys(self::COLUMNS),
            ['Jane', 'Doe', '1990-05-17', 'F', now()->format('Y-m-d'), $department, $title, 60000],
        ];
    }
}
