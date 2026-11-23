<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Wastage {{ $wastageNo }} approved</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="720" cellpadding="0" cellspacing="0" style="max-width:720px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #e5e7eb;">
                <tr>
                    <td style="background:#b91c1c;color:#ffffff;padding:18px 24px;">
                        <div style="font-size:12px;letter-spacing:1px;text-transform:uppercase;opacity:.85;">DAVID Inventory System &middot; Automatic notification</div>
                        <div style="font-size:20px;font-weight:bold;margin-top:4px;">Wastage approved</div>
                    </td>
                </tr>
                <tr>
                    <td style="background:#fef3c7;color:#92400e;padding:10px 24px;font-size:13px;border-bottom:1px solid #fde68a;">
                        <strong>This is a no-reply email.</strong> It was sent automatically and replies to it are not read.
                    </td>
                </tr>
                <tr>
                    <td style="padding:20px 24px 8px;font-size:14px;line-height:1.5;">
                        <p style="margin:0 0 12px;">
                            The wastage below of <strong>{{ $storeName }}</strong> got its final approval{!! $approvedBy ? ' from <strong>'.e($approvedBy).'</strong>' : '' !!}.
                            The approved quantities have been taken out of the store's stock.
                        </p>
                        <table role="presentation" cellpadding="0" cellspacing="0" style="font-size:13px;margin:0 0 8px;">
                            <tr><td style="padding:2px 12px 2px 0;color:#6b7280;">Wastage #</td><td style="padding:2px 0;font-weight:bold;">{{ $wastageNo }}</td></tr>
                            <tr><td style="padding:2px 12px 2px 0;color:#6b7280;">Store</td><td style="padding:2px 0;">{{ $storeName }}@if($entityName) ({{ $entityName }})@endif</td></tr>
                            <tr><td style="padding:2px 12px 2px 0;color:#6b7280;">Wastage Date</td><td style="padding:2px 0;">{{ $wastageDate ?? '-' }}</td></tr>
                            <tr><td style="padding:2px 12px 2px 0;color:#6b7280;">Filed by</td><td style="padding:2px 0;">{{ $filedBy ?? '-' }}</td></tr>
                            @if($level1By)
                                <tr><td style="padding:2px 12px 2px 0;color:#6b7280;">Level 1 approved by</td><td style="padding:2px 0;">{{ $level1By }}</td></tr>
                            @endif
                            <tr><td style="padding:2px 12px 2px 0;color:#6b7280;">Approved</td><td style="padding:2px 0;">{{ $approvedAt }}@if($approvedBy) by {{ $approvedBy }}@endif</td></tr>
                            @if($remarks !== '')
                                <tr><td style="padding:2px 12px 2px 0;color:#6b7280;">Remarks</td><td style="padding:2px 0;">{{ $remarks }}</td></tr>
                            @endif
                            <tr><td style="padding:2px 12px 2px 0;color:#6b7280;">Total Cost</td><td style="padding:2px 0;font-weight:bold;">{{ number_format($totalCost, 4) }}</td></tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="padding:8px 24px 16px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;">
                            <thead>
                                <tr style="background:#f9fafb;">
                                    <th align="left" style="padding:8px;border-bottom:2px solid #e5e7eb;">Item Code</th>
                                    <th align="left" style="padding:8px;border-bottom:2px solid #e5e7eb;">Item Name</th>
                                    <th align="left" style="padding:8px;border-bottom:2px solid #e5e7eb;">UOM</th>
                                    <th align="right" style="padding:8px;border-bottom:2px solid #e5e7eb;">Approved Qty</th>
                                    <th align="right" style="padding:8px;border-bottom:2px solid #e5e7eb;">Cost</th>
                                    <th align="right" style="padding:8px;border-bottom:2px solid #e5e7eb;">Total Cost</th>
                                    <th align="left" style="padding:8px;border-bottom:2px solid #e5e7eb;">Reason</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach ($rows as $row)
                                <tr>
                                    <td style="padding:8px;border-bottom:1px solid #f3f4f6;font-family:monospace;">{{ $row['item_code'] }}</td>
                                    <td style="padding:8px;border-bottom:1px solid #f3f4f6;">{{ $row['item_name'] }}</td>
                                    <td style="padding:8px;border-bottom:1px solid #f3f4f6;">{{ $row['uom'] }}</td>
                                    <td align="right" style="padding:8px;border-bottom:1px solid #f3f4f6;font-weight:bold;">{{ number_format($row['quantity'], 4) }}</td>
                                    <td align="right" style="padding:8px;border-bottom:1px solid #f3f4f6;">{{ number_format($row['unit_cost'], 4) }}</td>
                                    <td align="right" style="padding:8px;border-bottom:1px solid #f3f4f6;">{{ number_format($row['total_cost'], 4) }}</td>
                                    <td style="padding:8px;border-bottom:1px solid #f3f4f6;">{{ $row['reason'] }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 24px 20px;font-size:12px;color:#6b7280;line-height:1.5;">
                        Open it in DAVID under <strong>Wastage Record</strong> (wastage # {{ $wastageNo }}), with
                        {{ $entityName ?? "the store's entity" }} as your active entity.
                    </td>
                </tr>
                <tr>
                    <td style="background:#f9fafb;padding:12px 24px;font-size:11px;color:#9ca3af;border-top:1px solid #e5e7eb;">
                        Sent from the DAVID no-reply notification mailbox to the store's Wastage Approval Email in Store Branches. Please do not reply.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
