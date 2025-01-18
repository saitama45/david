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
        {{-- Day Header --}}
        <tr>
            <th colspan="{{ count($branches) }}">{{ $day }}</th>
        </tr>

        {{-- Branch Headers --}}
        <tr>
            @foreach($branches as $branch)
            <th>{{ $branch }}</th>
            @endforeach
        </tr>

        {{-- Orders for each item --}}
        @foreach($orders as $order)
        <tr>
            <td colspan="{{ count($branches) }}">{{ $order['item'] }} ({{ $order['item_code'] }})</td>
        </tr>
        <tr>
            @foreach($branches as $branchName)
            @php
            $branchOrder = $order['branches']->firstWhere('display_name', $branchName);
            $quantity = $branchOrder ? $branchOrder['quantity_ordered'] : 0;
            @endphp
            <td>{{ $quantity }}</td>
            @endforeach
        </tr>
        @endforeach

        {{-- Empty row between days --}}
        <tr>
            <td colspan="{{ count($branches) }}">&nbsp;</td>
        </tr>
        @endforeach
    </tbody>
</table>