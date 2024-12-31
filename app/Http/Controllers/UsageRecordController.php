<?php

namespace App\Http\Controllers;

use App\Models\Menu;
use App\Models\StoreBranch;
use App\Models\UsageRecord;
use App\Models\UsageRecordItem;
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
            'usage_date' => ['required', 'before_or_equal:today'],
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

    public function show($id)
    {
        $record = UsageRecord::with([
            'branch',
            'encoder',
            'usage_record_items',
            'usage_record_items.menu',
            'usage_record_items.menu.menu_ingredients',
            'usage_record_items.menu.menu_ingredients.product',
        ])->findOrFail($id);
        $itemsSold = $record->usage_record_items()->with('menu')->paginate(5);

        $ingredients = UsageRecordItem::where('usage_record_id', $id)
            ->join('menus', 'usage_record_items.menu_id', '=', 'menus.id')
            ->join('menu_ingredients', 'menus.id', '=', 'menu_ingredients.menu_id')
            ->join('product_inventories', 'menu_ingredients.product_inventory_id', '=', 'product_inventories.id')
            ->join('unit_of_measurements', 'product_inventories.unit_of_measurement_id', '=', 'unit_of_measurements.id')
            ->select([
                'product_inventories.*',
                'menu_ingredients.quantity as ingredient_quantity',
                'usage_record_items.quantity as ordered_quantity',
                DB::raw('SUM(menu_ingredients.quantity * usage_record_items.quantity) as total_quantity'),
                'unit_of_measurements.name as uom'
            ])
            ->groupBy('product_inventories.id')
            ->get();

        return Inertia::render('UsageRecord/Show', [
            'record' => $record,
            'itemsSold' => $itemsSold,
            'ingredients' => $ingredients
        ]);
    }
}
