<template>
    <div>
        <PageDataContainer
            title="Donation Funds"
            :buttonProps="{ title: 'Add Fund', type: 'button', class: 'btn btn-success', disabled: false }"
            @headerButtonClick="openCreateModal"
        >
            <div class="container w-100">
                <!-- Stats Card -->
                <div class="row mb-4">
                    <div class="col-md-4 col-lg-3">
                        <div class="stats-card">
                            <div class="stats-icon">
                                <i class="bi bi-cash-stack"></i>
                            </div>
                            <div class="stats-content">
                                <div class="stats-label">Total Funds</div>
                                <div class="stats-value">{{ funds.length }}</div>
                            </div>
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
                <div v-else-if="funds.length === 0" class="text-center py-5 text-muted">
                    <i class="bi bi-wallet2 fs-1 d-block mb-3"></i>
                    <p>No funds yet</p>
                </div>

                <!-- Funds Table -->
                <div v-else class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Receiptable</th>
                                <th>Active</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="fund in funds" :key="fund.id">
                                <td>
                                    <strong>{{ fund.name }}</strong>
                                </td>
                                <td>
                                    <span class="badge bg-secondary text-capitalize">{{ fund.type }}</span>
                                </td>
                                <td>
                                    <span v-if="fund.receiptable" class="badge bg-success-subtle text-success">
                                        <i class="bi bi-check-circle me-1"></i>Yes
                                    </span>
                                    <span v-else class="badge bg-light text-muted">No</span>
                                </td>
                                <td>
                                    <span v-if="fund.is_active" class="badge bg-success-subtle text-success">Active</span>
                                    <span v-else class="badge bg-danger-subtle text-danger">Inactive</span>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-secondary" @click="openEditModal(fund)" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-outline-danger" @click="confirmDelete(fund)" title="Delete">
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
                                <i class="bi bi-wallet2 me-2"></i>
                                {{ isEditForm ? 'Edit Fund' : 'Add New Fund' }}
                            </h5>
                            <button type="button" class="btn-close" @click="closeFormModal"></button>
                        </div>
                        <form @submit.prevent="submitForm">
                            <div class="modal-body">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label">Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" v-model.trim="form.name" required>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Type <span class="text-danger">*</span></label>
                                        <select class="form-select text-capitalize" v-model="form.type" required>
                                            <option v-for="t in fundTypes" :key="t" :value="t" class="text-capitalize">{{ t }}</option>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="fundReceiptable" v-model="form.receiptable">
                                            <label class="form-check-label" for="fundReceiptable">
                                                Receiptable
                                                <small class="text-muted d-block">Donations to this fund are eligible for a tax receipt.</small>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="fundActive" v-model="form.is_active">
                                            <label class="form-check-label" for="fundActive">
                                                Active
                                                <small class="text-muted d-block">Inactive funds are hidden from new donations.</small>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" @click="closeFormModal" :disabled="saving">
                                    Cancel
                                </button>
                                <button type="submit" class="btn btn-success" :disabled="saving || !form.name || !form.type">
                                    <span v-if="saving" class="spinner-border spinner-border-sm me-1" role="status"></span>
                                    {{ isEditForm ? 'Save Changes' : 'Add Fund' }}
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
import { ref, onBeforeMount, watch } from 'vue';
import PageDataContainer from '@/components/PageDataContainer.vue';
import { Fund, FundPayload, FundType, FUND_TYPES } from '@/core/types/data/masjid-related/Fund';
import { useFundsStore } from '@/stores/masjid/fundsStore';
import Swal from 'sweetalert2';

// Store
const fundsStore = useFundsStore();

// State
const loading = ref(false);
const saving = ref(false);
const showFormModal = ref(false);
const isEditForm = ref(false);
const editingId = ref<number | null>(null);
const fundTypes: FundType[] = FUND_TYPES;

// New funds default to receiptable + active (the common case).
const emptyForm = (): FundPayload => ({ name: '', type: 'general', receiptable: true, is_active: true });
const form = ref<FundPayload>(emptyForm());

// Computed
const funds = ref<Fund[]>([]);

// Lifecycle
onBeforeMount(async () => {
    await loadData();
});

// Methods
const loadData = async () => {
    loading.value = true;
    try {
        await fundsStore.fetchFunds();
        funds.value = fundsStore.funds;
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Error!', text: 'Failed to load funds.' });
    } finally {
        loading.value = false;
    }
};

const openCreateModal = () => {
    isEditForm.value = false;
    editingId.value = null;
    form.value = emptyForm();
    showFormModal.value = true;
};

const openEditModal = (fund: Fund) => {
    isEditForm.value = true;
    editingId.value = fund.id;
    form.value = {
        name: fund.name ?? '',
        type: fund.type,
        receiptable: !!fund.receiptable,
        is_active: !!fund.is_active
    };
    showFormModal.value = true;
};

const closeFormModal = () => {
    showFormModal.value = false;
};

const submitForm = async () => {
    if (!form.value.name || !form.value.type) return;
    saving.value = true;
    try {
        if (isEditForm.value && editingId.value !== null) {
            await fundsStore.updateFund(editingId.value, form.value);
        } else {
            await fundsStore.createFund(form.value);
        }
        showFormModal.value = false;
        await loadData();
        Swal.fire({
            icon: 'success',
            title: isEditForm.value ? 'Saved!' : 'Added!',
            text: isEditForm.value ? 'Fund updated successfully.' : 'Fund added successfully.',
            timer: 2000,
            showConfirmButton: false
        });
    } catch (error: any) {
        const data = error?.response?.data?.data;
        const text = (data && typeof data === 'object')
            ? Object.values(data).flat().join(' ')
            : (error?.response?.data?.message ?? error?.message ?? 'Failed to save fund.');
        Swal.fire({ icon: 'error', title: 'Error!', text });
    } finally {
        saving.value = false;
    }
};

const confirmDelete = async (fund: Fund) => {
    const result = await Swal.fire({
        title: 'Are you sure?',
        text: `Delete the "${fund.name}" fund?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
    });

    if (result.isConfirmed) {
        try {
            await fundsStore.deleteFund(fund.id);
            await loadData();
            Swal.fire({ icon: 'success', title: 'Deleted!', text: 'Fund has been removed.', timer: 2000, showConfirmButton: false });
        } catch (error: any) {
            // A fund with donations attached cannot be deleted (FK constraint).
            const text = error?.response?.data?.data ?? error?.response?.data?.message
                ?? 'Failed to delete fund. It may still have donations attached.';
            Swal.fire({ icon: 'error', title: 'Error!', text });
        }
    }
};

// Lock body scroll while the modal is open
watch(showFormModal, (open) => {
    document.body.style.overflow = open ? 'hidden' : '';
});
</script>

<style scoped>
.card {
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

/* Stats Card */
.stats-card {
    background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
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

/* Modal */
.modal {
    display: block;
    z-index: 1055;
}

.modal-dialog {
    margin: 1.75rem auto;
}
</style>
