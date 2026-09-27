/**
 * Step 3, Generate: the pure half (docs/manara-studio-w1.md S8, R7, R24, R27).
 *
 *  - generateBlockers(): what keeps the Provision button disabled. The web
 *    gate is R27's three items (a logo, a subdomain and an approved layout),
 *    worded as StudioBrandGate words its 422, so the operator reads the same
 *    sentence whether the SPA or the server stops them. The server checks all
 *    of it again; this is so the button says why before anyone presses it.
 *  - The BYO store credentials (R7). They are typed on this step, held only in
 *    the step's own memory, and leave the browser only inside the provision
 *    body that provisionBody() builds. Nothing here reaches the draft: the
 *    autosave body is draftAnswers.ts autosaveBody(), which knows nothing of
 *    them, and StudioSpaSourceTest pins that only this file and the fields
 *    component name them.
 *  - saveThenPost(): the order the store must keep. The autosave is flushed
 *    first, because the server provisions the draft it holds; if the answers
 *    on screen are still not the server's (the flush failed or conflicted),
 *    nothing is posted.
 *  - readProvisionOutcome(): the provision answer read into one outcome, so
 *    the results screen never guesses. A 201 without `capabilities_applied` is
 *    NOT success: a backend older than S8 ignores the draft's feature map and
 *    seeds its defaults, and saying "created" then would hand the client
 *    switches nobody chose (catalogue risk [2]). "Not created" is said only
 *    when the controller itself says so; a gateway's error page or no answer
 *    at all may follow a commit, and is reported as unknown.
 *  - inviteOutcome(): an invitation is called sent only when the server says
 *    one went and none failed; otherwise the reason, in words. It names the
 *    administrator the draft held when Provision was pressed, not whatever
 *    the answers say later.
 *
 * Only `import type`, so tests/studio-provision.test.ts runs it under node.
 */
import type {
    StudioAnswers,
    StudioCatalogue,
    StudioProvisionAfterCommit,
    StudioProvisionResult,
    StudioSaveState,
} from "@/core/types/data/Studio";

/** The platforms whose store account a client can bring (the wizard's Apps step). */
export type ByoPlatform = 'ios' | 'android';

/** What the operator types for a BYO platform. Never stored: see the file's docblock. */
export type ProvisionSecrets = {
    ios: { asc_key_p8: string; asc_key_id: string; asc_issuer_id: string };
    android: { play_service_account_json: string };
};

/**
 * The provision body (StudioProvisionRequest). `lock_version` is the version
 * of the draft this tab reviewed, so a save from elsewhere since is refused
 * rather than provisioned unseen. `secrets` only when a BYO platform needs them.
 */
export type ProvisionBody = {
    lock_version?: number;
    secrets?: {
        ios?: ProvisionSecrets['ios'];
        android?: ProvisionSecrets['android'];
    };
};

/** Who the provision invites, as the draft said when Provision was pressed. */
export type Invitee = { email: string | null; existingUserId: number | null };

export type ProvisionOutcome =
    /** The organisation exists and the server confirmed every Studio step it ran. */
    | { kind: 'created'; result: StudioProvisionResult; invitee: Invitee }
    /** The organisation exists, but the server did not confirm the feature choices. */
    | { kind: 'unconfirmed'; masjidId: number | null; message: string }
    /** The draft was already an organisation; nothing new was created. */
    | { kind: 'conflict'; masjidId: number | null }
    /** The draft changed after this tab reviewed it (409); nothing was written. */
    | { kind: 'changed'; message: string }
    /** Refused before anything was written (422). */
    | { kind: 'invalid'; messages: string[] }
    /** Nothing was committed: the controller said so (its own 500), the draft is gone, or the answers were not saved. */
    | { kind: 'failed'; message: string }
    /** No answer, or one that does not say: the organisation may exist, and a retry finds out. */
    | { kind: 'unknown'; message: string };

export type InviteOutcome = { sent: boolean; text: string };

