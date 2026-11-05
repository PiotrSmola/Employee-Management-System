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
            $query = $query->exceptIds($this->idList($request, 'excludedEmployees'));
        } else {
            $selected = $this->idList($request, 'selectedEmployees');

            if ($selected === []) {
                return back()->with('error', __('No employees were selected for export.'));
            }

            $query = $query->onlyIds($selected);
        }

        return $request->input('format') === 'pdf'
            ? $this->exports->pdf($query, $visibility->canSeeSalaries())
            : $this->exports->csv($query, $visibility->canSeeSalaries());
    }

    /**
     * Employee numbers sent as one comma-separated field ("1,2,3"; the export form
     * does this because PHP drops request fields beyond max_input_vars, so thousands
     * of `ids[]` inputs would silently lose ids) or, for older clients, as an array.
     * Anything that is not a positive integer is ignored.
     *
     * @return int[]
     */
    private function idList(Request $request, string $key): array
    {
        $value = $request->input($key, []);
        $values = is_string($value) ? explode(',', $value) : (array) $value;

        $ids = [];
        foreach ($values as $id) {
            $id = is_string($id) || is_int($id) ? trim((string) $id) : '';

            if (ctype_digit($id) && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
