<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Reports\ReportCache;
use App\Reports\ReportCatalog;
use App\Reports\ReportContext;
use App\Reports\ReportPage;
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
    public function __construct(
        private readonly ReportCatalog $catalog,
        private readonly ReportCache $cache,
    ) {}

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
        $errors = [];
        $context = ReportContext::fromInput(EmployeeVisibility::for($request->user()), $request->query(), $errors);

        $summary = $this->catalog->summary($section, $context);
        $tables = $this->catalog->tables($section, $context);

        $departments = Department::query()->orderBy('dept_no')->pluck('dept_name', 'dept_no')->all();

        return view('reports.show', [
            'page' => new ReportPage($section, $context, $summary, $departments),
            'context' => $context,
            'inputErrors' => $errors,
            'summary' => $summary,
            'tables' => $tables,
            'figuresStatus' => $this->cache->status(),
            'departments' => $departments,
        ]);
    }
}