/** Nobody named: what an outcome built without a snapshot reports. */
const NO_INVITEE: Invitee = { email: null, existingUserId: null };

export function emptySecrets(): ProvisionSecrets {
    return {
        ios: { asc_key_p8: '', asc_key_id: '', asc_issuer_id: '' },
        android: { play_service_account_json: '' },
    };
}

/** Blank every typed credential in place, so nothing lingers once it has been sent. */
export function clearSecrets(secrets: ProvisionSecrets): void {
    Object.assign(secrets.ios, emptySecrets().ios);
    Object.assign(secrets.android, emptySecrets().android);
}

/**
 * Whether the typed credentials are finished with (R7): once an organisation
 * exists (this provision's, or the one a 409 found), nothing will send them
 * again. Kept after a refusal or an unknown answer, so a retry needs no
 * retyping; a changed draft reloads the step, which drops them anyway.
 */
export function clearsSecrets(outcome: ProvisionOutcome | null): boolean {
    return outcome?.kind === 'created' || outcome?.kind === 'unconfirmed' || outcome?.kind === 'conflict';
}

function selected(answers: StudioAnswers): string[] {
    return answers.platforms.platforms ?? [];
}

/**
 * The selected platforms set to "Bring your own", in the Apps step's order.
 * tvOS ships under the iOS account and the website has no store, so only these
 * two ever ask for credentials.
 */
export function byoPlatforms(answers: StudioAnswers): ByoPlatform[] {
    const apps = answers.platforms.apps ?? {};
    return (['ios', 'android'] as const).filter((platform) =>
        selected(answers).includes(platform) && apps[platform]?.account_mode === 'byo');
}

function blank(value: string | null | undefined): boolean {
    return !value || !value.trim();
}

function parsesAsJson(value: string): boolean {
    try {
        JSON.parse(value);
        return true;
    } catch {
        return false;
    }
}

/**
 * What a BYO platform still needs. The server refuses the same gaps with the
 * wizard's `required_if` rules (ProvisionMasjidRequest), so this only says it
 * sooner.
 */
export function secretsBlockers(answers: StudioAnswers, secrets: ProvisionSecrets): string[] {
    const reasons: string[] = [];
    const byo = byoPlatforms(answers);

    if (byo.includes('ios')) {
        if (blank(secrets.ios.asc_key_p8)) reasons.push('Paste the App Store Connect .p8 key: iOS uses the client\'s own account.');
        if (blank(secrets.ios.asc_key_id)) reasons.push('Enter the App Store Connect key ID.');
        if (blank(secrets.ios.asc_issuer_id)) reasons.push('Enter the App Store Connect issuer ID.');
    }

    if (byo.includes('android')) {
        const json = secrets.android.play_service_account_json;
        if (blank(json)) {
            reasons.push('Paste the Google Play service-account JSON: Android uses the client\'s own account.');
        } else if (!parsesAsJson(json.trim())) {
            reasons.push('The Google Play service-account JSON does not parse as JSON.');
        }
    }

    return reasons;
}

/** The iqama offsets in prayer order, with the names ProvisionMasjidRequest::IQAMA_PRAYERS gives them. */
export const IQAMA_PRAYERS = [
    ['fajr', 'Fajr'], ['dhuhr', 'Dhuhr'], ['asr', 'Asr'], ['maghrib', 'Maghrib'], ['isha', 'Isha'],
] as const;

export type IqamaStatus =
    /** Not a masjid: never asked, never shown. */
    | { state: 'not_asked' }
    /** "Client has not given iqama times" is ticked: hidden. */
    | { state: 'not_given' }
    /** Nothing typed: hidden, and nothing invented. */
    | { state: 'none' }
    /** Some typed: the server refuses the draft until the rest are, or the box is ticked. */
    | { state: 'partial'; missing: string[] }
    /** All five: shown. */
    | { state: 'given' };

