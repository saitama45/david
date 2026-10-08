<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Incident Report {{ $report->number }}</title>
    <style>
        @page { margin: 36px 40px; }
        body { font-family: sans-serif; font-size: 11px; color: #111; margin: 0; }
        h1 { font-size: 18px; margin: 0; letter-spacing: 1px; }
        table { width: 100%; border-collapse: collapse; }
        .header { text-align: center; border-bottom: 2px solid #111; padding-bottom: 8px; margin-bottom: 14px; }
        .header p { margin: 3px 0 0; font-size: 11px; }
        .details td { padding: 4px 6px; vertical-align: top; border: 1px solid #bbb; }
        .label { width: 22%; background-color: #f3f4f6; font-weight: bold; }
        .value { width: 28%; }
        .section { font-size: 12px; font-weight: bold; margin: 16px 0 6px; }
        .pendings th, .pendings td { border: 1px solid #bbb; padding: 5px 6px; vertical-align: top; text-align: left; }
        .pendings th { background-color: #f3f4f6; }
        .box { border: 1px solid #bbb; padding: 8px; min-height: 40px; }
        .signatures { margin-top: 44px; }
        .signatures td { width: 50%; padding: 0 20px 0 0; vertical-align: bottom; }
        .line { border-top: 1px solid #111; margin-top: 34px; padding-top: 3px; }
        .small { font-size: 9px; color: #555; }
        .footer { margin-top: 24px; font-size: 9px; color: #555; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <h1>INCIDENT REPORT</h1>
        <p>Month End Count: unfinished transactions after the MEC Scheduled Date</p>
        @if($entity)<p>{{ $entity->name }}</p>@endif
    </div>

    <table class="details">
        <tr>
            <td class="label">IR No.</td>
            <td class="value">{{ $report->number }}</td>
            <td class="label">Date Filed</td>
            <td class="value">{{ $report->filed_at->timezone('Asia/Manila')->format('M j, Y g:i A') }}</td>
        </tr>
        <tr>
            <td class="label">Store</td>
            <td class="value">{{ $report->branch?->name }}@if($report->branch?->branch_code) ({{ $report->branch->branch_code }})@endif</td>
            <td class="label">Filed By</td>
            <td class="value">{{ $filedBy }}</td>
        </tr>
        <tr>
            <td class="label">Month End Count</td>
            <td class="value">{{ \Carbon\Carbon::create($report->schedule->year, $report->schedule->month, 1)->format('F Y') }}</td>
            <td class="label">MEC Scheduled Date</td>
            <td class="value">{{ $report->schedule->calculated_date->format('M j, Y') }}</td>
        </tr>
        <tr>
            <td class="label">Period Covered</td>
            <td class="value">{{ $periodFrom->format('M j, Y') }} to {{ $periodThrough->format('M j, Y') }}</td>
            <td class="label">Pendings Found On</td>
            <td class="value">{{ $report->required_at->timezone('Asia/Manila')->format('M j, Y g:i A') }}</td>
        </tr>
    </table>

    <p class="section">Unfinished transactions and the reason for the delay</p>
    <table class="pendings">
        <thead>
            <tr>
                <th width="5%">No.</th>
                <th width="33%">Pending Transaction</th>
                <th width="17%">Status When Filed</th>
                <th>Reason for the Delay</th>
            </tr>
        </thead>
        <tbody>
            @foreach($report->pendings as $index => $pending)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $pending['label'] }}</td>
                    <td>{{ ($pending['open'] ?? false) ? 'Still pending' : 'Finished' }}</td>
                    <td>{!! nl2br(e($pending['reason'] ?? '')) !!}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="section">Action taken</p>
    <div class="box">{!! nl2br(e($report->action_taken)) !!}</div>

    <p class="section">Target date to finish what is still pending</p>
    <div class="box" style="min-height: 0;">
        {{ $report->target_date ? $report->target_date->format('M j, Y') : 'Not applicable. Everything was finished when this report was filed.' }}
    </div>

    <table class="signatures">
        <tr>
            <td>
                <div class="line">
                    <strong>{{ $filedBy }}</strong><br>
                    <span class="small">Prepared by (signature over printed name) / Date</span>
                </div>
            </td>
            <td>
                <div class="line">
                    <strong>&nbsp;</strong><br>
                    <span class="small">Noted by (signature over printed name) / Date</span>
                </div>
            </td>
        </tr>
    </table>

    <p class="footer">
        Filed in the DAVID Inventory System. This report cannot be changed after it is filed.
        Printed {{ $generatedAt->format('M j, Y g:i A') }}.
    </p>
</body>
</html>
