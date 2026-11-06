<?php

namespace Tests\Feature\HrOperations;

use App\Models\DeptEmp;
use App\Models\DeptManager;
use App\Models\Salary;
use App\Models\Title;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Static guard: code under app/ that reads the history tables must exclude voided
 * rows (App\Support\HistoryRows). A statement mentioning one of the tables needs as
 * many filters as it has table references; inserts are exempt.
 *
 * Accepted as a filter: HistoryRows::active / HistoryRows::sql / HistoryRows::COLUMN,
 * `void_log_id`, and EmployeeSearchQuery::departmentMembership(), which applies it.
 */
class VoidedRowsGuardTest extends TestCase
{
    /** "relative path" => reason, for code that may read voided rows on purpose. */
    private const ALLOWLIST = [
        // None so far.
    ];

    private const TABLES = 'dept_emp|dept_manager|titles|salaries';

    public function test_no_query_over_the_history_tables_forgets_the_filter(): void
    {
        $violations = [];

        foreach (File::allFiles(app_path()) as $file) {
            $path = str_replace('\\', '/', $file->getRelativePathname());

            if (! isset(self::ALLOWLIST[$path])) {
                array_push($violations, ...array_map(fn ($s) => $path.': '.$s, $this->violations($file->getContents())));
            }
        }

        $this->assertSame([], $violations, 'Use App\Support\HistoryRows for these queries (docs/EXTENDING.md).');
    }

    public function test_the_guard_notices_unfiltered_queries(): void
    {
        $bad = [
            "<?php DB::table('salaries')->where('emp_no', 1)->get();",
            "<?php DB::table('dept_emp as de')->join('titles as t', 't.emp_no', '=', 'de.emp_no')->where('de.void_log_id', 0)->get();",
            "<?php \$q->from('titles as t')->get();",
            "<?php \$q->leftJoin('dept_manager as m', 'm.emp_no', '=', 'e.emp_no')->get();",
            "<?php DB::select('SELECT * FROM salaries s WHERE s.emp_no = 1');",
            "<?php DB::raw('(SELECT 1 FROM titles t JOIN salaries s ON s.emp_no = t.emp_no WHERE t.void_log_id = 0)');",
            "<?php \$this->belongsToMany(Department::class, 'dept_emp', 'emp_no', 'dept_no');",
            "<?php DB::table(\$table)->max('from_date');",
        ];
        foreach ($bad as $code) {
            $this->assertNotSame([], $this->violations($code), $code);
        }

        $good = [
            "<?php HistoryRows::active(DB::table('salaries'))->get();",
            "<?php DB::table('salaries')->insert(['emp_no' => 1]);",
            '<?php DB::table($table)->insert($row);',
            "<?php // DB::table('salaries')->get();\n",
            "<?php DB::table('employees')->get(); \$label = 'titles';",
            "<?php EmployeeSearchQuery::departmentMembership(DB::table('dept_emp as de_f'), 'd001');",
        ];
        foreach ($good as $code) {
            $this->assertSame([], $this->violations($code), $code);
        }
    }

    public function test_history_models_exclude_voided_rows(): void
    {
        foreach ([Salary::class, Title::class, DeptEmp::class, DeptManager::class] as $model) {
            $this->assertStringContainsString('void_log_id', (new $model)->newQuery()->toSql(), $model);
        }
    }

    public function test_nothing_under_app_deletes_history_rows(): void
    {
        $found = [];
        $delete = '/(?:table\(\s*[\'"]?\$?(?:'.self::TABLES.'|table)|\$this->rows\(|\$query\b).*->delete\(\)|DELETE\s+FROM\s+(?:'.self::TABLES.')\b|(?:Salary|Title|DeptEmp|DeptManager)::[^;]*->delete\(\)/i';

        foreach (File::allFiles(app_path()) as $file) {
            foreach ($this->statements($this->withoutComments($file->getContents())) as $statement) {
                if (preg_match($delete, $statement)) {
                    $found[] = $file->getRelativePathname().': '.trim($statement);
                }
            }
        }

        $this->assertSame([], $found);
    }

    /**
     * @return string[] statements that reference a history table without a filter
     */
    private function violations(string $source): array
    {
        $reference = '/(?:table\(\s*[\'"](?:'.self::TABLES.')\b)'
            .'|(?:table\(\s*\$(?:table|historyTable)\b)'
            .'|(?:\b(?:from|join|leftJoin|rightJoin)\(\s*[\'"](?:'.self::TABLES.')\b)'
            .'|(?:belongsToMany\([^)]*[\'"](?:'.self::TABLES.')[\'"])'
            .'|(?:\b(?:FROM|JOIN)\s+(?:'.self::TABLES.')\b)/i';
        $filter = '/HistoryRows::(?:active|sql|COLUMN)|void_log_id|departmentMembership\(/';

        $violations = [];
        foreach ($this->statements($this->withoutComments($source)) as $statement) {
            $references = preg_match_all($reference, $statement);

            if ($references > 0 && ! str_contains($statement, '->insert(') && preg_match_all($filter, $statement) < $references) {
                $violations[] = trim((string) preg_replace('/\s+/', ' ', $statement));
            }
        }

        return $violations;
    }

    /**
     * @return string[]
     */
    private function statements(string $source): array
    {
        return explode(';', $source);
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
