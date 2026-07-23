<template>
    <div>
        <PageDataContainer
            title="Member Directory"
            :paginationOptions="paginationOptions"
            :buttonProps="{ title: 'Add Member', type: 'button', class: 'btn btn-success', disabled: false }"
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
                                <div class="stats-label">Total Members</div>
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
                                placeholder="Search by name, email, or phone..."
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

                <!-- Empty State -->
                <div v-else-if="contacts.length === 0" class="text-center py-5 text-muted">
                    <i class="bi bi-person-x fs-1 d-block mb-3"></i>
                    <p>No members yet</p>
                </div>

                <!-- Members Table -->
                <div v-else class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="contact in contacts" :key="contact.id">
                                <td>
                                    <strong>{{ contact.first_name }} {{ contact.last_name }}</strong>
                                </td>
                                <td>
                                    <a v-if="contact.email" :href="`mailto:${contact.email}`" class="text-decoration-none">
                                        {{ contact.email }}
                                    </a>
                                    <span v-else class="text-muted">-</span>
                                </td>
                                <td>
                                    <a v-if="contact.phone" :href="`tel:${contact.phone}`" class="text-decoration-none">
                                        {{ contact.phone }}
                                    </a>
                                    <span v-else class="text-muted">-</span>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-primary" @click="viewContact(contact)" title="View Details">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <button class="btn btn-outline-secondary" @click="openEditModal(contact)" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-outline-danger" @click="confirmDelete(contact)" title="Delete">
                                            <i class="bi bi-trash"></i>
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
                                <i class="bi bi-person-plus me-2"></i>
                                {{ isEditForm ? 'Edit Member' : 'Add New Member' }}
                            </h5>
                            <button type="button" class="btn-close" @click="closeFormModal"></button>
                        </div>
                        <form @submit.prevent="submitForm">
                            <div class="modal-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">First Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" v-model.trim="form.first_name" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" v-model.trim="form.last_name" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Email</label>
                                        <input type="email" class="form-control" v-model.trim="form.email">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Phone</label>
                                        <input type="text" class="form-control" v-model.trim="form.phone">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Notes</label>
                                        <textarea class="form-control" rows="3" v-model.trim="form.notes"></textarea>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" @click="closeFormModal" :disabled="saving">
                                    Cancel
                                </button>
                                <button type="submit" class="btn btn-success" :disabled="saving || !form.first_name || !form.last_name">
                                    <span v-if="saving" class="spinner-border spinner-border-sm me-1" role="status"></span>
                                    {{ isEditForm ? 'Save Changes' : 'Add Member' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- View Details Modal -->
        <Teleport to="body">
            <div v-if="showViewModal && selectedContact" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);" @click.self="showViewModal = false">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="bi bi-person-vcard me-2"></i>
                                Member Details
                                <span v-if="(selectedContact as any).is_placeholder" class="badge bg-warning-subtle text-warning ms-2">Unidentified card</span>
                            </h5>
                            <button type="button" class="btn-close" @click="showViewModal = false"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <h6 class="text-muted mb-1">Name</h6>
                                    <p class="mb-0">{{ selectedContact.first_name }} {{ selectedContact.last_name }}</p>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-muted mb-1">Email</h6>
                                    <p class="mb-0">
                                        <a v-if="selectedContact.email" :href="`mailto:${selectedContact.email}`">{{ selectedContact.email }}</a>
                                        <span v-else class="text-muted">Not provided</span>
                                    </p>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <h6 class="text-muted mb-1">Phone</h6>
                                    <p class="mb-0">
                                        <a v-if="selectedContact.phone" :href="`tel:${selectedContact.phone}`">{{ selectedContact.phone }}</a>
                                        <span v-else class="text-muted">Not provided</span>
                                    </p>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <h6 class="text-muted mb-1">Total giving</h6>
                                    <p class="mb-0 fw-semibold">{{ formatCents((selectedContact as any).giving_total || 0) }}</p>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-muted mb-1">Card last-4 on file</h6>
                                    <p class="mb-0">
                                        <span v-for="c in ((selectedContact as any).cards || [])" :key="c.id" class="badge bg-light text-dark border me-1 font-monospace">{{ c.last4 }}</span>
                                        <span v-if="!((selectedContact as any).cards || []).length" class="text-muted">—</span>
                                    </p>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-12">
                                    <h6 class="text-muted mb-2">Notes</h6>
                                    <p class="mb-0 small" style="white-space: pre-wrap;">{{ selectedContact.notes || '—' }}</p>
                                </div>
                            </div>
                            <h6 class="text-muted mb-2">Giving history</h6>
                            <div class="table-responsive" style="max-height:40vh; overflow-y:auto;">
                                <table class="table table-sm align-middle mb-0">
                                    <thead><tr><th>Date</th><th>Fund</th><th>Method</th><th class="text-end">Amount</th></tr></thead>
                                    <tbody>
                                        <tr v-for="d in ((selectedContact as any).donations || [])" :key="d.id">
                                            <td>{{ formatDate(d.donated_at || d.created_at) }}</td>
                                            <td>{{ d.fund?.name || '—' }}</td>
                                            <td class="text-capitalize">{{ d.source === 'offline' ? (d.payment_method || 'offline') : 'card' }}</td>
                                            <td class="text-end">{{ formatCents(d.charged_amount) }}</td>
                                        </tr>
                                        <tr v-if="!((selectedContact as any).donations || []).length"><td colspan="4" class="text-center text-muted py-3">No giving recorded</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" @click="showViewModal = false">Close</button>
                            <button v-if="(selectedContact as any).is_placeholder" type="button" class="btn btn-outline-primary" @click="openMerge">
                                <i class="bi bi-person-plus me-1"></i> Attach to member
                            </button>
                            <button type="button" class="btn btn-outline-secondary" @click="editFromView">
                                <i class="bi bi-pencil me-1"></i> Edit
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Attach placeholder card to a member -->
        <Teleport to="body">
            <div v-if="showMergeModal && selectedContact" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);" @click.self="showMergeModal = false">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Attach card to a member</h5>
                            <button type="button" class="btn-close" @click="showMergeModal = false"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-muted small">
                                This moves “{{ selectedContact.first_name }} {{ selectedContact.last_name }}”’s giving and card
                                onto a member, then removes the placeholder.
                            </p>
                            <div class="btn-group w-100 mb-3">
                                <button class="btn" :class="mergeMode==='existing' ? 'btn-success' : 'btn-outline-secondary'" @click="mergeMode='existing'">Existing member</button>
                                <button class="btn" :class="mergeMode==='new' ? 'btn-success' : 'btn-outline-secondary'" @click="mergeMode='new'">New member</button>
                            </div>

                            <div v-if="mergeMode==='existing'">
                                <label class="form-label small text-muted">Search members</label>
                                <input class="form-control mb-2" v-model="mergeSearch" @input="searchMembers" placeholder="Name or email…">
                                <div class="list-group" style="max-height:30vh; overflow-y:auto;">
                                    <button v-for="m in mergeResults" :key="m.id" type="button"
                                        class="list-group-item list-group-item-action d-flex justify-content-between"
                                        :class="{ active: mergeTarget?.id === m.id }" @click="mergeTarget = m">
                                        <span>{{ m.first_name }} {{ m.last_name }}</span>
                                        <small class="text-muted">{{ m.email || '' }}</small>
                                    </button>
                                    <div v-if="!mergeResults.length" class="text-muted small p-2">Type to search…</div>
                                </div>
                            </div>

                            <div v-else class="row">
                                <div class="col-md-6 mb-2">
                                    <label class="form-label small text-muted">First name *</label>
                                    <input class="form-control" v-model="mergeNew.first_name">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label class="form-label small text-muted">Last name</label>
                                    <input class="form-control" v-model="mergeNew.last_name">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label class="form-label small text-muted">Email</label>
                                    <input class="form-control" v-model="mergeNew.email">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label class="form-label small text-muted">Phone</label>
                                    <input class="form-control" v-model="mergeNew.phone">
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-secondary" @click="showMergeModal = false">Cancel</button>
                            <button class="btn btn-success" :disabled="!canMerge || merging" @click="doMerge">
                                <span v-if="merging" class="spinner-border spinner-border-sm"></span><span v-else>Attach</span>
                            </button>
                        </div>
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
import { Contact, ContactPayload } from '@/core/types/data/masjid-related/Contact';
import { useContactsStore } from '@/stores/masjid/contactsStore';
import { useMasjidStore } from '@/stores/masjidStore';
import ApiService from '@/core/services/ApiService';
import Swal from 'sweetalert2';

