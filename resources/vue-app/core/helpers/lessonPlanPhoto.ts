/** Photo reads stay in the draft until the plan's Save request. */
const PHOTO_FIELDS = new Set([
    'title', 'body', 'objective', 'standard_code', 'standard_description', 'learning_outcomes',
    'differentiation_support', 'differentiation_extension', 'differentiation_learning_styles',
    'differentiation_ell_aal', 'differentiation_sen', 'cross_integration_subject',
    'cross_integration_islamic', 'cross_integration_stem', 'teaching_methods',
    'teaching_methods_other', 'teaching_aids', 'assessment_formative', 'assessment_exit_ticket',
    'reflection_worked', 'reflection_improve',
]);
const empty = (v: unknown): boolean => Array.isArray(v) ? v.every(empty) : v == null || String(v).trim() === '';
export const photoAvailable = (data: any): boolean => data?.lesson_plan_photo_fill === true;
export const photoSlots = (attached: number, pending: number, limit = 10): number => Math.max(0, Math.min(pending, limit - attached));
export function applyPhotoFields(form: Record<string, any>, fields: Record<string, any>, suggested: string[], hidden: Set<string>) {
    const suggestions: Record<string, string> = {};
    let kept = false;
    for (const [key, value] of Object.entries(fields)) {
        if (!PHOTO_FIELDS.has(key) || hidden.has(key) || empty(value)) continue;
        if (!empty(form[key])) { kept = true; continue; }
        form[key] = Array.isArray(value) ? [...value] : value;
        if (suggested.includes(key)) suggestions[key] = JSON.stringify(form[key]);
    }
    return { suggestions, kept };
}
export function clearEditedSuggestions(tags: Record<string, string>, form: Record<string, any>) {
    return Object.fromEntries(Object.entries(tags).filter(([key, value]) => JSON.stringify(form[key]) === value));
}
export async function downscalePhoto(file: File, index: number): Promise<File> {
    const url = URL.createObjectURL(file);
    try {
        const image = new Image();
        image.src = url;
        await image.decode();
        const scale = Math.min(1, 2000 / Math.max(image.naturalWidth, image.naturalHeight));
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(image.naturalWidth * scale));
        canvas.height = Math.max(1, Math.round(image.naturalHeight * scale));
        const context = canvas.getContext('2d');
        if (!context) throw new Error('Image unavailable');
        context.fillStyle = '#fff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(image, 0, 0, canvas.width, canvas.height);
        const blob = await new Promise<Blob>((resolve, reject) => canvas.toBlob(
            (b) => b ? resolve(b) : reject(new Error('Image unavailable')), 'image/jpeg', 0.85));
        if (blob.size > 3 * 1024 * 1024) throw new Error('Image too large');
        // Canvas encoding produces new pixels without the original EXIF/GPS or filename.
        return new File([blob], `lesson-photo-${index + 1}.jpg`, { type: 'image/jpeg' });
    } finally {
        URL.revokeObjectURL(url);
    }
}
export function photoSaveForm(payload: Record<string, any>, photos: File[], method: string): FormData {
    const form = new FormData();
    const append = (key: string, value: any) => {
        if (value === undefined) return;
        if (Array.isArray(value)) {
            value.forEach((v, i) => append(`${key}[${i}]`, v));
            // Empty optional arrays may be absent: the plan save still clears its prose.
        } else form.append(key, value == null ? '' : String(value));
    };
    Object.entries(payload).forEach(([key, value]) => append(key, value));
    // An empty resource list must remain PRESENT to detach old links.
    if (payload.resource_ids?.length === 0) form.append('resource_ids', '[]');
    photos.forEach((photo) => form.append('photos[]', photo));
    if (method === 'put') form.append('_method', 'PUT');
    return form;
}
