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
    /**
     * When this login's two-step sign-in was confirmed, or null/absent.
     *
     * The ONLY 2FA field any payload carries — the secret, the recovery codes
     * and the replay fingerprint are all in `User::$hidden` and never leave the
     * server. It is here so the SuperAdmin user screen can tell whether there
     * is a second factor to clear for somebody who has lost their phone.
     */
    two_factor_confirmed_at?: string | null;
}