// Store
const contactsStore = useContactsStore();
const masjidStore = useMasjidStore();

// State
const loading = ref(false);
const saving = ref(false);
const searchQuery = ref('');
const showFormModal = ref(false);
const showViewModal = ref(false);
const isEditForm = ref(false);
const editingId = ref<number | null>(null);
const selectedContact = ref<Contact | null>(null);
let searchTimeout: ReturnType<typeof setTimeout> | null = null;

const emptyForm = (): ContactPayload => ({ first_name: '', last_name: '', email: '', phone: '', notes: '' });
const form = ref<ContactPayload>(emptyForm());

// Computed
const contacts = computed<Contact[]>(() => (contactsStore.contactsPaginated?.data as Contact[]) || []);

const paginationOptions = computed<PaginationOptions | undefined>(() => {
    if (!contactsStore.contactsPaginated) return undefined;
    return {
        currentPage: contactsStore.contactsPaginated.current_page,
        itemsTotal: contactsStore.contactsPaginated.total,
        perPage: contactsStore.contactsPaginated.per_page
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
    try {
        await contactsStore.fetchContacts(page, search);
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Error!', text: 'Failed to load members.' });
    } finally {
        loading.value = false;
    }
};

const pageChange = async (data: PageChangeData) => {
    await loadData(data.toPage, searchQuery.value);
};

const openCreateModal = () => {
    isEditForm.value = false;
    editingId.value = null;
    form.value = emptyForm();
    showFormModal.value = true;
};

const openEditModal = (contact: Contact) => {
    isEditForm.value = true;
    editingId.value = contact.id;
    form.value = {
        first_name: contact.first_name ?? '',
        last_name: contact.last_name ?? '',
        email: contact.email ?? '',
        phone: contact.phone ?? '',
        notes: contact.notes ?? ''
    };
    showViewModal.value = false;
    showFormModal.value = true;
};

const closeFormModal = () => {
    showFormModal.value = false;
};

const viewContact = async (contact: Contact) => {
    selectedContact.value = contact;   // show immediately with row data
    showViewModal.value = true;
    try {
        const full = await contactsStore.fetchContact(contact.id);   // hydrate cards + giving history
        if (full) selectedContact.value = full;
    } catch (e) { /* keep row-level data */ }
};

const editFromView = () => {
    if (selectedContact.value) openEditModal(selectedContact.value);
};

// --- Placeholder → member merge ---
const showMergeModal = ref(false);
const mergeMode = ref<'existing' | 'new'>('existing');
const mergeSearch = ref('');
const mergeResults = ref<any[]>([]);
const mergeTarget = ref<any>(null);
const mergeNew = ref<any>({ first_name: '', last_name: '', email: '', phone: '' });
const merging = ref(false);
let mergeSearchTimer: any = null;

const canMerge = computed(() =>
    mergeMode.value === 'existing' ? !!mergeTarget.value : !!mergeNew.value.first_name);

const openMerge = () => {
    mergeMode.value = 'existing';
    mergeSearch.value = ''; mergeResults.value = []; mergeTarget.value = null;
    mergeNew.value = { first_name: '', last_name: '', email: '', phone: '' };
    showMergeModal.value = true;
};

const searchMembers = () => {
    clearTimeout(mergeSearchTimer);
    mergeSearchTimer = setTimeout(async () => {
        const q = mergeSearch.value.trim();
        if (!q) { mergeResults.value = []; return; }
        const id = masjidStore.masjid?.id;
        const res = await ApiService.get(`/api/admin/masjids/${id}/contacts?search=${encodeURIComponent(q)}&per_page=8` as any);
        // exclude the placeholder itself and other placeholders
        mergeResults.value = (res.data?.data?.data || []).filter((c: any) => c.id !== selectedContact.value?.id && !c.is_placeholder);
    }, 300);
};

const doMerge = async () => {
    if (!selectedContact.value || !canMerge.value) return;
    const id = masjidStore.masjid?.id;
    const payload = new URLSearchParams();
    if (mergeMode.value === 'existing') payload.append('target_contact_id', String(mergeTarget.value.id));
    else {
        payload.append('first_name', mergeNew.value.first_name);
        if (mergeNew.value.last_name) payload.append('last_name', mergeNew.value.last_name);
        if (mergeNew.value.email) payload.append('email', mergeNew.value.email);
        if (mergeNew.value.phone) payload.append('phone', mergeNew.value.phone);
    }
    merging.value = true;
    try {
        await ApiService.post(`/api/admin/masjids/${id}/contacts/${selectedContact.value.id}/merge` as any, payload);
        showMergeModal.value = false;
        showViewModal.value = false;
        await loadData();
        Swal.fire({ icon: 'success', title: 'Attached', text: 'The card and its giving were moved to the member.' });
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Error!', text: 'Could not attach. Please try again.' });
    } finally { merging.value = false; }
};

const formatCents = (cents: number): string => {
    try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format((cents ?? 0) / 100); }
    catch (e) { return `$${((cents ?? 0) / 100).toFixed(2)}`; }
};
const formatDate = (iso: string): string => {
    if (!iso) return '—';
    const d = new Date(iso);
    return isNaN(d.getTime()) ? iso : d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
};

const submitForm = async () => {
    if (!form.value.first_name || !form.value.last_name) return;
    saving.value = true;
    try {
        if (isEditForm.value && editingId.value !== null) {
            await contactsStore.updateContact(editingId.value, form.value);
        } else {
            await contactsStore.createContact(form.value);
        }
        showFormModal.value = false;
        await loadData(isEditForm.value ? (paginationOptions.value?.currentPage || 1) : 1, searchQuery.value);
        Swal.fire({
            icon: 'success',
            title: isEditForm.value ? 'Saved!' : 'Added!',
            text: isEditForm.value ? 'Member updated successfully.' : 'Member added successfully.',
            timer: 2000,
            showConfirmButton: false
        });
    } catch (error: any) {
        const data = error?.response?.data?.data;
        const text = (data && typeof data === 'object')
            ? Object.values(data).flat().join(' ')
            : (error?.response?.data?.message ?? error?.message ?? 'Failed to save member.');
        Swal.fire({ icon: 'error', title: 'Error!', text });
    } finally {
        saving.value = false;
    }
};

const confirmDelete = async (contact: Contact) => {
    const result = await Swal.fire({
        title: 'Are you sure?',
        text: `Remove ${contact.first_name} ${contact.last_name} from the directory?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
    });

    if (result.isConfirmed) {
        try {
            await contactsStore.deleteContact(contact.id);
            await loadData(paginationOptions.value?.currentPage || 1, searchQuery.value);
            Swal.fire({ icon: 'success', title: 'Deleted!', text: 'Member has been removed.', timer: 2000, showConfirmButton: false });
        } catch (error) {
            Swal.fire({ icon: 'error', title: 'Error!', text: 'Failed to delete member.' });
        }
    }
};

// Lock body scroll while any modal is open
watch([showFormModal, showViewModal], ([a, b]) => {
    document.body.style.overflow = (a || b) ? 'hidden' : '';
});
</script>

<style scoped>
.card {
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

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
