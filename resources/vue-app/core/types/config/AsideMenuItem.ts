import { MasjidDashboardRoute, SuperDashboardRoute } from "@/core/types/config/SystemRoutes"
import { UserType } from "@/core/types/data/User";
import { CapabilityKey, ModuleKey } from "@/core/types/data/Capability";
import { OrgType, TerminologyKey } from "@/core/types/data/Vertical";

// Visibility is decided in ONE place, menuItemState() in
// core/access/orgAccess.ts. The sidebar, the SuperAdmin's "Switched off" list
// and the switch panel's "Sidebar:" lines all read it; do not re-derive it inline.
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
    // An opt-in GRANT (config/capabilities.php, kind `grant`): the item is shown
    // to the organisation's administrators only once the organisation HAS it.
    // For a SuperAdmin it is listed under "Switched off for {org}" instead —
    // unless the item also has `requiresModule`, in which case the module
    // decides for the SuperAdmin. Menu visibility only: the server's
    // `capability:` gate is the boundary.
    //
    // NEVER use this for a module key: its strict `=== true` hides the item
    // whenever the payload is silent, which is every default-on screen.
    requiresCapability?: CapabilityKey;
    // A MODULE (kind `module`): the item is hidden only when the organisation's
    // `modules_off` names it — for its administrators hidden, for a SuperAdmin
    // listed under "Switched off for {org}". A payload with no `modules_off`
    // hides nothing. For a module offered to masjids only, it also lets the item
    // past `requiresOrgTypes` once a SuperAdmin switched it ON for another org
    // type (`modules_on`).
    requiresModule?: ModuleKey;
    // What decides an item that has no switch, as one phrase for the SuperAdmin's
    // "Not switchable here" list. Set it only where reading the flags would say
    // the wrong thing (the Details screen, the SuperAdmin-only library).
    lever?: string;
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
    //
    // The one way past it: the item's `requiresModule` is in the organisation's
    // `modules_on` (a SuperAdmin switched a masjid-only module on for it).
    requiresOrgTypes?: OrgType[];
}