/** As the server's `filled()`: null and blank are not given; 0 is. */
function filled(value: unknown): boolean {
    return value !== null && value !== undefined && String(value).trim() !== '';
}

/**
 * Whether the client gave `salah`'s iqama: minutes after adhan or a fixed
 * clock time. ProvisionMasjidRequest::iqamaGiven is the server's copy, and
 * StudioDraft::showsIqama and the request both ask it.
 */
export function iqamaGiven(answers: StudioAnswers, salah: typeof IQAMA_PRAYERS[number][0]): boolean {
    return filled(answers.prayer.iqama?.[salah]) || filled(answers.prayer.iqama_fixed?.[salah]);
}

/** The prayers given a fixed time, in prayer order, with their display names. */
export function fixedIqamaPrayers(answers: StudioAnswers): string[] {
    return IQAMA_PRAYERS.filter(([key]) => filled(answers.prayer.iqama_fixed?.[key])).map(([, name]) => name);
}

/**
 * What the draft's iqama answers amount to, as StudioDraft::toProvisionPayload
 * and the request read them (DECISIONS, S8 "Iqama"). `asked` is whether the
 * organisation type has the Prayer panel (foundationGate.ts asksPrayer).
 */
export function iqamaStatus(answers: StudioAnswers, asked: boolean): IqamaStatus {
    if (!asked) return { state: 'not_asked' };
    if (answers.prayer.iqama_given === false) return { state: 'not_given' };

    const missing = IQAMA_PRAYERS
        .filter(([key]) => !iqamaGiven(answers, key))
        .map(([, name]) => name);

    if (missing.length === IQAMA_PRAYERS.length) return { state: 'none' };
    return missing.length ? { state: 'partial', missing } : { state: 'given' };
}

/** ProvisionMasjidRequest::FIXED_IQAMA_MAX_DAYS: how far ahead the until-date may be. */
export const FIXED_IQAMA_MAX_DAYS = 400;

/**
 * Today where the organisation is, YYYY-MM-DD, as ProvisionMasjidRequest::
 * organisationToday reads it: the draft's timezone, or UTC (the app's) when
 * it has none the browser knows.
 */
