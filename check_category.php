<?php
$order = App\Models\StoreOrder::with('store_order_items')->find(706);
foreach($order->store_order_items as $item) {
    $code = $item->item_code;
    $si = App\Models\SupplierItems::where('ItemCode', $code)->first();
    $cat = $si ? $si->category : 'No SupplierItem';
    
    echo "Item: $code | DB Cat: $cat \n";
}

