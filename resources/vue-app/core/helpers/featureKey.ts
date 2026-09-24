/**
 * A mobile-app feature key reduced to its lower-case ASCII letters and digits,
 * so `qur’an`, `qur'an` and `quran` are one key.
 *
 * Mirrors MobileAppFeature::normaliseKey() in PHP, which is the authority.
 * Production's Qur'an row is keyed `qur’an` (U+2019) while config/verticals.php
 * bundles `quran`, so matching a vertical's bundle against the catalogue by the
 * raw key drops Qur'an for every new masjid.
 *
 * Non-ASCII characters are stripped BEFORE lower-casing, because PHP's
 * strtolower() only folds A-Z: lower-casing first would let JavaScript fold a
 * character PHP drops (`İ` becomes `i̇`) and the two sides would disagree.
 */
export function normaliseFeatureKey(key: string | null | undefined): string {
    return (key ?? '').replace(/[^A-Za-z0-9]/g, '').toLowerCase();
}

/**
 * The catalogue keys a vertical's bundle names, in catalogue order and in the
 * catalogue's own spelling, matched by normaliseFeatureKey().
 *
 * The wizard seeds its Content-step toggles from this and always posts them as
 * an explicit selection, which the server honours as-is. So a bundle key this
 * fails to match is a feature the new organisation is born without: the exact
 * match it replaced turned production's `qur’an` off for every masjid.
 * Returning the catalogue's spelling keeps every posted key one that
 * `exists:mobile_app_features,key` accepts.
 */
export function catalogueKeysFor(bundle: readonly string[], catalogueKeys: readonly string[]): string[] {
    const wanted = new Set(bundle.map(normaliseFeatureKey));
    return catalogueKeys.filter(key => wanted.has(normaliseFeatureKey(key)));
}
