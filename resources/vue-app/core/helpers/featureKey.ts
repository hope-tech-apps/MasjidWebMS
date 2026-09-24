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
