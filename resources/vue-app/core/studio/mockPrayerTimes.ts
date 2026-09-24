/**
 * One day's prayer times for Studio's mockups and the Prayer panel, worked out
 * in the browser with the `adhan` package the SPA already ships.
 *
 * The inputs are the draft's own answers: its coordinates, timezone, and the
 * calculation method, madhab and high-latitude rule the operator chose. The
 * method names are the server's (app/Enums/PrayerCalculationMethod.php:7-18),
 * which are adhan's CalculationMethod names one for one, so a choice made here
 * is computed exactly as it will be once provisioned. Nothing is guessed: with
 * no coordinates, no timezone or no method there are no times, and the caller
 * says so rather than showing another city's day.
 *
 * Iqama times are the adhan time plus the offset the client gave, and only
 * when they gave one; Jumu'ah is the fixed time they gave.
 */
import { CalculationMethod, Coordinates, HighLatitudeRule, Madhab, PrayerTimes } from 'adhan';

/** `PrayerCalculationMethod` values, in the enum's order. */
export const PRAYER_METHODS = [
    'MuslimWorldLeague',
    'Egyptian',
    'Karachi',
    'UmmAlQura',
    'Dubai',
    'MoonsightingCommittee',
    'NorthAmerica',
    'Kuwait',
    'Qatar',
    'Singapore',
    'Tehran',
    'Turkey',
] as const;

export type PrayerMethod = typeof PRAYER_METHODS[number];

export const SALAH_KEYS = ['fajr', 'dhuhr', 'asr', 'maghrib', 'isha'] as const;

export type SalahKey = typeof SALAH_KEYS[number];

export type MockPrayerInput = {
    latitude?: number | null;
    longitude?: number | null;
    timezone?: string | null;
    method?: string | null;
    /** `Madhab` values: Shafi | Hanafi (app/Enums/Madhab.php). */
    madhab?: string | null;
    /** `HighLatitudeRule` values (app/Enums/HighLatitudeRule.php). */
    high_latitude_rule?: string | null;
    /** Minutes after adhan, per salah; absent when not given. */
    iqama?: Partial<Record<SalahKey, number | null>> | null;
    /** false: the client has not given iqama times, so none are shown. */
    iqama_given?: boolean | null;
};

export type MockPrayerRow = {
    key: SalahKey;
    /** HH:MM, 24-hour, in the organisation's timezone. */
    adhan: string;
    iqama: string | null;
};

const MADHABS: Record<string, string> = {
    Shafi: Madhab.Shafi,
    Hanafi: Madhab.Hanafi,
};

const HIGH_LATITUDE_RULES: Record<string, string> = {
    MiddleOfTheNight: HighLatitudeRule.MiddleOfTheNight,
    SeventhOfTheNight: HighLatitudeRule.SeventhOfTheNight,
    TwilightAngle: HighLatitudeRule.TwilightAngle,
};

function isMethod(value: unknown): value is PrayerMethod {
    return typeof value === 'string' && (PRAYER_METHODS as readonly string[]).includes(value);
}

function isTimeZone(value: unknown): value is string {
    if (typeof value !== 'string' || value === '') {
        return false;
    }
    try {
        new Intl.DateTimeFormat('en-GB', { timeZone: value });
        return true;
    } catch {
        return false;
    }
}

/** HH:MM of an instant in a timezone. */
export function clockIn(instant: Date, timeZone: string): string {
    const parts = new Intl.DateTimeFormat('en-GB', { timeZone, hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(instant);
    const hour = parts.find((part) => part.type === 'hour')?.value ?? '00';
    const minute = parts.find((part) => part.type === 'minute')?.value ?? '00';
    return `${hour}:${minute}`;
}

/**
 * The calendar date `instant` falls on in `timeZone`, as the local-midnight Date
 * adhan expects (it reads only the year, month and day).
 */
function calendarDateIn(instant: Date, timeZone: string): Date {
    const parts = new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(instant);
    const read = (type: string) => Number(parts.find((part) => part.type === type)?.value);
    return new Date(read('year'), read('month') - 1, read('day'));
}

/** True when there is enough to compute a day: coordinates, a timezone and a method. */
export function canMockPrayerTimes(input: MockPrayerInput): boolean {
    const { latitude, longitude } = input;
    return typeof latitude === 'number' && Number.isFinite(latitude) && latitude >= -90 && latitude <= 90
        && typeof longitude === 'number' && Number.isFinite(longitude) && longitude >= -180 && longitude <= 180
        && isTimeZone(input.timezone)
        && isMethod(input.method);
}

/** The day's five prayers in order, or null when the draft does not say enough. */
export function mockPrayerTimes(input: MockPrayerInput, on: Date = new Date()): MockPrayerRow[] | null {
    if (!canMockPrayerTimes(input)) {
        return null;
    }

    const timeZone = input.timezone as string;
    const params = CalculationMethod[input.method as PrayerMethod]();

    const madhab = MADHABS[input.madhab ?? ''];
    if (madhab) {
        params.madhab = madhab as typeof params.madhab;
    }
    const rule = HIGH_LATITUDE_RULES[input.high_latitude_rule ?? ''];
    if (rule) {
        params.highLatitudeRule = rule as typeof params.highLatitudeRule;
    }

    const times = new PrayerTimes(new Coordinates(input.latitude as number, input.longitude as number), calendarDateIn(on, timeZone), params);
    const showIqama = input.iqama_given !== false;

    return SALAH_KEYS.map((key) => {
        const adhan: Date = times[key];
        const offset = input.iqama?.[key];
        const iqama = showIqama && typeof offset === 'number' && Number.isFinite(offset)
            ? clockIn(new Date(adhan.getTime() + offset * 60_000), timeZone)
            : null;

        return { key, adhan: clockIn(adhan, timeZone), iqama };
    });
}
