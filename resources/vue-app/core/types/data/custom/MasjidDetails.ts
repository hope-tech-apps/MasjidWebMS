import { Masjid } from "@/core/types/data/Masjid";
import { SocialMediaLink } from "@/core/types/data/masjid-related/SocialMediaLink";

export type MasjidDetails = Masjid &{
    social_media_links: Array<SocialMediaLink>
};

export type MasjidDetailsModel = {
    name: string;
    email: string;
    phone: string;
    timezone: string;
    latitude: string;
    longitude: string;
    facebook: string;
    youtube: string;
    instagram: string;
    wahtsapp: string;
    website_link: string;
}
