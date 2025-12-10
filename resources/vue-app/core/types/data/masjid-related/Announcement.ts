import { Media } from "@/core/types/data/Media"

export type Announcement = {
    id: number;
    masjid_id: number;
    title: string;
    details: string;
    text: string;
    start_date: string;
    end_date: string;
    link: string;
    created_at: string;
    updated_at: string;
    deleted_at: string|null;
    image?: Media|null;
}
