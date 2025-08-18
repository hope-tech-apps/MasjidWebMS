import { TranslatableObject } from "@/core/types/data/interfaces/TranslatableObject";

export type Hadith = {
    id: number;
    title: string;
    isnad: string;
    matn: string;
    strength: TranslatableObject;
    muhaddith: TranslatableObject;
    references: HadithReference[];
    description: string;
    show_date: string;
    created_at: string;
    updated_at: string;
};

export type HadithReference = {
    title: string;
    reference: string;
};

export const HADITH_SHOW_ATTRIBUTES = [
    'title',
    'isnad',
    'matn',
    'strength',
    'muhaddith',
    'references',
    'description',
    'show_date'
];

export enum HadithStrengthEnum {
    Sahih = 'Sahih',
    Hasan = 'Hasan',
    Daaif = 'Daaif',
    Maodua = 'Maodua',
    NotHadith = 'NotHadith'
}

export const HADITH_STRENGTHS = {
    [HadithStrengthEnum.Sahih]: { en: 'Sahih', ar: 'صحيح' },
    [HadithStrengthEnum.Hasan]: { en: 'Hasan', ar: 'حسن' },
    [HadithStrengthEnum.Daaif]: { en: 'Daaif', ar: 'ضعيف' },
    [HadithStrengthEnum.Maodua]: { en: 'Maodua', ar: 'موضوع' },
    [HadithStrengthEnum.NotHadith]: { en: 'Not-Hadith', ar: 'ليس حديث' }
};