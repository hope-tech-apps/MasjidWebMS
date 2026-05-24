import { Media } from "@/core/types/data/Media"

export type SplashAnnouncement = {
    id: number;
    masjid_id: number;
    title: string;
    body: string | null;
    cta_label: string | null;
    cta_url: string | null;
    starts_at: string;
    ends_at: string;
    priority: number;
    is_active: boolean;
    onesignal_iam_id: string | null;
    created_at: string;
    updated_at: string;
    deleted_at: string | null;
    image?: Media | null;
}
