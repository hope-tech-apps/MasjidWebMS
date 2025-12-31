import { Media } from "@/core/types/data/Media"

export type Service = {
    id: number;
    masjid_id: number;
    title: string;
    description: string;
    text: string;
    created_at: string;
    updated_at: string;
    deleted_at: string|null;
    icon?: Media|null;
    image?: Media|null;
}
