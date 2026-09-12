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
                            <th>Status</th>
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
                                <!--
                                    Which messages have been dealt with. Without
                                    this the office cannot tell an answered
                                    message from an unopened one, and two people
                                    write back to the same person.
                                -->
                                <td>
                                    <span v-if="request.answered_at" class="badge bg-success">
                                        <i class="bi bi-check2 me-1"></i>Answered
                                    </span>
                                    <span v-else class="badge bg-secondary">Awaiting reply</span>
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
            <div v-if="showViewModal && selectedRequest" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);" @click.self="closeViewModal">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="bi bi-envelope-open me-2"></i>
                                Contact Request Details
                            </h5>
                            <button type="button" class="btn-close" @click="closeViewModal"></button>
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
                                <div class="col-md-6">
                                    <h6 class="text-muted mb-1">Date</h6>
                                    <p class="mb-0">{{ formatDate(selectedRequest.created_at) }}</p>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-muted mb-1">Status</h6>
                                    <p class="mb-0">
                                        <span v-if="selectedRequest.answered_at" class="badge bg-success">
                                            <i class="bi bi-check2 me-1"></i>Answered
                                            {{ formatDate(selectedRequest.answered_at) }}
                                            <template v-if="selectedRequest.answered_by_name">
                                                by {{ selectedRequest.answered_by_name }}
                                            </template>
                                        </span>
                                        <span v-else class="badge bg-secondary">Awaiting reply</span>
                                    </p>
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

                            <!--
                                Previous replies. The point of showing them is
                                the second person to open this message: they must
                                be able to read what the first person already
                                said before writing anything of their own.
                            -->
                            <div class="row mb-3">
                                <div class="col-12">
                                    <h6 class="text-muted mb-2">
                                        Previous replies
                                        <span v-if="replies.length" class="badge bg-light text-dark ms-1">{{ replies.length }}</span>
                                    </h6>
                                    <p v-if="!replies.length" class="text-muted small mb-0">
                                        No reply has been sent yet.
                                    </p>
                                    <div
                                        v-for="reply in replies"
                                        :key="reply.id"
                                        class="card mb-2"
                                    >
                                        <div class="card-body py-2 px-3">
                                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-1 mb-1">
                                                <small class="fw-semibold">
                                                    {{ reply.actor_name || 'A staff member' }}
                                                    <span class="text-muted fw-normal">to {{ reply.sent_to }}</span>
                                                </small>
                                                <!--
                                                    sent_at, not created_at: a
                                                    reply the relay refused was
                                                    written down and never left
                                                    the building, and saying
                                                    otherwise is how somebody
                                                    goes unanswered while the
                                                    office thinks they were not.
                                                -->
                                                <small v-if="reply.sent_at" class="text-muted">
                                                    {{ formatDate(reply.sent_at) }}
                                                </small>
                                                <small v-else-if="reply.sending_at" class="text-warning">
                                                    <i class="bi bi-hourglass-split me-1"></i>Sending now
                                                </small>
                                                <small v-else class="text-danger">
                                                    <i class="bi bi-exclamation-triangle me-1"></i>Saved but not sent
                                                </small>
                                            </div>
                                            <p class="mb-0 small" style="white-space: pre-wrap;">{{ reply.body }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Reply -->
                            <div class="row">
                                <div class="col-12">
                                    <h6 class="text-muted mb-2">Reply</h6>
                                    <!--
                                        No usable address means no email can be
                                        sent. Said here rather than discovered
                                        after typing a reply and pressing Send:
                                        the sender's address comes from an
                                        anonymous form and can be blank or
                                        nonsense. Marking the message answered
                                        still works — that is what the phone is
                                        for.
                                    -->
                                    <div v-if="!replyRecipient" class="alert alert-warning py-2 px-3 small mb-2">
                                        <i class="bi bi-exclamation-triangle me-1"></i>
                                        This person did not leave a usable email address, so no reply can
                                        be emailed. Contact them another way, then mark the message answered.
                                    </div>
                                    <textarea
                                        class="form-control"
                                        rows="4"
                                        maxlength="5000"
                                        placeholder="Type your reply here. You will see exactly what is sent before it goes."
                                        v-model="replyText"
                                        :disabled="sendingReply || !replyRecipient"
                                    ></textarea>
                                    <div class="text-end text-muted small mt-1">
                                        {{ replyText.length }} / 5000 characters
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" @click="closeViewModal" :disabled="sendingReply">
                                Close
                            </button>
                            <!--
                                For the answer that was given on the phone. Both
                                directions, any number of times: triage is a
                                label, not a state machine.
                            -->
                            <button
                                type="button"
                                class="btn btn-outline-secondary"
                                @click="toggleAnswered"
                                :disabled="markingAnswered || sendingReply"
                            >
                                <span v-if="markingAnswered" class="spinner-border spinner-border-sm me-1" role="status"></span>
                                <i v-else class="bi bi-check2-square me-1"></i>
                                {{ selectedRequest.answered_at ? 'Mark unanswered' : 'Mark answered' }}
                            </button>
                            <button
                                type="button"
                                class="btn btn-primary"
                                @click="reviewReply"
                                :disabled="sendingReply || markingAnswered || !replyText.trim() || !replyRecipient"
                            >
                                <i class="bi bi-send me-1"></i>
                                Review and send
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!--
            The confirmation step. This is the only screen in the dashboard that
            mails a member of the public who wrote in, so nothing goes out until
            the admin has seen the exact recipient, the exact subject line and
            the exact body. The subject comes from the SERVER (the Mailable
            composes it) rather than being rebuilt here, so the preview cannot
            drift away from what is actually sent.
        -->
        <Teleport to="body">
            <div v-if="showConfirmModal && selectedRequest" class="modal fade show d-block confirm-modal" tabindex="-1" style="background: rgba(0,0,0,0.5);" @click.self="cancelReview">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="bi bi-envelope-check me-2"></i>
                                Send this email?
                            </h5>
                            <button type="button" class="btn-close" @click="cancelReview" :disabled="sendingReply"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-muted small">
                                This will be emailed to the person who contacted you. Check it before it goes.
                            </p>

                            <table class="table table-sm mb-3">
                                <tbody>
                                    <tr>
                                        <th scope="row" class="text-muted fw-normal" style="width: 6.5rem;">From</th>
                                        <td>{{ contactRequestsStore.replyFromName || 'Your organization' }}</td>
                                    </tr>
                                    <tr>
                                        <th scope="row" class="text-muted fw-normal">To</th>
                                        <!--
                                            The RESOLVED recipient, not the raw
                                            column: this line is the promise the
                                            admin is asked to confirm, so it has
                                            to be the address the mail will
                                            actually go to.
                                        -->
                                        <td>
                                            <strong>{{ selectedRequest.contacter.name }}</strong>
                                            &lt;{{ replyRecipient }}&gt;
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row" class="text-muted fw-normal">Subject</th>
                                        <td>{{ previewSubject }}</td>
                                    </tr>
                                </tbody>
                            </table>

                            <h6 class="text-muted mb-2">Message</h6>
                            <div class="card bg-light">
                                <div class="card-body">
                                    <p class="mb-0" style="white-space: pre-wrap;">{{ replyText.trim() }}</p>
                                </div>
                            </div>

                            <p
                                v-if="selectedRequest.answered_at"
                                class="text-muted small mt-3 mb-0"
                            >
                                <i class="bi bi-info-circle me-1"></i>
                                This message is already marked answered. Sending again adds a second
                                reply to the thread rather than replacing the first.
                            </p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" @click="cancelReview" :disabled="sendingReply">
                                Back
                            </button>
                            <button
                                type="button"
                                class="btn btn-primary"
                                @click="sendReply"
                                :disabled="sendingReply"
                            >
                                <span v-if="sendingReply" class="spinner-border spinner-border-sm me-1" role="status"></span>
                                <i v-else class="bi bi-send me-1"></i>
                                {{ sendingReply ? 'Sending...' : 'Send email' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<script setup lang="ts">
import {
    ContactRequest,
    ContactRequestReply,
    ContactRequestState
} from '@/core/types/data/masjid-related/ContactRequest';
import {
    ContactReplyNotDeliveredError,
    useContactRequestsStore
} from '@/stores/masjid/contactRequestsStore';
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
const showConfirmModal = ref(false);
const selectedRequest = ref<ContactRequest | null>(null);
const replyText = ref('');
const sendingReply = ref(false);
const markingAnswered = ref(false);
let searchTimeout: ReturnType<typeof setTimeout> | null = null;

/*
 * There is deliberately NO idempotency key in this component.
 *
 * There used to be: one minted per modal-open and held in a `ref`. That key
 * identified when a dialog was opened, not what was being sent, so reopening
 * the modal or having the message open in a second tab produced a different
 * key and the double-send guard did nothing in precisely the cases it existed
 * for — two tabs, same answer, two emails to the person who wrote in. The key
 * is now derived server-side from the message and the exact text being sent,
 * which every tab computes identically without remembering anything.
 *
 * The disabled Send button below is the first line of defence and not a
 * guarantee: it does nothing about a second tab, and nothing about a request
 * the browser retried after the click already succeeded. The server's claim on
 * the send is the guarantee.
 */

// Computed
const contactRequests = computed(() => contactRequestsStore.contactRequestsPaginated?.data || []);

const replies = computed<ContactRequestReply[]>(() => selectedRequest.value?.replies ?? []);

/**
 * The subject line the server will actually use, looked up by message id. Never
 * rebuilt in TypeScript — a local copy of the format would drift the first time
 * either side was edited, and the confirmation step would then be describing an
 * email that is not the one being sent.
 */
const previewSubject = computed(() => {
    const id = selectedRequest.value?.id;
    return id === undefined ? '' : (contactRequestsStore.replySubjects[String(id)] ?? '');
});

/**
 * The address a reply would go to, or null when there is not a usable one.
 *
 * contact_us_accounts.email is written by an UNAUTHENTICATED intake, so it can
 * be any string at all — blank, or "not-an-address". The server refuses those
 * with a 422 and is the authority; this is only so the screen does not offer a
 * confirmation step reading "To: <>" and then fail. The check is deliberately
 * loose (the server's filter_var is the real one): its job is to catch the
 * obviously-unsendable, never to reject an address the server would accept.
 */
const replyRecipient = computed<string | null>(() => {
    const email = (selectedRequest.value?.contacter?.email ?? '').trim();
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) ? email : null;
});

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
watch([showViewModal, showConfirmModal], ([viewing, confirming]) => {
    document.body.style.overflow = viewing || confirming ? 'hidden' : '';
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

const closeViewModal = () => {
    showViewModal.value = false;
    showConfirmModal.value = false;
};

const reviewReply = () => {
    if (!selectedRequest.value || !replyText.value.trim() || !replyRecipient.value) {
        return;
    }

    showConfirmModal.value = true;
};

const cancelReview = () => {
    if (sendingReply.value) return;
    showConfirmModal.value = false;
};

/**
 * Write the server's answer back into the row the table is rendering AND into
 * the open modal, so the badge and the reply history are correct without
 * re-fetching the page (which would also lose the admin's place in it).
 */
const applyState = (state: ContactRequestState) => {
    const patch = (target: ContactRequest) => {
        target.answered_at = state.answered_at;
        target.answered_by_name = state.answered_by_name;
        target.replies = state.replies;
    };

    if (selectedRequest.value && selectedRequest.value.id === state.id) {
        patch(selectedRequest.value);
    }

    const row = contactRequests.value.find((request) => request.id === state.id);
    if (row) {
        patch(row);
    }
};

const sendReply = async () => {
    if (!selectedRequest.value || !replyText.value.trim() || sendingReply.value) {
        return;
    }

    sendingReply.value = true;
    try {
        const { message, state, sentNow } = await contactRequestsStore.replyToContactRequest(
            selectedRequest.value.id,
            replyText.value.trim()
        );

        applyState(state);

        replyText.value = '';
        showConfirmModal.value = false;
        showViewModal.value = false;

        // `sentNow === false` means this exact reply had already gone and the
        // server refused to send a second copy. That is the guard working, and
        // titling it "Reply Sent!" would hide the one thing the admin needs to
        // know: nothing new left the building just now.
        Swal.fire({
            icon: 'success',
            title: sentNow ? 'Reply Sent!' : 'Already Sent',
            text: message,
            timer: sentNow ? 2500 : undefined,
            showConfirmButton: !sentNow
        });
    } catch (error: any) {
        // On file but not delivered by this request — the relay refused it, or
        // another request is at the relay with it right now. Keep the text in
        // the box so pressing Send again retries (the derived key makes that
        // the same reply, not a second one), and redraw the history so the
        // attempt is visible.
        if (error instanceof ContactReplyNotDeliveredError) {
            if (error.state) applyState(error.state);
            showConfirmModal.value = false;
        }

        // The SERVER's message first, then the thrown one. An axios rejection
        // always has a `message`, and it is "Request failed with status code
        // 422" — so reading it first would bury the only sentence that tells
        // the admin what to do ("This contact request has no email address to
        // reply to."). ContactReplyNotDeliveredError carries the server's
        // wording in `message` and no response, so both cases land correctly.
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

const toggleAnswered = async () => {
    if (!selectedRequest.value || markingAnswered.value) {
        return;
    }

    const answered = !selectedRequest.value.answered_at;

    markingAnswered.value = true;
    try {
        applyState(await contactRequestsStore.markAnswered(selectedRequest.value.id, answered));
    } catch (error: any) {
        const text = error?.response?.data?.message
            ?? error?.message
            ?? 'Failed to update the message.';
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: text
        });
    } finally {
        markingAnswered.value = false;
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

/* The send-confirmation stacks ON TOP of the detail modal it was opened from,
   so the admin keeps the message in view behind what is about to go out. */
.confirm-modal {
    z-index: 1060;
}

.modal-dialog {
    margin: 1.75rem auto;
}
</style>
