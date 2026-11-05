<?php

namespace Tests\Feature\Queries;

use App\Queries\EmployeeFilters;
use App\Queries\EmployeeSearchQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsDirectory;
use Tests\TestCase;

class EmployeeSearchQueryTest extends TestCase
{
    use BuildsDirectory;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildDirectory();
    }

    /**
     * Asserts that every way of reading the result agrees: the segment based
     * reader (no total known), the per-employee sort key reader (total known),
     * count(), the raw filtered() query and a one-page paginator.
     *
     * @param  int[]  $expected  emp_no values in the expected order
     * @param  array<string, mixed>  $filters
     */
    private function assertResult(array $expected, array $filters = []): void
    {
        $query = EmployeeSearchQuery::make($filters);
        $count = count($expected);

        $this->assertSame($expected, $query->ids(), 'ids() without known total (segments)');
        $this->assertSame($expected, $query->ids(null, 0, $count), 'ids() with known total (sort key)');
        $this->assertSame($count, $query->count(), 'count()');
        $this->assertSame($count, $query->filtered()->count(), 'filtered()->count()');

        $page = $query->paginate(50, 1);
        $this->assertSame($count, $page->total());
        $this->assertSame($expected, $page->getCollection()->pluck('emp_no')->map(fn ($n) => (int) $n)->all(), 'paginate()');
    }

    /** Resolve closures (needed because data providers run before the fixture exists). */
    private function resolve(array $filters): array
    {
        return array_map(fn ($v) => $v instanceof \Closure ? $v($this) : $v, $filters);
    }

    // ---------------------------------------------------------------- filters

    public function test_no_filters_returns_everybody_in_emp_no_order(): void
    {
        $this->assertResult([1, 2, 3, 4, 5, 6, 7]);
    }

    #[DataProvider('searchProvider')]
    public function test_search(string $term, array $expected): void
    {
        $this->assertResult($expected, ['search' => $term]);
    }

    public static function searchProvider(): array
    {
        return [
            'first name' => ['Bob', [2]],
            'last name' => ['Smith', [1, 3, 6]],
            'partial first name' => ['nna', [1, 6]],
            'partial inside a longer name' => ['ana', [4]],
            'partial last name' => ['row', [4]],
            'full name' => ['Anna Smith', [1, 6]],
            'full name partial across the space' => ['a S', [1, 6]],
            'case insensitive' => ['hANK lEE', [7]],
            'no match' => ['Zzzzz', []],
            'sql injection attempt is just text' => ["x' OR '1'='1", []],
            'quote and comment' => ["'; DROP TABLE employees; --", []],
        ];
    }

    #[DataProvider('statusProvider')]
    public function test_employment_status(string $status, array $expected): void
    {
        $this->assertResult($expected, ['employmentStatus' => $status]);
    }

    public static function statusProvider(): array
    {
        return [
            'all' => ['all', [1, 2, 3, 4, 5, 6, 7]],
            'current (has a department row ending 9999-01-01)' => ['current', [1, 2, 6, 7]],
            'former (includes employees without any history)' => ['former', [3, 4, 5]],
            'unknown value means all' => ['retired', [1, 2, 3, 4, 5, 6, 7]],
        ];
    }

    public function test_gender(): void
    {
        $this->assertResult([1, 4, 5], ['gender' => 'F']);
        $this->assertResult([2, 3, 6, 7], ['gender' => 'M']);
        $this->assertResult([1, 2, 3, 4, 5, 6, 7], ['gender' => 'all']);
        $this->assertResult([1, 2, 3, 4, 5, 6, 7], ['gender' => 'X']);
    }

    public function test_department_matches_current_department_or_last_department_of_former_employees(): void
    {
        // 3 worked in Alpha before Mid and 7 in Omega before Mid: earlier departments do not count.
        $this->assertResult([1, 4, 6], ['department' => $this->alpha->dept_no]);
        $this->assertResult([3, 7], ['department' => $this->mid->dept_no]);
        $this->assertResult([2], ['department' => $this->omega->dept_no]);
    }

    public function test_department_combined_with_employment_status(): void
    {
        $alpha = $this->alpha->dept_no;

        $this->assertResult([1, 6], ['department' => $alpha, 'employmentStatus' => 'current']);
        $this->assertResult([4], ['department' => $alpha, 'employmentStatus' => 'former']);
        $this->assertResult([3], ['department' => $this->mid->dept_no, 'employmentStatus' => 'former']);
        $this->assertResult([], ['department' => $this->omega->dept_no, 'employmentStatus' => 'former']);
    }

    public function test_unknown_department_matches_nothing(): void
    {
        $this->assertResult([], ['department' => 'd999']);
        $this->assertResult([], ['department' => "d001' OR '1'='1"]);
    }

    public function test_job_title_matches_current_title_or_last_title_of_former_employees(): void
    {
        // 3 was an Engineer before Staff, 7 a Staff before Senior Staff.
        $this->assertResult([1, 6], ['jobTitle' => 'Engineer']);
        $this->assertResult([2, 4], ['jobTitle' => 'Manager']);
        $this->assertResult([3], ['jobTitle' => 'Staff']);
        $this->assertResult([7], ['jobTitle' => 'Senior Staff']);
        $this->assertResult([], ['jobTitle' => 'Janitor']);
        $this->assertResult([], ['jobTitle' => "Staff' OR '1'='1"]);
    }

    public function test_job_title_combined_with_status(): void
    {
        $this->assertResult([4], ['jobTitle' => 'Manager', 'employmentStatus' => 'former']);
        $this->assertResult([2], ['jobTitle' => 'Manager', 'employmentStatus' => 'current']);
        $this->assertResult([], ['jobTitle' => 'Engineer', 'employmentStatus' => 'former']);
    }

    #[DataProvider('salaryProvider')]
    public function test_salary_range_uses_current_salary_only(array $range, array $expected): void
    {
        // Former employees (3, 4) earned 60000 / 80000 but have no *current* salary row.
        $this->assertResult($expected, $range);
    }

    public static function salaryProvider(): array
    {
        return [
            'min only' => [['salaryMin' => 60000], [2, 7]],
            'max only' => [['salaryMax' => 50000], [1, 6]],
            'range' => [['salaryMin' => 50000, 'salaryMax' => 70000], [1, 6, 7]],
            'inclusive bounds' => [['salaryMin' => 90000, 'salaryMax' => 90000], [2]],
            'numeric strings' => [['salaryMin' => '60000', 'salaryMax' => '95000'], [2, 7]],
            'empty strings mean no bound' => [['salaryMin' => '', 'salaryMax' => ''], [1, 2, 3, 4, 5, 6, 7]],
            'zero means no bound' => [['salaryMin' => 0, 'salaryMax' => 0], [1, 2, 3, 4, 5, 6, 7]],
            'non numeric means no bound' => [['salaryMin' => 'abc'], [1, 2, 3, 4, 5, 6, 7]],
            'impossible range' => [['salaryMin' => 80000, 'salaryMax' => 60000], []],
            'above everything' => [['salaryMin' => 1000000], []],
        ];
    }

    #[DataProvider('combinationProvider')]
    public function test_filter_combinations(array $filters, array $expected): void
    {
        $this->assertResult($expected, $this->resolve($filters));
    }

    public static function combinationProvider(): array
    {
        return [
            'search + current' => [['search' => 'Smith', 'employmentStatus' => 'current'], [1, 6]],
            'search + gender' => [['search' => 'Smith', 'gender' => 'M'], [3, 6]],
            'search + gender + title' => [['search' => 'Smith', 'gender' => 'M', 'jobTitle' => 'Engineer'], [6]],
            'female former' => [['gender' => 'F', 'employmentStatus' => 'former'], [4, 5]],
            'male current salary range' => [['gender' => 'M', 'employmentStatus' => 'current', 'salaryMin' => 60000], [2, 7]],
            'department + salary' => [['department' => fn ($t) => $t->alpha->dept_no, 'salaryMin' => 1], [1, 6]],
            'department + gender + former' => [['department' => fn ($t) => $t->alpha->dept_no, 'gender' => 'F', 'employmentStatus' => 'former'], [4]],
            'department + title' => [['department' => fn ($t) => $t->mid->dept_no, 'jobTitle' => 'Staff'], [3]],
            'department + title (no overlap)' => [['department' => fn ($t) => $t->omega->dept_no, 'jobTitle' => 'Staff'], []],
            'everything at once' => [[
                'search' => 'Anna', 'employmentStatus' => 'current', 'gender' => 'F',
                'department' => fn ($t) => $t->alpha->dept_no, 'jobTitle' => 'Engineer',
                'salaryMin' => 40000, 'salaryMax' => 60000,
            ], [1]],
            'everything at once, nothing left' => [[
                'search' => 'Anna', 'employmentStatus' => 'former', 'gender' => 'F',
                'department' => fn ($t) => $t->alpha->dept_no, 'jobTitle' => 'Engineer',
            ], []],
        ];
    }

    // ----------------------------------------------------------------- sorting

    #[DataProvider('sortProvider')]
    public function test_sort_orders(string $field, string $direction, array $expected): void
    {
        $this->assertResult($expected, ['sortBy' => $field, 'sortDirection' => $direction]);
    }

    public static function sortProvider(): array
    {
        return [
            'emp_no asc' => ['emp_no', 'asc', [1, 2, 3, 4, 5, 6, 7]],
            'emp_no desc' => ['emp_no', 'desc', [7, 6, 5, 4, 3, 2, 1]],
            // equal names (1 and 6) are ordered by emp_no in the same direction
            'name asc' => ['name', 'asc', [1, 6, 2, 3, 4, 5, 7]],
            'name desc' => ['name', 'desc', [7, 5, 4, 3, 2, 6, 1]],
            // equal hire dates (1 and 6) are ordered by emp_no
            'hire_date asc' => ['hire_date', 'asc', [2, 3, 1, 6, 7, 4, 5]],
            'hire_date desc' => ['hire_date', 'desc', [5, 4, 7, 6, 1, 3, 2]],
            // the column is enum('M','F'): MySQL sorts enums by declaration order, so M comes first
            'gender asc' => ['gender', 'asc', [2, 3, 6, 7, 1, 4, 5]],
            'gender desc' => ['gender', 'desc', [5, 4, 1, 7, 6, 3, 2]],
            // no department sorts first ascending / last descending; former employees by LAST department
            'department asc' => ['department', 'asc', [5, 1, 4, 6, 3, 7, 2]],
            'department desc' => ['department', 'desc', [2, 7, 3, 6, 4, 1, 5]],
            // former employees by LAST title (3 -> Staff, not Engineer)
            'title asc' => ['title', 'asc', [5, 1, 6, 2, 4, 7, 3]],
            'title desc' => ['title', 'desc', [3, 7, 4, 2, 6, 1, 5]],
            // no current salary (3, 4, 5) counts as NULL: first ascending, last descending
            'salary asc' => ['salary', 'asc', [3, 4, 5, 1, 6, 7, 2]],
            'salary desc' => ['salary', 'desc', [2, 7, 6, 1, 5, 4, 3]],
        ];
    }

    #[DataProvider('sortWithFilterProvider')]
    public function test_sorting_together_with_filters(array $filters, array $expected): void
    {
        $this->assertResult($expected, $this->resolve($filters));
    }

    public static function sortWithFilterProvider(): array
    {
        return [
            'gender M by department asc' => [['gender' => 'M', 'sortBy' => 'department'], [6, 3, 7, 2]],
            'gender M by department desc' => [['gender' => 'M', 'sortBy' => 'department', 'sortDirection' => 'desc'], [2, 7, 3, 6]],
            'former by title asc' => [['employmentStatus' => 'former', 'sortBy' => 'title'], [5, 4, 3]],
            'former by title desc' => [['employmentStatus' => 'former', 'sortBy' => 'title', 'sortDirection' => 'desc'], [3, 4, 5]],
            'salary range by salary asc' => [['salaryMin' => 50000, 'sortBy' => 'salary'], [1, 6, 7, 2]],
            'salary range by salary desc' => [['salaryMin' => 50000, 'sortBy' => 'salary', 'sortDirection' => 'desc'], [2, 7, 6, 1]],
            'department filter by salary asc' => [['department' => fn ($t) => $t->alpha->dept_no, 'sortBy' => 'salary'], [4, 1, 6]],
            'department filter by salary desc' => [['department' => fn ($t) => $t->alpha->dept_no, 'sortBy' => 'salary', 'sortDirection' => 'desc'], [6, 1, 4]],
            'title filter by department desc (tie -> emp_no desc)' => [['jobTitle' => 'Engineer', 'sortBy' => 'department', 'sortDirection' => 'desc'], [6, 1]],
            'department filter by title asc' => [['department' => fn ($t) => $t->alpha->dept_no, 'sortBy' => 'title'], [1, 6, 4]],
            'search by name asc' => [['search' => 'Smith', 'sortBy' => 'name'], [1, 6, 3]],
            'status current by hire date desc' => [['employmentStatus' => 'current', 'sortBy' => 'hire_date', 'sortDirection' => 'desc'], [7, 6, 1, 2]],
        ];
    }

    public function test_ties_are_broken_by_emp_no_in_the_sort_direction(): void
    {
        // 1 and 6 tie on name, hire date, department, title and salary.
        foreach (['name', 'hire_date', 'department', 'title', 'salary'] as $field) {
            $asc = EmployeeSearchQuery::make(['sortBy' => $field, 'sortDirection' => 'asc', 'search' => 'Anna'])->ids();
            $desc = EmployeeSearchQuery::make(['sortBy' => $field, 'sortDirection' => 'desc', 'search' => 'Anna'])->ids();

            $this->assertSame([1, 6], $asc, "$field asc");
            $this->assertSame([6, 1], $desc, "$field desc");
        }
    }

    public function test_invalid_sort_field_and_direction_fall_back_safely(): void
    {
        $injection = ['sortBy' => 'salary; DROP TABLE employees; --', 'sortDirection' => 'desc; DROP TABLE employees'];

        $filters = EmployeeFilters::fromArray($injection);
        $this->assertSame('emp_no', $filters->sortBy);
        $this->assertSame('asc', $filters->sortDirection);

        $this->assertResult([1, 2, 3, 4, 5, 6, 7], $injection);
        $this->assertResult([1, 2, 3, 4, 5, 6, 7], ['sortBy' => 'password', 'sortDirection' => 'sideways']);
        $this->assertResult([1, 2, 3, 4, 5, 6, 7], ['sortBy' => '', 'sortDirection' => '']);
        $this->assertSame(7, DB::table('employees')->count());
    }

    public function test_array_valued_input_is_treated_as_invalid_instead_of_crashing(): void
    {
        $this->markTestIncomplete('BUG: EmployeeFilters::fromArray() casts input with (string) without checking for arrays, so sortBy[]=x (also search[], gender[], ...) in the export request raises "Array to string conversion" (HTTP 500) instead of falling back to the defaults.');

        $filters = EmployeeFilters::fromArray(['sortBy' => ['name'], 'search' => ['x']]);
        $this->assertSame('emp_no', $filters->sortBy);
    }

    public function test_sort_direction_is_case_insensitive(): void
    {
        $this->assertResult([7, 6, 5, 4, 3, 2, 1], ['sortDirection' => 'DESC']);
        $this->assertResult([1, 2, 3, 4, 5, 6, 7], ['sortDirection' => 'ASC']);
    }

    public function test_only_whitelisted_sort_fields_are_accepted(): void
    {
        foreach (['e.emp_no', 'first_name', 'last_name', 'birth_date', 'current_salary', 'Name'] as $field) {
            $this->assertSame('emp_no', EmployeeFilters::fromArray(['sortBy' => $field])->sortBy, $field);
        }

        foreach (EmployeeFilters::SORTABLE as $field) {
            $this->assertSame($field, EmployeeFilters::fromArray(['sortBy' => $field])->sortBy, $field);
        }
    }

    // ------------------------------------------------------------------ counts

    public function test_count_matches_the_number_of_results_for_many_filter_sets(): void
    {
        $sets = [
            [],
            ['employmentStatus' => 'current'],
            ['employmentStatus' => 'former'],
            ['employmentStatus' => 'former', 'gender' => 'F'],
            ['search' => 'Smith'],
            ['salaryMin' => 55000],
            ['department' => $this->alpha->dept_no],
            ['jobTitle' => 'Manager', 'employmentStatus' => 'current'],
            ['gender' => 'M', 'salaryMax' => 70000, 'department' => $this->mid->dept_no],
        ];

        foreach ($sets as $filters) {
            $query = EmployeeSearchQuery::make($filters);
            $this->assertSame(count($query->ids()), $query->count(), json_encode($filters));
        }
    }

    public function test_status_only_counts_use_the_dept_emp_shortcut_but_agree_with_the_full_query(): void
    {
        $this->assertSame(4, EmployeeSearchQuery::make(['employmentStatus' => 'current'])->count());
        $this->assertSame(3, EmployeeSearchQuery::make(['employmentStatus' => 'former'])->count());
        $this->assertSame(7, EmployeeSearchQuery::make([])->count());
    }

    public function test_status_counts_do_not_double_count_employees_with_several_current_department_rows(): void
    {
        // Employee 1 gets a second *current* department row (e.g. dual assignment).
        DB::table('dept_emp')->insert([
            'emp_no' => 1, 'dept_no' => $this->omega->dept_no, 'from_date' => '1995-01-01', 'to_date' => '9999-01-01',
        ]);

        $this->assertSame(4, EmployeeSearchQuery::make(['employmentStatus' => 'current'])->count());
        $this->assertSame(3, EmployeeSearchQuery::make(['employmentStatus' => 'former'])->count());
        $this->assertSame(4, EmployeeSearchQuery::make(['employmentStatus' => 'current'])->filtered()->count());
    }

    // -------------------------------------------------------- onlyIds / exceptIds

    public function test_only_ids_restricts_the_result_and_keeps_the_filters(): void
    {
        $query = EmployeeSearchQuery::make(['gender' => 'F'])->onlyIds([1, 2, 3, 4]);

        $this->assertSame([1, 4], $query->ids());
        $this->assertSame(2, $query->count());
    }

    public function test_only_ids_ignores_non_numeric_values_and_duplicates(): void
    {
        $query = EmployeeSearchQuery::make([])->onlyIds(['2', 'abc', 2, '5; DROP TABLE employees', null, 7]);

        $this->assertSame([2, 7], $query->ids());
        $this->assertSame(2, $query->count());
    }

    public function test_only_ids_with_unknown_employees_returns_nothing(): void
    {
        $this->assertSame([], EmployeeSearchQuery::make([])->onlyIds([999, 1000])->ids());
        $this->assertSame(0, EmployeeSearchQuery::make([])->onlyIds([])->count());
    }

    public function test_except_ids_removes_employees_from_the_result(): void
    {
        $query = EmployeeSearchQuery::make(['employmentStatus' => 'current'])->exceptIds([2, '7', 'junk']);

        $this->assertSame([1, 6], $query->ids());
        $this->assertSame(2, $query->count());
    }

    public function test_only_and_except_ids_are_immutable_and_combinable(): void
    {
        $base = EmployeeSearchQuery::make(['sortBy' => 'emp_no', 'sortDirection' => 'desc']);
        $narrowed = $base->onlyIds([1, 2, 3, 4])->exceptIds([2]);

        $this->assertSame([7, 6, 5, 4, 3, 2, 1], $base->ids());
        $this->assertSame([4, 3, 1], $narrowed->ids());
    }

    // -------------------------------------------------------------- pagination

    public function test_paginate_returns_the_requested_slice_with_totals(): void
    {
        $query = EmployeeSearchQuery::make([]);

        $first = $query->paginate(3, 1);
        $this->assertSame(7, $first->total());
        $this->assertSame(3, $first->lastPage());
        $this->assertSame([1, 2, 3], $first->getCollection()->pluck('emp_no')->all());

        $this->assertSame([4, 5, 6], $query->paginate(3, 2)->getCollection()->pluck('emp_no')->all());
        $this->assertSame([7], $query->paginate(3, 3)->getCollection()->pluck('emp_no')->all());
    }

    public function test_paginate_beyond_the_last_page_is_empty_and_page_below_one_is_page_one(): void
    {
        $query = EmployeeSearchQuery::make([]);

        $this->assertCount(0, $query->paginate(3, 4)->getCollection());
        $this->assertSame(7, $query->paginate(3, 4)->total());
        $this->assertSame([1, 2, 3], $query->paginate(3, 0)->getCollection()->pluck('emp_no')->all());
        $this->assertSame([1, 2, 3], $query->paginate(3, -5)->getCollection()->pluck('emp_no')->all());
    }

    public function test_paginate_with_a_known_total_skips_the_count_query(): void
    {
        $query = EmployeeSearchQuery::make([]);
        DB::enableQueryLog();
        $page = $query->paginate(3, 1, 7);
        $log = strtolower(collect(DB::getQueryLog())->pluck('query')->implode("\n"));
        DB::disableQueryLog();

        $this->assertSame(7, $page->total());
        $this->assertStringNotContainsString('count(', $log);
    }

    public function test_paginate_of_an_empty_result(): void
    {
        $page = EmployeeSearchQuery::make(['search' => 'nobody-like-this'])->paginate(3, 1);

        $this->assertSame(0, $page->total());
        $this->assertCount(0, $page->getCollection());
    }

    #[DataProvider('everySortProvider')]
    public function test_pages_concatenate_to_the_full_sorted_result_with_both_readers(string $field, string $direction): void
    {
        $query = EmployeeSearchQuery::make(['sortBy' => $field, 'sortDirection' => $direction]);
        $all = $query->ids();
        $this->assertCount(7, $all);

        // segment reader (no total) and sort key reader (total known) must agree on every slice
        foreach ([1, 2, 3, 4, 7] as $perPage) {
            $viaSegments = [];
            $viaKey = [];
            for ($offset = 0; $offset < 7; $offset += $perPage) {
                $viaSegments = array_merge($viaSegments, $query->ids($perPage, $offset));
                $viaKey = array_merge($viaKey, $query->ids($perPage, $offset, 7));
            }

            $this->assertSame($all, $viaSegments, "$field $direction perPage=$perPage (segments)");
            $this->assertSame($all, $viaKey, "$field $direction perPage=$perPage (sort key)");
        }
    }

    public static function everySortProvider(): array
    {
        $cases = [];
        foreach (EmployeeFilters::SORTABLE as $field) {
            foreach (['asc', 'desc'] as $direction) {
                $cases["$field $direction"] = [$field, $direction];
            }
        }

        return $cases;
    }

    public function test_ids_limit_and_offset(): void
    {
        $query = EmployeeSearchQuery::make([]);

        $this->assertSame([], $query->ids(0));
        $this->assertSame([], $query->ids(-3));
        $this->assertSame([1, 2], $query->ids(2));
        $this->assertSame([3, 4], $query->ids(2, 2));
        $this->assertSame([7], $query->ids(5, 6));
        $this->assertSame([], $query->ids(5, 7));
    }

    // ------------------------------------------------------- rowsFor / chunk

    public function test_rows_for_returns_display_columns_in_the_requested_order(): void
    {
        $rows = EmployeeSearchQuery::make([])->rowsFor([4, 1, 999, 3]);

        $this->assertSame([4, 1, 3], $rows->pluck('emp_no')->all(), 'unknown ids are skipped, order kept');

        $current = $rows->firstWhere('emp_no', 1);
        $this->assertSame('Anna', $current->first_name);
        $this->assertSame('Smith', $current->last_name);
        $this->assertSame('F', $current->gender);
        $this->assertSame('Alpha', $current->current_dept_name);
        $this->assertSame('Engineer', $current->current_title);
        $this->assertEquals(50000, $current->current_salary);
        $this->assertNull($current->last_dept_name);
        $this->assertNull($current->last_job_title);

        $former = $rows->firstWhere('emp_no', 3);
        $this->assertNull($former->current_dept_name);
        $this->assertNull($former->current_title);
        $this->assertNull($former->current_salary);
        $this->assertSame('Mid', $former->last_dept_name);
        $this->assertSame('Staff', $former->last_job_title);
    }

    public function test_rows_for_an_employee_without_history_has_only_null_columns(): void
    {
        $row = EmployeeSearchQuery::make([])->rowsFor([5])->first();

        $this->assertSame('Eve', $row->first_name);
        foreach (['current_dept_name', 'current_title', 'current_salary', 'last_dept_name', 'last_job_title'] as $column) {
            $this->assertNull($row->$column, $column);
        }
    }

    public function test_rows_for_of_nothing_is_empty(): void
    {
        $this->assertTrue(EmployeeSearchQuery::make([])->rowsFor([])->isEmpty());
    }

    public function test_last_department_is_the_latest_end_date_then_lowest_dept_no(): void
    {
        // Employee 4 ends in two departments on the same day: the lowest dept_no wins.
        DB::table('dept_emp')->where('emp_no', 4)->delete();
        $sorted = collect([$this->alpha, $this->mid, $this->omega])->sortBy('dept_no')->values();
        DB::table('dept_emp')->insert([
            ['emp_no' => 4, 'dept_no' => $sorted[2]->dept_no, 'from_date' => '1992-02-02', 'to_date' => '1998-08-08'],
            ['emp_no' => 4, 'dept_no' => $sorted[1]->dept_no, 'from_date' => '1992-02-02', 'to_date' => '1998-08-08'],
            ['emp_no' => 4, 'dept_no' => $sorted[0]->dept_no, 'from_date' => '1992-02-02', 'to_date' => '1997-01-01'],
        ]);

        $row = EmployeeSearchQuery::make([])->rowsFor([4])->first();
        $this->assertSame($sorted[1]->dept_name, $row->last_dept_name);

        // and the department filter agrees with the displayed department
        $formerIn = fn ($dept) => EmployeeSearchQuery::make(['department' => $dept->dept_no, 'employmentStatus' => 'former'])->ids();
        $this->assertContains(4, $formerIn($sorted[1]));
        $this->assertNotContains(4, $formerIn($sorted[2]));
        $this->assertNotContains(4, $formerIn($sorted[0]));
    }

    public function test_chunk_walks_through_all_results_in_sort_order(): void
    {
        $chunks = [];
        EmployeeSearchQuery::make(['sortDirection' => 'desc'])->chunk(3, function ($rows) use (&$chunks) {
            $chunks[] = $rows->pluck('emp_no')->all();
        });

        $this->assertSame([[7, 6, 5], [4, 3, 2], [1]], $chunks);
    }

    public function test_chunk_honours_a_limit_and_skips_empty_results(): void
    {
        $seen = [];
        EmployeeSearchQuery::make([])->chunk(2, function ($rows) use (&$seen) {
            $seen = array_merge($seen, $rows->pluck('emp_no')->all());
        }, limit: 3);
        $this->assertSame([1, 2, 3], $seen);

        $called = false;
        EmployeeSearchQuery::make(['search' => 'nobody-like-this'])->chunk(2, function () use (&$called) {
            $called = true;
        });
        $this->assertFalse($called);
    }

    public function test_make_accepts_prebuilt_filters_and_exposes_them(): void
    {
        $filters = new EmployeeFilters(gender: 'F', sortBy: 'name');
        $query = EmployeeSearchQuery::make($filters);

        $this->assertSame($filters, $query->filters());
        $this->assertSame([1, 4, 5], $query->ids());
    }
}
