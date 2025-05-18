<table>
    <thead>
        <tr>
            <th colspan="{{ count($branches) }}">
                Ice Cream Orders ({{ $startDate->format('M d, Y') }} - {{ $endDate->format('M d, Y') }})
            </th>
        </tr>
    </thead>
    <tbody>
        @foreach([
        'Monday' => [$mondayOrders, $mondayTotal],
        'Tuesday' => [$tuesdayOrders, $tuesdayTotal],
        'Wednesday' => [$wednesdayOrders, $wednesdayTotal],
        'Thursday' => [$thursdayOrders, $thursdayTotal],
        'Friday' => [$fridayOrders, $fridayTotal],
        'Saturday' => [$saturdayOrders, $saturdayTotal]
        ] as $day => $dayData)

        @php
        $orders = $dayData[0];
        $dayTotal = $dayData[1];
        @endphp

        @if($orders->isNotEmpty())
        <tr>
            <th colspan="{{ count($branches) }}">
                {{ $day }} (Total Orders: {{ $dayTotal }})
            </th>
        </tr>

        @foreach($orders as $order)
        <tr>
            <td colspan="{{ count($branches) }}">{{ $order['item'] }} ({{ $order['item_code'] }})</td>
        </tr>

        <tr>
            @foreach($branches as $branchName)
            @if($order['branches']->where('display_name', $branchName)->where('quantity_ordered', '>', 0)->isNotEmpty())
            <th>{{ $branchName }}</th>
            @endif
            @endforeach
        </tr>
        <tr>
            @foreach($branches as $branchName)
            @php
            $branchAddress = $branchesWithAddresses->firstWhere('display_name', $branchName)['complete_address'] ?? '';
            @endphp
            @if($order['branches']->where('display_name', $branchName)->where('quantity_ordered', '>', 0)->isNotEmpty() && !empty($branchAddress))
            <th>{{ $branchAddress }}</th>
            @elseif($order['branches']->where('display_name', $branchName)->where('quantity_ordered', '>', 0)->isNotEmpty())
            <th>-</th>
            @endif
            @endforeach
        </tr>
        <tr>
            @foreach($branches as $branchName)
            @php
            $branchOrder = $order['branches']->firstWhere('display_name', $branchName);
            $quantity = $branchOrder ? $branchOrder['quantity_ordered'] : 0;
            @endphp
            @if($order['branches']->where('display_name', $branchName)->where('quantity_ordered', '>', 0)->isNotEmpty())
            <td>{{ $quantity }}</td>
            @endif
            @endforeach
        </tr>
        @endforeach

        <tr>
            <td colspan="{{ count($branches) }}">&nbsp;</td>
        </tr>
        @endif
        @endforeach
    </tbody>
</table>