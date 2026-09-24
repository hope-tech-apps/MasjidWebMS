/**
 * The four platforms a Studio client can be given, in the order every Studio
 * screen lists them (the wizard's Apps step order). The slugs are the server's
 * (`UpdateStudioDraftRequest`: ios|android|tvos|web); only the names are here.
 *
 * Shared by the Platforms panel, the feature step's "suggested with" chip and
 * the preview's tabs, so a platform is named the same way on all three.
 *
 * Only `import type`, so node can run the modules that read this.
 */
import type { StudioPlatform } from "@/core/types/data/Studio";

export const PLATFORM_OPTIONS: { slug: StudioPlatform; label: string }[] = [
    { slug: 'ios', label: 'iOS' },
    { slug: 'android', label: 'Android' },
    { slug: 'tvos', label: 'tvOS' },
    { slug: 'web', label: 'Web' },
];

/** A platform's name; an unknown slug is shown as it came. */
export function platformLabel(slug: string): string {
    return PLATFORM_OPTIONS.find((option) => option.slug === slug)?.label ?? slug;
}
