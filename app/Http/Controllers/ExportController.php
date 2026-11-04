<?php

namespace App\Http\Controllers;

use App\Services\EmployeeExportService;
use App\Support\EmployeeVisibility;
use Illuminate\Http\Request;

class ExportController extends Controller
{
    public function __construct(private readonly EmployeeExportService $exports)
    {
    }

    public function export(Request $request)
    {
        // Route is behind `can:export-data`; the manager's department scope and the
        // salary rules are applied to the filters (ids outside the scope are dropped).
        $visibility = EmployeeVisibility::for($request->user());
        $query = $visibility->query($request->only([
            'search', 'employmentStatus', 'gender', 'department', 'jobTitle',
            'salaryMin', 'salaryMax', 'sortBy', 'sortDirection',
        ]));

        // Selection is either "everything matching the filters except ..." or "only these".
        if ($request->boolean('selectAll')) {
            $query = $query->exceptIds((array) $request->input('excludedEmployees', []));
        } else {
            $selected = (array) $request->input('selectedEmployees', []);

            if ($selected === []) {
                return back()->with('error', __('No employees were selected for export.'));
            }

            $query = $query->onlyIds($selected);
        }

        return $request->input('format') === 'pdf'
            ? $this->exports->pdf($query, $visibility->canSeeSalaries())
            : $this->exports->csv($query, $visibility->canSeeSalaries());
    }
}
