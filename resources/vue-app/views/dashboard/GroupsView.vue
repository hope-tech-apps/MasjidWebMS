<template>
    <div>
        <PageDataContainer
            :title="groupsTerm"
            :paginationOptions="paginationOptions"
            :buttonProps="{ title: 'Add New', type: 'button', class: 'btn btn-success', disabled: false }"
            @headerButtonClick="openCreateModal"
            @pageChange="pageChange"
        >
            <div class="container w-100">
                <!-- Stats Card -->
                <div class="row mb-4">
                    <div class="col-md-4 col-lg-3">
                        <div class="stats-card">
                            <div class="stats-icon">
                                <i class="bi bi-people-fill"></i>
                            </div>
                            <div class="stats-content">
                                <div class="stats-label">Total {{ groupsTerm }}</div>
                                <div class="stats-value">{{ paginationOptions?.itemsTotal || 0 }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Search -->
                <div class="row mb-4">
                    <div class="col-md-8 col-lg-6">
                        <div class="search-box">
                            <i class="bi bi-search search-icon"></i>
                            <input
                                type="text"
                                class="search-input"
                                placeholder="Search by name, slug, or description..."
                                v-model="searchQuery"
                            >
                            <button
                                v-if="searchQuery"
                                class="search-clear-btn"
                                @click="searchQuery = ''"
                                type="button"
                            >
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Loading State -->
                <div v-if="loading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>

                <!-- Error State -->
                <div v-else-if="loadError" class="alert alert-danger" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    {{ loadError }}
                    <button class="btn btn-sm btn-outline-danger ms-3" @click="loadData(1, searchQuery)">Retry</button>
                </div>

                <!-- Empty State -->
                <div v-else-if="groups.length === 0" class="text-center py-5 text-muted">
                    <i class="bi bi-people fs-1 d-block mb-3"></i>
                    <p>No {{ groupsTerm.toLowerCase() }} yet</p>
                </div>

                <!-- Groups Table -->
                <div v-else class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Kind</th>
                                <th class="text-center">Members</th>
                                <th class="text-center">Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="group in groups" :key="group.id">
                                <td>
                                    <router-link
                                        class="fw-semibold text-decoration-none"
                                        :to="{ name: 'masjid.groupDetail', params: { groupId: group.id } }"
                                    >
                                        {{ group.name }}
                                    </router-link>
                                    <div class="text-muted small font-monospace">{{ group.slug }}</div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border text-capitalize">{{ group.kind }}</span>
                                </td>
                                <td class="text-center">{{ group.participants_count ?? 0 }}</td>
                                <td class="text-center">
                                    <span v-if="group.is_active" class="badge bg-success-subtle text-success">Active</span>
                                    <span v-else class="badge bg-secondary-subtle text-secondary">Inactive</span>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <router-link
                                            class="btn btn-outline-primary"
                                            :to="{ name: 'masjid.groupDetail', params: { groupId: group.id } }"
                                            title="Open"
                                        >
                                            <i class="bi bi-box-arrow-in-right"></i>
                                        </router-link>
                                        <button class="btn btn-outline-secondary" @click="openEditModal(group)" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-outline-danger" @click="confirmDeactivate(group)" title="Deactivate">
                                            <i class="bi bi-archive"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </PageDataContainer>

        <!-- Create / Edit Modal -->
        <Teleport to="body">
            <div v-if="showFormModal" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);" @click.self="closeFormModal">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="bi bi-people me-2"></i>
                                {{ isEditForm ? 'Edit' : 'Add New' }}
                            </h5>
                            <button type="button" class="btn-close" @click="closeFormModal"></button>
                        </div>
                        <form @submit.prevent="submitForm">
                            <div class="modal-body">
                                <div class="row g-3">
                                    <div class="col-md-7">
                                        <label class="form-label">Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" v-model.trim="form.name" @input="syncSlug" required>
                                    </div>
                                    <div class="col-md-5">
                                        <label class="form-label">Kind <span class="text-danger">*</span></label>
                                        <!-- Options come from the server's Group::KINDS, never a local copy. -->
                                        <select class="form-select text-capitalize" v-model="form.kind" required>
                                            <option v-for="kind in kinds" :key="kind" :value="kind">{{ kind }}</option>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Slug <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control font-monospace" v-model.trim="form.slug" required>
                                        <div class="form-text">Lowercase letters, numbers and hyphens.</div>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Description</label>
                                        <textarea class="form-control" rows="2" v-model.trim="form.description"></textarea>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Starts on</label>
                                        <input type="date" class="form-control" v-model="form.starts_on">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Ends on</label>
                                        <input type="date" class="form-control" v-model="form.ends_on">
                                    </div>
                                    <div class="col-12">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="group_is_active" v-model="form.is_active">
                                            <label class="form-check-label" for="group_is_active">Active</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" @click="closeFormModal" :disabled="saving">
                                    Cancel
                                </button>
                                <button type="submit" class="btn btn-success" :disabled="saving || !form.name || !form.slug">
                                    <span v-if="saving" class="spinner-border spinner-border-sm me-1" role="status"></span>
                                    {{ isEditForm ? 'Save Changes' : 'Add' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<script setup lang="ts">
import { ref, onBeforeMount, computed, watch } from 'vue';
import PageDataContainer from '@/components/PageDataContainer.vue';
import { PageChangeData, PaginationOptions } from '@/core/types/elements/Pagination';
import { Group, GroupKind, GroupPayload } from '@/core/types/data/masjid-related/Group';
import { useGroupsStore } from '@/stores/masjid/groupsStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { apiErrorText } from '@/core/services/ApiErrors';
import Swal from 'sweetalert2';

/**
 * The tenant's groups — the ClassDojo-equivalent entry point.
 *
 * Every admin-facing mention of the concept is `masjidStore.term('groups')`, so
 * a school reads "Classrooms", a masjid reads "Halaqat" and a community org
 * reads "Teams" off the same screen. Nothing here hardcodes any of the three;
 * see .claude/rules/verticals.md.
 */

// Stores
const groupsStore = useGroupsStore();
const masjidStore = useMasjidStore();

// State
const loading = ref(false);
const saving = ref(false);
const loadError = ref('');
const searchQuery = ref('');
const showFormModal = ref(false);
const isEditForm = ref(false);
const editingId = ref<number | null>(null);
/** Only auto-derive the slug while the admin has not typed one of their own. */
const slugTouched = ref(false);
let searchTimeout: ReturnType<typeof setTimeout> | null = null;

const emptyForm = (): GroupPayload => ({
    name: '', slug: '', kind: 'class', description: '', is_active: true, starts_on: '', ends_on: ''
});
const form = ref<GroupPayload>(emptyForm());

// Computed
/** What this tenant calls a group — "Classrooms", "Halaqat", "Teams". */
const groupsTerm = computed<string>(() => masjidStore.term('groups'));

const groups = computed<Group[]>(() => (groupsStore.groupsPaginated?.data as Group[]) || []);

/** The kind vocabulary as the SERVER states it; the literal list is a fallback for a cold load. */
const kinds = computed<GroupKind[]>(() => groupsStore.groupsMeta?.kinds ?? ['general', 'class', 'halaqa', 'team']);

const paginationOptions = computed<PaginationOptions | undefined>(() => {
    if (!groupsStore.groupsPaginated) return undefined;
    return {
        currentPage: groupsStore.groupsPaginated.current_page,
        itemsTotal: groupsStore.groupsPaginated.total,
        perPage: groupsStore.groupsPaginated.per_page
    };
});

// Lifecycle
onBeforeMount(async () => {
    await loadData();
});

// Debounced search
watch(searchQuery, () => {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(async () => {
        await loadData(1, searchQuery.value);
    }, 500);
});

// Methods
const loadData = async (page: number = 1, search: string = '') => {
    loading.value = true;
    loadError.value = '';
    try {
        await groupsStore.fetchGroups(page, search);
    } catch (error) {
        loadError.value = apiErrorText(error, `Failed to load ${groupsTerm.value.toLowerCase()}.`);
    } finally {
        loading.value = false;
    }
};

const pageChange = async (data: PageChangeData) => {
    await loadData(data.toPage, searchQuery.value);
};

/** A URL-safe slug from the name, matching the server's `^[a-z0-9]+(-[a-z0-9]+)*$`. */
const slugify = (value: string): string =>
    value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');

const syncSlug = () => {
    if (!slugTouched.value) form.value.slug = slugify(form.value.name);
};

const openCreateModal = () => {
    isEditForm.value = false;
    editingId.value = null;
    slugTouched.value = false;
    form.value = emptyForm();
    showFormModal.value = true;
};

const openEditModal = (group: Group) => {
    isEditForm.value = true;
    editingId.value = group.id;
    slugTouched.value = true;
    form.value = {
        name: group.name ?? '',
        slug: group.slug ?? '',
        kind: group.kind ?? 'class',
        description: group.description ?? '',
        is_active: group.is_active,
        starts_on: group.starts_on ?? '',
        ends_on: group.ends_on ?? ''
    };
    showFormModal.value = true;
};

const closeFormModal = () => {
    showFormModal.value = false;
};

const submitForm = async () => {
    if (!form.value.name || !form.value.slug) return;
    saving.value = true;
    try {
        if (isEditForm.value && editingId.value !== null) {
            await groupsStore.updateGroup(editingId.value, form.value);
        } else {
            await groupsStore.createGroup(form.value);
        }
        showFormModal.value = false;
        await loadData(isEditForm.value ? (paginationOptions.value?.currentPage || 1) : 1, searchQuery.value);
        Swal.fire({
            icon: 'success',
            title: isEditForm.value ? 'Saved!' : 'Added!',
            timer: 2000,
            showConfirmButton: false
        });
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Error!', text: apiErrorText(error, 'Failed to save.') });
    } finally {
        saving.value = false;
    }
};

/**
 * "Deactivate", not "delete", because that is what the endpoint does: the group
 * SOFT-deletes and its roster is retained with it, on purpose — a mis-click must
 * not destroy a list of children. Saying "delete" would misdescribe it.
 */
const confirmDeactivate = async (group: Group) => {
    const result = await Swal.fire({
        title: 'Are you sure?',
        text: `Deactivate "${group.name}"? Its roster is kept and can be restored by an administrator.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, deactivate'
    });

    if (result.isConfirmed) {
        try {
            await groupsStore.deleteGroup(group.id);
            await loadData(paginationOptions.value?.currentPage || 1, searchQuery.value);
            Swal.fire({ icon: 'success', title: 'Deactivated', timer: 2000, showConfirmButton: false });
        } catch (error) {
            Swal.fire({ icon: 'error', title: 'Error!', text: apiErrorText(error, 'Failed to deactivate.') });
        }
    }
};

// Lock body scroll while the modal is open
watch(showFormModal, (open) => {
    document.body.style.overflow = open ? 'hidden' : '';
});
</script>

<style scoped>
/* Stats Card */
.stats-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 12px;
    padding: 1.5rem;
    color: white;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    display: flex;
    align-items: center;
    gap: 1rem;
    transition: transform 0.2s, box-shadow 0.2s;
}

.stats-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
}

.stats-icon {
    width: 60px;
    height: 60px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.75rem;
    flex-shrink: 0;
}

.stats-content {
    flex: 1;
}

.stats-label {
    font-size: 0.875rem;
    opacity: 0.9;
    margin-bottom: 0.25rem;
    font-weight: 500;
}

.stats-value {
    font-size: 2rem;
    font-weight: 700;
    line-height: 1;
}

/* Search Box */
.search-box {
    position: relative;
    width: 100%;
}

.search-icon {
    position: absolute;
    left: 1rem;
    top: 50%;
    transform: translateY(-50%);
    color: #6c757d;
    font-size: 1.1rem;
    pointer-events: none;
    z-index: 1;
}

.search-input {
    width: 100%;
    padding: 0.75rem 2.75rem 0.75rem 2.75rem;
    border: 2px solid #e9ecef;
    border-radius: 10px;
    font-size: 1rem;
    transition: all 0.3s ease;
    background-color: #f8f9fa;
}

.search-input:focus {
    outline: none;
    border-color: #667eea;
    background-color: white;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.search-input::placeholder {
    color: #adb5bd;
}

.search-clear-btn {
    position: absolute;
    right: 0.75rem;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: #6c757d;
    font-size: 0.875rem;
    cursor: pointer;
    padding: 0.25rem;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 1.5rem;
    height: 1.5rem;
    border-radius: 50%;
    transition: all 0.2s ease;
}

.search-clear-btn:hover {
    background-color: #e9ecef;
    color: #495057;
}

/* Modal */
.modal {
    display: block;
    z-index: 1055;
}

.modal-dialog {
    margin: 1.75rem auto;
}
</style>
