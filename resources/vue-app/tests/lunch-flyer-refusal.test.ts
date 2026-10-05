/**
 * A refused lunch flyer says why, in the server's words, where the person is looking.
 *
 * The flyer upload answers a refused file as a field-keyed 422
 * ({ status: 'failed', data: { flyer: ['…'] } }). The board's own `serverReason()` reads a
 * string `data` or a `message`, so for that envelope it fell through to axios's "Request
 * failed with status code 422", and the sentence that says what to do with the file (rename
 * it) never reached the person who picked it.
 *
 * It is not a toast either. The menu dialog's overlay (`.jl-modal`, z-index 1080) is stacked
 * above the toast's container, so a toast raised while the dialog is open sits behind it, and
 * at phone width the dialog covers it completely (seen in a browser, 2026-10-05). The sentence
 * goes under the file input, the way the order, edit and paid dialogs show theirs.
 *
 * `.vue` files cannot be loaded by `node --test`, so the view is read as source text.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { serverFieldErrors } from '../core/helpers/serverMessage.ts';

const appRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const repoRoot = path.resolve(appRoot, '..', '..');
const view = readFileSync(path.join(appRoot, 'views/dashboard/JummahLunchView.vue'), 'utf8');
const controller = readFileSync(
    path.join(repoRoot, 'app/Http/Controllers/AdminDashboard/MealMenusController.php'),
    'utf8',
);

/** The sentence the server sends for a flyer whose NAME is refused, read from the controller. */
const sentence = (() => {
    const found = controller.match(/'flyer\.extensions'\s*=>\s*'((?:[^'\\]|\\.)*)'/);
    assert.ok(found, 'MealMenusController no longer gives flyer.extensions its own sentence');
    return found[1].replace(/\\'/g, "'");
})();

/** `onFlyerFile`, from its first line to the next top-level function. */
const onFlyerFile = (() => {
    const start = view.indexOf('async function onFlyerFile(');
    assert.ok(start > -1, 'JummahLunchView no longer has onFlyerFile');
    return view.slice(start, view.indexOf('\nasync function ', start + 1));
})();

test('the sentence says what the name must end in and what to do about it', () => {
    assert.match(sentence, /file name must end in \.jpg, \.jpeg, \.png or \.webp\./);
    assert.match(sentence, /Rename the file and upload it again\.$/);
});

test('the refusal as the server sends it is read as the flyer field\'s own messages', () => {
    const refused = { response: { status: 422, data: { status: 'failed', data: { flyer: [sentence] } } } };

    assert.deepEqual(serverFieldErrors(refused).flyer, [sentence]);
});

test('any other failure carries no field messages, so it is shown as it was before', () => {
    const off = { response: { status: 403, data: { status: 'error', message: 'Friday lunch ordering is not switched on for this organisation.' } } };
    const broke = { response: { status: 500, data: { status: 'failed', data: 'Something went wrong.' } } };
    const offline = { message: 'Network Error' };

    for (const error of [off, broke, offline]) {
        assert.equal(serverFieldErrors(error).flyer, undefined);
    }
});

test('the board puts a refused flyer\'s own messages under the file input, not in a toast', () => {
    assert.match(view, /import \{ serverFieldErrors \} from "@\/core\/helpers\/serverMessage";/);
    assert.match(onFlyerFile, /const refused = serverFieldErrors\(err\)\.flyer;/);
    assert.match(onFlyerFile, /if \(refused\?\.length\) flyerError\.value = refused\.join\(" "\);/);
    // Anything that is not a refusal of the file is shown as it always was.
    assert.match(onFlyerFile, /else toastError\(err\);/);

    // Under the input, announced, and inside the dialog rather than behind it.
    const input = view.indexOf('@change="onFlyerFile"');
    const line = view.indexOf('<div v-if="flyerError" class="alert alert-danger py-2 mt-2 mb-0" role="alert">{{ flyerError }}</div>');
    assert.ok(input > -1 && line > input, 'the refusal is not shown after the flyer input');
    assert.ok(line - input < 400, 'the refusal is not beside the flyer input');
});

test('a refusal does not outlive its file: the next pick and a reopened dialog both clear it', () => {
    // Cleared before the upload is tried, so a second, accepted file is not left under the first one's refusal.
    assert.match(onFlyerFile, /uploadingFlyer\.value = true;\s+flyerError\.value = "";\s+try \{/);

    // Both ways the dialog opens.
    const opens = [...view.matchAll(/menuModal\.show = true;/g)].map((m) => m.index as number);
    assert.equal(opens.length, 2, 'the menu dialog is opened somewhere this test does not know about');
    for (const at of opens) {
        assert.match(view.slice(at - 60, at), /flyerError\.value = "";\s*$/, 'a way of opening the dialog keeps the last refusal');
    }
});
