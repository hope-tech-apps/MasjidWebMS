import { MobileAppFeature } from "../MobileAppFeature"

export type MasjidMobileAppFeature = {
    id: number;
    masjid_id: number;
    feature_id: number;
    is_available: boolean | "0" | "1" | 0 | 1;
    created_at: string;
    updated_at: string;
    feature: MobileAppFeature;
}