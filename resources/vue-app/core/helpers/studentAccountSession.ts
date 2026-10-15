export const studentSlotKey = (school: string | number) => `MANARA_STUDENT_ACCOUNT_${school}`;
export function studentTokenForUrl(storage: Pick<Storage, 'getItem'>, url: string, origin: string): string | null {
    try {
        const parsed = new URL(url, origin);
        if (parsed.origin !== new URL(origin).origin) return null;
        const match = parsed.pathname.match(/^\/api\/student\/masjids\/(\d+)(?:\/|$)/);
        return match ? storage.getItem(studentSlotKey(match[1])) : null;
    } catch { return null; }
}
