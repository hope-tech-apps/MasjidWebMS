/**
 * The member directory's tag helpers (views/dashboard/contacts/contactTags.ts).
 * Display answers only; the server decides again. Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    clashingTag,
    contactIdsBody,
    contactsListUrl,
    selectableIds,
    tagNameKey,
    tagsNotOn,
    toggleId,
    togglePage,
    tagContactsUrl,
    untagContactsUrl,
} from '../views/dashboard/contacts/contactTags.ts';

test('a tag name compares the way the server key does: case and spacing ignored', () => {
    assert.equal(tagNameKey('  Volunteer   Team\t'), 'volunteer team');
    assert.equal(tagNameKey(null), '');
    // Arabic keeps its letters; nothing is slugged away.
    assert.equal(tagNameKey(' متطوع '), 'متطوع');
});

test('a clash is found by key, and renaming a tag to itself in a new case is not a clash', () => {
    const tags = [{ id: 1, name: 'Volunteer' }, { id: 2, name: 'Donor' }];
    assert.deepEqual(clashingTag(tags, ' volunteer'), tags[0]);
    assert.equal(clashingTag(tags, 'VOLUNTEER', 1), null);
    assert.equal(clashingTag(tags, 'Teacher'), null);
    assert.equal(clashingTag(tags, '   '), null);
});

test('the bulk body is form-encoded with bracketed ids, once each', () => {
    assert.equal(contactIdsBody([4, 9, 4]).toString(), 'contact_ids%5B%5D=4&contact_ids%5B%5D=9');
});

test('a deleted member can never be ticked', () => {
    assert.deepEqual(selectableIds([{ id: 1 }, { id: 2, deleted_at: '2026-09-01' }, { id: 3, deleted_at: null }]), [1, 3]);
});

test('ticking toggles one row, and the header toggles only this page', () => {
    assert.deepEqual(toggleId([1, 2], 2), [1]);
    assert.deepEqual(toggleId([1], 2), [1, 2]);

    // 7 was ticked on another page and survives both directions.
    assert.deepEqual(togglePage([7], [1, 2]), [7, 1, 2]);
    assert.deepEqual(togglePage([7, 1, 2], [1, 2]), [7]);
    assert.deepEqual(togglePage([7, 1], [1, 2]), [7, 1, 2]);
    assert.deepEqual(togglePage([7], []), [7]);
});

test('the picker on a record offers only the tags the member does not carry', () => {
    const tags = [{ id: 1, name: 'Volunteer' }, { id: 2, name: 'Donor' }];
    assert.deepEqual(tagsNotOn(tags, [{ id: 1, name: 'Volunteer' }]), [tags[1]]);
    assert.deepEqual(tagsNotOn(tags, undefined), tags);
});

test('tag and untag are different endpoints: "Remove tag" can never add the tag', () => {
    assert.equal(tagContactsUrl(13, 7), '/api/admin/masjids/13/contact-tags/7/contacts');
    assert.equal(untagContactsUrl(13, 7), '/api/admin/masjids/13/contact-tags/7/contacts/remove');
});

test('the directory list asks the server for the chosen tag, and only when one is chosen', () => {
    assert.equal(contactsListUrl(13, 2, '', '', 5), '/api/admin/masjids/13/contacts?page=2&tag_id=5');
    assert.equal(contactsListUrl(13, 1, 'a b', 'only', null), '/api/admin/masjids/13/contacts?page=1&search=a%20b&trashed=only');
});
