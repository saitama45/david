<?php
try {
    $db = new PDO('sqlite:iportfolio_backup.20260131');
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    
    $results = [];
    foreach ($tables as $table) {
        $count = $db->query("SELECT count(*) FROM \"$table\"")->fetchColumn();
        if ($count > 0) {
            $results[$table] = $count;
        }
    }
    
    arsort($results);
    echo "Tables with data (sorted by count):\n";
    foreach ($results as $table => $count) {
        echo str_pad($table, 35) . ": " . number_format($count) . " records\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
