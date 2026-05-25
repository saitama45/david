<?php
try {
    $db = new PDO('sqlite:iportfolio_backup.20260131');
    
    $query = "SELECT fsale_date, SUM(fnetsales) as total_net, SUM(ftotal_trx) as total_trx 
              FROM pos_reading_summary 
              WHERE fsale_date LIKE '202601%' AND fnetsales > 0
              GROUP BY fsale_date 
              ORDER BY fsale_date ASC";
    $rows = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);

    echo "January 2026 Sales Details:\n";
    echo "Date       | Net Sales  | Trx Count\n";
    echo "-----------|------------|----------\n";
    foreach ($rows as $row) {
        echo "{$row['fsale_date']} | " . str_pad(number_format($row['total_net'], 2), 10, ' ', STR_PAD_LEFT) . 
             " | " . str_pad($row['total_trx'], 8, ' ', STR_PAD_LEFT) . "\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
