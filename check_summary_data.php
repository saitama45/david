<?php
try {
    $db = new PDO('sqlite:iportfolio_backup.20260131');
    
    echo "--- Table: pos_reading_summary ---\n";
    $cols = $db->query("PRAGMA table_info(pos_reading_summary)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        echo "{$col['name']} ({$col['type']})\n";
    }

    echo "\n--- Sample Data (pos_reading_summary) ---\n";
    $samples = $db->query("SELECT * FROM pos_reading_summary LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($samples as $sample) {
        print_r($sample);
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
