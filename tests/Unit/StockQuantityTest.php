<?php

use App\Support\StockQuantity;

test('stock quantity normalizes scientific notation to fixed decimal strings', function () {
    expect(StockQuantity::normalize(9.9999999999989E-5))->toBe('0.0001000000');
});

test('stock quantity adjustment keeps meaningful small differences', function () {
    $adjustment = StockQuantity::adjustment('18243.2001000000', '18243.2000000000');

    expect($adjustment)->toBe('0.0001000000')
        ->and(StockQuantity::absolute($adjustment))->toBe('0.0001000000')
        ->and(StockQuantity::isPositive($adjustment))->toBeTrue()
        ->and(StockQuantity::isZero($adjustment))->toBeFalse();
});

test('stock quantity adjustment removes sub precision float noise', function () {
    $adjustment = StockQuantity::adjustment(10.000000000000004, 10);

    expect($adjustment)->toBe('0.0000000000')
        ->and(StockQuantity::isZero($adjustment))->toBeTrue();
});
