<?php
try {
    $db = new PDO('sqlite:iportfolio_backup.20260131');
    
    $query = "SELECT fsale_date, ftermid, fgross, fpgross, ftax, fptax, ftax_sale, fptax_sale, fnotax_sale, fpnotax_sale 
              FROM pos_reading 
              ORDER BY fsale_date ASC";
    $rows = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);

    echo "Date       | Term | Daily Gross (Calculated)\n";
    echo "-----------|------|-------------------------\n";
    foreach ($rows as $row) {
        $daily_gross = $row['fgross'] - $row['fpgross'];
        echo "{$row['fsale_date']} | {$row['ftermid']} | " . number_format($daily_gross, 2) . "\n";
    }

    echo "\nMonthly Summary:\n";
    $monthly = [];
    foreach ($rows as $row) {
        $month = substr($row['fsale_date'], 0, 6);
        $daily_gross = $row['fgross'] - $row['fpgross'];
        if (!isset($monthly[$month])) {
            $monthly[$month] = 0;
        }
        $monthly[$month] += $daily_gross;
    }

    foreach ($monthly as $month => $total) {
        echo "$month: " . number_format($total, 2) . "\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
