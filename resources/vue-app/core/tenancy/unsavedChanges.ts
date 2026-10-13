/** Screens register a discard check; switching asks before changing any tenant state. */
const checks = new Set<() => Promise<boolean>>();
export function registerUnsavedChanges(check: () => Promise<boolean>): () => void {
    checks.add(check);
    return () => { checks.delete(check); };
}
export async function confirmUnsavedChanges(): Promise<boolean> {
    for (const check of checks) if (!await check()) return false;
    return true;
}
