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
        'Monday' => $mondayOrders,
        'Tuesday' => $tuesdayOrders,
        'Wednesday' => $wednesdayOrders,
        'Thursday' => $thursdayOrders,
        'Friday' => $fridayOrders,
        'Saturday' => $saturdayOrders
        ] as $day => $orders)
        @if($orders->isNotEmpty())
        {{-- Day Header --}}
        <tr>
            <th colspan="{{ count($branches) }}">{{ $day }}</th>
        </tr>

        @foreach($orders as $order)
        {{-- Product Name --}}
        <tr>
            <td colspan="{{ count($branches) }}">{{ $order['item'] }} ({{ $order['item_code'] }})</td>
        </tr>

        {{-- Branch Headers and Quantities --}}
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
            $branchOrder = $order['branches']->firstWhere('display_name', $branchName);
            $quantity = $branchOrder ? $branchOrder['quantity_ordered'] : 0;
            @endphp
            @if($order['branches']->where('display_name', $branchName)->where('quantity_ordered', '>', 0)->isNotEmpty())
            <td>{{ $quantity }}</td>
            @endif
            @endforeach
        </tr>
        @endforeach

        {{-- Empty row between days --}}
        <tr>
            <td colspan="{{ count($branches) }}">&nbsp;</td>
        </tr>
        @endif
        @endforeach
    </tbody>
</table>