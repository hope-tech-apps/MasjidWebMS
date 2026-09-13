/**
 * Volunteer credentials (T-023, Community vertical) — one credential held by
 * one Contact: a medical licence, a background check, a BLS card. The pilot is
 * a free clinic staffed by licensed volunteers.
 *
 * Everything below exists on the server already
 * (`App\Models\ContactCredential`, `ContactCredentialsController::serialize`).
 * These shapes are deliberately THIN MIRRORS and add no rules of their own,
 * because three of `.claude/rules/credentials.md`'s non-negotiables are exactly
 * the things a TypeScript file is tempted to re-implement:
 *
 *  1. `kind` is a PHP constant set (`ContactCredential::KINDS`), never a DB
 *     enum. `CredentialKind` below is a `string`, NOT a literal union, and the
 *     screen renders its `<select>` from `meta.kinds` — the server's own
 *     answer. A union here would compile fine and silently drop any kind added
 *     on the server until somebody remembered to edit this file too.
 *  2. `status` is DERIVED SERVER-SIDE from `expires_at` against
 *     `config('credentials.expiring_within_days')`. It arrives on the payload.
 *     NOTHING in the SPA may compute it: a second copy of the window rule
 *     agrees on the day it is written and drifts the first time the config
 *     moves, and a stale "valid" badge on an expired background check is a
 *     safeguarding failure, not a rendering bug.
 *  3. The scanned document has NO public URL and never gets one. The only
 *     field pointing at bytes is `download_url`, which addresses the
 *     authenticated endpoint that re-resolves masjid -> contact -> credential
 *     before anything leaves (`.claude/rules/private-uploads.md`). There is
 *     deliberately no `path`, no `disk`, and no `/storage/...` anywhere in
 *     this file — the model hides those columns for the same reason.
 *
 * `identifier` is a licence number. It is `encrypted` at rest and decrypts into
 * the admin payload, so it is a plain `string | null` here — but the screen
 * masks it by default. Never send one to a console, an analytics call or a URL.
 */

/**
 * A credential kind, as the server names it (`medical_license`,
 * `background_check`, …, `other`).
 *
 * A `string`, on purpose — see note 1 above. The authority is `meta.kinds`.
 */
export type CredentialKind = string;

/**
 * The derived status, as the server computed it.
 *
 * A union is safe here in a way it is not for `kind`, because these three are
 * the DERIVED vocabulary of an accessor rather than an extensible catalogue —
 * but the screen still reads `meta.statuses` for anything it enumerates, and
 * every lookup keyed on this falls back to a neutral rendering so an unforeseen
 * fourth status shows up plainly instead of vanishing.
 */
export type CredentialStatus = 'valid' | 'expiring' | 'expired';

/**
 * The scanned document, as `ContactCredentialsController::serialize()` ships
 * it. `null` on a credential that has no scan, which is normal.
 *
 * `download_url` is an `/api/admin/...` path behind
 * `auth:sanctum + admin + tenant + crm + permission:view contacts`. A plain
 * `<a href>` to it would 401 — the SPA's bearer token travels on the axios
 * instance, not on a browser navigation. Fetch it as a blob.
 */
export type CredentialDocument = {
    file_name: string;
    mime_type: string;
    size_bytes: number;
    download_url: string;
};

/** One credential, as the admin endpoints serve it. */
export type ContactCredential = {
    id: number;
    masjid_id: number;
    contact_id: number;
    kind: CredentialKind;
    /** Free text naming the credential; REQUIRED server-side when kind is `other`. */
    label: string | null;
    issuing_body: string | null;
    /** The licence number. Encrypted at rest, decrypted here, masked on screen. */
    identifier: string | null;
    /** Y-m-d at heart, serialised as ISO8601. Never parse it as a UTC instant for display. */
    issued_at: string | null;
    /** `null` means NON-EXPIRING (a one-time check, a lifetime cert) — not "unknown". */
    expires_at: string | null;
    notes: string | null;
    /** Derived server-side. Read it; never recompute it. */
    status: CredentialStatus;
    created_at: string;
    updated_at: string;
    document: CredentialDocument | null;
};

/**
 * `meta` on every credential endpoint — "the vocabulary the SPA needs to render
 * a credential form without hardcoding it" (the controller's own words).
 *
 * `expiring_within_days` is the window the server's status accessor uses. The
 * screen labels its filter chip with this NUMBER rather than the word "30", so
 * the chip and the badges can never disagree about what "expiring" means.
 */
export type CredentialMeta = {
    kinds: CredentialKind[];
    statuses: CredentialStatus[];
    expiring_within_days: number;
    /**
     * The document allowlist, exactly as `config('credentials.document.mime_types')`
     * holds it — the same list the `mimetypes` rule enforces against the type
     * SNIFFED from the uploaded bytes.
     *
     * Here for one reason: the form binds its file input's `accept` to it. That
     * attribute used to be the literal `.pdf,image/jpeg,image/png` in the
     * template, which is a second copy of a list the config file says is
     * "comma-separated in .env so a tenant-specific need can be met without a
     * deploy". On a fleet that added, say, `image/heic` for phone-camera scans,
     * the server accepted the scan and the file picker greyed it out — no
     * error, nothing on screen, and no way for the admin to learn why the file
     * could not be chosen.
     *
     * Optional so an SPA built against an older server degrades to an
     * unfiltered picker rather than to a wrong filter.
     */
    document_mime_types?: string[];
};

/**
 * What the add/edit form collects.
 *
 * Sent as `FormData` because a credential may carry a file, and posted rather
 * than PUT for the same reason (PHP does not parse a multipart PUT body — the
 * store spoofs the verb with `_method`, as SectionFormModal already does).
 *
 * `document` is `File | null`: null means "leave whatever is stored alone" on
 * an edit. There is no "remove the document" verb — the server keeps exactly
 * one scan per credential and replacing it deletes the old bytes.
 */
export type CredentialPayload = {
    kind: CredentialKind;
    label: string;
    issuing_body: string;
    identifier: string;
    issued_at: string;
    expires_at: string;
    notes: string;
    document: File | null;
};
