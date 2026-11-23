<?php

use App\Support\ItemStockUnit;

/**
 * Pins which base row an item's stock lives on and how units convert into it.
 * Rows are plain objects, so nothing here touches the database.
 */
function sapRows(array $rows): \Illuminate\Support\Collection
{
    return collect($rows)->map(fn ($row, $i) => (object) [
        'id' => $row[4] ?? $i + 1, 'AltUOM' => $row[0], 'BaseUOM' => $row[1], 'AltQty' => $row[2], 'BaseQty' => $row[3],
    ]);
}

test('an item SAP restates in a second, linked base keeps its stock on the SAP base unit', function () {
    // Condense Milk: the Can/Can row comes first, but 48 Can = 1 Case and 18720 Gm = 1 Case.
    $unit = ItemStockUnit::fromRows(sapRows([
        ['Can', 'Can', 1, 1, 27501], ['Can', 'Case', 48, 1, 27389], ['Gm', 'Case', 18720, 1, 27390], ['Case', 'Case', 1, 1, 27450],
    ]));

    expect($unit->stockRow()->id)->toBe(27450)
        ->and($unit->unit())->toBe('Case')
        ->and($unit->stockRowFor('Can')->id)->toBe(27450)
        ->and($unit->factor('Case'))->toBe(1.0)
        ->and($unit->factor('can'))->toEqualWithDelta(1 / 48, 1e-12)
        ->and($unit->factor('Gm'))->toEqualWithDelta(1 / 18720, 1e-12)
        ->and($unit->factor(''))->toBe(1.0)
        ->and($unit->factor('Bottle'))->toBeNull();
});

test('BaseQty per AltQty is honoured whichever way SAP writes it', function () {
    // TC writes 1000 Gm = 1 Bag; Nono's writes 1 BOX(18) = 18 SACHET.
    $espresso = ItemStockUnit::fromRows(sapRows([['Gm', 'Bag', 1000, 1], ['Bag', 'Bag', 1, 1], ['Gm', 'Gm', 1, 1]]));
    $sachets = ItemStockUnit::fromRows(sapRows([['SACHET', 'SACHET', 1, 1], ['BOX(18)', 'SACHET', 1, 18]]));

    expect($espresso->unit())->toBe('Bag')
        ->and($espresso->factor('Gm'))->toBe(0.001)
        ->and($sachets->factor('BOX(18)'))->toBe(18.0);
});

test('a base unit SAP does not link to the others stays a separate stock', function () {
    // Sprite in Can: Can/Can has no conversion to the LIT group, as before this change.
    $unit = ItemStockUnit::fromRows(sapRows([
        ['Can', 'Can', 1, 1, 20492], ['LIT', 'LIT', 1, 1, 25202], ['CASE(24)', 'LIT', 1, 7.92, 25203],
    ]));

    expect($unit->stockRowFor('Can')->id)->toBe(20492)
        ->and($unit->factor('Can'))->toBe(1.0)
        ->and($unit->stockRowFor('CASE(24)')->id)->toBe(25202)
        ->and($unit->factor('CASE(24)'))->toBe(7.92)
        ->and($unit->stockRow()->id)->toBe(25202)
        ->and($unit->factors())->not->toHaveKey('CAN');
});

test('a pack SAP gives only as a conversion is the SAP base unit, while stock stays on the small unit', function () {
    // TC: 6 Slice = 1 Loaf and Slice/Slice, with no Loaf/Loaf row; 250 Gm = 1 Bag likewise.
    $loaf = ItemStockUnit::fromRows(sapRows([['Slice', 'Loaf', 6, 1, 27470], ['Slice', 'Slice', 1, 1, 27471]]));
    $beans = ItemStockUnit::fromRows(sapRows([['Gm', 'Bag', 250, 1, 27534], ['Gm', 'Gm', 1, 1, 27535]]));

    expect($loaf->unit())->toBe('Slice')
        ->and($loaf->stockRow()->id)->toBe(27471)
        ->and($loaf->sapBaseUnit())->toBe('Loaf')
        ->and($loaf->sapBaseFactors()['SLICE'])->toEqualWithDelta(1 / 6, 1e-12)
        ->and($loaf->sapBaseFactors()['LOAF'])->toEqualWithDelta(1.0, 1e-12)
        ->and($beans->sapBaseUnit())->toBe('Bag')
        ->and($beans->sapBaseFactors()['GM'] * 73)->toEqualWithDelta(0.292, 1e-12);
});

test('the SAP base unit is the stock unit whenever that unit has its own row', function () {
    $milk = ItemStockUnit::fromRows(sapRows([
        ['Can', 'Can', 1, 1, 27501], ['Can', 'Case', 48, 1, 27389], ['Gm', 'Case', 18720, 1, 27390], ['Case', 'Case', 1, 1, 27450],
    ]));
    $sachets = ItemStockUnit::fromRows(sapRows([['SACHET', 'SACHET', 1, 1], ['BOX(18)', 'SACHET', 1, 18]]));

    expect($milk->sapBaseUnit())->toBe('Case')
        ->and($milk->sapBaseFactors())->toBe($milk->factors())
        ->and($sachets->sapBaseUnit())->toBe('SACHET')
        ->and($sachets->sapBaseFactors())->toBe($sachets->factors());
});

test('a single base row item behaves as it always did', function () {
    $unit = ItemStockUnit::fromRows(sapRows([['PC', 'PC', 1, 1], ['PACK(50)', 'PC', 1, 50]]));

    expect($unit->unit())->toBe('PC')->and($unit->factor('PACK(50)'))->toBe(50.0);
});
