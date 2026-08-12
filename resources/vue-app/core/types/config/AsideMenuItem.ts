import { MasjidDashboardRoute, SuperDashboardRoute } from "@/core/types/config/SystemRoutes"
import { UserType } from "@/core/types/data/User";
import { OrgType, TerminologyKey } from "@/core/types/data/Vertical";

export type AsideMenuItem = {
    title: string;
    // When set, the label is built from the tenant's own vocabulary instead of
    // `title` — the terminology term for this key, followed by `title_suffix`
    // if one is given ("Congregants Directory" for a masjid, "Families
    // Directory" for a school). `title` stays the authored default.
    title_term?: TerminologyKey;
    title_suffix?: string;
    svg_icon: string;
    to: MasjidDashboardRoute | SuperDashboardRoute;
    allowed_types: UserType[];
    // When true, the item is only shown if the active masjid's crm_enabled is true.
    requiresCrm?: boolean;
    // When true, the item is only shown if the active masjid's assistant_enabled is true.
    requiresAssistant?: boolean;
    // When set, the item is only shown to these verticals — for a workflow that
    // belongs to one kind of organization (a clinic's intake queue) rather than
    // to every tenant.
    //
    // This hides a MENU ITEM; it is not authorization and it must never be the
    // only way to reach a screen that can hold real data. The route stays
    // reachable and the server keeps deciding who may read what — the same
    // relationship `requiresCrm` has with the `crm` middleware. A tenant whose
    // vertical is unknown reads as `masjid` (Vertical.ts), so an item gated to
    // another vertical stays hidden until the payload proves otherwise.
    requiresOrgTypes?: OrgType[];
}