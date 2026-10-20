import test from 'node:test';
import assert from 'node:assert/strict';
import { heldInTransfer, sohLeft, formatSoh, exceedsSoh } from '../resources/js/lib/intercoSoh.js';

// 1.5 Bag on hand, kept on one balance with its Gm unit (1 Gm = 0.001 Bag).
const bag = { item_code: 'RM-TC-0001', unit_of_measurement: 'Bag', stock: '1.50', stock_row_id: 7, stock_factor: 1 };
const gm = { item_code: 'RM-TC-0001', unit_of_measurement: 'Gm', stock: '1500.00', stock_row_id: 7, stock_factor: 0.001 };

test('the Sender SOH left takes off what the transfer already holds', () => {
    const lines = [{ ...bag, quantity_ordered: 0.5 }];

    assert.equal(sohLeft(lines, bag), 1);
    assert.equal(formatSoh(sohLeft(lines, bag)), '1.00');
});

test('lines of one item in another unit hold the same balance', () => {
    const lines = [{ ...bag, quantity_ordered: 0.5 }, { ...gm, quantity_ordered: 250 }];

    assert.equal(heldInTransfer(lines, bag), 0.75);
    assert.equal(sohLeft(lines, bag), 0.75);
    assert.equal(sohLeft(lines, gm), 750);
});

test('a line being edited does not hold stock against itself', () => {
    const line = { ...bag, quantity_ordered: 1.5 };

    assert.equal(sohLeft([line], line, line), 1.5);
    assert.equal(exceedsSoh(1.5, sohLeft([line], line, line)), false);
    assert.equal(exceedsSoh(1.6, sohLeft([line], line, line)), true);
});

test('another item, or another balance of the same item, is not taken off', () => {
    const lines = [
        { item_code: 'RM-TC-0002', unit_of_measurement: 'Bag', stock: '9', stock_row_id: 8, stock_factor: 1, quantity_ordered: 5 },
        { item_code: 'RM-TC-0001', unit_of_measurement: 'Can', stock: '4', stock_row_id: 9, stock_factor: 1, quantity_ordered: 2 },
    ];

    assert.equal(sohLeft(lines, bag), 1.5);
});

test('it never goes below 0, and a float rounding error is not "more"', () => {
    assert.equal(sohLeft([{ ...bag, quantity_ordered: 2 }], bag), 0);
    assert.equal(exceedsSoh(0.3, 0.1 + 0.2), false);
    assert.equal(formatSoh(0.125), '0.125');
});
