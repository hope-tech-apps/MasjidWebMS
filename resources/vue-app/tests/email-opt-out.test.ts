/**
 * The member record's email badge (views/dashboard/contacts/emailOptOut.ts).
 * Display only; the server decides who is emailed. Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { emailConsentBody, emailOptOutBadge } from '../views/dashboard/contacts/emailOptOut.ts';

test('an import\'s "not opted in" reads differently from an unsubscribe, and only it offers recording consent', () => {
    assert.deepEqual(emailOptOutBadge('2026-10-01', 'not_opted_in'), { label: 'Emails: not opted in (imported)', canRecordConsent: true });
    assert.deepEqual(emailOptOutBadge('2026-10-01', 'bounce'), { label: 'Emails: address bounced', canRecordConsent: false });
    for (const reason of ['unsubscribe_link', 'imported_opt_out', 'complaint', 'manual']) {
        assert.deepEqual(emailOptOutBadge('2026-10-01', reason), { label: 'Emails: unsubscribed', canRecordConsent: false }, reason);
    }
});

test('an order-history hold reads as a hold, not an unsubscribe, and offers recording consent like "not opted in"', () => {
    assert.deepEqual(emailOptOutBadge('2026-10-01', 'order_history_import'), { label: 'Emails: held (imported order, no consent on record)', canRecordConsent: true });
});

test('no badge for a mailable address, and a record without its reason yet reads as the stricter "unsubscribed"', () => {
    assert.equal(emailOptOutBadge(null, 'not_opted_in'), null);
    assert.deepEqual(emailOptOutBadge('2026-10-01', undefined), { label: 'Emails: unsubscribed', canRecordConsent: false });
});

test('the consent body is form-encoded evidence, trimmed', () => {
    assert.equal(emailConsentBody('  signed the sheet ').toString(), 'evidence=signed+the+sheet');
});
