<?php

namespace Tests\Feature\HrOperations;

use App\Models\DeptEmp;
use App\Models\DeptManager;
use App\Models\Salary;
use App\Models\Title;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Static guard: code under app/ that reads the history tables must exclude voided
 * rows (App\Support\HistoryRows). Every single reference to one of the tables, by
 * query builder or raw SQL, needs its own filter in the same statement:
 *
 *   HistoryRows::active(DB::table('salaries'))
 *   HistoryRows::active(<query or join>, 'alias')
 *   HistoryRows::sql('alias')  /  alias.void_log_id
 *   ->wherePivot(HistoryRows::COLUMN, ...)
 *   departmentMembership(): applies the filter to its `de_f`
 *
 * Inserts are exempt. Dynamic table names count as history tables. See also
 * VoidedRowsRuntimeGuardTest, which checks the SQL the read paths really run.
 */
class VoidedRowsGuardTest extends TestCase
{
    /** "path relative to app/" => reason, for code that may touch voided rows on purpose. */
    private const ALLOWLIST = [
        // None so far.
    ];

    private const TABLES = 'dept_emp|dept_manager|titles|salaries';

    /** Words that can follow a table name in SQL without being its alias. */
    private const NOT_AN_ALIAS = ['where', 'join', 'inner', 'left', 'right', 'cross', 'on', 'using', 'group', 'order', 'limit', 'having', 'union', 'set', 'as', 'for'];

