<template>
    <div>
        <PageDataContainer
            title="Contact Requests"
            :paginationOptions="paginationOptions"
            :hideButton="true"
            @pageChange="pageChange"
        >
            <div class="container w-100">
            <!-- Stats Cards -->
            <div class="row mb-4">
                <div class="col-md-4 col-lg-3">
                    <div class="stats-card">
                        <div class="stats-icon">
                            <i class="bi bi-envelope-fill"></i>
                        </div>
                        <div class="stats-content">
                            <div class="stats-label">Total Requests</div>
                            <div class="stats-value">{{ paginationOptions?.itemsTotal || 0 }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Search and Filter -->
            <div class="row mb-4">
                <div class="col-md-8 col-lg-6">
                    <div class="search-box">
                        <i class="bi bi-search search-icon"></i>
                        <input
                            type="text"
                            class="search-input"
                            placeholder="Search by name, email, or message..."
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
            <div v-else-if="!contactRequests || contactRequests.length === 0" class="text-center py-5 text-muted">
                <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                <p>No contact requests yet</p>
            </div>

            <!-- Contact Requests Table -->
            <div v-else class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Reason</th>
                            <th>Message</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="request in contactRequests" :key="request.id">
                            <tr v-if="request.contacter">
                                <td>
                                    <strong>{{ request.contacter.name }}</strong>
                                </td>
                                <td>
                                    <a :href="`mailto:${request.contacter.email}`" class="text-decoration-none">
                                        {{ request.contacter.email }}
                                    </a>
                                </td>
                                <td>
                                    <a
                                        v-if="request.contacter.phone"
                                        :href="`tel:${request.contacter.phone}`"
                                        class="text-decoration-none"
                                    >
                                        {{ request.contacter.phone }}
                                    </a>
                                    <span v-else class="text-muted">-</span>
                                </td>
                                <td>
                                    <span class="badge bg-info">
                                        {{ request.reason?.text || 'N/A' }}
                                    </span>
                                </td>
                                <td>
                                    <div class="message-preview">
                                        {{ truncateMessage(request.message) }}
                                    </div>
                                </td>
                                <td>
                                    <small class="text-muted">
                                        {{ formatDate(request.created_at) }}
                                    </small>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button
                                            class="btn btn-outline-primary"
                                            @click="viewRequest(request)"
                                            title="View Details"
                                        >
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <button
                                            class="btn btn-outline-danger"
                                            @click="confirmDelete(request)"
                                            title="Delete"
                                        >
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            </div>
        </PageDataContainer>

        <!-- View Request Modal -->
        <Teleport to="body">
            <div v-if="showViewModal && selectedRequest" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);" @click.self="showViewModal = false">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="bi bi-envelope-open me-2"></i>
                                Contact Request Details
                            </h5>
                            <button type="button" class="btn-close" @click="showViewModal = false"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <h6 class="text-muted mb-1">Name</h6>
                                    <p class="mb-0">{{ selectedRequest.contacter.name }}</p>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-muted mb-1">Email</h6>
                                    <p class="mb-0">
                                        <a :href="`mailto:${selectedRequest.contacter.email}`">
                                            {{ selectedRequest.contacter.email }}
                                        </a>
                                    </p>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <h6 class="text-muted mb-1">Phone</h6>
                                    <p class="mb-0">
                                        <a v-if="selectedRequest.contacter.phone" :href="`tel:${selectedRequest.contacter.phone}`">
                                            {{ selectedRequest.contacter.phone }}
                                        </a>
                                        <span v-else class="text-muted">Not provided</span>
                                    </p>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-muted mb-1">Reason</h6>
                                    <p class="mb-0">
                                        <span class="badge bg-info">
                                            {{ selectedRequest.reason?.text || 'N/A' }}
                                        </span>
                                    </p>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-12">
                                    <h6 class="text-muted mb-1">Date</h6>
                                    <p class="mb-0">{{ formatDate(selectedRequest.created_at) }}</p>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-12">
                                    <h6 class="text-muted mb-2">Message</h6>
                                    <div class="card bg-light">
                                        <div class="card-body">
                                            <p class="mb-0" style="white-space: pre-wrap;">{{ selectedRequest.message }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Reply -->
                            <div class="row">
                                <div class="col-12">
                                    <h6 class="text-muted mb-2">Reply</h6>
                                    <textarea
                                        class="form-control"
                                        rows="4"
                                        maxlength="5000"
                                        placeholder="Type your reply here. It will be emailed to the contacter."
                                        v-model="replyText"
                                        :disabled="sendingReply"
                                    ></textarea>
                                    <div class="text-end text-muted small mt-1">
                                        {{ replyText.length }} / 5000 characters
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" @click="showViewModal = false" :disabled="sendingReply">
                                Close
                            </button>
                            <button
                                type="button"
                                class="btn btn-primary"
                                @click="sendReply"
                                :disabled="sendingReply || !replyText.trim()"
                            >
                                <span v-if="sendingReply" class="spinner-border spinner-border-sm me-1" role="status"></span>
                                <i v-else class="bi bi-send me-1"></i>
                                {{ sendingReply ? 'Sending...' : 'Send Reply' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<script setup lang="ts">
import { ContactRequest } from '@/core/types/data/masjid-related/ContactRequest';
import { useContactRequestsStore } from '@/stores/masjid/contactRequestsStore';
import { ref, onBeforeMount, computed, watch } from 'vue';
import PageDataContainer from '@/components/PageDataContainer.vue';
import { PageChangeData, PaginationOptions } from '@/core/types/elements/Pagination';
import Swal from 'sweetalert2';

// Store
const contactRequestsStore = useContactRequestsStore();

// State
const loading = ref(false);
const searchQuery = ref('');
const showViewModal = ref(false);
const selectedRequest = ref<ContactRequest | null>(null);
const replyText = ref('');
const sendingReply = ref(false);
let searchTimeout: ReturnType<typeof setTimeout> | null = null;

// Computed
const contactRequests = computed(() => contactRequestsStore.contactRequestsPaginated?.data || []);

const paginationOptions = computed<PaginationOptions | undefined>(() => {
    if (!contactRequestsStore.contactRequestsPaginated) return undefined;

    return {
        currentPage: contactRequestsStore.contactRequestsPaginated.current_page,
        itemsTotal: contactRequestsStore.contactRequestsPaginated.total,
        perPage: contactRequestsStore.contactRequestsPaginated.per_page
    };
});

// Lifecycle
onBeforeMount(async () => {
    await loadData();
});

// Watch for search query changes with debouncing
watch(searchQuery, (newValue) => {
    if (searchTimeout) {
        clearTimeout(searchTimeout);
    }

    searchTimeout = setTimeout(async () => {
        await loadData(1, newValue);
    }, 500); // 500ms debounce
});

// Watch for modal state to handle body scroll
watch(showViewModal, (newValue) => {
    if (newValue) {
        document.body.style.overflow = 'hidden';
    } else {
        document.body.style.overflow = '';
    }
});

// Methods
const loadData = async (page: number = 1, search: string = '') => {
    loading.value = true;
    try {
        await contactRequestsStore.fetchContactRequests(page, search);
    } catch (error) {
        console.error('Error loading contact requests:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: 'Failed to load contact requests.',
        });
    } finally {
        loading.value = false;
    }
};

const pageChange = async (data: PageChangeData) => {
    await loadData(data.toPage, searchQuery.value);
};

const formatDate = (dateString: string) => {
    const date = new Date(dateString);
    return date.toLocaleString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
};

const truncateMessage = (message: string, length: number = 100) => {
    if (message.length <= length) return message;
    return message.substring(0, length) + '...';
};

const viewRequest = (request: ContactRequest) => {
    selectedRequest.value = request;
    replyText.value = '';
    showViewModal.value = true;
};

const sendReply = async () => {
    if (!selectedRequest.value || !replyText.value.trim()) {
        return;
    }

    sendingReply.value = true;
    try {
        const message = await contactRequestsStore.replyToContactRequest(
            selectedRequest.value.id,
            replyText.value.trim()
        );
        showViewModal.value = false;
        replyText.value = '';
        Swal.fire({
            icon: 'success',
            title: 'Reply Sent!',
            text: message,
            timer: 2500,
            showConfirmButton: false
        });
    } catch (error: any) {
        const text = error?.response?.data?.message
            ?? error?.message
            ?? 'Failed to send the reply.';
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: text
        });
    } finally {
        sendingReply.value = false;
    }
};

const confirmDelete = async (request: ContactRequest) => {
    const result = await Swal.fire({
        title: 'Are you sure?',
        text: `Do you want to delete this contact request from ${request.contacter.name}?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
    });

    if (result.isConfirmed) {
        try {
            await contactRequestsStore.deleteContactRequest(request.id);
            await loadData(paginationOptions.value?.currentPage || 1);

            Swal.fire({
                icon: 'success',
                title: 'Deleted!',
                text: 'Contact request has been deleted.',
                timer: 2000,
                showConfirmButton: false
            });
        } catch (error) {
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: 'Failed to delete contact request.',
            });
        }
    }
};
</script>

<style scoped>
.card {
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
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

/* Message Preview */
.message-preview {
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
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

