import { MasjidDashboardRoute, SuperDashboardRoute } from "@/core/types/config/SystemRoutes"
import { UserType } from "@/core/types/data/User";

export type AsideMenuItem = {
    title: string;
    svg_icon: string;
    to: MasjidDashboardRoute | SuperDashboardRoute;
    allowed_types: UserType[];
    // When true, the item is only shown if the active masjid's crm_enabled is true.
    requiresCrm?: boolean;
}