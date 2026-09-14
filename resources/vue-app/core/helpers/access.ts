import { ModuleKey, UserOrganisation } from '@/core/types/data/Capability';

// Shared wording for the layered access model on the SuperAdmin's user screens.
// The Team & Access screen (views/dashboard/TeamView.vue) uses the same words.

// What an administrator gets on top of the listed grants. Each noun is a module a
// SuperAdmin can switch off; settings (the Details screen) is always there.
const ADMIN_EXTRAS: { module: ModuleKey; noun: string }[] = [
    { module: 'announcements', noun: 'announcements' },
    { module: 'events', noun: 'events' },
    { module: 'services', noun: 'services' },
];

/**
 * "announcements, events, services and settings", less each noun whose module is
 * switched off there (the `modules_off` / `screens_off` keys). With none off it is
 * byte-identical to the sentence both screens always printed.
 */
export function adminExtrasPhrase(offKeys: readonly string[]): string {
    const nouns = [
        ...ADMIN_EXTRAS.filter(extra => !offKeys.includes(extra.module)).map(extra => extra.noun),
        'settings',
    ];

    return nouns.length === 1 ? nouns[0] : `${nouns.slice(0, -1).join(', ')} and ${nouns[nouns.length - 1]}`;
}

export function accessLabel(org: Pick<UserOrganisation, 'access' | 'is_owner'>): string {
    if (org.access === 'admin') return org.is_owner ? 'Owner · Administrator' : 'Administrator';
    if (org.access === 'jummah_lunch') return 'Friday lunch only';
    if (org.access === 'teacher') return 'Teacher';
    return 'No access';
}

export function accessBadge(org: Pick<UserOrganisation, 'access'>): string {
    if (org.access === 'admin') return 'text-bg-primary';
    if (org.access === 'jummah_lunch') return 'text-bg-warning';
    if (org.access === 'teacher') return 'text-bg-info';
    return 'text-bg-light border';
}

export function initials(name?: string | null): string {
    const parts = (name ?? '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    return ((parts[0][0] ?? '') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
}
