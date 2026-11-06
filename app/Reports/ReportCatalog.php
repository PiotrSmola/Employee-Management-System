<?php

namespace App\Reports;

/**
 * Every report by key: section (page) and whether it shows salary data.
 *
 *   workforce     (view-reports):                  headcount, hires-leavers, tenure, gender, age
 *   compensation  (view-reports + view-salaries):  salary-departments, salary-titles,
 *                                                  salary-distribution, pay-gap, salary-growth
 */
class ReportCatalog
{
    public const WORKFORCE = 'workforce';

    public const COMPENSATION = 'compensation';

    public function __construct(
        private readonly WorkforceReports $workforce,
        private readonly CompensationReports $compensation,
    ) {
    }

    public function has(string $key): bool
    {
        return $this->section($key) !== null;
    }

    public function section(string $key): ?string
    {
        return match (true) {
            in_array($key, WorkforceReports::KEYS, true) => self::WORKFORCE,
            in_array($key, CompensationReports::KEYS, true) => self::COMPENSATION,
            default => null,
        };
    }

    public function showsSalaries(string $key): bool
    {
        return $this->section($key) === self::COMPENSATION;
    }

    /**
     * @return list<string>
     */
    public function keys(string $section): array
    {
        return $section === self::COMPENSATION ? CompensationReports::KEYS : WorkforceReports::KEYS;
    }

    public function table(string $key, ReportContext $context): ReportTable
    {
        return $this->showsSalaries($key)
            ? $this->compensation->table($key, $context)
            : $this->workforce->table($key, $context);
    }

    /**
     * @return list<ReportTable>
     */
    public function tables(string $section, ReportContext $context): array
    {
        return array_map(fn (string $key) => $this->table($key, $context), $this->keys($section));
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(string $section, ReportContext $context): array
    {
        return $section === self::COMPENSATION
            ? $this->compensation->summary($context)
            : $this->workforce->summary($context);
    }

    /**
     * Compute (or load) every cached figure of $context.
     */
    public function warm(ReportContext $context, bool $salaries = true): void
    {
        $this->workforce->warm($context);

        if ($salaries) {
            $this->compensation->warm($context);
        }
    }
}
