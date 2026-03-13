<?php

namespace App\Http\Controllers;

use App\Models\StoreBranch;
use App\Models\Template;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;

class OrderingCalendarController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:view ordering calendar')->only('index');
    }

    public function index()
    {
        $user = Auth::user();
        
        // Get templates (using Template model which seems to store template names)
        $templates = Template::all()->map(function($t) {
            return [
                'label' => $t->template,
                'value' => $t->template,
            ];
        });

        // Get assigned stores for the user
        $user->load('store_branches');
        $stores = $user->store_branches->map(function($branch) {
            return [
                'label' => $branch->name . ' (' . $branch->branch_code . ')',
                'value' => $branch->id,
                'branch_code' => $branch->branch_code,
            ];
        });

        // If admin, they might see all stores? The requirement says "based on the assigned stores of the User"
        if ($user->hasRole('admin') && $stores->isEmpty()) {
            $stores = StoreBranch::where('is_active', true)->get()->map(function($branch) {
                return [
                    'label' => $branch->name . ' (' . $branch->branch_code . ')',
                    'value' => $branch->id,
                    'branch_code' => $branch->branch_code,
                ];
            });
        }

        return Inertia::render('OrderingCalendar/Index', [
            'templates' => $templates,
            'stores' => $stores,
        ]);
    }

    /**
     * Get items for a specific template
     */
    public function getItems(Request $request)
    {
        $templateName = $request->query('template');
        
        // This is a placeholder. You'll need to adjust this to match 
        // how items are actually linked to templates in your system.
        // Assuming there's a relationship or a way to filter items by template.
        
        // For now, returning dummy data or a basic query if you have an Item model
        // that belongs to a template.
        
        /*
        $items = Item::whereHas('templates', function($q) use ($templateName) {
            $q->where('template', $templateName);
        })->get();
        */
        
        // Based on other controllers, let's see how they get items.
        // StoreOrderController uses getSupplierItems($supplierCode)
        
        return response()->json([
            'items' => [] // Fill this with actual items
        ]);
    }

    /**
     * Get calendar data for a specific item and store
     */
    public function getCalendarData(Request $request)
    {
        $itemId = $request->query('item_id');
        $storeId = $request->query('store_id');
        $month = $request->query('month', now()->month);
        $year = $request->query('year', now()->year);

        // Fetch ordering, committing, and delivery data for the calendar
        // This will involve querying StoreOrders and StoreOrderItems
        
        return response()->json([
            'data' => [] // Fill with calendar events
        ]);
    }
}
