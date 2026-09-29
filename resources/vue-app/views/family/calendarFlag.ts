/**
 * Whether the school on screen offers the Calendar link, read from the parent's
 * own `/me` for THAT school.
 *
 * The flag is one boolean shared by every school the layout shows in turn, so a
 * value read for school A is still sitting there when the parent moves to B.
 * The run therefore clears it FIRST, before any request, and only then asks: B's
 * header never shows A's answer while B's `/me` is in flight, and a failed or
 * slow request leaves the link hidden rather than borrowed.
 */
export interface CalendarFlagRun {
    /** The school this run was started for. */
    id: string;
    /** Whether the parent holds a session for that school. */
    signedIn: boolean;
    /** The school on screen now; a late answer for another one is dropped. */
    current: () => string;
    get: (url: string) => Promise<any>;
    set: (published: boolean) => void;
}

export async function loadCalendarFlagFor(run: CalendarFlagRun): Promise<void> {
    run.set(false);

    if (!run.signedIn || !run.id) return;

    try {
        const res = await run.get(`/api/family/masjids/${run.id}/me`);

        // A slow answer for the school the parent has since left must not set
        // the link for the one they are on now.
        if (run.id === run.current()) {
            run.set(res.data?.data?.school_calendar_published === true);
        }
    } catch {
        if (run.id === run.current()) run.set(false);
    }
}
