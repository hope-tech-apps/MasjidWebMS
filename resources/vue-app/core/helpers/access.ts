import { APP_SURFACE_MODULES, ModuleKey, SwitchedOffScreen, UserOrganisation } from '@/core/types/data/Capability';

// Shared wording for the layered access model on the SuperAdmin's user screens.
// The Team & Access screen (views/dashboard/TeamView.vue) uses the same words.

/**
 * The switched-off modules a staff sentence may name: the ADMIN screens.
 *
 * `modules_off` / `screens_off` are the gate's truth, so they carry every module —
 * including the app-only ones (APP_SURFACE_MODULES), which have no admin screen at
 * all and decide one row of the mobile app's menu instead. Naming those in
 * "Switched off here: …" would tell an administrator that a screen they never had
 * was taken away from them, and after the app-features cutover writes `quran=false`
 * for the organisations whose app menu never listed it, that is exactly what both
 * screens would say. The SuperAdmin's switch panel still shows them: that is where
 * they are meant to be flipped.
 */
export function adminScreensOff(screens: readonly SwitchedOffScreen[] | null | undefined): SwitchedOffScreen[] {
    const appOnly: readonly string[] = APP_SURFACE_MODULES;

    return (screens ?? []).filter(screen => !appOnly.includes(screen.key));
}

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
