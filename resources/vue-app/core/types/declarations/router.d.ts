import 'vue-router';
import { UserType } from '@/core/types/data/User';
import { CapabilityKey } from '@/core/types/data/Capability';

declare module 'vue-router' {
    interface RouteMeta {
        auth?: boolean;
        allowedUsers?: Array<UserType>;
        pageTitle?: string;
        dashboardType?: 'masjid' | 'super';
        requiresCrm?: boolean;
        requiresAssistant?: boolean;
        // The active organisation must HAVE this capability (SuperAdmins pass).
        requiresCapability?: CapabilityKey;
    }
}