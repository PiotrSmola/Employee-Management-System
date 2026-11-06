<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Reports\ReportCatalog;
use App\Reports\ReportContext;
use App\Support\Ability;
use App\Support\EmployeeVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports (routes/web/reports.php, behind `can:view-reports`). The compensation
 * page and salary CSVs additionally need `view-salaries`; managers only get
 * figures of their own department (ReportContext).
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportCatalog $catalog)
    {
    }

    public function index(Request $request): RedirectResponse
    {
        return redirect()->route('reports.workforce', $request->only(['department', 'as_of']));
    }

    public function workforce(Request $request): View
    {
        return $this->page($request, ReportCatalog::WORKFORCE);
    }

    public function compensation(Request $request): View
    {
        return $this->page($request, ReportCatalog::COMPENSATION);
    }

    public function csv(Request $request, string $report): StreamedResponse
    {
        abort_unless($this->catalog->has($report), 404);

        if ($this->catalog->showsSalaries($report)) {
            Gate::authorize(Ability::VIEW_SALARIES);
        }

        $this->allowColdComputation();
        $context = ReportContext::fromInput(EmployeeVisibility::for($request->user()), $request->query());
        $table = $this->catalog->table($report, $context);

        $filename = sprintf('report_%s_%s_%s.csv', $report, $context->department ?? 'all', $context->date());

        return response()->streamDownload(function () use ($table) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            foreach ($table->csvRows() as $line) {
                fputcsv($handle, $line);
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function page(Request $request, string $section): View
    {
        $this->allowColdComputation();
        $errors = [];
        $context = ReportContext::fromInput(EmployeeVisibility::for($request->user()), $request->query(), $errors);

        return view('reports.show', [
            'section' => $section,
            'context' => $context,
            'inputErrors' => $errors,
            'summary' => $this->catalog->summary($section, $context),
            'tables' => $this->catalog->tables($section, $context),
            'departments' => Department::query()->orderBy('dept_no')->pluck('dept_name', 'dept_no'),
        ]);
    }

    /**
     * Figures that are not cached yet (other as-of dates, after a flush before the
     * next reports:warm) are computed in the request; on the full dataset that can
     * take longer than the default PHP time limit.
     */
    private function allowColdComputation(): void
    {
        set_time_limit(300);
    }
}
