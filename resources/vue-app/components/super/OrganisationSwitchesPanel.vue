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

            <div v-for="group in groups" :key="group.key" class="switch-group">
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
                                <span v-if="sidebarTitles(entry.key).length" class="small">
                                    Sidebar: {{ sidebarTitles(entry.key).join(', ') }}
                                </span>
                                <span v-if="entry.default_for_org_type !== null" class="small text-muted">
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
                                        :aria-describedby="`switch-help-${entry.key}`"
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
import { menuItemTitle } from '@/core/access/orgAccess';
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
import { SweetAlertOptions } from 'sweetalert2';
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
    updated: [saved: { capabilities?: Masjid['capabilities']; modules_off?: Masjid['modules_off'] }];
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

// The sidebar items this organisation's type can show at all.
const itemsForType = computed<AsideMenuItem[]>(() => MASJID_DASHBOARD_ASIDE_MENU.filter(item =>
    !item.requiresOrgTypes || item.requiresOrgTypes.includes(orgType.value)));

/** "Sidebar: …" — the items a switch hides, in this organisation's vocabulary. */
function sidebarTitles(key: string): string[] {
    return itemsForType.value
        .filter(item => item.requiresModule === key || item.requiresCapability === key)
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

/** The confirm dialog. Switching a module OFF says exactly what stops and what stays. */
async function confirmFlip(entry: CapabilityEntry, enabled: boolean): Promise<boolean> {
    const org = orgName.value;

    if (entry.kind === 'module' && !enabled) {
        const lines = [
            `${org}'s administrators stop seeing ${entry.label}, and its editing API refuses them.`,
            `You can still open it from “Switched off for ${org}” in your sidebar.`,
            'What families already see on the website or app stays.',
        ];
        if (entry.in_use && entry.in_use > 0) lines.push(`${sectionCount(entry.in_use)} on live pages show this.`);
        if (entry.key === 'contact_requests') lines.push('Website and app contact forms will refuse new messages.');
        if (entry.key === 'programs') lines.push('Public program sign-up closes.');

        const result = await QSwal.fire({
            title: `Switch off ${entry.label}?`,
            html: `<ul class="text-start mb-0">${lines.map(line => `<li>${escapeHtml(line)}</li>`).join('')}</ul>`,
            icon: 'warning',
            confirmButtonText: 'Yes, switch it off',
        });
        return result.isConfirmed;
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
            swalInstance.title = e.message;
            swalInstance.text = getMessageFromObj(e);
            swalInstance.icon = 'error';
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
