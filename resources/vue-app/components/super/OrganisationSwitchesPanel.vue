<template>
    <section class="d-flex flex-column gap-3 w-100" aria-labelledby="org-switches-title">
        <div class="d-flex flex-column gap-1">
            <h2 id="org-switches-title" class="fs-5 fw-semibold mb-0">What {{ orgName }} has</h2>
            <span class="fs-6 text-muted">
                Its administrators see every screen that is switched on here. App Directory Listing, CRM Access
                and Manara Assistant keep their own switches above.
            </span>
        </div>

        <div v-if="flipNotice" class="alert alert-info py-2 px-3 mb-0 small" role="status">
            <i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ flipNotice }}
        </div>

        <div v-if="loading" class="text-muted small" role="status">
            <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
            Loading the switch list…
        </div>

        <template v-else>
            <!--
                The GET failed (or an older backend has no such endpoint). The grant switches
                still render from the masjid payload, exactly as this screen worked before, so
                a flip never depends on the new endpoint; only the module rows are missing.
            -->
            <div v-if="loadFailed" class="alert alert-danger py-2 px-3 mb-0 d-flex flex-wrap align-items-center gap-2" role="alert">
                <span>
                    <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
                    The switch list could not be loaded. Nothing has changed; reload to try again.
                </span>
                <button type="button" class="btn btn-sm btn-outline-danger" @click="load">Try again</button>
            </div>

            <div v-for="group in visibleGroups" :key="group.key" class="switch-group">
                <h3 class="fs-6 fw-semibold mb-3">{{ group.label }}</h3>

                <ul class="list-unstyled d-flex flex-column gap-3 m-0">
                    <li v-for="entry in group.entries" :key="entry.key" class="switch-row">
                        <div class="d-flex flex-column flex-md-row align-items-start justify-content-between gap-3">
                            <div class="d-flex flex-column gap-1">
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <span class="fw-semibold">{{ entry.label }}</span>
                                    <span v-if="entry.overridden" class="badge text-bg-light border">Set by a SuperAdmin</span>
                                </div>
                                <span :id="`switch-help-${entry.key}`" class="small text-muted">{{ entry.description }}</span>
                                <!-- What is live for this organisation right now (App\Support\ModuleFacts). -->
                                <ul v-if="entry.facts?.length" :id="`switch-facts-${entry.key}`" class="small text-muted mb-0 ps-3">
                                    <li v-for="(fact, index) in entry.facts" :key="index">{{ fact }}</li>
                                </ul>
                                <span v-if="sidebarTitles(entry).length" class="small">
                                    Sidebar: {{ sidebarTitles(entry).join(', ') }}
                                </span>
                                <!--
                                    A module's placement: the Details tabs it lives on, or — for a
                                    module with no admin screen at all (surface: 'app') — the mobile
                                    app's menu, which is the only thing its switch decides.
                                -->
                                <span v-if="entry.surface === 'app'" class="small">
                                    Where: Mobile app menu
                                </span>
                                <span v-else-if="entry.where" class="small">
                                    Where: {{ detailsTitle }} › {{ entry.where }}
                                </span>
                                <span v-if="notOfferedAndOff(entry)" class="small text-muted">
                                    Off — not offered to a {{ orgType }} unless you switch it on
                                </span>
                                <span v-else-if="entry.default_for_org_type !== null" class="small text-muted">
                                    Default for a {{ orgType }}: {{ entry.default_for_org_type ? 'on' : 'off' }}
                                </span>
                                <span v-if="entry.in_use" class="small text-warning-emphasis">
                                    {{ sectionCount(entry.in_use) }} on live pages show this.
                                </span>
                            </div>

                            <div class="flex-shrink-0 d-flex flex-column align-items-md-end gap-1">
                                <div v-if="entry.writer === 'capability'" class="form-check form-switch m-0 d-flex align-items-center gap-2">
                                    <input :id="`switch-${entry.key}`" class="form-check-input org-switch" type="checkbox" role="switch"
                                        :checked="entry.enabled" :disabled="busyKey !== null"
                                        :aria-label="`${entry.label} for ${orgName}`"
                                        :aria-describedby="entry.facts?.length ? `switch-help-${entry.key} switch-facts-${entry.key}` : `switch-help-${entry.key}`"
                                        @click.prevent="flip(entry)" />
                                    <label :for="`switch-${entry.key}`" class="form-check-label small fw-semibold" aria-hidden="true">
                                        <span v-if="busyKey === entry.key" class="spinner-border spinner-border-sm me-1"></span>
                                        {{ entry.enabled ? 'On' : 'Off' }}
                                    </label>
                                </div>
                                <template v-else>
                                    <span class="small fw-semibold">{{ columnEnabled(entry) ? 'On' : 'Off' }}</span>
                                    <span class="small text-muted">
                                        {{ entry.writer === 'crm' ? 'Use the CRM Access switch above.' : 'Use the Manara Assistant switch above.' }}
                                    </span>
                                </template>
                            </div>
                        </div>
                    </li>
                </ul>
            </div>

            <!-- Rows left out by rowRenders(): switches for screens this org type has no place for. -->
            <p v-if="hiddenLabels.length" class="small text-muted mb-0">
                Switches for screens a {{ orgType }} does not have are not shown: {{ hiddenLabels.join(', ') }}.
            </p>

            <!-- Every flip is audited server-side (masjid_capability_changes). -->
            <details v-if="history.length" class="switch-group">
                <summary class="fw-semibold">Recent changes ({{ history.length }})</summary>
                <div class="table-responsive mt-3">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">When</th>
                                <th scope="col">Who</th>
                                <th scope="col">What</th>
                                <th scope="col">Change</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="change in history" :key="change.id">
                                <td class="small text-nowrap">{{ formatWhen(change.created_at) }}</td>
                                <td class="small">
                                    <span v-if="change.actor_name">{{ change.actor_name }}</span>
                                    <span v-else class="text-muted fst-italic">deleted user</span>
                                </td>
                                <td class="small">{{ change.label }}</td>
                                <td class="small text-nowrap">
                                    {{ change.enabled_before ? 'on' : 'off' }} → {{ change.enabled_after ? 'on' : 'off' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </details>

            <!--
                Generated from the sidebar itself, so it can never claim a screen is switchable
                when it is not: every item this organisation's type can show that has no switch
                in the list above, with what actually decides it.
            -->
            <details v-if="notSwitchable.length" class="switch-group">
                <summary class="fw-semibold">Not switchable here ({{ notSwitchable.length }})</summary>
                <p class="small text-muted mt-2 mb-2">
                    Screens a {{ orgType }} can have that this list does not switch, and what decides each one.
                </p>
                <ul class="small mb-0">
                    <li v-for="row in notSwitchable" :key="row.to">
                        <span class="fw-semibold">{{ row.title }}</span>: {{ row.lever }}
                    </li>
                </ul>
            </details>
        </template>
    </section>
</template>

<script setup lang="ts">
import { getMessageFromObj } from '@/assets/ts/swalMethods';
import { MASJID_DASHBOARD_ASIDE_MENU } from '@/core/constants/dashboardAsideMenuItems';
import { detailsScreenTitle, menuItemTitle } from '@/core/access/orgAccess';
import { MSwal, QSwal } from '@/core/plugins/SweetAlerts2';
import ApiService from '@/core/services/ApiService';
import { AsideMenuItem } from '@/core/types/config/AsideMenuItem';
import { BackendResponseData } from '@/core/types/config/AxiosCustom';
import {
    CAPABILITY_LABELS,
    CapabilityChange,
    CapabilityEntry,
    CapabilityGroup,
    CapabilityKey,
    OrganisationCapabilities,
} from '@/core/types/data/Capability';
import { Masjid } from '@/core/types/data/Masjid';
import { DEFAULT_ORG_TYPE, MASJID_TERMINOLOGY, OrgType, TerminologyKey } from '@/core/types/data/Vertical';
import { AxiosError } from 'axios';
import { SweetAlertIcon, SweetAlertOptions } from 'sweetalert2';
import { computed, ref, watch } from 'vue';

/**
 * SuperAdmin > Masjids > {org}: what this organisation has, and the switches.
 *
 * Rows come from GET /api/admin/masjids/{id}/capabilities, grouped and labelled by
 * config/capabilities.php, so a new catalogue entry needs no change here. A flip is
 * PATCH .../capabilities/{key} form-encoded `enabled` 1/0, then a re-fetch for the
 * server's truth. CRM and the Assistant are listed read-only: their own switches on
 * the parent screen stay the only writers.
 *
 * The owner's sidebar for this organisation does NOT update from here: masjidStore is
 * null on every super route (router.ts), and re-entering the organisation's dashboard
 * reloads its payload. The screen says so after each flip.
 */

const props = defineProps<{
    masjid: Masjid;
}>();

const emit = defineEmits<{
    /** The saved organisation's grant and module state, for the parent's copy of the masjid. */
    updated: [saved: { capabilities?: Masjid['capabilities']; modules_off?: Masjid['modules_off']; modules_on?: Masjid['modules_on'] }];
}>();

const payload = ref<OrganisationCapabilities | null>(null);
const loading = ref(true);
const loadFailed = ref(false);
const busyKey = ref<string | null>(null);
const flipNotice = ref('');

const orgName = computed(() => payload.value?.org.name || props.masjid.name || 'this organisation');

const ORG_TYPES: OrgType[] = ['masjid', 'school', 'community'];

const orgType = computed<OrgType>(() => {
    const candidate = payload.value?.org.org_type ?? props.masjid.vertical?.org_type ?? props.masjid.org_type;
    return ORG_TYPES.includes(candidate as OrgType) ? candidate as OrgType : DEFAULT_ORG_TYPE;
});

/** This organisation's own words for the sidebar titles, with the masjid pack behind them. */
const term = (key: TerminologyKey): string =>
    props.masjid.vertical?.terminology?.[key] || MASJID_TERMINOLOGY[key];

/** "Masjid Details" / "School Details": the sidebar name every pointer to a Details tab uses. */
const detailsTitle = computed<string>(() => detailsScreenTitle(term));

// The sidebar items this organisation's type can show at all.
const itemsForType = computed<AsideMenuItem[]>(() => MASJID_DASHBOARD_ASIDE_MENU.filter(item =>
    !item.requiresOrgTypes || item.requiresOrgTypes.includes(orgType.value)));

/** A module this org type is not offered, and nobody has switched on here. */
function notOfferedAndOff(entry: CapabilityEntry): boolean {
    return entry.kind === 'module' && entry.offered_by_default === false && !entry.enabled;
}

/**
 * "Sidebar: …" — the items a switch hides, in this organisation's vocabulary. For a
 * module this type is not offered, the items it would add once switched on.
 */
function sidebarTitles(entry: CapabilityEntry): string[] {
    const couldSwitchOn = entry.kind === 'module' && entry.offered_by_default === false;

    return MASJID_DASHBOARD_ASIDE_MENU
        .filter(item => item.requiresModule === entry.key || item.requiresCapability === entry.key)
        .filter(item => couldSwitchOn || itemsForType.value.includes(item))
        .map(item => menuItemTitle(item, term));
}

// Wording kept from the switches this panel replaced, for the rows that still render
// when the list cannot be loaded.
const FALLBACK_GRANT_HELP: Partial<Record<CapabilityKey, string>> = {
    web_pages: "Let this organisation's admins build and edit their public website (pages and sections).",
    jummah_lunch: 'Jummah lunch ordering, the order board, and lunch-only volunteer logins.',
    school_calendar: 'School days, no-school days, and the school-day choices on registration forms.',
    form_editing: "Let this organisation's admins create and edit sign-up forms from Form Responses.",
};

const FALLBACK_GRANT_KEYS: CapabilityKey[] = ['web_pages', 'jummah_lunch', 'school_calendar', 'form_editing'];

const groups = computed<CapabilityGroup[]>(() => {
    if (payload.value) return payload.value.groups;
    if (!loadFailed.value) return [];

    const capabilities = props.masjid.capabilities ?? {};
    const entries: CapabilityEntry[] = FALLBACK_GRANT_KEYS
        .filter(key => key in capabilities)
        .map((key): CapabilityEntry => ({
            key,
            label: CAPABILITY_LABELS[key],
            description: FALLBACK_GRANT_HELP[key] ?? '',
            kind: 'grant',
            writer: 'capability',
            enabled: capabilities[key] === true,
            default_for_org_type: null,
            overridden: false,
            in_use: null,
        }));

    return entries.length ? [{ key: 'grants', label: 'Switched on per organisation', entries }] : [];
});

/**
 * Whether a row renders. A module row has to point somewhere this organisation can
 * see: a sidebar item its type can show, a `where` on the Details screen, the mobile
 * app's menu (`surface: 'app'`), or nothing yet because the module is not offered to
 * its type and this row is how it gets switched on. Any other module row would switch
 * a screen this organisation has no place for (Appointment Requests for a masjid), so
 * it is left out and named in one muted line. So a module needs one of the three
 * placements in config/capabilities.php.
 */
function rowRenders(entry: CapabilityEntry): boolean {
    if (entry.kind !== 'module') return true;
    if (entry.where) return true;
    // An app-only module has nothing in the admin to point at, and its row is the
    // only place it can be flipped: without this it would be dropped as a screen
    // this organisation has no place for.
    if (entry.surface === 'app') return true;
    if (entry.offered_by_default === false) return true;

    return itemsForType.value.some(item => item.requiresModule === entry.key);
}

const visibleGroups = computed<CapabilityGroup[]>(() => groups.value
    .map(group => ({ ...group, entries: group.entries.filter(rowRenders) }))
    .filter(group => group.entries.length > 0));

const hiddenLabels = computed<string[]>(() => groups.value
    .flatMap(group => group.entries.filter(entry => !rowRenders(entry)).map(entry => entry.label)));

const history = computed<CapabilityChange[]>(() => payload.value?.history ?? []);

/** CRM and the Assistant read from the masjid, so the switches above are reflected at once. */
function columnEnabled(entry: CapabilityEntry): boolean {
    if (entry.writer === 'crm') return !!props.masjid.crm_enabled;
    if (entry.writer === 'assistant') return !!props.masjid.assistant_enabled;
    return entry.enabled;
}

const notSwitchable = computed<{ to: string; title: string; lever: string }[]>(() => itemsForType.value
    .filter(item => !item.requiresModule && !item.requiresCapability)
    .map(item => ({ to: item.to, title: menuItemTitle(item, term), lever: leverFor(item) })));

function leverFor(item: AsideMenuItem): string {
    if (item.lever) return item.lever;
    if (!item.allowed_types.includes('MasjidAdmin')) return 'app drawer: Mobile App Features';
    if (item.requiresCrm) return 'part of Members, classes & giving (CRM switch above)';
    if (item.requiresAssistant) return 'part of Manara Assistant (switch above)';
    if (item.requiresOrgTypes) return `${item.requiresOrgTypes.join(' / ')}-only screen, no switch yet`;
    return 'always on';
}

function sectionCount(n: number): string {
    return `${n} ${n === 1 ? 'section' : 'sections'}`;
}

function formatWhen(value: string): string {
    const date = new Date(value);
    return isNaN(date.getTime()) ? value : date.toLocaleString();
}

function escapeHtml(text: string): string {
    return text.replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch] as string));
}

