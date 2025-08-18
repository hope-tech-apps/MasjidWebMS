import { Media } from "@/core/types/data/Media"

export type Event = {
    id: number;
    masjid_id: number;
    title: string;
    details: string;
    place: string;
    start: string;
    end: string;
    link: string;
    created_at: string;
    updated_at: string;
    deleted_at: string|null;
}