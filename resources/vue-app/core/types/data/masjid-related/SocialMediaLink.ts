export type SocialMediaLink = {
    id: number,
    masjid_id: number,
    type: SocialMediaType,
    value: string,
    created_at: string,
    updated_at: string
};

export type SocialMediaType =
    'Facebook' |
    'YouTube' |
    'Instagram' |
    'WhatsApp_URL' |
    'WhatsApp_Number';
