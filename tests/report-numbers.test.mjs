import test from 'node:test';
import assert from 'node:assert/strict';
import { formatReportNumber, formatReportCurrency, formatReportPercent, formatReportCount } from '../resources/js/lib/reportNumbers.js';

test('a quantity is printed with four decimals whatever its value', () => {
    assert.equal(formatReportNumber(5), '5.0000');
    assert.equal(formatReportNumber(0.1), '0.1000');
    assert.equal(formatReportNumber('1234.5'), '1,234.5000');
    assert.equal(formatReportNumber(0.036125), '0.0361');
    assert.equal(formatReportNumber(-2.5), '-2.5000');
});
test('nothing, and a value that rounds to nothing, is 0.0000', () => {
    assert.equal(formatReportNumber(null), '0.0000');
    assert.equal(formatReportNumber(undefined), '0.0000');
    assert.equal(formatReportNumber('abc'), '0.0000');
    assert.equal(formatReportNumber(-0.00001), '0.0000');
});
test('an amount and a percentage follow the same four decimals', () => {
    assert.equal(formatReportCurrency(1800), '₱1,800.0000');
    assert.equal(formatReportCurrency(-12.3), '-₱12.3000');
    assert.equal(formatReportCurrency(0), '₱0.0000');
    assert.equal(formatReportPercent(47.13), '47.1300%');
});
test('a count of records stays a whole number', () => {
    assert.equal(formatReportCount(1234), '1,234');
    assert.equal(formatReportCount('2'), '2');
    assert.equal(formatReportCount(null), '0');
});
