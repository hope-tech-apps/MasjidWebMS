import { Masjid } from "@/core/types/data/Masjid"
import { Media } from "@/core/types/data/Media"
import { User } from "./User";

export type AdminType = 'SuperAdmin' | 'MasjidAdmin';

export type Admin = {
    id: number;
    name: string;
    email: string;
    email_verified_at: Date;
    password: string;
    phone: string;
    phone_verified_at: Date;
    type: AdminType;
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