<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Export;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "My exports": the user's own queued exports (routes behind `can:export-data`).
 * Another user's export is answered with 404, as if it did not exist.
 */
class MyExportsController extends Controller
{
    public function index(Request $request): View
    {
        $exports = Export::ownedBy($request->user())->latest('id')->paginate(20);

        return view('data-tools.exports.index', [
            'exports' => $exports,
            'hasUnfinished' => $exports->getCollection()->contains(fn (Export $export) => ! $export->isFinished()),
            'retentionDays' => (int) config('data_tools.exports.retention_days', 7),
        ]);
    }

    public function download(Request $request, int $export): StreamedResponse
    {
        $export = $this->own($request, $export);

        abort_unless($export->isDownloadable() && Export::disk()->exists($export->file_path), 404);

        AuditLogger::log('data.export_downloaded', ActivityLog::SUBJECT_EXPORT, $export->id, [
            'format' => $export->format->value,
            'rows' => $export->row_count,
        ], $request->user());

        return Export::disk()->download($export->file_path, $export->file_name, [
            'Content-Type' => $export->format->mimeType(),
        ]);
    }

    public function destroy(Request $request, int $export): RedirectResponse
    {
        $this->own($request, $export)->deleteWithFile();

        return redirect()->route('exports.index')->with('status', __('The export has been deleted.'));
    }

    private function own(Request $request, int $id): Export
    {
        return Export::ownedBy($request->user())->findOrFail($id);
    }
}
