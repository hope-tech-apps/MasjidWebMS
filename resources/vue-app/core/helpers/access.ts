import { UserOrganisation } from '@/core/types/data/Capability';

// Shared wording for the layered access model on the SuperAdmin's user screens.
// The Team & Access screen (views/dashboard/TeamView.vue) uses the same words.

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
