<?php

use App\Models\StoreOrder;
use App\Models\User;
use App\Models\SupplierItems;

$order = StoreOrder::find(706);
$user = User::find(1);

if (!$order) {
    echo "Order 706 not found.\n";
    exit;
}

echo "User ID: " . $user->id . "\n";
echo "User Permissions:\n";
echo " - edit finished good commits: " . ($user->can('edit finished good commits') ? 'YES' : 'NO') . "\n";
echo " - edit other commits: " . ($user->can('edit other commits') ? 'YES' : 'NO') . "\n";

echo "Order Items:\n";
foreach ($order->store_order_items as $item) {
    $si = SupplierItems::where('ItemCode', $item->item_code)->first();
    $cat = $si ? $si->category : 'N/A';
    $isFG = in_array(strtoupper(trim($cat)), ['FINISHED GOODS', 'FG', 'FINISHED GOOD']);
    
    echo " - Item: {$item->item_code} | Cat: {$cat} | IsFG: " . ($isFG ? 'YES' : 'NO') . " | CommittedBy: {$item->committed_by}\n";
}

