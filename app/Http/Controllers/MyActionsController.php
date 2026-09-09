<?php

namespace App\Http\Controllers;

use App\Http\Services\MyActionsService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MyActionsController extends Controller
{
    public function __invoke(Request $request, MyActionsService $service)
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        $filters['date_from'] = $filters['date_from'] ?? now('Asia/Manila')->startOfMonth()->toDateString();
        $filters['date_to'] = $filters['date_to'] ?? now('Asia/Manila')->addDays(14)->toDateString();
        abort_if($filters['date_to'] < $filters['date_from'], 422, 'The end date must be on or after the start date.');
        abort_if(\Carbon\Carbon::parse($filters['date_from'])->diffInDays($filters['date_to']) > 93, 422, 'Select a missing-submission period of 93 days or less.');

        $data = $service->get($request->user(), $filters);
        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['summary' => $data['summary'], 'tasks' => $data['tasks']->where('ownership', 'mine')->take(3)->values()]);
        }

        return Inertia::render('MyActions/Index', $data + ['filters' => $filters]);
    }
}
