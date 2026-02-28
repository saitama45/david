<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$branchId = 8; // User's branch in the error message
$dateFrom = '2026-01-01';
$dateTo = '2026-03-31';

echo "--- CHECKING ORDERS ---
";
$orders = DB::table('store_orders')
    ->where('store_branch_id', $branchId)
    ->whereBetween('order_date', [$dateFrom, $dateTo])
    ->limit(5)
    ->get();

foreach ($orders as $order) {
    echo "ID: {$order->id}, Status: '{$order->order_status}', Date: {$order->order_date}
";
}

echo "
--- CHECKING STATUS CASE SENSITIVITY ---
";
$receivedCountLower = DB::table('store_orders')->where('order_status', 'received')->count();
$receivedCountUpper = DB::table('store_orders')->where('order_status', 'Received')->count();
echo "Count 'received': {$receivedCountLower}
";
echo "Count 'Received': {$receivedCountUpper}
";

echo "
--- CHECKING SALES ---
";
$sales = DB::table('store_transactions')
    ->where('store_branch_id', $branchId)
    ->whereBetween('order_date', [$dateFrom, $dateTo])
    ->limit(5)
    ->get();

foreach ($sales as $sale) {
    echo "ID: {$sale->id}, Date: {$sale->order_date}
";
}
