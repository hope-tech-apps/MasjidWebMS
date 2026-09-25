/**
 * The Iqama Times screen's two decisions that decide what is SAVED, as plain
 * functions so they can be pinned without a browser
 * (resources/vue-app/tests/iqama-settings-form.test.ts). Both were bugs that
 * saved the wrong thing while the screen reported success.
 */
import type { IqamaType } from '../../core/types/data/masjid-related/IqamaTimeSetting';

export const SALAH_KEYS = ['fajr', 'dhuhr', 'asr', 'maghrib', 'isha'] as const;

export type Salah = typeof SALAH_KEYS[number];

/** One range as the screen holds it; a half-filled one is not sent. */
export type RangeRow = { start_date: string; end_date: string; specific_time: string };

/**
 * A stored "YYYY-MM-DD" as the date picker's Date, at LOCAL midnight.
 *
 * `new Date("2026-09-21")` is UTC midnight, which is Sep 20 on the wall anywhere
 * west of UTC, so the picker showed every range a day early, and a save wrote
 * that day back (formatDate reads the local date).
 */
export function parseLocalDate(ymd: string): Date {
    const [y, m, d] = ymd.slice(0, 10).split('-').map(Number);
    return new Date(y, m - 1, d);
}

/** A picker Date as "YYYY-MM-DD", read in local time: the inverse of parseLocalDate. */
export function formatDate(date: Date): string {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

/**
 * The body of POST /api/admin/masjids/{id}/iqama.
 *
 * The five offsets go in BOTH modes. On Specific Time Ranges they are the
 * fallback for every prayer on a day no range covers (the rule in
 * tests/fixtures/iqama-resolution.json); the screen used to leave them out in
 * that mode and the server stored 0, so a save put Fajr and Maghrib iqama on
 * the adhan. Ranges go only on Specific Time Ranges, and only complete ones.
 */
export function iqamaSavePayload(input: {
    iqamaType: IqamaType;
    showIqamaTimes: boolean;
    offsets: Record<Salah, number>;
    timeRanges: Record<Salah, RangeRow[]>;
}): Record<string, unknown> {
    const payload: Record<string, unknown> = {
        iqama_type: input.iqamaType,
        show_iqama_times: input.showIqamaTimes,
    };

    SALAH_KEYS.forEach((salah) => {
        payload[salah] = input.offsets[salah];
    });

    if (input.iqamaType === 'specific_time_ranges') {
        const ranges: Array<RangeRow & { salah: Salah }> = [];
        SALAH_KEYS.forEach((salah) => {
            (input.timeRanges[salah] ?? []).forEach((range) => {
                if (range.start_date && range.end_date && range.specific_time) {
                    ranges.push({
                        salah,
                        start_date: range.start_date,
                        end_date: range.end_date,
                        specific_time: range.specific_time,
                    });
                }
            });
        });
        payload.time_ranges = ranges;
    }

    return payload;
}
