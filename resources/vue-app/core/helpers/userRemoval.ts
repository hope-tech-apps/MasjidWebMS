/**
 * The words on the SuperAdmin's delete and archive confirmations for a login.
 *
 * Both actions are GLOBAL, and a login can belong to several organisations now (a
 * Teacher attached to a second school, an admin with two grants): a force delete
 * cascades through `masjid_user` and `group_staff` in every organisation, and an
 * archive locks the login out of every one of them. The dialog used to say "delete
 * this user" whatever the count, so a SuperAdmin who meant to remove someone from ONE
 * school erased them from all. It now names every organisation, and says "all N".
 *
 * Only relative imports: this file runs under `npm run test:spa` (node, no `@/` alias).
 */

export type RemovalOrganisation = { name: string; archived?: boolean };

export type RemovalAction = 'delete' | 'archive';

/** The organisations the action reaches, in the order given, blanks dropped. */
export function removalReach(orgs: readonly RemovalOrganisation[] | null | undefined): string[] {
    return (orgs ?? [])
        .filter(org => typeof org?.name === 'string' && org.name.trim() !== '')
        .map(org => (org.archived ? `${org.name} (archived)` : org.name));
}

/**
 * HTML for the confirmation. `escape` is the dialog escaper (core/plugins/swalSanitize)
 * so an organisation's or a person's name is text here, never markup; it is a parameter
 * only so this file stays loadable by the node test runner.
 */
export function removalWarning(
    action: RemovalAction,
    userName: string,
    orgs: readonly RemovalOrganisation[] | null | undefined,
    escape: (text: string) => string,
): string {
    const reach = removalReach(orgs);
    const verb = action === 'delete' ? 'delete' : 'archive';
    const who = escape(userName || 'this user');

    if (reach.length === 0) {
        return `You are going to ${verb} ${who} !`;
    }

    const list = reach.map(escape).join(', ');
    const effect = action === 'delete'
        ? 'This erases their login for good, along with their access and class assignments there.'
        : 'This locks their login out. It stays archived, with its access, until it is restored.';

    if (reach.length === 1) {
        return `You are going to ${verb} ${who}, who belongs to ${list}. ${effect}`;
    }

    return `You are going to ${verb} ${who}. This removes them from all ${reach.length} organisations: ${list}. ${effect}`
        + ` To remove them from one school only, use that school's own Teachers or Team & Access screen instead.`;
}
