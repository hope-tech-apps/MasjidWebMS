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
 *  - readProvisionOutcome(): the provision answer read into one of five
 *    outcomes, so the results screen never guesses. A 201 without
 *    `capabilities_applied` is NOT success: a backend older than S8 ignores
 *    the draft's feature map and seeds its defaults, and saying "created" then
 *    would hand the client switches nobody chose (catalogue risk [2]).
 *  - inviteOutcome(): an invitation is called sent only when the server says
 *    one went and none failed; otherwise the reason, in words.
 *
 * Only `import type`, so tests/studio-provision.test.ts runs it under node.
 */
import type {
    StudioAnswers,
    StudioCatalogue,
    StudioProvisionAfterCommit,
    StudioProvisionResult,
} from "@/core/types/data/Studio";

/** The platforms whose store account a client can bring (the wizard's Apps step). */
export type ByoPlatform = 'ios' | 'android';

/** What the operator types for a BYO platform. Never stored: see the file's docblock. */
export type ProvisionSecrets = {
    ios: { asc_key_p8: string; asc_key_id: string; asc_issuer_id: string };
    android: { play_service_account_json: string };
};

/** The provision body (StudioProvisionRequest). `secrets` only when a BYO platform needs them. */
export type ProvisionBody = {
    secrets?: {
        ios?: ProvisionSecrets['ios'];
        android?: ProvisionSecrets['android'];
    };
};

export type ProvisionOutcome =
    /** The organisation exists and the server confirmed every Studio step it ran. */
    | { kind: 'created'; result: StudioProvisionResult }
    /** The organisation exists, but the server did not confirm the feature choices. */
    | { kind: 'unconfirmed'; masjidId: number | null; message: string }
    /** The draft was already an organisation; nothing new was created. */
    | { kind: 'conflict'; masjidId: number | null }
    /** Refused before anything was written (422). */
    | { kind: 'invalid'; messages: string[] }
    /** Nothing was committed (500), or no answer arrived at all. */
    | { kind: 'failed'; message: string };

export type InviteOutcome = { sent: boolean; text: string };

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
 * The provision body: the credentials of each selected BYO platform, trimmed,
 * and nothing for any other platform. The draft itself is read on the server,
 * so the answers are not sent again.
 */
export function provisionBody(answers: StudioAnswers, secrets: ProvisionSecrets): ProvisionBody {
    const byo = byoPlatforms(answers);
    if (!byo.length) return {};

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
    return { secrets: out };
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

/**
 * The provision answer as one outcome. `status` is undefined when no answer
 * arrived. The body is the whole JSON response (`{status, data}`).
 */
export function readProvisionOutcome(status: number | undefined, body: unknown): ProvisionOutcome {
    const data = isObject(body) ? body.data : undefined;

    if (status === undefined) {
        return {
            kind: 'failed',
            message: 'No answer came back from the server. Trying again is safe: if the organisation was created, '
                + 'the server says so instead of creating another.',
        };
    }

    if (status === 201 || status === 200) {
        const result = isObject(data) ? data : null;
        const masjidId = idOf(result?.masjid_id);
        if (result && masjidId !== null && appliedIsValid(result.capabilities_applied)) {
            return { kind: 'created', result: result as unknown as StudioProvisionResult };
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
        return { kind: 'conflict', masjidId: isObject(data) ? idOf(data.provisioned_masjid_id) : null };
    }

    if (status === 422) {
        const messages = validationMessages(data);
        return { kind: 'invalid', messages: messages.length ? messages : ['The server refused the draft without saying why.'] };
    }

    const reason = typeof data === 'string' && data.trim() ? data : 'The server gave no reason.';
    return { kind: 'failed', message: `The organisation was not created. ${reason}` };
}

/**
 * The invitation line of the results. Sent only when the server says at least
 * one went and none failed. The provisioner invites only an administrator it
 * created from `admin.email` (an existing `user_id` is not invited), which is
 * what the "none sent" sentences say.
 */
export function inviteOutcome(afterCommit: StudioProvisionAfterCommit | null | undefined, answers: StudioAnswers): InviteOutcome {
    if (!afterCommit) {
        return {
            sent: false,
            text: 'The server did not report the invitation. Check the organisation\'s Team & Access screen before telling the client.',
        };
    }

    const { invites_sent: sent, invites_failed: failed } = afterCommit;
    const email = answers.identity.admin?.email?.trim();

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
        text: answers.identity.user_id
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
