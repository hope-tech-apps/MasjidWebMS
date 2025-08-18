import { Media } from "@/core/types/data/Media"

export type MobileAppFeature = {
    id: number;
    name: string;
    created_at: string;
    updated_at: string;
    pivot: {
        masjid_id: number;
        feature_id: number;
        is_available: boolean | "0" | "1" | 0 | 1;
    };
    icon: Media;
}