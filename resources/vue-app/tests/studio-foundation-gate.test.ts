/**
 * What blocks Next on Studio's Foundation step (core/studio/foundationGate.ts),
 * above all the logo rule: web selected and no logo. Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { foundationBlockers, logoRequired } from '../core/studio/foundationGate.ts';
import { normaliseAnswers } from '../core/studio/draftAnswers.ts';

const complete = () => normaliseAnswers({
    identity: { org_type: 'school', name: 'Al-Noor Academy' },
    brand: { primary_color: '#0a3d62', secondary_color: '#1e272e', accent_color: '#f6b93b', background_color: '#ffffff' },
    platforms: { platforms: ['ios', 'web'] },
});

test('a complete foundation with a logo may go on', () => {
    assert.deepEqual(foundationBlockers(complete(), true), []);
});

test('with web selected and no logo, Next is blocked and says why', () => {
    assert.deepEqual(foundationBlockers(complete(), false), ['Upload a logo: the website needs one.']);
    assert.equal(logoRequired(complete(), false), true);
});

test('without web, no logo is needed', () => {
    const answers = complete();
    answers.platforms.platforms = ['ios', 'android'];

    assert.deepEqual(foundationBlockers(answers, false), []);
});

test('a new draft has no colours and must be given all four', () => {
    const answers = complete();
    delete answers.brand.accent_color;
    answers.brand.primary_color = '#0a3d6';

    assert.deepEqual(foundationBlockers(answers, true), ['Choose all four brand colours.']);
});

test('an empty draft lists every reason', () => {
    assert.equal(foundationBlockers(normaliseAnswers({}), false).length, 4);
});
