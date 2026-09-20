<?php

namespace App\Http\Controllers;

use App\Models\ImportLog;
use App\Services\SalesImportStatus;
use App\Models\StoreBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ImportLogController extends Controller
{
    public function missingBom(Request $request)
    {
        abort_unless($request->user()->can('view store transactions'), 403);
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'branchId' => ['nullable', 'string'], 'search' => ['nullable', 'string', 'max:100'],
            'export' => ['nullable', 'in:csv'],
            'view' => ['nullable', 'in:stores,distinct'],
        ]);
        $from = $filters['from'] ?? now()->subDays(6)->toDateString();
        $to = $filters['to'] ?? now()->toDateString();
        if ($from > $to || \Carbon\Carbon::parse($from)->diffInDays(\Carbon\Carbon::parse($to)) > 30) {
            throw \Illuminate\Validation\ValidationException::withMessages(['from' => 'Choose a date range of up to 31 days.']);
        }
        $branches = StoreBranch::whereIn('id', app(SalesImportStatus::class)->branchIds($request->user()))->get();
        $branchId = $filters['branchId'] ?? 'all';
        abort_if($branchId !== 'all' && !$branches->contains('id', $branchId), 403);
        $report = app(\App\Services\MissingPosBomReport::class)->build(
            $branchId === 'all' ? $branches->pluck('id')->all() : [(int) $branchId], $from, $to, $filters['search'] ?? ''
        );
        if ($request->input('export') === 'csv') {
            $distinct = ($filters['view'] ?? 'stores') === 'distinct';
            return response()->streamDownload(function () use ($report, $from, $to, $distinct) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                $write = function ($values) use ($out) {
                    fputcsv($out, array_map(fn ($v) => preg_match('/^[=+@\-\t\r\n]/', (string) $v) ? "'".$v : $v, $values));
                };
                $write(['Sales period', $from, $to, 'Generated at', $report['generated_at']]);
                $write(['Counts are separate evidence sources and must not be added. Current missing setup, not a complete inventory reconciliation.']);
                foreach ($report['warnings'] as $warning) $write(['Coverage warning', $warning]);
                $write($distinct ? ['POS Code', 'Description', 'Stores', 'Store Count', 'Issue', 'First Sale', 'Last Sale', 'POS Source Receipts', 'Imported Receipts'] : ['POS Code', 'Description', 'Store', 'Issue', 'First Sale', 'Last Sale', 'POS Source Receipts', 'Imported Receipts']);
                foreach ($report[$distinct ? 'distinct_rows' : 'rows'] as $row) $write(array_values($row));
                fclose($out);
            }, 'missing-pos-bom-'.($distinct ? 'distinct-' : 'stores-')."{$from}-{$to}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        return Inertia::render('ImportLog/MissingBom', [
            'report' => $report, 'branches' => $branches->map(fn ($b) => ['value' => (string) $b->id, 'label' => $b->branch_code.' - '.$b->name]),
            'filters' => ['from' => $from, 'to' => $to, 'branchId' => $branchId, 'search' => $filters['search'] ?? '', 'view' => $filters['view'] ?? 'stores'],
        ]);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $isAdmin = $user->hasRole('admin');
        $search = $request->string('search')->trim()->toString();
        $branchId = $request->input('branchId', 'all');
        $tab = $request->input('tab', 'all');
        if (!in_array($tab, ['all', 'automated', 'manual'], true)) $tab = 'all';

        $logs = app(SalesImportStatus::class)->visibleQuery($user)
            ->when($tab === 'automated', fn ($q) => $q->where('type', 'pos_sales'))
            ->when($tab === 'manual', fn ($q) => $q->where('type', '!=', 'pos_sales'))
            ->with(['user:id,first_name,last_name,email', 'storeBranches:id,branch_code,name'])
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
            ->through(function (ImportLog $log) {
                $startedAt = $log->processing_started_at
                    ?: ($log->status === 'processing' ? $log->updated_at : null);
                $runtimeSeconds = null;

                if ($startedAt && $log->completed_at) {
                    $runtimeSeconds = $startedAt->diffInSeconds($log->completed_at);
                } elseif ($startedAt && $log->status === 'processing') {
                    $runtimeSeconds = $startedAt->diffInSeconds(now());
                }

                return [
                    'id' => $log->id,
                    'type' => $log->type,
                    'original_filename' => $log->original_filename,
                    'status' => $log->status,
                    'display_status' => $log->display_status,
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
            'unresolvedPosReceipts' => $user->can('view store transactions')
                ? app(SalesImportStatus::class)->unresolvedReceipts($user, $branchId) : null,
            'canReviewBom' => $user->can('view store transactions'),
            'logs' => $logs,
            'branches' => StoreBranch::options()->toArray(),
            'filters' => [
                'search' => $search,
                'branchId' => $branchId,
                'tab' => $tab,
            ],
            'isAdmin' => $isAdmin,
        ]);
    }

    public function salesCompletions(Request $request)
    {
        return response()->json(app(SalesImportStatus::class)->completions($request->user()));
    }

    public function download(Request $request, $id)
    {
        $log = app(SalesImportStatus::class)->visibleQuery($request->user())->findOrFail($id);

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
