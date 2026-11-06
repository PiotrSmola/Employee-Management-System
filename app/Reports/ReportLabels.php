<?php

namespace App\Reports;

use App\Models\Department;

/**
 * Display names used by the reports (one small query per request).
 */
class ReportLabels
{
    /** @var array<string, string>|null */
    private ?array $departments = null;

    /**
     * @return array<string, string> dept_no => dept_name, ordered by dept_no
     */
    public function departments(): array
    {
        return $this->departments ??= Department::query()->orderBy('dept_no')->pluck('dept_name', 'dept_no')->all();
    }
}
