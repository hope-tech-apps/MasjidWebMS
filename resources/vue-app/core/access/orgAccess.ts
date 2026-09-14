import { AsideMenuItem } from "@/core/types/config/AsideMenuItem";
import { CapabilityKey, ModuleKey } from "@/core/types/data/Capability";
import { Masjid } from "@/core/types/data/Masjid";
import { UserType } from "@/core/types/data/User";
import { OrgType, TerminologyKey } from "@/core/types/data/Vertical";

/**
 * What an organisation has, as the admin SPA reads it — written once.
 *
 * The sidebar, the router, the header search, the broadcast composer, the
 * SuperAdmin's switch panel and the form editor all ask the same questions.
 * Every one of them used to carry its own inline copy of the condition, and a
 * condition kept in several places is the one that ends up updated in only some
 * of them (.claude/rules/shipping.md). Menu visibility and route guards only:
 * the server's `capability:` gates are the boundary.
 */

type OrgPayload = Pick<Masjid, 'capabilities' | 'modules_off' | 'crm_enabled' | 'assistant_enabled'>;

export type MenuItemState = 'visible' | 'switched_off' | 'hidden';

/**
 * Whether a SuperAdmin switched this module off for the organisation.
 *
 * True only on an explicit `modules_off` entry: a payload without the field (an
 * older backend, or one not loaded yet) reads as ON, so no deploy order can hide
 * a default-on screen.
 */
export function moduleIsOff(masjid: Pick<Masjid, 'modules_off'> | null | undefined, key: ModuleKey): boolean {
    return masjid?.modules_off?.includes(key) === true;
}

/** Whether the organisation HAS this opt-in grant. A payload that is silent reads as "not had". */
export function hasGrant(masjid: Pick<Masjid, 'capabilities'> | null | undefined, key: CapabilityKey): boolean {
    return masjid?.capabilities?.[key] === true;
}

/**
 * Whether this person may create and edit sign-up forms outside the page builder:
 * a SuperAdmin, or an organisation holding `web_pages` or `form_editing`. The same
 * any-of the server's `capability:web_pages,form_editing` gate on form writes applies.
 */
export function canEditForms(userType: UserType | undefined, masjid: Pick<Masjid, 'capabilities'> | null | undefined): boolean {
    return userType === 'SuperAdmin' || hasGrant(masjid, 'web_pages') || hasGrant(masjid, 'form_editing');
}

/**
 * Whether help text may send this person to Web Pages Management: the organisation
 * has its website switched on, and the viewer is a SuperAdmin or the organisation
 * holds `web_pages`. Anywhere else the pointer names a screen they cannot open (an
 * organisation with no website, or one whose own admins may not edit its pages),
 * so the copy has to say Manara places the form instead.
 */
export function canUseWebPages(userType: UserType | undefined, masjid: Pick<Masjid, 'capabilities' | 'modules_off'> | null | undefined): boolean {
    if (moduleIsOff(masjid, 'website')) return false;

    return userType === 'SuperAdmin' || hasGrant(masjid, 'web_pages');
}

/**
 * Where a menu item goes for this person and this organisation.
 *
 *   hidden        not in the sidebar at all
 *   switched_off  a SuperAdmin's "Switched off for {org}" list, and nowhere else
 *   visible       in the sidebar
 *
 * The order is load-bearing:
 *   1. user type, vertical, CRM and Assistant — hidden for EVERYONE when they fail,
 *      SuperAdmins included, because the router and the `crm` / `assistant`
 *      middleware refuse SuperAdmins there too;
 *   2. a module switched off — the SuperAdmin still reaches it from the list;
 *   3. a grant the organisation lacks — for a SuperAdmin, an item that ALSO names a
 *      module stays visible (the module decides: Web Pages Management stays in the
 *      owner's sidebar for an organisation whose own admins may not edit its site);
 *      any other grant item goes to the list.
 */
export function menuItemState(
    item: AsideMenuItem,
    userType: UserType | undefined,
    masjid: OrgPayload | null | undefined,
    orgType: OrgType
): MenuItemState {
    if (!userType || !item.allowed_types.includes(userType)) return 'hidden';
    if (item.requiresOrgTypes && !item.requiresOrgTypes.includes(orgType)) return 'hidden';
    if (item.requiresCrm && !masjid?.crm_enabled) return 'hidden';
    if (item.requiresAssistant && !masjid?.assistant_enabled) return 'hidden';

    const isSuper = userType === 'SuperAdmin';

    if (item.requiresModule && moduleIsOff(masjid, item.requiresModule)) {
        return isSuper ? 'switched_off' : 'hidden';
    }

    if (item.requiresCapability && !hasGrant(masjid, item.requiresCapability)) {
        if (!isSuper) return 'hidden';
        return item.requiresModule ? 'visible' : 'switched_off';
    }

    return 'visible';
}

/**
 * A nav label in the tenant's own vocabulary when the item opts in with
 * `title_term`, and the authored `title` otherwise. `term` carries its own
 * fallback, so this reads correctly before the organisation has loaded too.
 */
export function menuItemTitle(item: AsideMenuItem, term: (key: TerminologyKey) => string): string {
    if (!item.title_term) return item.title;

    const word = term(item.title_term);

    return item.title_suffix ? `${word} ${item.title_suffix}` : word;
}
