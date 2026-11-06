<?php

namespace App\Services;

use App\Enums\ExportFormat;
use App\Queries\EmployeeSearchQuery;
use Barryvdh\DomPDF\Facade\Pdf;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Bulk CSV / XLSX / PDF export of the employee directory.
 *
 * Every export takes the list of columns to include (keys of COLUMNS, see
 * {@see columns()}); the salary columns are only ever included with
 * `view-salaries`. The direct downloads (csv(), xlsx(), pdf()) are used for
 * small selections, the write*() methods by the queued export job
 * ({@see \App\Jobs\GenerateExport}) for large ones.
 */
class EmployeeExportService
{
    /** Rows of a directly downloaded PDF; larger PDF exports are queued. */
    public const PDF_LIMIT = 1000;

    private const CHUNK = 1000;

    /**
     * Exportable columns in their fixed order: key => [label, salary data?].
     *
     * @var array<string, array{0: string, 1: bool}>
     */
    public const COLUMNS = [
        'emp_no' => ['Employee ID', false],
        'first_name' => ['First Name', false],
        'last_name' => ['Last Name', false],
        'full_name' => ['Full Name', false],
        'department' => ['Department', false],
        'title' => ['Job Title', false],
        'salary' => ['Current Salary', true],
        'earnings' => ['Total Earnings', true],
        'hire_date' => ['Hire Date', false],
        'gender' => ['Gender', false],
        'status' => ['Employment Status', false],
    ];

    /** Columns of a PDF when none were chosen (the full name instead of first / last name). */
    public const PDF_DEFAULT_COLUMNS = ['emp_no', 'full_name', 'department', 'title', 'salary', 'earnings', 'hire_date', 'gender', 'status'];

    /**
     * Columns a user may choose from: key => label.
     *
     * @return array<string, string>
     */
    public static function availableColumns(bool $withSalaries): array
    {
        $columns = [];
        foreach (self::COLUMNS as $key => [$label, $isSalary]) {
            if ($withSalaries || ! $isSalary) {
                $columns[$key] = __($label);
            }
        }

        return $columns;
    }

    /**
     * The columns to export: the requested keys (array or comma-separated string, unknown
     * keys ignored) in the fixed COLUMNS order, without salary columns unless $withSalaries.
     * Nothing (valid) requested means the format's default columns.
     *
     * @return string[]
     */
    public function columns(bool $withSalaries, mixed $requested = null, ExportFormat $format = ExportFormat::Csv): array
    {
        $allowed = array_keys(self::availableColumns($withSalaries));
        $requested = is_string($requested) ? explode(',', $requested) : (is_array($requested) ? $requested : []);
        $requested = array_map(fn ($key) => is_scalar($key) ? trim((string) $key) : '', $requested);

        $columns = array_values(array_intersect($allowed, $requested));

        if ($columns !== []) {
            return $columns;
        }

        return $format === ExportFormat::Pdf
            ? array_values(array_intersect(self::PDF_DEFAULT_COLUMNS, $allowed))
            : $allowed;
    }

    /**
     * File name of an export of $count employees.
     */
    public function filename(ExportFormat $format, int $count): string
    {
        $prefix = $format === ExportFormat::Pdf ? 'employees_selected_' : 'employees_export_selected_';

        return $prefix.$count.'_'.date('Y-m-d_H-i-s').'.'.$format->extension();
    }

    // ------------------------------------------------------------ direct downloads

    /**
     * Stream the employees as CSV, in the list's sort order.
     *
     * @param  string[]|null  $columns  null: every column the user may see ({@see columns()})
     * @param  int[]|null  $ids  the matching ids when already known
     */
    public function csv(EmployeeSearchQuery $query, bool $withSalaries = true, ?array $columns = null, ?array $ids = null): Response
    {
        set_time_limit(600);

        $columns = $this->columns($withSalaries, $columns);
        $ids ??= $query->ids();

        if ($ids === []) {
            return back()->with('error', __('No employees found for export.'));
        }

        return new StreamedResponse(function () use ($query, $ids, $columns) {
            $handle = fopen('php://output', 'w');
            $this->streamCsv($handle, $query, $ids, $columns);
            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$this->filename(ExportFormat::Csv, count($ids)).'"',
        ]);
    }

