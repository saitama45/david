<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Ordering Calendar</title>
    <style>
        body {
            font-family: sans-serif;
            font-size: 12px;
            color: #333;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
        }
        .title {
            font-size: 18px;
            font-weight: bold;
            color: #1a56db;
            margin-bottom: 5px;
        }
        .subtitle {
            font-size: 14px;
            color: #666;
        }
        .info-section {
            margin-bottom: 20px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        .info-row {
            margin-bottom: 5px;
        }
        .info-label {
            font-weight: bold;
            display: inline-block;
            width: 150px;
        }
        .calendar-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .calendar-table th {
            background-color: #1a56db;
            color: white;
            padding: 10px;
            text-align: center;
            font-weight: bold;
            text-transform: uppercase;
            border: 1px solid #1e429f;
        }
        .calendar-table td {
            height: 80px;
            padding: 5px;
            border: 1px solid #e5e7eb;
            vertical-align: top;
        }
        .day-number {
            font-weight: bold;
            font-size: 10px;
            color: #6b7280;
            margin-bottom: 5px;
        }
        .qty-display {
            font-size: 24px;
            font-weight: bold;
            text-align: center;
            margin-top: 10px;
        }
        .status-order { background-color: #e2f3e2; }
        .status-commit { background-color: #fce4ec; }
        .status-delivered { background-color: #fff9c4; }
        .status-no-delivery { background-color: #f3f4f6; }
        
        .empty-cell {
            background-color: #f9fafb;
        }
        .legend {
            margin-top: 20px;
            padding: 10px;
            background-color: #f9fafb;
            border-radius: 5px;
        }
        .legend-item {
            display: inline-block;
            margin-right: 20px;
        }
        .legend-box {
            display: inline-block;
            width: 12px;
            height: 12px;
            margin-right: 5px;
            border: 1px solid #ddd;
            vertical-align: middle;
        }
        .footer {
            margin-top: 30px;
            text-align: right;
            font-size: 10px;
            color: #999;
        }
        .no-delivery-text {
            font-size: 8px;
            font-weight: bold;
            color: #9ca3af;
            text-align: center;
            margin-top: 15px;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">ORDERING CALENDAR</div>
        <div class="subtitle">{{ $monthName }} {{ $year }}</div>
    </div>

    <div class="info-section">
        <div class="info-row">
            <span class="info-label">Store:</span>
            <span>[{{ $store->branch_code }}] {{ $store->name }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Item:</span>
            <span>{{ $item->ItemCode }} - {{ $item->item_name }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Generated On:</span>
            <span>{{ $today }}</span>
        </div>
    </div>

    <table class="calendar-table">
        <thead>
            <tr>
                <th>Mon</th>
                <th>Tue</th>
                <th>Wed</th>
                <th>Thu</th>
                <th>Fri</th>
                <th>Sat</th>
            </tr>
        </thead>
        <tbody>
            @php
                $firstDayOfMonth = \Carbon\Carbon::createFromDate($year, $month, 1);
                $daysInMonth = $firstDayOfMonth->daysInMonth;
                $startOffset = $firstDayOfMonth->dayOfWeek === 0 ? 0 : $firstDayOfMonth->dayOfWeek - 1;
                
                $dataMap = [];
                foreach($calendarData as $d) {
                    $dataMap[$d['day']] = $d;
                }
                
                $currentDay = 1;
                $rows = [];
                $currentRow = [];
                
                // First week offset
                if ($firstDayOfMonth->dayOfWeek !== 0) {
                    for ($i = 0; $i < $startOffset; $i++) {
                        $currentRow[] = ['empty' => true];
                    }
                }
                
                for ($day = 1; $day <= $daysInMonth; $day++) {
                    $date = \Carbon\Carbon::createFromDate($year, $month, $day);
                    if ($date->dayOfWeek === 0) continue; // Skip Sundays
                    
                    $currentRow[] = [
                        'empty' => false,
                        'day' => $day,
                        'data' => $dataMap[$day] ?? null
                    ];
                    
                    if (count($currentRow) === 6) {
                        $rows[] = $currentRow;
                        $currentRow = [];
                    }
                }
                
                if (count($currentRow) > 0) {
                    while (count($currentRow) < 6) {
                        $currentRow[] = ['empty' => true];
                    }
                    $rows[] = $currentRow;
                }
            @endphp

            @foreach($rows as $row)
                <tr>
                    @foreach($row as $cell)
                        @if($cell['empty'])
                            <td class="empty-cell"></td>
                        @else
                            @php
                                $statusClass = '';
                                if(isset($cell['data']['status'])) {
                                    $statusClass = 'status-' . $cell['data']['status'];
                                }
                            @endphp
                            <td class="{{ $statusClass }}">
                                <div class="day-number">{{ $cell['day'] }}</div>
                                @if(isset($cell['data']['qty']))
                                    <div class="qty-display">{{ $cell['data']['qty'] }}</div>
                                @elseif(isset($cell['data']['status']) && $cell['data']['status'] === 'no-delivery')
                                    <div class="no-delivery-text">NO DELIVERY</div>
                                @endif
                            </td>
                        @endif
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="legend">
        <strong>Legend:</strong>
        <div class="legend-item"><span class="legend-box status-order"></span> Order</div>
        <div class="legend-item"><span class="legend-box status-commit"></span> Commit</div>
        <div class="legend-item"><span class="legend-box status-delivered"></span> Delivered</div>
        <div class="legend-item"><span class="legend-box status-no-delivery"></span> No Delivery</div>
    </div>

    <div class="footer">
        * Quantities are expressed in Ordering UoM. Sundays are excluded as there are no Sunday deliveries.
    </div>
</body>
</html>
