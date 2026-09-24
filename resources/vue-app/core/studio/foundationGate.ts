/**
 * What stops the operator leaving Foundation (Step 0) for Features and Layout.
 *
 * Each rule is there because a later step reads the answer:
 *  - the organisation type picks the catalogue (Step 1) and the layout presets
 *    (Step 2) the server offers;
 *  - the name is on every mockup;
 *  - a new draft has no colours and must be given four (R25): a default would
 *    be a live client's palette, shipped by accident;
 *  - the platforms decide which mockups Step 2 draws and which features are
 *    preselected;
 *  - the website needs a logo (the logo rule, docs/manara-studio-w1.md S5; S8
 *    enforces the same rule on the server), because the renderer shows none
 *    in its place.
 *
 * Pure and import-free apart from types, so tests/studio-foundation-gate.test.ts
 * runs it under node.
 */
import type { StudioAnswers, StudioColourKey } from "@/core/types/data/Studio";
import type { OrgType } from "@/core/types/data/Vertical";

/**
 * The one organisation type Studio asks prayer settings of, as the server
 * decides it (StudioPreview: the tvOS prayer panel is `$org->isMasjid()`).
 */
export const PRAYER_ORG_TYPE: OrgType = 'masjid';

/** Whether the Prayer panel applies to the chosen organisation type. */
export function asksPrayer(answers: StudioAnswers): boolean {
    return answers.identity.org_type === PRAYER_ORG_TYPE;
}

export const BRAND_COLOUR_KEYS: StudioColourKey[] = ['primary_color', 'secondary_color', 'accent_color', 'background_color'];

const HEX6 = /^#[0-9a-fA-F]{6}$/;

export function isHex6(value: unknown): boolean {
    return typeof value === 'string' && HEX6.test(value);
}

/** True when the draft's platforms include the website. */
export function webSelected(answers: StudioAnswers): boolean {
    return (answers.platforms.platforms ?? []).includes('web');
}

/** The logo rule on its own, for the Brand panel to say it where the upload is. */
export function logoRequired(answers: StudioAnswers, hasLogo: boolean): boolean {
    return webSelected(answers) && !hasLogo;
}

/** Why Next is blocked, one sentence per reason; empty when the operator may go on. */
export function foundationBlockers(answers: StudioAnswers, hasLogo: boolean): string[] {
    const reasons: string[] = [];

    if (!answers.identity.org_type) {
        reasons.push('Choose the organisation type.');
    }
    if (!answers.identity.name?.trim()) {
        reasons.push("Enter the organisation's name.");
    }
    if (!BRAND_COLOUR_KEYS.every((key) => isHex6(answers.brand[key]))) {
        reasons.push('Choose all four brand colours.');
    }
    if (!(answers.platforms.platforms ?? []).length) {
        reasons.push('Choose at least one platform.');
    }
    if (logoRequired(answers, hasLogo)) {
        reasons.push('Upload a logo: the website needs one.');
    }

    return reasons;
}