async function load() {
    const id = props.masjid?.id;
    if (!id) {
        loading.value = false;
        return;
    }

    loading.value = payload.value === null;

    await ApiService.get(`/api/admin/masjids/${id}/capabilities`)
        .then(res => {
            if (res.data?.status === 'success' && Array.isArray(res.data?.data?.groups)) {
                payload.value = res.data.data;
                loadFailed.value = false;
            } else {
                payload.value = null;
                loadFailed.value = true;
            }
        })
        .catch((e: Error) => {
            console.log('Fetch organisation capabilities error: ', e);
            payload.value = null;
            loadFailed.value = true;
        })
        .finally(() => {
            loading.value = false;
        });
}

watch(() => props.masjid?.id, () => {
    payload.value = null;
    flipNotice.value = '';
    load();
}, { immediate: true });

/** The CRM clause for a module that also needs Members, classes & giving. */
function crmLine(org: string): string {
    return props.masjid.crm_enabled
        ? 'It also needs Members, classes & giving, which is on.'
        : `It also needs Members, classes & giving, which is off for ${org}, so nobody there sees it until that is switched on too.`;
}

/**
 * Per module: what else happens when it is switched OFF, beyond the lines every module
 * gets. Only what the server actually does; the entry's facts carry the live counts.
 */
