<?php
try {
    $db = new PDO('sqlite:iportfolio_backup.20260131');
    
    $query = "SELECT fsale_date, SUM(fnetsales) as total_net, SUM(fgross) as total_gross, SUM(ftotal_trx) as total_trx 
              FROM pos_reading_summary 
              GROUP BY fsale_date 
              ORDER BY fsale_date DESC";
    $rows = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);

    echo "Top 10 Recent Days:\n";
    echo "Date       | Net Sales  | Gross Sales | Trx Count\n";
    echo "-----------|------------|-------------|----------\n";
    $count = 0;
    foreach ($rows as $row) {
        if ($count++ < 10) {
            echo "{$row['fsale_date']} | " . str_pad(number_format($row['total_net'], 2), 10, ' ', STR_PAD_LEFT) . 
                 " | " . str_pad(number_format($row['total_gross'], 2), 11, ' ', STR_PAD_LEFT) . 
                 " | " . str_pad($row['total_trx'], 8, ' ', STR_PAD_LEFT) . "\n";
        }
    }

    echo "\nMonthly Summary (Net Sales):\n";
    $monthly = [];
    foreach ($rows as $row) {
        $month = substr($row['fsale_date'], 0, 6);
        if (!isset($monthly[$month])) {
            $monthly[$month] = 0;
        }
        $monthly[$month] += $row['total_net'];
    }

    foreach ($monthly as $month => $total) {
        echo "$month: " . number_format($total, 2) . "\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
