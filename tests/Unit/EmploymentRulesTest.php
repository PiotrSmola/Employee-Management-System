<?php

namespace Tests\Unit;

use App\Support\EmploymentRules;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EmploymentRulesTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_age_in_full_years(): void
    {
        $this->assertSame(29, EmploymentRules::ageOn('1990-05-20', '2020-05-19'));
        $this->assertSame(30, EmploymentRules::ageOn('1990-05-20', '2020-05-20'));
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function ageProvider(): array
    {
        return [
            '16 on the day' => ['2000-03-01', '2016-03-01', true],
            'one day short of 16' => ['2000-03-02', '2016-03-01', false],
            '80' => ['1940-01-01', '2020-12-31', true],
            '81' => ['1939-01-01', '2020-12-31', false],
            'born after hire' => ['2020-01-02', '2020-01-01', false],
            'born on the hire date' => ['2020-01-01', '2020-01-01', false],
        ];
    }

    #[DataProvider('ageProvider')]
    public function test_plausible_age_on_the_hire_date(string $birth, string $hire, bool $expected): void
    {
        $this->assertSame($expected, EmploymentRules::isPlausibleAgeAtHire($birth, $hire));
    }

    public function test_percent_changes(): void
    {
        $this->assertSame(63000, EmploymentRules::applyPercent(60000, 5));
        $this->assertSame(54000, EmploymentRules::applyPercent(60000, -10));
        $this->assertSame(62101, EmploymentRules::applyPercent(60001, 3.5));
        $this->assertSame(7.5, EmploymentRules::percentChange(60000, 64500));
        $this->assertSame(-33.33, EmploymentRules::percentChange(60000, 40000));
        $this->assertNull(EmploymentRules::percentChange(0, 100));
    }

    public function test_date_helpers(): void
    {
        $this->assertSame('2024-03-01', EmploymentRules::dayAfter('2024-02-29'));
        $this->assertSame('2024-02-01', EmploymentRules::latest('2024-01-01', null, '2024-02-01', ''));
        $this->assertNull(EmploymentRules::latest(null, null));
        $this->assertSame('05.11.2026', EmploymentRules::display('2026-11-05'));
        $this->assertSame('-', EmploymentRules::display(null));

        CarbonImmutable::setTestNow('2030-06-15 13:45:00');
        $this->assertSame('2030-06-15', EmploymentRules::today());
    }
}