function switchOffLines(entry: CapabilityEntry, org: string): string[] {
    switch (entry.key) {
        case 'prayer_times':
            return [
                `Manara stops its backup prayer reminders for ${org} to phones that have not opened the app for 5 days, and its daily background refresh.`,
                'The website, apps and TV keep showing the last saved times.',
                // iOS arms 6 days ahead and counts on the daily refresh to re-arm an unopened app.
                'Android phones re-arm their own adhan and iqama alerts every day. An iPhone re-arms them when the app is opened, so one left unopened for about 6 days can stop alerting.',
                'Iqama times saved while this is off reach iPhones only when the app is next opened.',
            ];
        case 'giving':
            return [
                // The Online payments tab needs the CRM (the connect routes sit inside it). A school
                // or community organisation has the tab whether or not Giving is on
                // (showsOnlinePaymentsTab), so the switch moves nothing there; a masjid's Connect
                // panel moves from the Giving Dashboard to the tab.
                ...(!props.masjid.crm_enabled
                    ? []
                    : orgType.value !== 'masjid'
                        ? [`Stripe setup and the forms card-payment Stop button stay on ${detailsTitle.value} › Online payments.`]
                        : [`Stripe setup and the forms card-payment Stop button move to ${detailsTitle.value} › Online payments.`]),
                // Both apps fall back to the donation link on Donate, and say "No donation options are
                // available right now" when there is none.
                payload.value?.org.donation_link_set === false
                    ? `The app stops taking new gifts. ${org} has no donation link set, so an app opening Donate says no donation options are available. To remove the Donate tab too, switch off Donate in Mobile App Features.`
                    : 'The app stops taking new gifts. An app opening Donate shows your donation link instead. To remove the Donate tab too, switch off Donate in Mobile App Features.',
                `Nobody at ${org} can send year-end giving statements while this is off (you still can, as SuperAdmin).`,
                // Open monthly-gift pages refuse the switch-off itself (GivingSwitch::openCheckoutCount),
                // so only one-time pages can still complete after it.
                'One-time gift checkout pages opened in the last 24 hours can still complete.',
            ];
        case 'splash':
            return ['A splash already live keeps showing until its end date.'];
        case 'services':
            return ['Broadcasts, Friday lunch and About Us keep listing the services already there.'];
        case 'donation_link':
            return ['The link already set keeps showing on the website, TV board and app.'];
        case 'properties':
            return ['The records stay; nothing public shows them.'];
        case 'appointment_requests':
            return ['The website appointment form refuses new requests. Requests already received stay.'];
        default:
            return [];
    }
}

