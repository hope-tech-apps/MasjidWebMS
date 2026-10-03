/**
 * The TV Display screen's form, as pure functions so npm run test:spa can pin them
 * (tests/tv-display.test.ts). The server owns the truth
 * (GET and POST /api/admin/masjids/{id}/tv-display) and validates every save again.
 *
 * A stored setting is null until somebody changes it, and null means "automatic":
 * the board keeps doing what it does today. The three switches are stored as false
 * or null only. The server works out the prayer panel from the organisation type
 * and the QR code from the donation link, and a stored true would freeze them.
 */

/** What is stored; null = automatic. */
export interface TvDisplaySettings {
    is_enabled: boolean | null;
    header_title: string | null;
    carousel_interval_seconds: number | null;
    show_prayer_panel: boolean | null;
    show_qr: boolean | null;
    donate_caption: string | null;
}

/** What the board receives now. A null title means the board shows the organisation's name. */
export interface TvDisplayEffective {
    is_enabled: boolean;
    header_title: string | null;
    carousel_interval_seconds: number;
    show_prayer_panel: boolean;
    show_qr: boolean;
    donate_caption: string;
}

export interface TvDisplayLimits {
    header_title_max: number;
    donate_caption_max: number;
    carousel_interval_min: number;
    carousel_interval_max: number;
}

export interface TvDisplayContext {
    organisation_name: string;
    is_masjid: boolean;
    has_donation_link: boolean;
    defaults: { carousel_interval_seconds: number; donate_caption: string };
    limits: TvDisplayLimits;
    updated_at: string | null;
}

export interface TvDisplayPayload {
    settings: TvDisplaySettings;
    effective: TvDisplayEffective;
    context: TvDisplayContext;
}

export interface TvDisplayForm {
    slides: boolean;
    prayerPanel: boolean;
    qr: boolean;
    title: string;
    caption: string;
    /** Text, so a blank field can mean "automatic". A number input hands back a number; both are read. */
    seconds: string;
}

/** The POST body: always the six keys. Seconds that are not a whole number go as typed, for the server to refuse. */
export type TvDisplayBody = Omit<TvDisplaySettings, 'carousel_interval_seconds'> & { carousel_interval_seconds: number | string | null };

export type TvDisplayProblems = Partial<Record<'title' | 'caption' | 'seconds', string>>;

/** Also said by the screen when its number field holds text it cannot read. */
export const SECONDS_NOT_WHOLE = 'Seconds per slide must be a whole number.';

const WHOLE_NUMBER = /^-?\d+$/;
// One line: no line break, tab or other control character. The server refuses the same set.
const LINE_BREAK = /[\x00-\x1F\x7F]/;

const secondsText = (form: TvDisplayForm): string => String(form.seconds ?? '').trim();

/** Stored settings as the form shows them: a switch is on unless it was turned off, a blank field is automatic. */
export function formFrom(settings: TvDisplaySettings): TvDisplayForm {
    return {
        slides: settings.is_enabled !== false,
        prayerPanel: settings.show_prayer_panel !== false,
        qr: settings.show_qr !== false,
        title: settings.header_title ?? '',
        caption: settings.donate_caption ?? '',
        seconds: settings.carousel_interval_seconds == null ? '' : String(settings.carousel_interval_seconds),
    };
}

/**
 * The POST body. A switch that is ON goes as null, never true, and a blank field goes
 * as null, so a form nobody touched saves six nulls and changes nothing on the board.
 * Seconds that are not a whole number are never turned into null: that would read as
 * "automatic" and save over the organisation's number.
 */
export function saveBody(form: TvDisplayForm): TvDisplayBody {
    const title = form.title.trim();
    const caption = form.caption.trim();
    const seconds = secondsText(form);

    return {
        is_enabled: form.slides ? null : false,
        header_title: title === '' ? null : title,
        carousel_interval_seconds: seconds === '' ? null : WHOLE_NUMBER.test(seconds) ? Number(seconds) : seconds,
        show_prayer_panel: form.prayerPanel ? null : false,
        show_qr: form.qr ? null : false,
        donate_caption: caption === '' ? null : caption,
    };
}

/** Length is counted the way the server counts it: characters, after trimming. */
function textProblem(text: string, max: number, name: string): string | null {
    const sent = text.trim();
    if (LINE_BREAK.test(sent)) return `${name} must be on one line.`;

    const length = [...sent].length;
    if (length > max) return `${name} can be at most ${max} characters. You have ${length}.`;

    return null;
}

/** What must be fixed before saving, by field; empty when the form can be saved. */
export function formProblems(form: TvDisplayForm, limits: TvDisplayLimits): TvDisplayProblems {
    const problems: TvDisplayProblems = {};

    const title = textProblem(form.title, limits.header_title_max, 'The title');
    if (title) problems.title = title;

    const caption = textProblem(form.caption, limits.donate_caption_max, 'The words under the QR code');
    if (caption) problems.caption = caption;

    const seconds = secondsText(form);
    if (seconds !== '') {
        if (!WHOLE_NUMBER.test(seconds)) {
            problems.seconds = SECONDS_NOT_WHOLE;
        } else if (Number(seconds) < limits.carousel_interval_min || Number(seconds) > limits.carousel_interval_max) {
            problems.seconds = `Seconds per slide must be between ${limits.carousel_interval_min} and ${limits.carousel_interval_max}.`;
        }
    }

    return problems;
}

/** Whether two forms would save the same thing; the screen has nothing to save while this holds. */
export function sameForm(a: TvDisplayForm, b: TvDisplayForm): boolean {
    const first = saveBody(a);
    const second = saveBody(b);

    return (Object.keys(first) as Array<keyof TvDisplayBody>).every((key) => first[key] === second[key]);
}

/**
 * "On the screen now", as short lines. A paused board draws no slides and no QR code:
 * it shows the prayer times alone, or only the title where there is no prayer panel.
 */
export function screenSummary(effective: TvDisplayEffective, context: TvDisplayContext): string[] {
    const title = effective.header_title || context.organisation_name;

    if (!effective.is_enabled) {
        return [
            'Announcement slides: paused',
            effective.show_prayer_panel ? 'The screen shows prayer times only' : `The screen shows only the title: ${title}`,
        ];
    }

    const lines = [
        `Title at the top: ${title}`,
        `Announcement slides: on, a new slide every ${effective.carousel_interval_seconds} seconds`,
    ];

    // Only a masjid has a prayer panel to show or hide.
    if (context.is_masjid) lines.push(effective.show_prayer_panel ? 'Prayer times: shown' : 'Prayer times: hidden');

    if (effective.show_qr) lines.push(`Donation QR code: shown, with the words “${effective.donate_caption}”`);
    else if (!context.has_donation_link) lines.push('Donation QR code: not shown, no donation link yet');
    else lines.push('Donation QR code: hidden');

    return lines;
}
