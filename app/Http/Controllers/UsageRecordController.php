<?php

namespace App\Http\Controllers;

use App\Models\Menu;
use App\Models\StoreBranch;
use App\Models\UsageRecord;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class UsageRecordController extends Controller
{
    public function index()
    {
        $query = UsageRecord::query()->with(['encoder', 'branch']);
        $records = $query->paginate(10);


        return Inertia::render('UsageRecord/Index', [
            'records' => $records
        ]);
    }

    public function create()
    {
        $menus = Menu::options();
        $branches = StoreBranch::options();
        return Inertia::render('UsageRecord/Create', [
            'menus' => $menus,
            'branches' => $branches
        ]);
    }

    public function store(Request $request)
    {
        $validated =  $request->validate([
            'store_branch_id' => ['required'],
            'usage_date' => ['required'],
            'items' => ['required', 'array']
        ], [
            'store_branch_id.required' => 'Store branch is required'
        ]);
        DB::beginTransaction();
        $record = UsageRecord::create([
            'encoder_id' => Auth::user()->id,
            'store_branch_id' => $validated['store_branch_id'],
            'usage_date' => Carbon::parse($validated['usage_date'])->addDays(1)->format('Y-m-d'),
        ]);
        foreach ($validated['items'] as $item) {
            $record->usage_record_items()->create([
                'menu_id' => $item['id'],
                'quantity' => $item['quantity']
            ]);
        }
        DB::commit();
        return redirect()->route('usage-records.index');
    }
}
