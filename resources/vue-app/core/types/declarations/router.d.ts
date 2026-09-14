import 'vue-router';
import { UserType } from '@/core/types/data/User';
import { CapabilityKey, ModuleKey } from '@/core/types/data/Capability';

declare module 'vue-router' {
    interface RouteMeta {
        auth?: boolean;
        allowedUsers?: Array<UserType>;
        pageTitle?: string;
        dashboardType?: 'masjid' | 'super';
        requiresCrm?: boolean;
        requiresAssistant?: boolean;
        // The active organisation must HAVE this opt-in grant (SuperAdmins pass).
        // Never a module key — see Capability.ts.
        requiresCapability?: CapabilityKey;
        // The active organisation must have AT LEAST ONE of these grants
        // (SuperAdmins pass). The server's `capability:a,b` gate reads the same way.
        requiresAnyCapability?: CapabilityKey[];
        // The default-on module this screen belongs to. Blocked only when the
        // organisation's `modules_off` names it (SuperAdmins pass).
        requiresModule?: ModuleKey;
    }
}
