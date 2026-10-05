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
        <strong>Supplier:</strong> {{ $supplier ? $supplier->name . ' (' . $supplier->supplier_code . ')' : 'All Suppliers' }} |
        <strong>Generated:</strong> {{ $date_generated }} | 
        <strong>By:</strong> {{ $generated_by }}
    </div>

    <p>All quantities use the SAP base unit shown in the UOM column (for example, 36 Gm of a 1,000 Gm Bag = 0.036 Bag). Supplies Used is the usage the month end count shows for Operating / Cleaning Supplies items.</p>
    <table>
        <thead>
            <tr style="background-color: #f3f4f6; font-size: 8px;">
                <th colspan="4">ITEM INFO</th>
                <th colspan="3">PROCUREMENT (DATE RANGE)</th>
                <th>BEGINNING</th>
                <th colspan="5">DEDUCTIONS / TRANSFERS</th>
                <th colspan="3">FINAL BALANCE</th>
            </tr>
            <tr style="background-color: #eee;">
                <th class="text-left" width="12%">Supplier</th>
                <th class="text-left">SAP Code</th>
                <th class="text-left" width="18%">Item Description</th>
                <th>UOM</th>
                <th>Ordered</th>
                <th>Committed</th>
                <th>Received</th>
                <th>Beg Bal Qty</th>
                <th>Sales Qty</th>
                <th>Wastage Qty</th>
                <th>Supplies Used</th>
                <th>In Interco</th>
                <th>Out Interco</th>
                <th>Theoretical</th>
                <th>Actual MEC</th>
                <th>Variance</th>
            </tr>
        </thead>
        <tbody>
            @foreach($movementData as $item)
                <tr>
                    <td class="text-left">{{ $item['supplier'] ?: '-' }}</td>
                    <td class="text-left">{{ $item['sap_code'] }}</td>
                    <td class="text-left">{{ $item['item_description'] }}</td>
                    <td>{{ $item['uom'] }}</td>
                    <td class="text-right">{{ \App\Support\ReportNumber::format($item['ordered_qty']) }}</td>
                    <td class="text-right">{{ \App\Support\ReportNumber::format($item['committed_qty']) }}</td>
                    <td class="text-right font-bold" style="background-color: #f0f7ff;">{{ \App\Support\ReportNumber::format($item['received_qty']) }}</td>
                    <td class="text-right font-bold" style="background-color: #f0fff4;">{{ \App\Support\ReportNumber::format($item['beg_bal_qty']) }}</td>
                    <td class="text-right">{{ \App\Support\ReportNumber::format($item['sales_qty']) }}</td>
                    <td class="text-right">
                        {{ \App\Support\ReportNumber::format($item['wastage_qty']) }}
                        {{-- The part that is a wasted Sub-Prep, charged to this raw material through its BOM --}}
                        @foreach($item['wastage_sub_preps'] ?? [] as $subPrep)
                            <div style="font-size: 80%; font-weight: normal; color: #92400e;">Sub-Prep {{ $subPrep['code'] }}: {{ \App\Support\ReportNumber::format($subPrep['quantity']) }}</div>
                        @endforeach
                    </td>
                    <td class="text-right">{{ $item['supplies_counted'] ? \App\Support\ReportNumber::format($item['supplies_qty']) : '-' }}</td>
                    <td class="text-right">{{ \App\Support\ReportNumber::format($item['interco_in_qty']) }}</td>
                    <td class="text-right">{{ \App\Support\ReportNumber::format($item['interco_out_qty']) }}</td>
                    <td class="text-right font-bold" style="background-color: #f5f3ff;">{{ \App\Support\ReportNumber::format($item['theoretical_qty']) }}</td>
                    <td class="text-right font-bold">
                        {{ $item['actual_mec'] !== null ? \App\Support\ReportNumber::format($item['actual_mec']) : '-' }}
                    </td>
                    <td class="text-right font-bold">{{ \App\Support\ReportNumber::format($item['variance_qty']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
