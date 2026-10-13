/** Screens register a discard check; switching asks before changing any tenant state. */
const checks = new Set<() => Promise<boolean>>();
export function registerUnsavedChanges(check: () => Promise<boolean>): () => void {
    checks.add(check);
    return () => { checks.delete(check); };
}
export async function confirmUnsavedChanges(): Promise<boolean> {
    // A screen that unregistered while its question was open no longer has a say.
    for (const check of [...checks]) if (!await check() && checks.has(check)) return false;
    return true;
}
