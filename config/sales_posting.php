<?php

return [
    // Explicit exception for products which legitimately consume no ingredients.
    // Use entity ID => ['POS-CODE']; absence of a BOM otherwise blocks posting.
    'non_inventory_products' => json_decode(env('SALES_NON_INVENTORY_PRODUCTS', '{}'), true) ?: [],
    // Emergency stop checked by the shared processor and each POS receipt.
    'paused' => env('SALES_POSTING_PAUSED', false),
];