/** Per module: what switching ON a module this org type is not offered means. */
function switchOnLines(entry: CapabilityEntry, org: string): string[] {
    const lines = [
        `${entry.label} is not offered to a ${orgType.value} unless you switch it on.`,
        `${org}'s administrators will see it in their sidebar, and its editing API will accept them.`,
    ];

    if (entry.key === 'giving') {
        // A linked program org (DECISIONS.md 2026-09-15) is refused Stripe setup while the link
        // is set, so the Online payments pointer would send it to a button it does not have.
        const linked = (props.masjid as Masjid & { forms_card_via_masjid_id?: number | null })
            .forms_card_via_masjid_id != null;

        lines.push(
            crmLine(org),
            linked
                ? `${org} cannot take card gifts: its form card payments go through its parent organisation's Stripe account, and Stripe setup is refused while that link is set. A super admin removes the link under Form card payments on this screen first, and its forms then take no card payments until its own account is ready.`
                : props.masjid.crm_enabled
                    ? `${org} needs its own Stripe account to take card gifts. Connect it on ${detailsTitle.value} › Online payments.`
                    : `${org} needs its own Stripe account to take card gifts. Connect it on ${detailsTitle.value} › Online payments once Members, classes & giving is on.`,
            `Receipts and year-end statements use wording for a ${orgType.value}: they leave out the sentence about intangible religious benefits.`,
            `They print the 501(c)(3) sentence only when ${org} has a tax ID saved.`,
        );
    }
    if (entry.key === 'properties') lines.push(crmLine(org));

    return lines;
}

