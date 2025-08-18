import { TranslatableObject } from "@/core/types/data/interfaces/TranslatableObject";

export type Zikr = {
    id: number;
    azkar_category_id: number;
    title: TranslatableObject;
    text: TranslatableObject;
    bless: TranslatableObject;
    pronunciation: string;
    frequency: number;
    reference: string;
    created_at: string;
    updated_at: string;
    azkar_category?: AzkarCategory;
};

export type AzkarCategory = {
    id: number;
    title: string;
    description: string;
    created_at: string;
    updated_at: string;
};

export const ZIKR_SHOW_ATTRIBUTES = [
    'title',
    'text',
    'bless',
    'pronunciation',
    'frequency',
    'reference'
];
