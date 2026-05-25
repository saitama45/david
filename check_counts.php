<?php
try {
    $db = new PDO('sqlite:iportfolio_backup.20260131');
    
    $tables = ['pos_sale_product', 'pos_reading', 'pos_sale_payment'];
    foreach ($tables as $table) {
        $count = $db->query("SELECT count(*) FROM $table")->fetchColumn();
        echo "Table: $table - Count: $count\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
