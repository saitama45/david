<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Laravel') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Figtree', sans-serif;
            padding: 1rem;
            font-size: 12px;
        }

        .max-w-7xl {
            max-width: 1140px;
            margin: 0 auto;
        }

        .header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #E5E7EB;
        }

        .logo {
            height: 3rem;
            width: auto;
        }

        .title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #111827;
            margin-bottom: 0.25rem;
        }

        .date-range {
            color: #4B5563;
            font-size: 0.875rem;
        }

        .orders-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .orders-table th,
        .orders-table td {
            border: 1px solid #E5E7EB;
            padding: 0.5rem;
            text-align: left;
        }

        .orders-table th {
            background-color: #F9FAFB;
            font-weight: 600;
        }

        .item-code {
            text-align: left;
            white-space: nowrap;
        }

        .footer {
            margin-top: 1rem;
            font-size: 0.75rem;
            color: #6B7280;
            text-align: right;
        }

        @media print {
            body {
                padding: 0.5rem;
            }
        }
    </style>
</head>

<body>
    <div class="max-w-7xl">
        <div class="header">
            <img src="{{ Vite::asset('resources/images/temporaryLoginImage.png')}}" alt="Logo" class="logo">
            <div>
                <h1 class="title">Orders Summary Report</h1>
                <p class="date-range">
                    {{ \Carbon\Carbon::parse($startDate)->format('M d, Y') }} -
                    {{ \Carbon\Carbon::parse($endDate)->format('M d, Y') }}
                </p>
            </div>
        </div>

        <table class="orders-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Inventory Code</th>
                    @foreach($branches as $branch)
                    <th>{{ $branch->branch_code }}</th>
                    @endforeach
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($products as $product)
                <tr>
                    <td class="item-code">{{ $product['name'] }}</td>
                    <td>{{ $product['inventory_code'] }}</td>
                    @foreach($branches as $branch)
                    <td>{{ $product['branch_quantities'][$branch->id] ?? 0 }}</td>
                    @endforeach
                    <td>{{ $product['total'] }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="footer">
            <p>Generated on {{ now()->format('M d, Y h:i A') }}</p>
        </div>
    </div>
</body>

</html>