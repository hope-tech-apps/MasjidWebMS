/**
 * Every SweetAlert2 option it renders as HTML is cleaned at the one door (core/plugins/swalSanitize.ts).
 * A MasjidAdmin could name their organisation `<img src=x onerror=…>` and a SuperAdmin's confirm
 * dialog would run it, with the token in localStorage. Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    SWAL_HTML_KEYS,
    installSwalSanitizer,
    sanitizeSwalArgs,
    sanitizeSwalOptions,
} from '../core/plugins/swalSanitize.ts';

// A stand-in for DOMPurify that marks what it touched, so each test sees exactly which values went through it.
const purify = (dirty: string) => `[clean:${dirty}]`;

test('every option SweetAlert2 renders as HTML goes through the sanitizer', () => {
    const options = Object.fromEntries(SWAL_HTML_KEYS.map((k) => [k, `<img src=x onerror=alert(1)> ${k}`]));
    const clean = sanitizeSwalOptions(options, purify) as Record<string, string>;

    for (const key of SWAL_HTML_KEYS) {
        assert.equal(clean[key], `[clean:<img src=x onerror=alert(1)> ${key}]`, key);
    }
    assert.deepEqual([...SWAL_HTML_KEYS].sort(), [
        'cancelButtonText', 'closeButtonHtml', 'confirmButtonText', 'denyButtonText',
        'footer', 'html', 'iconHtml', 'loaderHtml', 'title',
    ], 'the list matches sweetalert2 11: title, html, footer, the button captions, icon/close/loader HTML');
});

test('text options, non-strings and the caller\'s object are left alone', () => {
    const original = {
        title: '<b>x</b>', text: '<b>plain</b>', titleText: '<b>plain</b>', icon: 'warning',
        showCancelButton: true, preConfirm: () => 1,
    };
    const clean = sanitizeSwalOptions(original, purify) as Record<string, unknown>;

    assert.equal(clean.text, '<b>plain</b>', 'text is set as text by SweetAlert2');
    assert.equal(clean.titleText, '<b>plain</b>');
    assert.equal(clean.icon, 'warning');
    assert.equal(clean.showCancelButton, true);
    assert.equal(clean.preConfirm, original.preConfirm);
    assert.equal(original.title, '<b>x</b>', 'the caller\'s options object is not mutated');
});

test('the short form fire(title, html, icon) cleans the first two and never the icon name', () => {
    assert.deepEqual(sanitizeSwalArgs(['<i>t</i>', '<i>h</i>', 'error'], purify), ['[clean:<i>t</i>]', '[clean:<i>h</i>]', 'error']);
    assert.deepEqual(sanitizeSwalArgs([{ title: 'a' }], purify), [{ title: '[clean:a]' }]);
    assert.deepEqual(sanitizeSwalArgs([], purify), []);
});

test('installing wraps fire and mixin, keeps a mixin subclass\'s own class, and is idempotent', () => {
    const seen: unknown[][] = [];
    class FakeSwal {
        args: unknown[];
        constructor(...args: unknown[]) { this.args = args; seen.push(args); }
        static fire(this: new (...a: unknown[]) => unknown, ...args: unknown[]) { return new this(...args); }
        static mixin(this: typeof FakeSwal, params: unknown) {
            const Base = this;
            return class Mixin extends Base { static defaults = params; };
        }
        update(params: unknown) { return params; }
    }

    installSwalSanitizer(FakeSwal, purify);
    installSwalSanitizer(FakeSwal, purify); // twice: must not double-wrap

    FakeSwal.fire({ title: 'Stop taking card payments for <img src=x onerror=alert(1)>?' });
    assert.deepEqual(seen.at(-1), [{ title: '[clean:Stop taking card payments for <img src=x onerror=alert(1)>?]' }]);

    const Mixin = FakeSwal.mixin({ confirmButtonText: '<b>ok</b>' }) as typeof FakeSwal & { defaults: unknown };
    assert.deepEqual(Mixin.defaults, { confirmButtonText: '[clean:<b>ok</b>]' });

    const made = Mixin.fire({ html: '<svg onload=alert(1)>' });
    assert.ok(made instanceof Mixin, 'fire through a mixin still builds the mixin, as SweetAlert2 does');
    assert.deepEqual(seen.at(-1), [{ html: '[clean:<svg onload=alert(1)>]' }]);

    assert.deepEqual(new FakeSwal().update({ footer: '<a onclick=x>' }), { footer: '[clean:<a onclick=x>]' });
});

test('the app installs it with DOMPurify before the app is created, so no dialog opens unguarded', () => {
    const main = readFileSync(new URL('../main.ts', import.meta.url), 'utf8');
    const installed = main.indexOf('installSwalSanitizer(Swal, (dirty) => DOMPurify.sanitize(dirty));');

    assert.ok(installed !== -1, 'main.ts installs the sanitizer with DOMPurify');
    assert.ok(installed < main.indexOf('createApp({'), 'before the app is created');
});
