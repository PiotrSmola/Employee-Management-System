<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use App\Reports\CompensationReports;
use App\Reports\ReportContext;
use App\Support\EmployeeVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsReportData;
use Tests\TestCase;

/**
 * Exact figures of the compensation reports on the dataset of BuildsReportData.
 * Current salaries: E1 50000 (F, Sales, Staff), E2 70000 (M, Development, Engineer),
 * E4 80000 (F, Development, Senior Engineer), E5 65000 (M, Development, Engineer).
 */
class CompensationReportsTest extends TestCase
{
    use BuildsReportData;
    use RefreshDatabase;

    private CompensationReports $reports;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildReportData();
        $this->reports = app(CompensationReports::class);
    }

    private function context(?string $department = null, ?string $asOf = null): ReportContext
    {
        return ReportContext::make(EmployeeVisibility::unrestricted(), $department, $asOf ? CarbonImmutable::parse($asOf) : null);
    }

    public function test_salary_statistics_per_department_with_exact_medians(): void
    {
        $table = $this->reports->byDepartment($this->context());
        $rows = collect($table->rows)->keyBy('group');

        $this->assertSame(['Sales', 'Development'], $rows->keys()->all());
        $this->assertSame(1, $rows['Sales']['employees']);
        $this->assertEquals([50000, 50000, 50000, 50000], [$rows['Sales']['average'], $rows['Sales']['median'], $rows['Sales']['min'], $rows['Sales']['max']]);

        $this->assertSame(3, $rows['Development']['employees']);
        $this->assertEqualsWithDelta(215000 / 3, $rows['Development']['average'], 1e-6);
        $this->assertEquals(70000, $rows['Development']['median']);
        $this->assertSame([65000, 80000], [$rows['Development']['min'], $rows['Development']['max']]);

        // Overall: 50000, 65000, 70000, 80000 - even count, median = mean of the two middle values
        $all = $table->totals[0];
        $this->assertSame(4, $all['employees']);
        $this->assertEquals(66250, $all['average']);
        $this->assertEquals(67500, $all['median']);
    }

    public function test_salary_statistics_per_title(): void
    {
        $rows = collect($this->reports->byTitle($this->context())->rows)->keyBy('group');

        $this->assertSame(['Engineer', 'Senior Engineer', 'Staff'], $rows->keys()->all());
        $this->assertSame(2, $rows['Engineer']['employees']);
        $this->assertEquals([67500, 67500, 65000, 70000], [$rows['Engineer']['average'], $rows['Engineer']['median'], $rows['Engineer']['min'], $rows['Engineer']['max']]);
        $this->assertEquals(80000, $rows['Senior Engineer']['median']);
    }

    public function test_salary_distribution_in_bands_of_ten_thousand(): void
    {
        $rows = $this->reports->distribution($this->context())->rows;

        $this->assertSame([50000, 60000, 70000, 80000], array_column($rows, 'from'));
        $this->assertSame([[1, 0], [0, 1], [0, 1], [1, 0]], array_map(fn ($r) => [$r['female'], $r['male']], $rows));
        $this->assertSame('50,000 – 59,999', $rows[0]['band']);
    }

    public function test_gender_pay_gap_overall_per_department_and_title(): void
    {
        $rows = collect($this->reports->payGap($this->context())->rows)->keyBy(fn ($r) => $r['breakdown'].'/'.$r['group']);

        // Women 65000 on average, men 67500: (67500 - 65000) / 67500
        $overall = $rows['Overall/All employees'];
        $this->assertEquals([65000, 67500], [$overall['female_avg'], $overall['male_avg']]);
        $this->assertSame([2, 2], [$overall['female_count'], $overall['male_count']]);
        $this->assertEqualsWithDelta(2500 / 67500 * 100, $overall['gap'], 1e-9);

        // Development: women 80000, men 67500 - negative gap
        $this->assertEqualsWithDelta(-12500 / 67500 * 100, $rows['Department/Development']['gap'], 1e-9);
        // No men in Sales, no women among the engineers: no gap
        $this->assertNull($rows['Department/Sales']['gap']);
        $this->assertNull($rows['Title/Engineer']['gap']);

        $this->assertEqualsWithDelta(2500 / 67500 * 100, $this->reports->summary($this->context())['payGap'], 1e-9);
    }

    public function test_average_salary_on_31_december_of_each_year(): void
    {
        $rows = collect($this->reports->growth($this->context())->rows)->keyBy('year');

        $this->assertSame(['1990', '1991', '1992', '1993', '1994'], array_map('strval', $rows->keys()->all()));
        $this->assertEquals(50000, $rows[1990]['average']);           // 40000, 60000
        $this->assertEquals(55000, $rows[1991]['average']);           // 50000, 60000, 55000
        $this->assertEqualsWithDelta(175000 / 3, $rows[1992]['average'], 1e-6); // 50000, 70000, 55000
        $this->assertEqualsWithDelta(200000 / 3, $rows[1993]['average'], 1e-6); // E3 left, E4 80000
        $this->assertEquals(66250, $rows[1994]['average']);
        $this->assertSame([2, 3, 3, 3, 4], $rows->pluck('employees')->all());
        $this->assertNull($rows[1990]['change']);
        $this->assertEqualsWithDelta(10.0, $rows[1991]['change'], 1e-9);
    }

    public function test_average_salary_of_a_department_only_counts_the_time_spent_there(): void
    {
        $rows = collect($this->reports->growth($this->context('d002'))->rows)->keyBy('year');

        $this->assertSame(['1991', '1992', '1993', '1994'], array_map('strval', $rows->keys()->all()));
        $this->assertEquals(55000, $rows[1991]['average']);   // E3
        $this->assertEquals(62500, $rows[1992]['average']);   // E3, E2 (joined 1992-03, 70000 since 1992-06)
        $this->assertEquals(75000, $rows[1993]['average']);   // E2, E4
        $this->assertEqualsWithDelta(215000 / 3, $rows[1994]['average'], 1e-6);
    }

    public function test_as_of_date_uses_the_salaries_departments_and_titles_of_that_day(): void
    {
        $context = $this->context(asOf: '1992-12-31');

        $summary = $this->reports->summary($context);
        // E1 50000 (Sales), E2 70000 and E3 55000 (Development)
        $this->assertSame(3, $summary['employees']);
        $this->assertEqualsWithDelta(175000 / 3, $summary['average'], 1e-6);
        $this->assertEquals(55000, $summary['median']);

        $rows = collect($this->reports->byDepartment($context)->rows)->keyBy('group');
        $this->assertEquals(62500, $rows['Development']['median']);
        $this->assertSame(2, $rows['Development']['employees']);

        $growth = $this->reports->growth($context)->rows;
        $this->assertSame('1992', end($growth)['year']);
    }

    public function test_department_filter_and_manager_scope(): void
    {
        $rows = $this->reports->byDepartment($this->context('d002'))->rows;
        $this->assertSame(['Development'], array_column($rows, 'group'));

        $manager = User::factory()->manager($this->sales)->create();
        $context = ReportContext::make(EmployeeVisibility::for($manager));
        $this->assertSame(['Sales'], array_column($this->reports->byDepartment($context)->rows, 'group'));
        $this->assertSame(1, $this->reports->summary($context)['employees']);
        // E1's salary history in Sales: 40000 in 1990, 50000 from 1991
        $this->assertEquals([40000, 50000, 50000, 50000, 50000], array_column($this->reports->growth($context)->rows, 'average'));
    }
}