    /**
     * The employees as an Excel workbook (written to a temporary file in a streaming
     * fashion, row by row, then sent and deleted).
     *
     * @param  string[]|null  $columns
     * @param  int[]|null  $ids
     */
    public function xlsx(EmployeeSearchQuery $query, bool $withSalaries = true, ?array $columns = null, ?array $ids = null): Response
    {
        set_time_limit(600);

        $columns = $this->columns($withSalaries, $columns);
        $ids ??= $query->ids();

        if ($ids === []) {
            return back()->with('error', __('No employees found for export.'));
        }

        $path = tempnam(sys_get_temp_dir(), 'xlsx');

        try {
            $this->writeXlsxIds($path, $query, $ids, $columns);
        } catch (Throwable $e) {
            @unlink($path);

            throw $e;
        }

        return (new BinaryFileResponse($path, 200, ['Content-Type' => ExportFormat::Xlsx->mimeType()]))
            ->setContentDisposition('attachment', $this->filename(ExportFormat::Xlsx, count($ids)))
            ->deleteFileAfterSend();
    }

    /**
     * Render up to PDF_LIMIT employees into a landscape PDF.
     *
     * @param  string[]|null  $columns
     * @param  int[]|null  $ids
     */
    public function pdf(EmployeeSearchQuery $query, bool $withSalaries = true, ?array $columns = null, ?array $ids = null): Response
    {
        set_time_limit(600);
        ini_set('memory_limit', '2G');

        $columns = $this->columns($withSalaries, $columns, ExportFormat::Pdf);
        $ids ??= $query->ids(static::PDF_LIMIT);
        $ids = array_slice($ids, 0, static::PDF_LIMIT);

        if ($ids === []) {
            return back()->with('error', __('No employees found for export.'));
        }

        // The PDF holds at most PDF_LIMIT rows; the selection itself may be larger.
        $selectedCount = count($ids) < static::PDF_LIMIT ? count($ids) : $query->count();

        try {
            return $this->pdfDocument($query, $ids, $columns, $selectedCount, static::PDF_LIMIT)
                ->download($this->filename(ExportFormat::Pdf, $selectedCount));
        } catch (\Exception $e) {
            // Details go to the log only - the message may contain paths, SQL or data.
            report($e);

            return back()->with('error', __('The PDF export failed. Please try again or export fewer employees.'));
        }
    }

    // ------------------------------------------------------------- file writers

    /**
     * Write the export to $path; returns the number of employee rows.
     *
     * @param  string[]  $columns  a columns() result
     */
    public function writeFile(ExportFormat $format, EmployeeSearchQuery $query, array $columns, string $path, int $pdfLimit): int
    {
        if ($format === ExportFormat::Pdf) {
            $ids = $query->ids($pdfLimit);
            if ($ids === []) {
                return 0;
            }

            $selectedCount = count($ids) < $pdfLimit ? count($ids) : $query->count();
            file_put_contents($path, $this->pdfDocument($query, $ids, $columns, $selectedCount, $pdfLimit)->output());

            return count($ids);
        }

        $ids = $query->ids();
        if ($ids === []) {
            return 0;
        }

        if ($format === ExportFormat::Xlsx) {
            $this->writeXlsxIds($path, $query, $ids, $columns);
        } else {
            $handle = fopen($path, 'w');
            $this->streamCsv($handle, $query, $ids, $columns);
            fclose($handle);
        }

        return count($ids);
    }

    /**
     * @param  resource  $handle
     * @param  int[]  $ids
     * @param  string[]  $columns
     */
    private function streamCsv($handle, EmployeeSearchQuery $query, array $ids, array $columns): void
    {
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $this->header($columns));

