/**
 * Studio's four steps, in order (docs/manara-studio.md §1). The keys are
 * `StudioDraft::STEPS` (app/Models/StudioDraft.php), which the server stores as
 * `current_step` so a reload resumes where the operator was.
 *
 * Only `import type`, so node can run the modules that read this.
 */
import type { StudioStepKey } from "@/core/types/data/Studio";

export type StudioStep = { key: StudioStepKey; title: string };

export const STUDIO_STEPS: StudioStep[] = [
    { key: 'foundation', title: 'Foundation' },
    { key: 'features', title: 'Features' },
    { key: 'layout', title: 'Layout' },
    { key: 'generate', title: 'Generate' },
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

export function stepIndex(key: string | null | undefined): number {
    const index = STUDIO_STEPS.findIndex((step) => step.key === key);
    return index === -1 ? 0 : index;
}
