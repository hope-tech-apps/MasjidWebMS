import { computed, reactive, watch, type ComputedRef } from 'vue';
import ApiService from '@/core/services/ApiService';
import { useMasjidStore } from '@/stores/masjidStore';

/**
 * Live preview of the public site inside the editors (docs/live-preview.md).
 *
 * The API issues a short-lived session for one surface — pages, theme or splash —
 * behind the same gate as that surface's save, and answers `enabled: false` when the
 * renderer integration is not configured. The pane then frames the real public site in
 * preview mode and sends it the editor's UNSAVED values by postMessage. Nothing unsaved
 * is ever sent to the API.
 *
 * Message contract (renderer: shared/previewMessage.ts):
 *   admin → preview  {source:'manara-admin',   v:1, type:'overrides', overrides}
 *   preview → admin  {source:'manara-preview', v:1, type:'ready', path, org, surface}
 */

export type PreviewSurface = 'pages' | 'theme' | 'splash';

export interface PreviewSession {
    enabled: boolean;
    reason?: string;
    url?: string;
    origin?: string;
    surface?: PreviewSurface;
    path?: string;
    expires_at?: string;
}

export const PREVIEW_MESSAGE_SOURCE = 'manara-preview';
export const ADMIN_MESSAGE_SOURCE = 'manara-admin';

const endpoint = (masjidId: string | number, surface: PreviewSurface) => {
    switch (surface) {
        case 'pages':
            return `/api/admin/masjids/${masjidId}/pages/preview-session` as const;
        case 'theme':
            return `/api/admin/masjids/${masjidId}/theme/preview-session` as const;
        case 'splash':
            return `/api/admin/masjids/${masjidId}/splash-announcements/preview-session` as const;
    }
};

export async function requestPreviewSession(
    masjidId: string | number,
    surface: PreviewSurface,
    path: string,
): Promise<PreviewSession> {
    const res = await ApiService.post(endpoint(masjidId, surface), { path });
    const data = res.data?.data;

    return data && typeof data === 'object' ? (data as PreviewSession) : { enabled: false, reason: 'no_answer' };
}

/**
 * Whether this organisation's editors get a live preview for a surface, asked once per
 * organisation and surface for the life of the page. `null` while unknown. An error
 * counts as "no": the editor must work exactly as before when preview is unavailable.
 */
const availability = reactive<Record<string, boolean | null>>({});

export function usePreviewAvailability(surface: PreviewSurface): ComputedRef<boolean | null> {
    const masjidStore = useMasjidStore();

    watch(() => masjidStore.masjid?.id, (masjidId) => {
        if (!masjidId) return;
        const key = `${masjidId}:${surface}`;
        if (key in availability) return;
        availability[key] = null;
        requestPreviewSession(masjidId, surface, '/')
            .then((session) => { availability[key] = session.enabled === true; })
            .catch(() => { availability[key] = false; });
    }, { immediate: true });

    return computed(() => {
        const masjidId = masjidStore.masjid?.id;
        return masjidId ? (availability[`${masjidId}:${surface}`] ?? null) : null;
    });
}

/**
 * Makes a value safe to post: a plain JSON copy. Vue's reactive proxies cannot be
 * structured-cloned, and the renderer rejects anything that is not plain JSON anyway.
 */
export function toPlainJson<T>(value: T): T {
    return JSON.parse(JSON.stringify(value ?? {})) as T;
}

/** The public path the renderer serves a page-builder page at. */
export function pagePath(slug: string | null | undefined): string {
    if (!slug || slug === 'home') return '/';
    return `/${String(slug).replace(/^\/+/, '')}`;
}
