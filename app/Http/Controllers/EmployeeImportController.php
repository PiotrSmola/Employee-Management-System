<?php

namespace App\Http\Controllers;

use App\Exceptions\ImportException;
use App\Services\EmployeeImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Illuminate\View\View;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Import of new employees: upload -> validation preview -> confirm
 * (routes behind `can:import-data`).
 */
class EmployeeImportController extends Controller
{
    public function __construct(private readonly EmployeeImportService $imports)
    {
    }

    public function create(): View
    {
        return view('data-tools.import.create', [
            'columns' => EmployeeImportService::COLUMNS,
            'departments' => DB::table('departments')->orderBy('dept_no')->get(['dept_no', 'dept_name']),
            'titles' => DB::table('titles')->distinct()->orderBy('title')->pluck('title'),
            'maxRows' => (int) config('data_tools.imports.max_rows', 5000),
            'maxFileSize' => Number::fileSize((int) config('data_tools.imports.max_file_kb', 5120) * 1024),
        ]);
    }

    public function template(string $format): StreamedResponse|BinaryFileResponse
    {
        [$header, $example] = $this->imports->templateRows();

        if ($format === 'xlsx') {
            $path = tempnam(sys_get_temp_dir(), 'tpl');

            try {
                $writer = new XlsxWriter;
                $writer->openToFile($path);
                $writer->addRow(Row::fromValuesWithStyle($header, (new Style)->withFontBold(true)));
                $writer->addRow(Row::fromValues($example));
                $writer->close();
            } catch (Throwable $e) {
                @unlink($path);

                throw $e;
            }

            return response()->download($path, 'employees_import_template.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend();
        }

        return response()->streamDownload(function () use ($header, $example) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $header);
            fputcsv($handle, $example);
            fclose($handle);
        }, 'employees_import_template.csv', ['Content-Type' => 'text/csv']);
    }

    public function upload(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => [
                'required', 'file',
                'mimes:csv,txt,xlsx',
                'extensions:csv,txt,xlsx',
                'max:'.(int) config('data_tools.imports.max_file_kb', 5120),
            ],
        ], [
            'file.mimes' => __('Upload a CSV or XLSX file.'),
            'file.extensions' => __('Upload a CSV or XLSX file.'),
        ]);

        set_time_limit(120);

        $file = $request->file('file');
        $analysis = $this->imports->analyze($file);
        $token = $this->imports->stage($request->user(), $file->getClientOriginalName(), $analysis);

        return redirect()->route('employees.import.preview', $token);
    }

    public function preview(Request $request, string $token): View
    {
        $staged = $this->staged($request, $token);
        $analysis = $staged['analysis'];

        return view('data-tools.import.preview', [
            'token' => $token,
            'fileName' => $staged['file_name'],
            'analysis' => $analysis,
            'invalidRows' => array_slice(array_values(array_filter($analysis['rows'], fn ($row) => $row['errors'] !== [])), 0, 500),
            'sampleRows' => array_slice($analysis['rows'], 0, 50),
            'columns' => EmployeeImportService::COLUMNS,
            'canCommit' => $analysis['file_errors'] === [] && $analysis['invalid'] === 0 && $analysis['rows'] !== [],
        ]);
    }

    public function commit(Request $request, string $token): RedirectResponse
    {
        $this->staged($request, $token);

        try {
            $result = $this->imports->commit($request->user(), $token);
        } catch (ImportException $e) {
            return redirect()->route('employees.import.preview', $token)->with('error', $e->getMessage());
        }

        return redirect()->route('employees.import.create')->with('status', __(
            ':count employees have been imported (employee numbers :first - :last).',
            ['count' => number_format($result['count']), 'first' => $result['first_emp_no'], 'last' => $result['last_emp_no']],
        ));
    }

    public function cancel(Request $request, string $token): RedirectResponse
    {
        $this->staged($request, $token);
        $this->imports->forget($token);

        return redirect()->route('employees.import.create')->with('status', __('The import has been cancelled.'));
    }

    /**
     * @return array{user_id: int, file_name: string, analysis: array}
     */
    private function staged(Request $request, string $token): array
    {
        $staged = $this->imports->staged($request->user(), $token);

        if ($staged === null) {
            abort(404, __('This import has expired. Please upload the file again.'));
        }

        return $staged;
    }
}