async function confirmList(title: string, lines: string[], icon: SweetAlertIcon, confirmButtonText: string): Promise<boolean> {
    const result = await QSwal.fire({
        title,
        html: `<ul class="text-start mb-0">${lines.map(line => `<li>${escapeHtml(line)}</li>`).join('')}</ul>`,
        icon,
        confirmButtonText,
    });
    return result.isConfirmed;
}

/**
 * The confirm dialog. Switching a module OFF says exactly what stops and what stays.
 * An app-only module (`surface: 'app'`) gets its own pair of sentences: it has no
 * admin screen, so the only thing that moves is one row of the mobile app's menu.
 */
async function confirmFlip(entry: CapabilityEntry, enabled: boolean): Promise<boolean> {
    const org = orgName.value;
    const facts = entry.facts ?? [];
    const notOffered = entry.kind === 'module' && entry.offered_by_default === false;
    // A module with no admin screen at all: it decides one row of the mobile app's
    // menu and nothing else, so every sentence about sidebars, editing APIs and the
    // "Switched off for {org}" list would be false here.
    const appOnly = entry.kind === 'module' && entry.surface === 'app';

    if (appOnly) {
        return confirmList(
            enabled ? `Switch on ${entry.label} for ${org}?` : `Switch off ${entry.label}?`,
            [
                enabled
                    ? `The app menu shows ${entry.label} again. Nothing changes in the admin.`
                    : 'The app menu stops showing it. Nothing changes in the admin.',
                ...(notOffered && enabled
                    ? [`${entry.label} is not offered to a ${orgType.value} unless you switch it on.`]
                    : []),
                'People with the app see it the next time their menu refreshes.',
                ...facts,
            ],
            enabled ? 'question' : 'warning',
            enabled ? 'Yes, switch it on' : 'Yes, switch it off'
        );
    }

    if (entry.kind === 'module' && !enabled) {
        const lines = [
            `${org}'s administrators stop seeing ${entry.label}, and its editing API refuses them.`,
            // A module this type is not offered leaves the sidebar for everyone (menuItemState),
            // so the Switched-off list is no way back to it. A module with a `where` has no
            // sidebar item to list at all: its tabs stay on the Details screen, marked Off.
            notOffered
                ? `A ${orgType.value} is not offered ${entry.label}, so it leaves your sidebar too; switch it back on here to reach it.`
                : entry.where
                    ? `You can still open them, marked Off, on ${detailsTitle.value} › ${entry.where}.`
                    : `You can still open it from “Switched off for ${org}” in your sidebar.`,
            'What families already see on the website or app stays.',
        ];
        if (entry.in_use && entry.in_use > 0) lines.push(`${sectionCount(entry.in_use)} on live pages show this.`);
        if (entry.key === 'contact_requests') lines.push('Website and app contact forms will refuse new messages.');
        if (entry.key === 'programs') lines.push('Public program sign-up closes.');
        lines.push(...facts, ...switchOffLines(entry, org));

        return confirmList(`Switch off ${entry.label}?`, lines, 'warning', 'Yes, switch it off');
    }

    if (notOffered && enabled) {
        return confirmList(`Switch on ${entry.label} for ${org}?`, [...switchOnLines(entry, org), ...facts], 'question', 'Yes, switch it on');
    }

    const text = entry.kind === 'module'
        ? `Switch ${entry.label} back on for ${org}? Its administrators will see it again.`
        : `Are you sure you want to ${enabled ? 'switch on' : 'switch off'} ${entry.label} for ${org}? Its administrators ${enabled ? 'will' : 'will no longer'} see it.`;

    const result = await QSwal.fire('Question', text, 'question');
    return result.isConfirmed;
}

