<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$item = DB::table('store_order_items')->select('item_code')->first();
if ($item) {
    echo "Raw DB ItemCode: [" . $item->item_code . "]
";
    echo "Length: " . strlen($item->item_code) . "
";
}
