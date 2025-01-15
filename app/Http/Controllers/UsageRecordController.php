<?php

namespace App\Http\Controllers;

use App\Imports\StoreTransactionsImport;
use App\Models\Menu;
use App\Models\StoreBranch;
use App\Models\UsageRecord;
use App\Models\UsageRecordItem;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

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
        $validated = $request->validate([
            'store_branch_id' => ['required', 'exists:store_branches,id'],
            'transaction_period' => ['required', 'string'],
            'transaction_date' => ['required', 'date', 'before_or_equal:today'],
            'order_number' => ['required', 'string'],
            'cashier_id' => ['required'],
            'order_type' => ['required', 'string'],
            'sub_total' => ['required', 'numeric', 'min:0'],
            'total_amount' => ['required', 'numeric', 'min:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_type' => ['required', 'string'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_type' => ['nullable', 'string'],
            'service_charge' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string'],
            'items' => ['required', 'array'],
            'items.*.id' => ['required', 'exists:menus,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.1'],
        ], [
            'store_branch_id.required' => 'Store branch is required.',
            'transaction_date.required' => 'Transaction date is required.',
            'transaction_date.before_or_equal' => 'Transaction date cannot be in the future.',
            'items.required' => 'Items are required.',
            'items.*.id.exists' => 'The selected item is invalid.',
            'items.*.quantity.required' => 'Quantity is required.',
            'items.*.quantity.min' => 'Quantity must be at least 0.1.',
        ]);

        DB::beginTransaction();
        $record = UsageRecord::create([
            'encoder_id' => Auth::user()->id,
            'store_branch_id' => $validated['store_branch_id'],
            'order_number' => $validated['order_number'],
            'transaction_period' => $validated['transaction_period'],
            'transaction_date' => Carbon::parse($validated['transaction_date'])->format('Y-m-d'),
            'cashier_id' => $validated['cashier_id'],
            'order_type' => $validated['order_type'],
            'sub_total' => $validated['sub_total'],
            'total_amount' => $validated['total_amount'],
            'tax_amount' => $validated['tax_amount'] ?? 0,
            'payment_type' => $validated['payment_type'],
            'discount_amount' => $validated['discount_amount'] ?? 0,
            'discount_type' => $validated['discount_type'] ?? null,
            'service_charge' => $validated['service_charge'] ?? 0,
            'remarks' => $validated['remarks'] ?? null,
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

    public  function import(Request $request)
    {
        $request->validate([
            'store_transactions_file' => [
                'required',
                'file',
                'mimes:xlsx,xls,csv',
            ]
        ]);

        Excel::import(new StoreTransactionsImport, $request->file('menu_file'));

        return to_route('store-transactions.index');
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

        $totalQuantitySQL = DB::connection()->getDriverName() === 'sqlsrv'
            ? 'CAST(SUM(CAST(menu_ingredients.quantity AS DECIMAL(10,2)) * CAST(usage_record_items.quantity AS DECIMAL(10,2))) AS DECIMAL(10,2)) as total_quantity'
            : 'SUM(menu_ingredients.quantity * usage_record_items.quantity) as total_quantity';

        $ingredients = UsageRecordItem::where('usage_record_id', $id)
            ->join('menus', 'usage_record_items.menu_id', '=', 'menus.id')
            ->join('menu_ingredients', 'menus.id', '=', 'menu_ingredients.menu_id')
            ->join('product_inventories', 'menu_ingredients.product_inventory_id', '=', 'product_inventories.id')
            ->join('unit_of_measurements', 'product_inventories.unit_of_measurement_id', '=', 'unit_of_measurements.id')
            ->select([
                'product_inventories.*',
                'menu_ingredients.quantity as ingredient_quantity',
                'usage_record_items.quantity as ordered_quantity',
                DB::raw($totalQuantitySQL),
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
