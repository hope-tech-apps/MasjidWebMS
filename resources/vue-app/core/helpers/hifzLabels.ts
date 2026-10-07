/** Display wording only; recitation requests and stored values keep their original keys. */
export const HIFZ_KIND_LABELS: Record<string, string> = {
    sabak: 'New memorization',
    sabqi: 'Recent revision',
    manzil: 'Older revision',
};

export const HIFZ_QUALITY_LABELS: Record<string, string> = {
    excellent: 'Excellent',
    good: 'Good',
    fair: 'Fair',
    repeat: 'Needs work',
};

const fallback = (value: string): string => value.charAt(0).toUpperCase() + value.slice(1).replace(/_/g, ' ');
export const hifzKindLabel = (value: string): string => HIFZ_KIND_LABELS[value] ?? fallback(value);
export const hifzQualityLabel = (value: string): string => HIFZ_QUALITY_LABELS[value] ?? fallback(value);
