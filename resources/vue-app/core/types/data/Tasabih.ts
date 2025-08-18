import { TranslatableObject } from "@/core/types/data/interfaces/TranslatableObject";

export type Tasbih = {
    id: number;
    text: TranslatableObject;
    pronunciation: string;
    reference: string;
    created_at: string;
    updated_at: string;
}

export const TASBIH_SHOW_ATTRIBUTE = ['text', 'pronunciation', 'reference'];