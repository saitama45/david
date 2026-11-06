<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Commit variance on order {{ $order->order_number }}</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #e5e7eb;">
                <tr>
                    <td style="background:#1d4ed8;color:#ffffff;padding:18px 24px;">
                        <div style="font-size:12px;letter-spacing:1px;text-transform:uppercase;opacity:.85;">DAVID Inventory System &middot; Automatic notification</div>
                        <div style="font-size:20px;font-weight:bold;margin-top:4px;">Commit variance on your order</div>
                    </td>
                </tr>
                <tr>
                    <td style="background:#fef3c7;color:#92400e;padding:10px 24px;font-size:13px;border-bottom:1px solid #fde68a;">
                        <strong>This is a no-reply email.</strong> It was sent automatically and replies to it are not read.
                        For questions, contact your CS team.
                    </td>
                </tr>
                <tr>
                    <td style="padding:20px 24px 8px;font-size:14px;line-height:1.5;">
                        <p style="margin:0 0 12px;">Hello {{ $storeName ?? 'Team' }},</p>
                        <p style="margin:0 0 12px;">
                            CS committed the item(s) below at a quantity <strong>different from what your store ordered</strong>.
                            Your delivery will follow the committed quantity. Only the items with a variance are listed.
                        </p>
                        <table role="presentation" cellpadding="0" cellspacing="0" style="font-size:13px;margin:0 0 8px;">
                            <tr><td style="padding:2px 12px 2px 0;color:#6b7280;">Order #</td><td style="padding:2px 0;font-weight:bold;">{{ $order->order_number }}</td></tr>
                            <tr><td style="padding:2px 12px 2px 0;color:#6b7280;">Store</td><td style="padding:2px 0;">{{ $storeName ?? '-' }}</td></tr>
                            <tr><td style="padding:2px 12px 2px 0;color:#6b7280;">Ordering Template</td><td style="padding:2px 0;">{{ $template ?? '-' }}</td></tr>
                            <tr><td style="padding:2px 12px 2px 0;color:#6b7280;">Delivery Date</td><td style="padding:2px 0;">{{ $deliveryDate ?? '-' }}</td></tr>
                            <tr><td style="padding:2px 12px 2px 0;color:#6b7280;">Committed</td><td style="padding:2px 0;">{{ $committedAt }}@if($committedBy) by {{ $committedBy }}@endif</td></tr>
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
                                    <th align="right" style="padding:8px;border-bottom:2px solid #e5e7eb;">Ordered</th>
                                    <th align="right" style="padding:8px;border-bottom:2px solid #e5e7eb;">Committed</th>
                                    <th align="right" style="padding:8px;border-bottom:2px solid #e5e7eb;">Variance</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach ($rows as $row)
                                <tr>
                                    <td style="padding:8px;border-bottom:1px solid #f3f4f6;font-family:monospace;">{{ $row['item_code'] }}</td>
                                    <td style="padding:8px;border-bottom:1px solid #f3f4f6;">{{ $row['item_name'] }}</td>
                                    <td style="padding:8px;border-bottom:1px solid #f3f4f6;">{{ $row['uom'] }}</td>
                                    <td align="right" style="padding:8px;border-bottom:1px solid #f3f4f6;">{{ number_format($row['ordered'], 4) }}</td>
                                    <td align="right" style="padding:8px;border-bottom:1px solid #f3f4f6;font-weight:bold;">{{ number_format($row['committed'], 4) }}</td>
                                    <td align="right" style="padding:8px;border-bottom:1px solid #f3f4f6;font-weight:bold;color:{{ $row['variance'] < 0 ? '#b91c1c' : '#047857' }};">{{ $row['variance'] > 0 ? '+' : '' }}{{ number_format($row['variance'], 4) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 24px 20px;font-size:12px;color:#6b7280;line-height:1.5;">
                        Variance = Committed &minus; Ordered. A negative variance means less will be delivered than ordered.
                        Receive the delivery in <strong>Inbound Orders</strong> as usual.
                    </td>
                </tr>
                <tr>
                    <td style="background:#f9fafb;padding:12px 24px;font-size:11px;color:#9ca3af;border-top:1px solid #e5e7eb;">
                        Sent from the DAVID no-reply notification mailbox to the store's emails in Store Branches. Please do not reply.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
