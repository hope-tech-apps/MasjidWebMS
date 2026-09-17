import { ref } from "vue";

/**
 * The browser tab's title: "<page> | <organisation>".
 *
 * WHY THIS IS RUNTIME AND NOT AN ENV VAR
 * --------------------------------------
 * The router used to append `import.meta.env.VITE_APP_NAME`. That is baked in
 * at BUILD time, and the deployed bundle is built with no `.env` at all — which
 * is deliberate, because the same build must leave `VITE_APP_URL` empty so API
 * calls stay same-origin on every host (see `appConfigConstants.ts` and
 * `OrgPortal.vue`). So every tab on production read "… | undefined". Copying a
 * `.env` in would only have made it read "… | Laravel", and one bundle serves
 * many organisations anyway: no single baked name is right for most of them.
 *
 * So the name is whatever organisation the current screen is FOR, supplied at
 * runtime by the layout that already loads it (the admin masjid store, the
 * teacher's school, the family portal's org, the lunch board's masjid, the
 * portal page). Until one is known — the sign-in page, a super admin's
 * cross-tenant screens — the tab says "Manara", the same name the server's own
 * HTML <title> uses, rather than inventing an organisation.
 *
 * Owner decision 2026-09-17: "Org name in browser tabs (runtime, per org)".
 */
const FALLBACK = "Manara";

const page = ref("");
const org = ref("");

function render(): void {
    const suffix = org.value.trim() || FALLBACK;
    const head = page.value.trim();
    document.title = head ? `${head} | ${suffix}` : suffix;
}

/** Called by the router on every navigation. */
export function setPageTitle(title: unknown): void {
    page.value = typeof title === "string" ? title : "";
    render();
}

/**
 * Called by a layout once it knows which organisation it is showing, and with
 * `null` when it unmounts — a parent signing out of one school's portal must not
 * see the next page titled with that school's name.
 */
export function setOrgTitle(name: string | null | undefined): void {
    org.value = typeof name === "string" ? name : "";
    render();
}