export function organisationToday(timezone: string | null | undefined, now: Date = new Date()): string {
    let parts: Intl.DateTimeFormatPart[];
    try {
        parts = new Intl.DateTimeFormat('en-CA', { timeZone: timezone || 'UTC', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(now);
    } catch {
        parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'UTC', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(now);
    }
    const read = (type: string) => parts.find((part) => part.type === type)?.value ?? '';
    return `${read('year')}-${read('month')}-${read('day')}`;
}

/** YYYY-MM-DD, `days` after the YYYY-MM-DD `date`. */
export function addDays(date: string, days: number): string {
    const [year, month, day] = date.split('-').map(Number);
    return new Date(Date.UTC(year, month - 1, day + days)).toISOString().slice(0, 10);
}

/**
 * Everything in the Prayer panel that keeps Provision disabled, in the
 * request's words (ProvisionMasjidRequest::iqamaIncomplete and messages()):
 * some iqama times without the rest, fixed times without a valid until-date,
 * and a Jumu'ah time entered twice. Nothing for an organisation not asked, and
 * no fixed-time reason once "Client has not given iqama times" is ticked,
 * because StudioDraft::toProvisionPayload then sends no fixed times.
 */
export function iqamaBlockers(answers: StudioAnswers, asked: boolean, now: Date = new Date()): string[] {
    const status = iqamaStatus(answers, asked);
    const reasons: string[] = [];

    if (status.state === 'partial') {
        const verb = status.missing.length === 1 ? 'is' : 'are';
        reasons.push(`The iqama times are incomplete: ${status.missing.join(', ')} ${verb} missing. `
            + 'Enter all five in Foundation, or tick "Client has not given iqama times".');
    }

    if (status.state !== 'not_asked' && status.state !== 'not_given' && fixedIqamaPrayers(answers).length) {
        const until = answers.prayer.iqama_fixed_until;
        const today = organisationToday(answers.identity.timezone, now);
        if (!filled(until)) {
            reasons.push('Enter the date the fixed iqama times hold until, in Foundation.');
        } else if (!/^\d{4}-\d{2}-\d{2}$/.test(String(until)) || String(until) < today || String(until) > addDays(today, FIXED_IQAMA_MAX_DAYS)) {
            reasons.push(`The date the fixed iqama times hold until must be between today and ${FIXED_IQAMA_MAX_DAYS} days from today.`);
        }
    }

    if (asked) {
        const times = (answers.prayer.jumaa_times ?? []).filter(filled);
        if (new Set(times).size !== times.length) reasons.push("The Jumu'ah times must all be different.");
    }

    return reasons;
}

/**
 * R27: a website needs the client's logo, a subdomain and an approved layout.
 * Without a preset no `home` page is written, and the renderer's home route
 * spins forever on a page with no active section. Worded as StudioBrandGate.
 */
export function webGateBlockers(answers: StudioAnswers, hasLogo: boolean): string[] {
    if (!selected(answers).includes('web')) return [];

    const reasons: string[] = [];
    if (!hasLogo) reasons.push('A website needs the client\'s logo. Upload it in Foundation.');
    if (blank(answers.identity.slug)) reasons.push('A website needs a subdomain. Choose one in Foundation.');
    if (blank(answers.layout.preset) || blank(answers.layout.approved_at)) {
        reasons.push('A website needs an approved layout. Approve one in Layout.');
    }
    return reasons;
}

/**
 * Whether Step 1's map of served keys is there (R9). The Features step writes
 * it as soon as its catalogue loads; a draft that skipped the step has none,
 * and StudioProvisioning then sends an empty map, so every switch would start
 * at its default without anyone having looked at them.
 */
export function featureMapBlockers(answers: StudioAnswers): string[] {
    const map = answers.features.capabilities;
    return map && Object.keys(map).length
        ? []
        : ['The feature choices are not saved yet. Open Features and let the list load: without them every switch starts at its default.'];
}

/** Everything that keeps Provision disabled, one sentence each; empty when it may be pressed. */
export function generateBlockers(answers: StudioAnswers, hasLogo: boolean, secrets: ProvisionSecrets): string[] {
    return [
        ...featureMapBlockers(answers),
        ...webGateBlockers(answers, hasLogo),
        ...secretsBlockers(answers, secrets),
    ];
}

/**
 * The provision body: the version of the draft reviewed, and the credentials
 * of each selected BYO platform, trimmed, and nothing for any other platform.
 * The draft itself is read on the server, so the answers are not sent again.
 */
export function provisionBody(answers: StudioAnswers, secrets: ProvisionSecrets, lockVersion?: number): ProvisionBody {
    const body: ProvisionBody = lockVersion === undefined ? {} : { lock_version: lockVersion };
    const byo = byoPlatforms(answers);
    if (!byo.length) return body;

    const out: NonNullable<ProvisionBody['secrets']> = {};
    if (byo.includes('ios')) {
        out.ios = {
            asc_key_p8: secrets.ios.asc_key_p8.trim(),
            asc_key_id: secrets.ios.asc_key_id.trim(),
            asc_issuer_id: secrets.ios.asc_issuer_id.trim(),
        };
    }
    if (byo.includes('android')) {
        out.android = { play_service_account_json: secrets.android.play_service_account_json.trim() };
    }
    return { ...body, secrets: out };
}

/** The autosave's state at the moment of asking, for unsavedReason(). */
export type SaveSnapshot = {
    saveState: StudioSaveState;
    /** Sections edited here that the server does not have. */
    unsaved: number;
    conflictMessage: string | null;
    saveError: string | null;
};

/** Why the server does not hold the answers this tab shows, or null when it does. */
export function unsavedReason(state: SaveSnapshot): string | null {
    if (state.saveState !== 'error' && state.saveState !== 'conflict' && state.unsaved === 0) return null;

    const reason = state.saveState === 'conflict' ? state.conflictMessage : state.saveError;
    return `The latest answers are not saved, so nothing was created.${reason ? ` ${reason}` : ''}`;
}

export type ProvisionSteps<T> = {
    /** Send every unsaved edit now (the store's flush). */
    flush: () => Promise<void>;
    /** False once the draft was closed or another opened meanwhile. */
    stillOpen: () => boolean;
    save: () => SaveSnapshot;
    /** The provision POST itself. */
    post: () => Promise<T>;
};

export type SaveThenPost<T> =
    | { kind: 'closed' }
    | { kind: 'unsaved'; message: string }
    | { kind: 'posted'; answer: T };

/**
 * Step 3's order of effects, kept out of the store so it can be tested: the
 * server provisions the draft it holds, so the edits still on their way go
 * first, and if they did not arrive (a failed or conflicting save) nothing is
 * posted. Posting first, or regardless, would build the organisation from
 * answers the operator never saw saved: an old name, an old administrator.
 */
export async function saveThenPost<T>(steps: ProvisionSteps<T>): Promise<SaveThenPost<T>> {
    await steps.flush();
    if (!steps.stillOpen()) return { kind: 'closed' };

    const message = unsavedReason(steps.save());
    if (message) return { kind: 'unsaved', message };

    return { kind: 'posted', answer: await steps.post() };
}

function isObject(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function idOf(value: unknown): number | null {
    return typeof value === 'number' && Number.isInteger(value) ? value : null;
}

/** Every message of the legacy 422 envelope (`data: {field: [messages]}`), in order, once each. */
export function validationMessages(data: unknown): string[] {
    const out: string[] = [];
    const add = (value: unknown) => {
        if (typeof value === 'string' && value.trim() && !out.includes(value)) out.push(value);
        else if (Array.isArray(value)) value.forEach(add);
        else if (isObject(value)) Object.values(value).forEach(add);
    };
    add(data);
    return out;
}

function appliedIsValid(value: unknown): boolean {
    return isObject(value) && Array.isArray(value.changed) && Array.isArray(value.unchanged);
}

/** Who the provision will invite, read from the answers the server is about to provision. */
export function inviteeOf(answers: StudioAnswers): Invitee {
    return {
        email: answers.identity.admin?.email?.trim() || null,
        existingUserId: answers.identity.user_id ?? null,
    };
}

const RETRY_FINDS_OUT = 'Press Provision again to find out: if the organisation was created, the server says so '
    + 'instead of creating another.';

/**
 * The provision answer as one outcome. `status` is undefined when no answer
 * arrived. The body is the whole response (`{status, data}` from the
 * controller; anything at all from a proxy in front of it). `invitee` is who
 * the draft named when Provision was pressed.
 */
export function readProvisionOutcome(status: number | undefined, body: unknown, invitee: Invitee = NO_INVITEE): ProvisionOutcome {
    const data = isObject(body) ? body.data : undefined;

    if (status === undefined) {
        return { kind: 'unknown', message: `No answer came back from the server. ${RETRY_FINDS_OUT}` };
    }

    if (status === 201 || status === 200) {
        const result = isObject(data) ? data : null;
        const masjidId = idOf(result?.masjid_id);
        if (result && masjidId !== null && appliedIsValid(result.capabilities_applied)) {
            return { kind: 'created', result: result as unknown as StudioProvisionResult, invitee };
        }
        return {
            kind: 'unconfirmed',
            masjidId,
            message: 'The organisation was created, but the server did not confirm that the feature choices were applied. '
                + 'It may be running an older version that ignores them and turns on the default features. '
                + 'Check the organisation\'s features before handing it over.',
        };
    }

    if (status === 409) {
        const masjidId = isObject(data) ? idOf(data.provisioned_masjid_id) : null;
        if (masjidId !== null) return { kind: 'conflict', masjidId };

        // No organisation: the draft changed after it was reviewed (StudioDraftChanged).
        const message = isObject(body) && typeof body.message === 'string' && body.message.trim()
            ? body.message
            : 'This draft changed while it was being provisioned, so nothing was created. Review the latest answers and press Provision again.';
        return { kind: 'changed', message };
    }

    if (status === 422) {
        const messages = validationMessages(data);
        return { kind: 'invalid', messages: messages.length ? messages : ['The server refused the draft without saying why.'] };
    }

    if (status === 404) {
        return { kind: 'failed', message: 'This draft no longer exists: it was discarded, so nothing was created.' };
    }

    // Only the controller's own envelope promises that nothing was committed.
    if (status === 500 && isObject(body) && body.status === 'error') {
        const reason = typeof data === 'string' && data.trim() ? data : 'The server gave no reason.';
        return { kind: 'failed', message: `The organisation was not created. ${reason}` };
    }

    // A proxy's error page (502, 504) or a failure after the commit: PHP may
    // have created the organisation and sent the invitation all the same.
    return {
        kind: 'unknown',
        message: `The server's answer (HTTP ${status}) does not say whether the organisation was created. ${RETRY_FINDS_OUT}`,
    };
}

/**
 * Where keyboard focus goes once an outcome is drawn: the Provision button is
 * gone (created, conflict, a reloaded draft) or disabled while it ran, and
 * focus left on the page body announces nothing. The Created and Already
 * provisioned panels' headings (StudioPanel ids), or the outcome's own alert.
 */
export function outcomeFocusId(outcome: ProvisionOutcome | null): string | null {
    if (!outcome) return null;
    if (outcome.kind === 'created') return 'studio-panel-created';
    if (outcome.kind === 'conflict') return 'studio-panel-already-provisioned';
    return 'studio-generate-outcome';
}

/**
 * The invitation line of the results. Sent only when the server says at least
 * one went and none failed. The provisioner invites only an administrator it
 * created from `admin.email` (an existing `user_id` is not invited), which is
 * what the "none sent" sentences say.
 */
export function inviteOutcome(afterCommit: StudioProvisionAfterCommit | null | undefined, invitee: Invitee): InviteOutcome {
    if (!afterCommit) {
        return {
            sent: false,
            text: 'The server did not report the invitation. Check the organisation\'s Team & Access screen before telling the client.',
        };
    }

    const { invites_sent: sent, invites_failed: failed } = afterCommit;
    const email = invitee.email;

    if (sent > 0 && failed === 0) {
        return { sent: true, text: sent === 1 && email ? `Invitation sent to ${email}.` : `${sent} invitations sent.` };
    }

    if (failed > 0) {
        return {
            sent: false,
            text: failed === 1 ? 'The invitation was not sent.' : `${failed} invitations were not sent.`,
        };
    }

    return {
        sent: false,
        text: invitee.existingUserId
            ? 'No invitation was sent: an existing account was made the administrator.'
            : 'No invitation was sent: the draft gives no administrator email.',
    };
}

/**
 * Step 1's map counted for the review: how many switches are on, and how many
 * differ from the catalogue's `default_at_creation` (what the writer ledgers).
 * `departures` is null while the catalogue is not loaded.
 */
export function featureSummary(map: Record<string, boolean> | null | undefined, catalogue: StudioCatalogue | null): { on: number; total: number; departures: number | null } {
    const entries = Object.entries(map ?? {});
    const defaults = new Map<string, boolean>();
    for (const group of catalogue?.groups ?? []) {
        for (const entry of group.entries) defaults.set(entry.key, entry.default_at_creation);
    }

    return {
        on: entries.filter(([, on]) => on).length,
        total: entries.length,
        departures: catalogue
            ? entries.filter(([key, on]) => defaults.has(key) && defaults.get(key) !== on).length
            : null,
    };
}
