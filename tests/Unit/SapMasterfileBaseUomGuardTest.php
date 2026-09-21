<?php

use App\Imports\SAPMasterfileImport;

/**
 * An ItemCode has one base unit. A SAP file that brings it in under a second one
 * adds a parallel set of rows instead of correcting the first, which makes the
 * item countable twice at month end - see SAPMasterfileImport::conflictingBaseUom.
 */
it('accepts an item whose base unit matches what is already on file', function () {
    expect(SAPMasterfileImport::conflictingBaseUom('PC', ['PC']))->toBeFalse();
});

it('accepts an item that is not on file yet', function () {
    expect(SAPMasterfileImport::conflictingBaseUom('LIT', []))->toBeFalse();
});

it('refuses a second base unit for an item already on file', function () {
    expect(SAPMasterfileImport::conflictingBaseUom('LIT', ['PC']))->toBeTrue();
});

it('ignores casing and padding rather than refusing a cosmetic difference', function () {
    expect(SAPMasterfileImport::conflictingBaseUom(' pc ', ['PC']))->toBeFalse();
});

it('takes no view when the row leaves the base unit blank', function () {
    expect(SAPMasterfileImport::conflictingBaseUom('', ['PC']))->toBeFalse();
});

it('keeps items that already hold several base units maintainable', function () {
    // 647A2A is on file as both PC and LIT; either may still be updated, but a
    // third base unit is still refused.
    expect(SAPMasterfileImport::conflictingBaseUom('LIT', ['PC', 'LIT']))->toBeFalse()
        ->and(SAPMasterfileImport::conflictingBaseUom('PC', ['PC', 'LIT']))->toBeFalse()
        ->and(SAPMasterfileImport::conflictingBaseUom('CASE', ['PC', 'LIT']))->toBeTrue();
});
