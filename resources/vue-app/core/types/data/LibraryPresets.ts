import { TranslatableObject } from "@/core/types/data/interfaces/TranslatableObject";
import { HadithReference } from "@/core/types/data/Hadith";

/**
 * Curated GLOBAL library presets returned by the /api/admin/{type}/library
 * endpoints. The admin browses these in the picker and copies one into the live
 * collection. Shapes mirror the live tables (see app/Models/Library* and the
 * library_* migrations).
 */

export type LibraryHadithPreset = {
    id: number;
    slug: string;
    category: string | null;
    source: string | null;
    title: string;
    isnad: string | null;
    matn: string;
    strength: TranslatableObject;
    muhaddith: TranslatableObject;
    references: HadithReference[];
    description: string;
};

export type LibraryTasbeehPreset = {
    id: number;
    slug: string;
    text: TranslatableObject;
    pronunciation: string;
    reference: string | null;
    default_count: number | null;
};

export type LibraryAzkarPreset = {
    id: number;
    slug: string;
    category: string | null;
    title: TranslatableObject;
    text: TranslatableObject;
    bless: TranslatableObject | null;
    pronunciation: string;
    frequency: number | null;
    reference: string | null;
};

/** The kinds of library the picker supports. */
export type LibraryType = 'hadith' | 'tasbeeh' | 'azkar';
