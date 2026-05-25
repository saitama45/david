<?php
try {
    $db = new PDO('sqlite:iportfolio_backup.20260131');
    $count = $db->query("SELECT count(*) FROM pos_reading_summary")->fetchColumn();
    echo "pos_reading_summary count: $count\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
