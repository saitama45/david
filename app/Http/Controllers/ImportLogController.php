<?php

namespace App\Http\Controllers;

use App\Models\ImportLog;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ImportLogController extends Controller
{
    public function index()
    {
        $logs = ImportLog::where('user_id', auth()->id())
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('ImportLog/Index', [
            'logs' => $logs,
        ]);
    }

    public function download($id)
    {
        $log = ImportLog::where('user_id', auth()->id())->findOrFail($id);

        abort_if(
            !$log->skipped_file_path || !Storage::exists($log->skipped_file_path),
            404,
            'Skipped items file not found.'
        );

        return Storage::download(
            $log->skipped_file_path,
            'skipped_items_' . pathinfo($log->original_filename, PATHINFO_FILENAME) . '.csv'
        );
    }
}
