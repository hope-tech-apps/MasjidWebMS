import { Masjid } from "@/core/types/data/Masjid"
import { Media } from "@/core/types/data/Media"
import { Membership } from "@/core/types/data/Membership"
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
    /**
     * The organisations this account may act on (S4). OPTIONAL, and it has to
     * stay optional: `/api/teacher/user` and `/api/lunch/user` answer this same
     * shape without it, and so does any backend older than S4. Absent means
     * "this principal switches nothing" — never "this principal has nothing".
     * Read it through `grantedMemberships()`, never inline.
     */
    memberships?: Membership[];
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