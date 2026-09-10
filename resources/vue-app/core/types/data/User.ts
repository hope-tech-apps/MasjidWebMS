import { Masjid } from "@/core/types/data/Masjid"
import { Media } from "@/core/types/data/Media"
import { UserOrganisation } from "@/core/types/data/Capability"

// Every users.type a staff login can hold. Keep in step with the server's list
// (UserAdminMiddleware, ResolveMasjidTenant, AuthController, User::TYPE_ROLE_MAP).
export type UserType = 'SuperAdmin' | 'MasjidAdmin' | 'User' | 'Teacher' | 'LunchStaff';

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
    /** The organisation(s) this login belongs to and its access there (SuperAdmin user screens). */
    organisations?: UserOrganisation[];
}