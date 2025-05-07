<table>
    <thead>
        <tr>
            <th colspan="{{ count($branches) + 3 }}">
                Orders Summary Report ({{ \Carbon\Carbon::parse($startDate)->format('M d, Y') }} - {{ \Carbon\Carbon::parse($endDate)->format('M d, Y') }})
            </th>
        </tr>
        <tr>
            <th>BRAND</th>
            <th>ITEM CODE</th>
            <th>ITEM NAME</th>
            <th>Packaging</th>
            <th>UNIT</th>
            @foreach($branches as $branch)
            <th>{{ $branch->branch_code }}</th>
            @endforeach
            <th>Total</th>
        </tr>
    </thead>
    <tbody>
        @foreach($products as $product)
        <tr>
            <td>{{ $product['brand'] }}</td>
            <td>{{ $product['inventory_code'] }}</td>
            <td>{{ $product['name'] }}</td>

            <td>{{ $product['packaging'] }}</td>
            <td>{{ $product['uom'] }}</td>
            @foreach($branches as $branch)
            <td>{{ $product['branch_quantities'][$branch->id] ?? 0 }}</td>
            @endforeach
            <td>{{ $product['total'] }}</td>
        </tr>
        @endforeach
    </tbody>
</table>