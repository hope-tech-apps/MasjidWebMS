<template>
    <!--
        The organisation switcher (S5 of docs/multi-tenant-admin-design.md).

        Renders NOTHING for an account with one organisation — no button, no
        caret, no gap in the header. That is the requirement, not an
        optimisation: an admin who administers one masjid must see exactly the
        header they see today, and a control that offers a choice of one is a
        control that invites the question "which one am I in?" where there was
        never any doubt.

        Bootstrap dropdown, the same one the account menu beside it uses, because
        the admin UI has one way of showing a menu and this is it.
    -->
    <div v-if="tenantSwitch.canSwitch" class="btn-group org-switcher">
        <button type="button" class="btn btn-sm btn-light-success dropdown-toggle d-flex align-items-center gap-2"
            data-bs-toggle="dropdown" aria-expanded="false" :disabled="tenantSwitch.switching"
            :aria-label="`You are in ${currentName}. Switch organisation.`" title="Switch organisation">
            <span v-if="tenantSwitch.switching" class="spinner-border spinner-border-sm" role="status"
                aria-hidden="true"></span>
            <i v-else class="bi bi-arrow-left-right" aria-hidden="true"></i>
            <span class="d-none d-md-inline">Switch</span>
        </button>

        <ul class="dropdown-menu org-switcher-menu">
            <li>
                <h6 class="dropdown-header">Your organisations</h6>
            </li>

            <li v-for="membership in tenantSwitch.memberships" :key="membership.masjid_id">
                <button type="button" class="dropdown-item d-flex align-items-center gap-2"
                    :class="{ 'org-switcher-current': isCurrent(membership) }"
                    :aria-current="isCurrent(membership) ? 'true' : undefined" :disabled="tenantSwitch.switching"
                    @click.prevent="choose(membership.masjid_id)">
                    <i class="bi" :class="isCurrent(membership) ? 'bi-check2' : 'bi-building'" aria-hidden="true"></i>
                    <span class="flex-grow-1 text-start">{{ label(membership) }}</span>
                    <!--
                        `pivot.role` is ADVISORY (design § Roles): authorization
                        runs on the global `users.type` bridge and nothing in the
                        SPA may gate on this. It is shown because a consultant
                        holding two organisations wants to know which hat each
                        row is, and for no other reason.
                    -->
                    <span v-if="membership.role" class="badge org-switcher-role">{{ roleLabel(membership.role) }}</span>
                    <span v-if="isCurrent(membership)" class="visually-hidden">(current organisation)</span>
                </button>
            </li>

            <template v-if="tenantSwitch.switchError">
                <li>
                    <hr class="dropdown-divider">
                </li>
                <li>
                    <div class="px-3 py-1 small text-danger" role="alert">{{ tenantSwitch.switchError }}</div>
                </li>
            </template>
        </ul>
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { Membership, membershipLabel } from '@/core/types/data/Membership';
import { useTenantSwitchStore } from '@/stores/tenantSwitchStore';

const tenantSwitch = useTenantSwitchStore();

/**
 * Marked against what the SERVER bound, falling back to this tab's selection —
 * the tick has to follow the organisation the rows actually came from, not the
 * one the tab asked for. When those differ the header says so
 * (TenantMismatchNotice); the tick is not the place to hide it.
 */
const isCurrent = (membership: Membership): boolean =>
    tenantSwitch.currentMasjidId === membership.masjid_id;

const currentName = computed<string>(() => tenantSwitch.nameFor(tenantSwitch.currentMasjidId));

const label = (membership: Membership): string => membershipLabel(membership);

/** `masjid-admin` reads as "Masjid admin". Display only. */
function roleLabel(role: string): string {
    const words = role.replace(/[-_]/g, ' ');

    return words.charAt(0).toUpperCase() + words.slice(1);
}

function choose(masjidId: number): void {
    if (tenantSwitch.currentMasjidId === masjidId) return;

    tenantSwitch.switchTo(masjidId);
}
</script>

<style scoped>
.org-switcher-menu {
    min-width: 16rem;
    --bs-dropdown-border-color: var(--lighted-gray);
}

.org-switcher-menu .dropdown-item {
    cursor: pointer;
}

.org-switcher-menu .dropdown-item:focus {
    background-color: var(--cgreen-active) !important;
    color: white;
}

.org-switcher-current {
    /* The current row is marked, not disabled: re-selecting it is a no-op and
       greying it out reads as "this organisation is unavailable". */
    background-color: var(--cgreen-light);
    font-weight: 600;
}

.org-switcher-role {
    /* #016B31 on the white menu fill is 6.67:1, the same pairing the header
       search results use; --cgreen would be 2.39:1. */
    background-color: var(--input-border);
    color: #016B31;
    font-weight: 500;
}
</style>
