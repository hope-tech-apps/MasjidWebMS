import { test } from 'node:test';
import assert from 'node:assert/strict';
import { tuitionMinor, tuitionMoney, tuitionDecimal } from '../core/helpers/tuitionMoney.ts';

test('tuition decimal input is exact and rejects fractional minor units and exponent notation', () => {
    assert.equal(tuitionMinor('123.45', 'usd'), 12345);
    assert.equal(tuitionMinor('0.29', 'usd'), 29);
    assert.equal(tuitionMinor('1.234', 'kwd'), 1234);
    assert.equal(tuitionMinor('9', 'jpy'), 9);
    for (const value of ['1.001', '1e3', '-1', '0', 'Infinity', '10000000.00']) assert.equal(tuitionMinor(value, 'usd'), null);
});
test('statements retain exact signed amounts in all currency scales', () => {
    assert.equal(tuitionMoney(-12345, 'usd'), '-$123.45');
    assert.equal(tuitionMoney(12345, 'jpy'), '¥12,345');
    assert.equal(tuitionDecimal(1, 'kwd'), '0.001');
});
