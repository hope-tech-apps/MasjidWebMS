import { Media } from "../Media"

export type MasjidAboutUs = {
    id: number;
    masjid_id: number;
    about: string;
    mission: string;
    vision: string;
    created_at: string;
    updated_at: string;
    about_image?: Media;
    mission_icon?: Media;
    vision_icon?: Media;
}