import { AppointmentRequestStatus } from '@/core/types/data/masjid-related/AppointmentRequest';

/**
 * How an appointment request is PRESENTED — shared by the queue, the detail
 * screen and the notes panel so the four triage labels cannot drift apart
 * across three files.
 *
 * The status SET is never defined here: it comes from the server's
 * `meta.statuses`. This only decides how a status that has arrived is spelled
 * and coloured, and every lookup degrades to a humanized key, so a status added
 * in PHP tomorrow renders readably today instead of blanking a badge.
 */

const STATUS_LABELS: Partial<Record<AppointmentRequestStatus, string>> = {
    new: 'New',
    contacted: 'Contacted',
    scheduled: 'Scheduled',
    closed: 'Closed'
};

/**
 * Bootstrap subtle badges. `new` is the only one that draws the eye: it is the
 * one state that means somebody is waiting for a call.
 */
const STATUS_BADGES: Partial<Record<AppointmentRequestStatus, string>> = {
    new: 'bg-warning-subtle text-warning-emphasis',
    contacted: 'bg-info-subtle text-info-emphasis',
    scheduled: 'bg-success-subtle text-success',
    closed: 'bg-secondary-subtle text-secondary'
};

const STATUS_ICONS: Partial<Record<AppointmentRequestStatus, string>> = {
    new: 'bi-inbox',
    contacted: 'bi-telephone',
    scheduled: 'bi-calendar-check',
    closed: 'bi-check2-circle'
};

/** "contacted" -> "Contacted"; an unknown key still reads as words, never blank. */
function humanize(value: string): string {
    const words = value.replace(/_/g, ' ');

    return words.charAt(0).toUpperCase() + words.slice(1);
}

export function useAppointmentRequestDisplay() {

    const statusLabel = (status: AppointmentRequestStatus | string): string =>
        STATUS_LABELS[status as AppointmentRequestStatus] || humanize(String(status || 'new'));

    const statusBadgeClass = (status: AppointmentRequestStatus | string): string =>
        STATUS_BADGES[status as AppointmentRequestStatus] || 'bg-light text-dark border';

    const statusIcon = (status: AppointmentRequestStatus | string): string =>
        STATUS_ICONS[status as AppointmentRequestStatus] || 'bi-circle';

    /** Date + time in the reader's own locale and zone; the raw value if it will not parse. */
    const formatDateTime = (iso: string | null | undefined): string => {
        if (!iso) return '—';
        const date = new Date(iso);

        return isNaN(date.getTime())
            ? iso
            : date.toLocaleString(undefined, {
                year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
            });
    };

    /** Date only — for a date of birth, which has no meaningful time part. */
    const formatDate = (value: string | null | undefined): string => {
        if (!value) return '—';
        // A Y-m-d string parses as UTC midnight, which renders as the PREVIOUS
        // day west of Greenwich. A date of birth is not a moment in time, so it
        // is read as three numbers rather than through a timezone.
        const parts = /^(\d{4})-(\d{2})-(\d{2})/.exec(value);
        if (!parts) return value;

        const date = new Date(Number(parts[1]), Number(parts[2]) - 1, Number(parts[3]));

        return isNaN(date.getTime())
            ? value
            : date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    };

    /** "3 days ago" — how long someone has been waiting, which is the operator's real question. */
    const timeAgo = (iso: string | null | undefined): string => {
        if (!iso) return '';
        const then = new Date(iso).getTime();
        if (isNaN(then)) return '';

        const seconds = Math.floor((Date.now() - then) / 1000);
        if (seconds < 60) return 'just now';

        const units: [number, string][] = [
            [60, 'minute'],
            [3600, 'hour'],
            [86400, 'day'],
            [604800, 'week']
        ];

        let label = 'minute';
        let value = Math.floor(seconds / 60);
        for (const [divisor, unit] of units) {
            const amount = Math.floor(seconds / divisor);
            if (amount < 1) break;
            label = unit;
            value = amount;
        }

        return `${value} ${label}${value === 1 ? '' : 's'} ago`;
    };

    return { statusLabel, statusBadgeClass, statusIcon, formatDateTime, formatDate, timeAgo };
}
