<?php
try {
    $db = new PDO('sqlite:iportfolio_backup.20260131');
    
    echo "--- Table: pos_sale ---\n";
    $cols = $db->query("PRAGMA table_info(pos_sale)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        echo "{$col['name']} ({$col['type']})\n";
    }

    echo "\n--- Sample Data (pos_sale) ---\n";
    $sample = $db->query("SELECT * FROM pos_sale LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($sample) {
        print_r($sample);
    } else {
        echo "No data in pos_sale\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