async function flip(entry: CapabilityEntry) {
    if (entry.writer !== 'capability' || busyKey.value !== null || !props.masjid?.id) return;

    const enabled = !entry.enabled;
    if (!(await confirmFlip(entry, enabled))) return;

    await sendFlip(entry, enabled);
}

/**
 * PATCH one switch. Giving switched off while a monthly gift can still charge, or while a
 * monthly-gift checkout page is still open, answers 422 with its sentence and changes
 * nothing (owner, 2026-09-14: block). There is no "switch off anyway": the SuperAdmin
 * cancels the gift, or waits for the page to expire, and flips again.
 */
async function sendFlip(entry: CapabilityEntry, enabled: boolean) {
    busyKey.value = entry.key;
    const org = orgName.value;
    const swalInstance: SweetAlertOptions = { title: 'Info', text: 'Nothing', icon: 'info' };

    const apiRequestData = new URLSearchParams();
    apiRequestData.append('enabled', enabled ? '1' : '0');

    await ApiService.patch(`/api/admin/masjids/${props.masjid.id}/capabilities/${entry.key}`, apiRequestData)
        .then(async res => {
            if (res.data?.status === 'success') {
                const saved = res.data.data ?? {};
                emit('updated', {
                    capabilities: saved.capabilities
                        ?? (entry.kind === 'grant' ? { ...(props.masjid.capabilities ?? {}), [entry.key]: enabled } : undefined),
                    modules_off: saved.modules_off,
                    modules_on: saved.modules_on,
                });

                flipNotice.value = `Re-open ${org}'s dashboard to see its sidebar change.`;
                swalInstance.title = 'Success';
                swalInstance.text = `${entry.label} switched ${enabled ? 'on' : 'off'}. ${flipNotice.value}`;
                swalInstance.icon = 'success';

                // The server's truth: defaults, the override badge and the history row.
                await load();
            } else {
                swalInstance.title = 'Sorry';
                swalInstance.text = getMessageFromObj(res);
                swalInstance.icon = 'warning';
            }
        })
        .catch((e: AxiosError<BackendResponseData>) => {
            // A refusal the server explains reads as its sentence, not as an HTTP status: the
            // giving switch while monthly gifts can still charge donors, or while checkout
            // pages are still open, answers 422 with data.capability[0], which
            // getMessageFromObj flattens. Nothing was changed.
            const refused = e.response?.status === 422;

            swalInstance.title = refused ? 'Not changed' : e.message;
            swalInstance.text = getMessageFromObj(e);
            swalInstance.icon = refused ? 'warning' : 'error';
        })
        .finally(() => {
            busyKey.value = null;
            MSwal.fire(swalInstance);
        });
}
</script>

<style scoped>
.switch-group {
    border: 1px solid var(--input-border);
    border-radius: .5rem;
    padding: 1rem;
}

.switch-group summary {
    cursor: pointer;
}

.switch-row + .switch-row {
    border-top: 1px solid var(--input-border);
    padding-top: 1rem;
}

.org-switch {
    width: 2.5rem;
    height: 1.25rem;
    cursor: pointer;
}

.org-switch:checked {
    background-color: var(--cgreen);
    border-color: var(--cgreen);
}

.org-switch:focus-visible {
    outline: 2px solid var(--cgreen);
    outline-offset: 2px;
}
</style>
