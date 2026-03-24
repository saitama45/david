<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Inventory Movement Report</title>
    <style>
        @page { margin: 10px; }
        body { font-family: sans-serif; font-size: 9px; margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 3px; text-align: center; }
        .text-left { text-align: left; }
        .text-right { text-align: right; }
        .font-bold { font-weight: bold; }
        .header { text-align: center; margin-bottom: 10px; }
        .header h1 { font-size: 16px; margin: 0; }
        .info { margin-bottom: 10px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Inventory Movement Report</h1>
        <p>{{ \Carbon\Carbon::parse($filters['date_from'])->format('M d, Y') }} - {{ \Carbon\Carbon::parse($filters['date_to'])->format('M d, Y') }}</p>
    </div>

    <div class="info">
        <strong>Branch:</strong> {{ $branch ? $branch->name : 'N/A' }} | 
        <strong>Generated:</strong> {{ $date_generated }} | 
        <strong>By:</strong> {{ $generated_by }}
    </div>

    <table>
        <thead>
            <tr style="background-color: #f3f4f6; font-size: 8px;">
                <th colspan="3">ITEM INFO</th>
                <th colspan="3">PROCUREMENT (DATE RANGE)</th>
                <th>BEGINNING</th>
                <th colspan="4">DEDUCTIONS / TRANSFERS</th>
                <th colspan="2">FINAL BALANCE</th>
            </tr>
            <tr style="background-color: #eee;">
                <th class="text-left">SAP Code</th>
                <th class="text-left" width="20%">Item Description</th>
                <th>UOM</th>
                <th>Ordered</th>
                <th>Committed</th>
                <th>Received</th>
                <th>Beg Bal Qty</th>
                <th>Sales Qty</th>
                <th>Wastage Qty</th>
                <th>In Interco</th>
                <th>Out Interco</th>
                <th>Theoretical</th>
                <th>Actual MEC</th>
            </tr>
        </thead>
        <tbody>
            @foreach($movementData as $item)
                <tr>
                    <td class="text-left">{{ $item['sap_code'] }}</td>
                    <td class="text-left">{{ $item['item_description'] }}</td>
                    <td>{{ $item['uom'] }}</td>
                    <td class="text-right">{{ number_format($item['ordered_qty'], 2) }}</td>
                    <td class="text-right">{{ number_format($item['committed_qty'], 2) }}</td>
                    <td class="text-right font-bold" style="background-color: #f0f7ff;">{{ number_format($item['received_qty'], 2) }}</td>
                    <td class="text-right font-bold" style="background-color: #f0fff4;">{{ number_format($item['beg_bal_qty'], 2) }}</td>
                    <td class="text-right">{{ number_format($item['sales_qty'], 2) }}</td>
                    <td class="text-right">{{ number_format($item['wastage_qty'], 2) }}</td>
                    <td class="text-right">{{ number_format($item['interco_in_qty'], 2) }}</td>
                    <td class="text-right">{{ number_format($item['interco_out_qty'], 2) }}</td>
                    <td class="text-right font-bold" style="background-color: #f5f3ff;">{{ number_format($item['theoretical_qty'], 2) }}</td>
                    <td class="text-right font-bold">
                        {{ $item['actual_mec'] !== null ? number_format($item['actual_mec'], 2) : '-' }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