    public function test_no_reference_to_the_history_tables_forgets_the_filter(): void
    {
        $violations = [];

        foreach ($this->sourceFiles() as $path => $source) {
            array_push($violations, ...array_map(fn ($v) => $path.': '.$v, $this->violations($source)));
        }

        $this->assertSame([], $violations, 'Use App\Support\HistoryRows for these queries (docs/EXTENDING.md).');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function bypassProvider(): array
    {
        return [
            'plain table' => ["<?php DB::table('salaries')->where('emp_no', 1)->get();"],
            'second table in a statement with one filter' => ["<?php HistoryRows::active(DB::table('dept_emp as de'), 'de')->join('titles as t', 't.emp_no', '=', 'de.emp_no')->get();"],
            'one filter counted for the wrong alias' => ["<?php \$q->join('titles as t', 'a', '=', 'b')->join('salaries as s', 'a', '=', 'b')->where('s.void_log_id', 0)->where('s.void_log_id', 0)->get();"],
            'filter on another alias' => ["<?php HistoryRows::active(DB::table('titles as t'), 'x')->get();"],
            'from' => ["<?php \$q->from('titles as t')->get();"],
            'left join' => ["<?php \$q->leftJoin('dept_manager as m', 'm.emp_no', '=', 'e.emp_no')->get();"],
            'cross join' => ["<?php \$q->crossJoin('salaries as s')->get();"],
            'join where' => ["<?php \$q->joinWhere('salaries as s', 's.x', '=', 1)->get();"],
            'left join sub' => ["<?php \$q->leftJoinSub(DB::table('titles'), 't', 'a', '=', 'b')->get();"],
            'from sub' => ["<?php DB::query()->fromSub(DB::table('salaries'), 's')->get();"],
            'from raw' => ["<?php DB::query()->fromRaw('salaries s')->get();"],
            'raw select' => ["<?php DB::select('SELECT * FROM salaries s WHERE s.emp_no = 1');"],
            'raw join with one filter' => ["<?php DB::raw('(SELECT 1 FROM titles t JOIN salaries s ON s.emp_no = t.emp_no WHERE t.void_log_id = 0)');"],
            'raw lower case' => ["<?php DB::select('select * from titles where emp_no = 1');"],
            'raw backticks' => ['<?php DB::select("SELECT * FROM `salaries` WHERE emp_no = 1");'],
            'raw schema qualified' => ["<?php DB::select('SELECT * FROM employees.salaries s');"],
            'raw schema qualified backticks' => ['<?php DB::select("SELECT * FROM `employees`.`dept_emp` de");'],
            'raw comma join' => ["<?php DB::select('SELECT * FROM employees e, salaries s WHERE s.emp_no = e.emp_no');"],
            'relation' => ["<?php \$this->belongsToMany(Department::class, 'dept_emp', 'emp_no', 'dept_no');"],
            'variable table' => ["<?php DB::table(\$table)->max('from_date');"],
            'variable with another name' => ["<?php DB::table(\$history)->max('from_date');"],
            'variable from' => ['<?php $q->from($source)->get();'],
            'variable join' => ["<?php \$q->join(\$source, 'a', '=', 'b')->get();"],
            'constant table' => ['<?php DB::table(self::TABLE)->get();'],
            'concatenated raw' => ["<?php DB::raw('SELECT 1 FROM '.\$table);"],
        ];
    }

    #[DataProvider('bypassProvider')]
    public function test_the_guard_notices_a_bypass(string $code): void
    {
        $this->assertNotSame([], $this->violations($code), $code);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function correctCodeProvider(): array
    {
        return [
            'helper' => ["<?php HistoryRows::active(DB::table('salaries'))->get();"],
            'helper with alias' => ["<?php HistoryRows::active(DB::table('salaries as s'), 's')->get();"],
            'helper with the table name' => ["<?php HistoryRows::active(DB::table('dept_emp'), 'dept_emp')->join('departments', 'a', '=', 'b')->get();"],
            'dynamic table with helper' => ['<?php HistoryRows::active(DB::table($table))->get();'],
            'dynamic table with alias' => ['<?php HistoryRows::active(DB::table($source), $alias)->get();'],
            'dynamic join' => ["<?php \$q->join(\$source, function (\$join) use (\$alias) { HistoryRows::active(\$join, \$alias); });"],
            'sub-query' => ["<?php HistoryRows::active(\$this->subSelect(\$q)->from('titles as t'), 't')->get();"],
            'join' => ["<?php \$q->leftJoin('titles as t', function (\$join) { HistoryRows::active(\$join->on('a', '=', 'b'), 't')->where('x', 1); });"],
            'two joins' => ["<?php \$q->join('titles as t', fn (\$j) => HistoryRows::active(\$j, 't'))->join('salaries as s', fn (\$j) => HistoryRows::active(\$j, 's'))->get();"],
            'raw' => ["<?php DB::raw('SELECT 1 FROM salaries s WHERE '.HistoryRows::sql('s'));"],
            'raw column' => ["<?php DB::select('SELECT 1 FROM salaries s WHERE s.void_log_id = 0');"],
            'raw without alias' => ["<?php DB::select('SELECT 1 FROM salaries WHERE salaries.void_log_id = 0');"],
            'pivot' => ["<?php \$this->belongsToMany(Department::class, 'dept_emp', 'emp_no', 'dept_no')->wherePivot(HistoryRows::COLUMN, HistoryRows::ACTIVE);"],
            'insert' => ["<?php DB::table('salaries')->insert(['emp_no' => 1]);"],
            'dynamic insert' => ['<?php DB::table($table)->insert($row);'],
            'comment' => ["<?php // DB::table('salaries')->get();\n"],
            'docblock' => ['<?php /** FROM salaries */ $a = 1;'],
            'other tables' => ["<?php DB::table('employees')->get(); \$label = 'titles';"],
            'the words in a sentence' => ["<?php __('Pick the month from salaries later');"],
            'other methods' => ["<?php \$this->nextFrom('titles', \$empNo);"],
            'membership' => ["<?php EmployeeSearchQuery::departmentMembership(DB::table('dept_emp as de_f'), 'd001');"],
        ];
    }

    #[DataProvider('correctCodeProvider')]
    public function test_the_guard_accepts_correct_code(string $code): void
    {
        $this->assertSame([], $this->violations($code), $code);
    }

    public function test_history_models_exclude_voided_rows(): void
    {
        foreach ([Salary::class, Title::class, DeptEmp::class, DeptManager::class] as $model) {
            $this->assertStringContainsString('void_log_id', (new $model)->newQuery()->toSql(), $model);
        }
    }

    public function test_nothing_under_app_gets_around_the_global_scope(): void
    {
        $found = [];

        foreach ($this->sourceFiles() as $path => $source) {
            array_push($found, ...array_map(fn ($v) => $path.': '.$v, $this->scopeBypasses($source)));
        }

        $this->assertSame([], $found);
    }

    public function test_the_guard_notices_scope_bypasses(): void
    {
        foreach ([
            "<?php Salary::withoutGlobalScope('active')->get();",
            '<?php Salary::withoutGlobalScopes()->get();',
            '<?php (new Title)->newQueryWithoutScopes()->get();',
            '<?php (new Title)->newQueryWithoutScope(ExcludesVoidedRows::class);',
            '<?php $employee->titles()->withoutGlobalScopes()->count();',
            '<?php (new DeptEmp)->newModelQuery()->get();',
        ] as $code) {
            $this->assertNotSame([], $this->scopeBypasses($code), $code);
        }
    }

    public function test_nothing_under_app_deletes_history_rows(): void
    {
        $found = [];

        foreach ($this->sourceFiles() as $path => $source) {
            array_push($found, ...array_map(fn ($v) => $path.': '.$v, $this->deletions($source)));
        }

        $this->assertSame([], $found);
    }

    public function test_the_guard_notices_deletions(): void
    {
        foreach ([
            "<?php DB::table('salaries')->where('emp_no', 1)->delete();",
            "<?php HistoryRows::active(DB::table('titles'))->delete();",
            '<?php DB::table($table)->delete();',
            "<?php \$this->rows('titles')->delete();",
            '<?php Salary::where("emp_no", 1)->delete();',
            '<?php Title::query()->forceDelete();',
            "<?php DB::table('dept_emp')->truncate();",
            '<?php Salary::truncate();',
            "<?php DB::statement('DELETE FROM salaries WHERE emp_no = 1');",
            "<?php DB::statement('TRUNCATE TABLE `dept_manager`');",
            "<?php DB::table('activity_log')->truncate();",
            '<?php ActivityLog::truncate();',
        ] as $code) {
            $this->assertNotSame([], $this->deletions($code), $code);
        }

        $this->assertSame([], $this->deletions("<?php DB::table('sessions')->delete(); \$user->delete();"));
    }

    /**
     * @return array<string, string> path relative to app/ => source without comments
     */
    private function sourceFiles(): array
    {
        $sources = [];

        foreach (File::allFiles(app_path()) as $file) {
            $path = str_replace('\\', '/', $file->getRelativePathname());

            if (! isset(self::ALLOWLIST[$path])) {
                $sources[$path] = $file->getContents();
            }
        }

        return $sources;
    }

    /**
     * @return string[] references without their own filter
     */
    private function violations(string $source): array
    {
        $violations = [];

        foreach (explode(';', $this->withoutComments($source)) as $statement) {
            if (str_contains($statement, '->insert(')) {
                continue;
            }

            foreach ($this->references($statement) as [$table, $name]) {
                if (! $this->isFiltered($statement, $table, $name)) {
                    $violations[] = $table.($name === null ? '' : ' as '.$name).' in: '.trim((string) preg_replace('/\s+/', ' ', $statement));
                }
            }
        }

        return $violations;
    }

    /**
     * Every reference to a history table in a statement.
     *
     * @return array<int, array{string, string|null}> [table, alias]; table "$" = dynamic
     */
    private function references(string $statement): array
    {
        $tables = self::TABLES;
        $references = [];

        // Query builder: table(...), from(...), every *join*(...), fromRaw(...), with an optional alias.
        $call = '\b(?:table|from|fromRaw|\w*[jJ]oin\w*)\(\s*';
        preg_match_all("/{$call}['\"]`?({$tables})`?(?:\s+(?:as\s+)?(\w+))?['\"]/i", $statement, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $references[] = [strtolower($match[1]), $match[2] ?? null ?: null];
        }

        // Dynamic names: a variable or constant where a table is expected.
        preg_match_all('/(?:\bDB::table|->(?:from|(?:\w*J|j)oin(?:Sub|Where|Lateral)?))\(\s*(\$(?!this\b)\w+|(?:self|static)::\w+)/', $statement, $matches);
        foreach ($matches[1] as $name) {
            if ($name !== '$join') {
                $references[] = ['$', $name];
            }
        }

        // Relations.
        preg_match_all("/belongsToMany\([^)]*['\"]({$tables})['\"]/", $statement, $matches);
        foreach ($matches[1] as $table) {
            $references[] = [$table, 'pivot'];
        }

        // Raw SQL: FROM / JOIN table, also with backticks, a schema or in a comma list.
        $name = "(?:`?\w+`?\.)?`?({$tables})`?";
        $alias = '(?:\s+(?:as\s+)?(\w+))?';
        preg_match_all("/\b(?:FROM|JOIN)\s+{$name}{$alias}/i", $statement, $matches, PREG_SET_ORDER);
        $raw = $matches;
        preg_match_all("/\bFROM\s+[^'\"]*?,\s*{$name}{$alias}/i", $statement, $matches, PREG_SET_ORDER);
        foreach ([...$raw, ...$matches] as $match) {
            $word = $match[2] ?? null;
            $sentence = $word !== null && ! preg_match('/\bFROM\b.*?\b(SELECT|WHERE)\b|\bSELECT\b/is', $statement) && ! str_contains($match[0], '`');

            if (! $sentence || $word === null) {
                $references[] = [strtolower($match[1]), $word === null || in_array(strtolower($word), self::NOT_AN_ALIAS, true) ? null : $word];
            }
        }

        // SQL built from a variable table name.
        if (preg_match('/\b(?:FROM|JOIN)\s+[\'"]\s*\.\s*\$\w+/i', $statement, $match)) {
            $references[] = ['$', $match[0]];
        }

        return $references;
    }

    private function isFiltered(string $statement, string $table, ?string $name): bool
    {
        if ($name === 'pivot') {
            return (bool) preg_match('/wherePivot\(\s*(?:HistoryRows::COLUMN|[\'"]void_log_id[\'"])/', $statement);
        }

        if ($table === '$') {
            $table = preg_quote((string) $name, '/');

            return (bool) preg_match('/HistoryRows::active\(\s*DB::table\(\s*'.$table.'\s*\)\s*(?:,\s*\$\w+\s*)?\)|HistoryRows::active\(\s*\$join\s*,\s*\$\w+\s*\)/', $statement);
        }

        if ($name === 'de_f' && str_contains($statement, 'departmentMembership(')) {
            return true;
        }

        $reference = preg_quote($name ?? $table, '/');
        $filters = [
            "HistoryRows::sql\(\s*['\"]{$reference}['\"]\s*\)",
            "HistoryRows::active\(.*?,\s*['\"]{$reference}['\"]\s*\)",
            "\b{$reference}`?\.`?void_log_id\b",
        ];

        if ($name === null) {
            $filters[] = "HistoryRows::active\(\s*DB::table\(\s*['\"]{$table}['\"]\s*\)\s*\)";
            $filters[] = "\bFROM\s+`?{$table}`?\s+WHERE\s+(?:.*\s)?void_log_id\b";
        }

        return (bool) preg_match('/'.implode('|', $filters).'/is', $statement);
    }

    /**
     * @return string[]
     */
    private function scopeBypasses(string $source): array
    {
        preg_match_all('/\b(withoutGlobalScopes?|newQueryWithoutScopes?|newQueryWithoutRelationships|newModelQuery|getQuery)\(/', $this->withoutComments($source), $matches);

        return $matches[0];
    }

    /**
     * Deleting or truncating history rows, or truncating the audit log whose ids void_log_id holds.
     *
     * @return string[]
     */
    private function deletions(string $source): array
    {
        $tables = self::TABLES;
        $patterns = [
            "/(?:table\(\s*['\"]?(?:{$tables})\b|table\(\s*\\\$|\\\$this->rows\(|\b(?:Salary|Title|DeptEmp|DeptManager)::).*->(?:delete|forceDelete|truncate)\(/is",
            '/\b(?:Salary|Title|DeptEmp|DeptManager|ActivityLog)::truncate\(/',
            "/table\(\s*['\"]activity_log['\"]\s*\).*->truncate\(/is",
            "/\bDELETE\s+FROM\s+(?:`?\w+`?\.)?`?(?:{$tables})\b/i",
            "/\bTRUNCATE\s+(?:TABLE\s+)?(?:`?\w+`?\.)?`?(?:{$tables}|activity_log)\b/i",
        ];

        $found = [];
        foreach (explode(';', $this->withoutComments($source)) as $statement) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $statement)) {
                    $found[] = trim((string) preg_replace('/\s+/', ' ', $statement));
                }
            }
        }

        return $found;
    }

    private function withoutComments(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_string($token)) {
                $code .= $token;
            } elseif (! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $code .= $token[1];
            }
        }

        return $code;
    }
}
