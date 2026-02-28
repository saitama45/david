<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\SAPMasterfile;
use Illuminate\Support\Facades\DB;

$item = SAPMasterfile::where('is_active', true)->first();
echo "SAP ItemCode: '{$item->ItemCode}'
";

$orderItem = DB::table('store_order_items')->where('item_code', $item->ItemCode)->first();
if ($orderItem) {
    echo "Order ItemCode: '{$orderItem->item_code}'
";
} else {
    echo "No Order Item found for code '{$item->ItemCode}'
";
    // Try finding any order item
    $anyOrderItem = DB::table('store_order_items')->first();
    if ($anyOrderItem) {
        echo "Example Order ItemCode: '{$anyOrderItem->item_code}'
";
    }
}
