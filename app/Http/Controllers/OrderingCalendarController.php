<?php

namespace App\Http\Controllers;

use App\Models\StoreBranch;
use App\Models\Template;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;

class OrderingCalendarController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        
        // Get suppliers (ordering templates) for the user
        $suppliers = $user->suppliers()
            ->where('is_active', true)
            ->get()
            ->map(function ($supplier) {
                if ($supplier->supplier_code === 'CPO') {
                    return [
                        'label' => 'CPO',
                        'value' => 'CPO',
                    ];
                }
                return [
                    'label' => $supplier->name === 'DROPSHIPPING' ? 'FRUITS AND VEGETABLES' : $supplier->name . ' (' . $supplier->supplier_code . ')',
                    'value' => $supplier->supplier_code,
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
            'templates' => $suppliers,
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
        $itemCode = $request->query('item_code');
        $storeId = $request->query('store_id');
        $supplierCode = $request->query('supplier_code');
        $month = $request->query('month', now()->month);
        $year = $request->query('year', now()->year);

        $data = $this->fetchCalendarData($itemCode, $storeId, $supplierCode, $month, $year);
        
        return response()->json([
            'data' => $data
        ]);
    }

    /**
     * Export calendar to PDF
     */
    public function exportPdf(Request $request)
    {
        $itemCode = $request->query('item_code');
        $storeId = $request->query('store_id');
        $supplierCode = $request->query('supplier_code');
        $month = $request->query('month', now()->month);
        $year = $request->query('year', now()->year);

        $store = StoreBranch::findOrFail($storeId);
        // Find item details
        $item = \App\Models\SupplierItems::where('ItemCode', $itemCode)->first();
        
        $calendarData = $this->fetchCalendarData($itemCode, $storeId, $supplierCode, $month, $year);
        
        $monthName = date('F', mktime(0, 0, 0, $month, 10));
        
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.ordering-calendar', [
            'calendarData' => $calendarData,
            'store' => $store,
            'item' => $item,
            'monthName' => $monthName,
            'year' => $year,
            'month' => $month,
            'today' => now()->format('F d, Y')
        ]);

        $pdf->setPaper('a4', 'landscape');

        return $pdf->stream("Ordering_Calendar_{$store->branch_code}_{$itemCode}.pdf");
    }

    /**
     * Internal helper to fetch calendar data
     */
    private function fetchCalendarData($itemCode, $storeId, $supplierCode, $month, $year)
    {
        $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $endDate = Carbon::createFromDate($year, $month, 1)->endOfMonth();

        // Get delivery schedules for this store and supplier
        // delivery_schedule_id: 1=Mon, 2=Tue, ..., 7=Sun
        $schedules = \App\Models\DTSDeliverySchedule::where('store_branch_id', $storeId)
            ->where('variant', $supplierCode)
            ->pluck('delivery_schedule_id')
            ->toArray();

        // Fetch orders for this store and item within the date range
        $orderItems = \App\Models\StoreOrderItem::where('item_code', $itemCode)
            ->whereHas('store_order', function($query) use ($storeId, $startDate, $endDate) {
                $query->where('store_branch_id', $storeId)
                      ->whereBetween('order_date', [$startDate->toDateString(), $endDate->toDateString()]);
            })
            ->with(['store_order', 'ordered_item_receive_dates'])
            ->get();

        $orderMap = [];
        foreach ($orderItems as $orderItem) {
            $date = Carbon::parse($orderItem->store_order->order_date)->toDateString();
            $orderStatus = $orderItem->store_order->order_status;
            
            // Default values
            $status = 'ordered';
            $qty = $orderItem->quantity_ordered;

            // Check for receiving history first
            $receiveHistory = $orderItem->ordered_item_receive_dates;
            $hasReceivedHistory = $receiveHistory->contains(function($h) {
                return in_array(strtolower($h->status), ['received', 'approved']);
            });

            if ($hasReceivedHistory || in_array($orderStatus, ['received', 'incomplete'])) {
                $status = 'received';
                // Sum quantity from history if it exists, otherwise use quantity_received from item
                $qty = $hasReceivedHistory ? $receiveHistory->whereIn('status', ['received', 'approved'])->sum('quantity_received') : $orderItem->quantity_received;
            } elseif ($orderItem->committed_by !== null || in_array($orderStatus, ['committed', 'partial_committed'])) {
                $status = 'committed';
                $qty = $orderItem->quantity_commited;
            }

            $orderMap[$date] = [
                'status' => $status,
                'qty' => $qty
            ];
        }

        $data = [];
        $daysInMonth = $startDate->daysInMonth;
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $currentDate = Carbon::createFromDate($year, $month, $day);
            $dateStr = $currentDate->toDateString();
            $dayOfWeek = $currentDate->dayOfWeekIso; // 1 (Mon) to 7 (Sun)

            $hasSchedule = in_array($dayOfWeek, $schedules);
            $orderData = $orderMap[$dateStr] ?? null;

            $status = null;
            $qty = null;

            if ($orderData) {
                $status = $orderData['status'];
                $qty = $orderData['qty'];
            } elseif (!$hasSchedule) {
                $status = 'no-delivery';
            }

            $data[] = [
                'day' => $day,
                'date' => $dateStr,
                'status' => $status,
                'qty' => $qty,
                'has_schedule' => $hasSchedule
            ];
        }

        return $data;
    }
}
