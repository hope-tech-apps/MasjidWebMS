<template>
    <div>
        <PageDataContainer :title="pageTitle" :hideButton="true">
            <template #headerButtons>
                <router-link class="btn btn-outline-secondary" :to="{ name: 'masjid.groups' }">
                    <i class="bi bi-arrow-left me-1"></i>
                    {{ groupsTerm }}
                </router-link>
            </template>

            <div class="container w-100">
                <!-- Bootstrapping -->
                <div v-if="bootstrapping" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>

                <!-- Not found -->
                <div v-else-if="notFound" class="text-center py-5 text-muted">
                    <i class="bi bi-question-circle fs-1 d-block mb-3"></i>
                    <p class="mb-3">That {{ groupsTerm.toLowerCase() }} entry no longer exists.</p>
                    <router-link class="btn btn-outline-primary btn-sm" :to="{ name: 'masjid.groups' }">
                        Back to the list
                    </router-link>
                </div>

                <!-- Load failed for a reason other than a missing row -->
                <div v-else-if="loadError" class="alert alert-danger" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    {{ loadError }}
                    <button class="btn btn-sm btn-outline-danger ms-3" @click="bootstrap">Retry</button>
                </div>

                <div v-else-if="group">
                    <!-- Header -->
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-4">
                        <span class="badge bg-light text-dark border text-capitalize">{{ group.kind }}</span>
                        <span v-if="group.is_active" class="badge bg-success-subtle text-success">Active</span>
                        <span v-else class="badge bg-secondary-subtle text-secondary">Inactive</span>
                        <span class="text-muted small font-monospace">{{ group.slug }}</span>
                        <span v-if="group.description" class="text-muted small ms-2">{{ group.description }}</span>
                    </div>

                    <!-- Tabs -->
                    <ul class="nav nav-tabs mb-4">
                        <li v-for="tab in tabs" :key="tab.key" class="nav-item">
                            <button
                                type="button"
                                class="nav-link"
                                :class="{ active: activeTab === tab.key }"
                                @click="activeTab = tab.key"
                            >
                                <i :class="`bi ${tab.icon} me-1`"></i>
                                {{ tab.label }}
                            </button>
                        </li>
                    </ul>

                    <!--
                        Each tab is mounted only while it is the active one (v-if,
                        not v-show): every one of these panels is a group-scoped
                        DISCLOSURE about children, and mounting them all up front
                        would fetch four sets of records an admin never asked to
                        see. Least disclosure applies to what we request, not only
                        to what we render.
                    -->
                    <GroupRosterTab
                        v-if="activeTab === 'roster'"
                        :groupId="groupId"
                        :memberships="groupsStore.memberships"
                        :loading="rosterLoading"
                        :loadError="rosterError"
                        @changed="loadRoster"
                        @reload="loadRoster"
                    />

                    <GroupStoryTab v-else-if="activeTab === 'story'" :groupId="groupId" />

                    <GroupPointsTab
                        v-else-if="activeTab === 'points'"
                        :groupId="groupId"
                        :memberships="groupsStore.memberships"
                    />

                    <GroupHifzTab
                        v-else-if="activeTab === 'hifz'"
                        :groupId="groupId"
                        :memberships="groupsStore.memberships"
                    />

                    <GroupThreadsTab
                        v-else-if="activeTab === 'threads'"
                        :groupId="groupId"
                        :memberships="groupsStore.memberships"
                    />
                </div>
            </div>
        </PageDataContainer>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onBeforeMount } from 'vue';
import { useRoute } from 'vue-router';
import { AxiosError } from 'axios';
import PageDataContainer from '@/components/PageDataContainer.vue';
import GroupRosterTab from './groups/GroupRosterTab.vue';
import GroupStoryTab from './groups/GroupStoryTab.vue';
import GroupPointsTab from './groups/GroupPointsTab.vue';
import GroupHifzTab from './groups/GroupHifzTab.vue';
import GroupThreadsTab from './groups/GroupThreadsTab.vue';
import { Group } from '@/core/types/data/masjid-related/Group';
import { useGroupsStore } from '@/stores/masjid/groupsStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { apiErrorText } from '@/core/services/ApiErrors';

/**
 * One group, with everything that hangs off it: the roster, the class story, the
 * behaviour record, the hifz log and the teacher <-> guardian conversations.
 *
 * The ROSTER is loaded here rather than inside each tab because four of the five
 * panels need it (to name a student in a picker, to render a per-student row) and
 * fetching it five times would be four extra requests for the same list. The
 * disclosures themselves are NOT hoisted: each tab fetches its own records only
 * while it is open.
 *
 * Nothing on this screen names the concept. "Classroom" / "Halaqa" / "Team" comes
 * from the terminology pack via `masjidStore.term('groups')` — see
 * .claude/rules/verticals.md.
 */

type TabKey = 'roster' | 'story' | 'points' | 'hifz' | 'threads';

// Routing
const route = useRoute();

// Stores
const groupsStore = useGroupsStore();
const masjidStore = useMasjidStore();

// State
const groupId = Number(route.params.groupId);
const group = ref<Group | null>(null);
const bootstrapping = ref(true);
const notFound = ref(false);
const loadError = ref('');
const rosterLoading = ref(false);
const rosterError = ref('');
const activeTab = ref<TabKey>('roster');

const tabs: { key: TabKey; label: string; icon: string }[] = [
    { key: 'roster', label: 'Roster', icon: 'bi-people' },
    // "Class Story" is the name of the FEATURE, not of the tenant's group, so it
    // is authored rather than drawn from the terminology pack — a masjid's
    // halaqa has a class story just as a school's classroom does.
    { key: 'story', label: 'Class Story', icon: 'bi-journal-text' },
    { key: 'points', label: 'Points', icon: 'bi-star' },
    { key: 'hifz', label: 'Hifz', icon: 'bi-book' },
    { key: 'threads', label: 'Messages', icon: 'bi-chat-dots' }
];

// Computed
const groupsTerm = computed<string>(() => masjidStore.term('groups'));

const pageTitle = computed<string>(() => group.value?.name || groupsTerm.value);

// Lifecycle
onBeforeMount(async () => {
    await bootstrap();
});

// Methods
const bootstrap = async () => {
    bootstrapping.value = true;
    notFound.value = false;
    loadError.value = '';
    try {
        group.value = await groupsStore.fetchGroup(groupId);
        if (!group.value) {
            notFound.value = true;
            return;
        }
        await loadRoster();
    } catch (error) {
        // A cross-tenant or deleted id is a 404 MISS by design, not a failure to
        // report as an error — see .claude/rules/tenant-scoping.md.
        if ((error as AxiosError)?.response?.status === 404) {
            notFound.value = true;
        } else {
            loadError.value = apiErrorText(error, 'Failed to load this group.');
        }
    } finally {
        bootstrapping.value = false;
    }
};

const loadRoster = async () => {
    rosterLoading.value = true;
    rosterError.value = '';
    try {
        await groupsStore.fetchMemberships(groupId);
    } catch (error) {
        rosterError.value = apiErrorText(error, 'Failed to load the roster.');
    } finally {
        rosterLoading.value = false;
    }
};
</script>

<style scoped>
.nav-tabs .nav-link {
    color: #6c757d;
}

.nav-tabs .nav-link.active {
    color: #198754;
    font-weight: 600;
}
</style>
