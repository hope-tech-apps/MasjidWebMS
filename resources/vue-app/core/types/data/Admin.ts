import { Masjid } from "@/core/types/data/Masjid"
import { Media } from "@/core/types/data/Media"
import { User } from "./User";

export type AdminType = 'SuperAdmin' | 'MasjidAdmin' | 'Teacher';

export type Admin = {
    id: number;
    name: string;
    email: string;
    email_verified_at: Date;
    password: string;
    phone: string;
    phone_verified_at: Date;
    type: AdminType;
    // Set == two-step sign-in is ON for this account. The only 2FA field the
    // server ever serialises: the secret and the recovery codes are in
    // User::$hidden and never reach a payload. Optional because every other
    // realm's /user answers this same shape without it.
    two_factor_confirmed_at?: string | null;
    created_at: Date;
    updated_at: Date;
    deleted_at: Date;
    masjid: Masjid | null;
    avatar: Media;
}

export type MasjidAdmin = {
    id: number;
    name: string;
    email: string;
    email_verified_at: Date;
    password: string;
    phone: string;
    phone_verified_at: Date;
    type: 'MasjidAdmin';
    created_at: Date;
    updated_at: Date;
    deleted_at: Date;
    masjid: Masjid | null;
    avatar: Media;
}