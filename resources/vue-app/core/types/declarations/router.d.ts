import 'vue-router';
import { UserType } from '@/core/types/data/User';

declare module 'vue-router' {
    interface RouteMeta {
        auth?: boolean;
        allowedUsers?: Array<UserType>;
        pageTitle?: string;
        dashboardType?: 'masjid' | 'super';
        requiresCrm?: boolean;
        requiresAssistant?: boolean;
    }
}