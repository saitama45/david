<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$item = DB::table('store_order_items as soi')
    ->join('store_orders as so', 'so.id', '=', 'soi.store_order_id')
    ->where('so.order_status', 'received')
    ->select('soi.item_code', 'so.store_branch_id', 'so.order_date')
    ->first();

if ($item) {
    echo "Found Item with movement: Code='{$item->item_code}', Branch={$item->store_branch_id}, Date={$item->order_date}
";
} else {
    echo "No received order items found at all.
";
}
