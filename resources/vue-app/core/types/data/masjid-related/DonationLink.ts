import { Media } from "../Media";

export type DonationLink = {
    id: number;
    masjid_id: number;
    link: string;
    title: string;
    message: string;
    image?: Media;
    created_at: string;
    updated_at: string
}