        $this->eachRow($query, $ids, $columns, function (object $employee, int|float|string $earnings) use ($handle, $columns) {
            fputcsv($handle, array_map(self::csvCell(...), $this->csvRow($employee, $earnings, $columns)));
        });
    }

    /**
     * @param  int[]  $ids
     * @param  string[]  $columns
     */
    private function writeXlsxIds(string $path, EmployeeSearchQuery $query, array $ids, array $columns): void
    {
        $writer = new XlsxWriter(new XlsxOptions(tempFolder: sys_get_temp_dir()));
        $writer->openToFile($path);

        try {
            $writer->getCurrentSheet()->setName(__('Employees'));
            $writer->addRow(Row::fromValuesWithStyle($this->header($columns), (new Style)->withFontBold(true)));

            $dateStyle = (new Style)->withFormat('yyyy-mm-dd');
            $moneyStyle = (new Style)->withFormat('#,##0.00');

            $this->eachRow($query, $ids, $columns, function (object $employee, int|float|string $earnings) use ($writer, $columns, $dateStyle, $moneyStyle) {
                $cells = [];
                foreach ($this->xlsxRow($employee, $earnings, $columns) as $key => $value) {
                    $cells[] = match (true) {
                        // Text is always a text cell: a name like "=1+2" must never become a formula.
                        is_string($value) => new StringCell($value, null),
                        $value instanceof DateTimeImmutable => Cell::fromValue($value, $dateStyle),
                        in_array($key, ['salary', 'earnings'], true) && $value !== null => Cell::fromValue($value, $moneyStyle),
                        default => Cell::fromValue($value),
                    };
                }
                $writer->addRow(new Row($cells));
            });
        } finally {
            // Also removes the writer's temporary files after an error.
            $writer->close();
        }
    }

    /**
     * Display rows (and total earnings, when exported) chunk by chunk, in the order of $ids.
     *
     * @param  int[]  $ids
     * @param  string[]  $columns
     * @param  callable(object, int|float|string): void  $callback
     */
    private function eachRow(EmployeeSearchQuery $query, array $ids, array $columns, callable $callback): void
    {
        $withEarnings = in_array('earnings', $columns, true);

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $earnings = $withEarnings ? $this->totalEarnings($chunk) : [];

            foreach ($query->rowsFor($chunk) as $employee) {
                $callback($employee, $earnings[$employee->emp_no] ?? 0);
            }
        }
    }

    /**
     * @param  int[]  $ids
     * @param  string[]  $columns
     */
    private function pdfDocument(EmployeeSearchQuery $query, array $ids, array $columns, int $selectedCount, int $limit): \Barryvdh\DomPDF\PDF
    {
        $withSalaryColumns = array_intersect(['salary', 'earnings'], $columns) !== [];
        $earnings = in_array('earnings', $columns, true) ? $this->totalEarnings($ids) : [];
        $employees = $query->rowsFor($ids)->map(fn ($employee) => (object) [
            'emp_no' => $employee->emp_no,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'hire_date' => $employee->hire_date,
            'gender' => $employee->gender,
            'current_dept_name' => $employee->current_dept_name,
            'current_title' => $employee->current_title,
            'current_salary' => in_array('salary', $columns, true) ? $employee->current_salary : null,
            'last_dept_name' => $employee->last_dept_name,
            'last_job_title' => $employee->last_job_title,
            'total_earnings' => in_array('earnings', $columns, true) ? ($earnings[$employee->emp_no] ?? 0) : null,
        ]);

        return Pdf::loadView('exports.employees-pdf', [
            'employees' => $employees,
            'exportDate' => now()->format('Y-m-d H:i:s'),
            'totalCount' => $employees->count(),
            'selectedCount' => $selectedCount,
            'isLimited' => $selectedCount > $limit,
            'limit' => $limit,
            'filters' => $query->filters(),
            'withSalaries' => $withSalaryColumns,
            'columns' => $columns,
            'labels' => array_map(fn ($key) => __(self::COLUMNS[$key][0]), array_combine($columns, $columns)),
        ])
            ->setPaper('A4', 'landscape')
            ->setOptions([
                'isHtml5ParserEnabled' => true,
                'isPhpEnabled' => true,
                'defaultFont' => 'DejaVu Sans',
            ]);
    }

    /**
     * Sum of all salary rows per employee.
     *
     * @param  int[]  $ids
     * @return array<int, int|string>
     */
    public function totalEarnings(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('salaries')
            ->select('emp_no', DB::raw('SUM(salary) as total_salary'))
            ->whereIntegerInRaw('emp_no', $ids)
            ->groupBy('emp_no')
            ->pluck('total_salary', 'emp_no')
            ->toArray();
    }

    /**
     * @param  string[]  $columns
     * @return string[]
     */
    private function header(array $columns): array
    {
        return array_map(fn ($key) => __(self::COLUMNS[$key][0]), $columns);
    }

    /**
     * @param  string[]  $columns
     * @return array<int, mixed>
     */
    private function csvRow(object $employee, int|float|string $totalEarnings, array $columns): array
    {
        $values = $this->values($employee, $columns);

        if (array_key_exists('salary', $values)) {
            $values['salary'] = $employee->current_salary ? number_format($employee->current_salary, 2, '.', '') : 'N/A';
        }
        if (array_key_exists('earnings', $values)) {
            $values['earnings'] = number_format((float) $totalEarnings, 2, '.', '');
        }
        if (array_key_exists('hire_date', $values)) {
            $values['hire_date'] = date('Y-m-d', strtotime($employee->hire_date));
        }

        return array_values($values);
    }

    /**
     * Typed values for a spreadsheet: numbers as numbers, the hire date as a date.
     *
     * @param  string[]  $columns
     * @return array<string, mixed>
     */
    private function xlsxRow(object $employee, int|float|string $totalEarnings, array $columns): array
    {
        $values = $this->values($employee, $columns);

        if (array_key_exists('emp_no', $values)) {
            $values['emp_no'] = (int) $employee->emp_no;
        }
        if (array_key_exists('salary', $values)) {
            $values['salary'] = $employee->current_salary ? (int) $employee->current_salary : null;
        }
        if (array_key_exists('earnings', $values)) {
            $values['earnings'] = (float) $totalEarnings;
        }
        if (array_key_exists('hire_date', $values)) {
            $values['hire_date'] = new DateTimeImmutable(substr((string) $employee->hire_date, 0, 10));
        }

        return $values;
    }

    /**
     * Text values of the chosen columns, keyed by column.
     *
     * @param  string[]  $columns
     * @return array<string, mixed>
     */
    private function values(object $employee, array $columns): array
    {
        $values = [];

        foreach ($columns as $key) {
            $values[$key] = match ($key) {
                'emp_no' => $employee->emp_no,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'full_name' => $employee->first_name.' '.$employee->last_name,
                'department' => match (true) {
                    (bool) $employee->current_dept_name => $employee->current_dept_name,
                    (bool) $employee->last_dept_name => __('Former').' - '.$employee->last_dept_name,
                    default => 'N/A',
                },
                'title' => match (true) {
                    (bool) $employee->current_title => $employee->current_title,
                    (bool) $employee->last_job_title => $employee->last_job_title.' ('.__('Last').')',
                    default => 'N/A',
                },
                'gender' => $employee->gender === 'M' ? __('Male') : __('Female'),
                'status' => $employee->current_dept_name ? __('Active') : __('Former'),
                default => null, // salary, earnings, hire_date: formatted per format
            };
        }

        return $values;
    }

    /**
     * Neutralize spreadsheet formulas (CSV injection): a text cell starting with =, +, -, @,
     * a tab or a carriage return is prefixed with a single quote so that Excel / LibreOffice
     * show it as text instead of evaluating it. (XLSX cells are typed, see writeXlsxIds().)
     */
    private static function csvCell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
