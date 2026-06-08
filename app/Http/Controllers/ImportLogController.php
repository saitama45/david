<?php

namespace App\Http\Controllers;

use App\Models\ImportLog;
use App\Models\StoreBranch;
use App\Services\ImportQueueService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ImportLogController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $isAdmin = $user->hasRole('admin');
        $search = $request->string('search')->trim()->toString();
        $branchId = $request->input('branchId', 'all');
        $importQueue = app(ImportQueueService::class);

        $logs = ImportLog::query()
            ->with(['user:id,first_name,last_name,email', 'storeBranches:id,branch_code,name'])
            ->when(!$isAdmin, function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('original_filename', 'like', "%{$search}%")
                        ->orWhere('type', 'like', "%{$search}%")
                        ->orWhere('status', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($query) use ($search) {
                            $query->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        })
                        ->orWhereHas('storeBranches', function ($query) use ($search) {
                            $query->where('name', 'like', "%{$search}%")
                                ->orWhere('branch_code', 'like', "%{$search}%");
                        });
                });
            })
            ->when($branchId !== 'all' && is_numeric($branchId), function ($query) use ($branchId) {
                $query->whereHas('storeBranches', function ($query) use ($branchId) {
                    $query->where('store_branches.id', (int) $branchId);
                });
            })
            ->latest()
            ->paginate(15)
            ->withQueryString()
            ->through(function (ImportLog $log) use ($importQueue) {
                $startedAt = $log->processing_started_at
                    ?: ($log->status === 'processing' ? $log->updated_at : null);
                $runtimeSeconds = null;

                if ($startedAt && $log->completed_at) {
                    $runtimeSeconds = $startedAt->diffInSeconds($log->completed_at);
                } elseif ($startedAt && $log->status === 'processing') {
                    $runtimeSeconds = $startedAt->diffInSeconds(now());
                }

                $queueState = $importQueue->queueStateForLog($log);

                return [
                    'id' => $log->id,
                    'type' => $log->type,
                    'original_filename' => $log->original_filename,
                    'status' => $log->status,
                    'processed_count' => $log->processed_count,
                    'skipped_count' => $log->skipped_count,
                    'skipped_file_path' => $log->skipped_file_path,
                    'error_message' => $log->error_message,
                    'created_at' => $log->created_at,
                    'processing_started_at' => $log->processing_started_at,
                    'last_heartbeat_at' => $log->last_heartbeat_at,
                    'failed_at' => $log->failed_at,
                    'completed_at' => $log->completed_at,
                    'runtime_seconds' => $runtimeSeconds,
                    'queue_state' => $queueState['state'],
                    'queue_state_label' => $queueState['label'],
                    'user' => $log->user ? [
                        'id' => $log->user->id,
                        'name' => $log->user->full_name,
                        'email' => $log->user->email,
                    ] : null,
                    'store_branches' => $log->storeBranches->map(fn (StoreBranch $branch) => [
                        'id' => $branch->id,
                        'branch_code' => $branch->branch_code,
                        'name' => $branch->name,
                    ])->values(),
                ];
            });

        return Inertia::render('ImportLog/Index', [
            'logs' => $logs,
            'branches' => StoreBranch::options()->toArray(),
            'filters' => [
                'search' => $search,
                'branchId' => $branchId,
            ],
            'isAdmin' => $isAdmin,
        ]);
    }

    public function download(Request $request, $id)
    {
        $query = ImportLog::query();

        if (!$request->user()->hasRole('admin')) {
            $query->where('user_id', $request->user()->id);
        }

        $log = $query->findOrFail($id);

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
