/**
 * Studio's four steps, in order (docs/manara-studio.md §1). The keys are
 * `StudioDraft::STEPS` (app/Models/StudioDraft.php), which the server stores as
 * `current_step` so a reload resumes where the operator was.
 *
 * Only `import type`, so node can run the modules that read this.
 */
import type { StudioStepKey } from "@/core/types/data/Studio";

export type StudioStep = {
    key: StudioStepKey;
    title: string;
    /**
     * The heading keyboard focus moves to when the step opens (it carries
     * tabindex="-1"), so a screen reader announces the new step and Tab goes on
     * from its top rather than from the page body. Null while nothing renders.
     */
    headingId: string | null;
};

export const STUDIO_STEPS: StudioStep[] = [
    // StudioPanel names its heading after its title: the Identity panel opens Foundation.
    { key: 'foundation', title: 'Foundation', headingId: 'studio-panel-identity' },
    { key: 'features', title: 'Features', headingId: 'studio-features-title' },
    { key: 'layout', title: 'Layout', headingId: 'studio-layout-title' },
    { key: 'generate', title: 'Generate', headingId: null },
];

/**
 * Generate is Step 3, which provisions the organisation. It arrives with S8
 * (docs/manara-studio-w1.md); until then the stepper shows it and nothing can
 * open it.
 */
export const GENERATE_AVAILABLE = false;

export function stepTitle(key: string | null | undefined): string {
    return STUDIO_STEPS.find((step) => step.key === key)?.title ?? STUDIO_STEPS[0].title;
}

/** The id of the heading to focus when `key` opens, or null. */
export function stepHeadingId(key: string | null | undefined): string | null {
    return STUDIO_STEPS.find((step) => step.key === key)?.headingId ?? null;
}

export function stepIndex(key: string | null | undefined): number {
    const index = STUDIO_STEPS.findIndex((step) => step.key === key);
    return index === -1 ? 0 : index;
}
