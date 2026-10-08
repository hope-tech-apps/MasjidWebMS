import { readSchoolCalendar } from '@/core/types/data/masjid-related/SchoolCalendar';
import type { SchoolCalendarPayload, SchoolYear, SchoolYearPayload } from '@/core/types/data/masjid-related/SchoolCalendar';

/** Office edit helpers; enabled readers share the additive calendar fields. */
export type SchoolTerm = { id: number; name: string; starts_on: string; ends_on: string; position: number };
export type TermSystem = 'quarters' | 'semesters' | 'trimesters';
export type ConfiguredSchoolYear = SchoolYear & { meeting_weekdays?: number[] | null; term_system?: TermSystem | null; terms?: SchoolTerm[] };
export type ConfiguredYearPayload = SchoolYearPayload & { meeting_weekdays: number[]; term_system: TermSystem | null };

/** Compatibility for cached NULL/legacy payloads; current ON responses resolve this on the server. */
export function configuredMeetingWeekdays(year: ConfiguredSchoolYear): number[] {
    return year.meeting_weekdays ?? [year.meeting_weekday];
}

/** Preserve absent, null and [] rather than normalizing all three to an empty schedule. */
export function readConfiguredCalendar(data: any): SchoolCalendarPayload | null {
    const base = readSchoolCalendar(data);
    if (!base) return null;
    for (const year of base.years as ConfiguredSchoolYear[]) {
        const raw = data.years.find((y: any) => Number(y.id) === year.id);
        if ('meeting_weekdays' in raw) year.meeting_weekdays = raw.meeting_weekdays === null ? null : [...raw.meeting_weekdays];
        if ('term_system' in raw) year.term_system = raw.term_system;
        if ('terms' in raw) year.terms = raw.terms.map((t: any) => ({ id: Number(t.id), name: String(t.name), starts_on: t.starts_on, ends_on: t.ends_on, position: Number(t.position) }));
    }
    return base;
}
