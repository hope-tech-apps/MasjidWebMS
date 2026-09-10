import { Masjid } from "@/core/types/data/Masjid"
import { Media } from "@/core/types/data/Media"

export type UserType = 'SuperAdmin' | 'MasjidAdmin' | 'User' | 'Teacher';

// Per-account grants: boolean columns on `users` that open one screen to an
// account whose TYPE alone would not see it. Menu visibility only — the server
// still decides what the account may read and write.
export type UserGrant = 'can_manage_web_pages';

export type User = {
    id: number;
    name: string;
    email: string;
    email_verified_at: string | null;
    password?: string;
    phone: string;
    phone_verified_at: string | null;
    type: UserType;
    // Shows Web Pages Management to a MasjidAdmin (SuperAdmins see it by type).
    can_manage_web_pages?: boolean;
    created_at: string | null;
    updated_at: string | null;
    deleted_at: string | null;
    avatar: Media;
}