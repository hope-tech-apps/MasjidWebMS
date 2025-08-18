import { Masjid } from "@/core/types/data/Masjid"
import { Media } from "@/core/types/data/Media"

export type UserType = 'SuperAdmin' | 'MasjidAdmin' | 'User';

export type User = {
    id: number;
    name: string;
    email: string;
    email_verified_at: string | null;
    password?: string;
    phone: string;
    phone_verified_at: string | null;
    type: UserType;
    created_at: string | null;
    updated_at: string | null;
    deleted_at: string | null;
    avatar: Media;
}