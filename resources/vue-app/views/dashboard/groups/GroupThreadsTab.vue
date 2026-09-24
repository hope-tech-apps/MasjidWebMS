<template>
    <div>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0 text-muted">Conversations</h6>
            <button class="btn btn-sm btn-success" @click="openThreadModal">
                <i class="bi bi-chat-dots me-1"></i> New conversation
            </button>
        </div>

        <div v-if="loading" class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>

        <GroupForbiddenNotice v-else-if="forbidden" what="Conversations" />

        <div v-else-if="loadError" class="alert alert-danger" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i>
            {{ loadError }}
            <button class="btn btn-sm btn-outline-danger ms-3" @click="loadThreads(1)">Retry</button>
        </div>

        <div v-else class="row g-3">
            <!-- The list -->
            <div class="col-lg-5">
                <div v-if="threads.length === 0" class="text-center py-5 text-muted">
                    <i class="bi bi-chat-square-text fs-1 d-block mb-3"></i>
                    <p class="mb-0">No conversations yet</p>
                </div>
                <div v-else class="list-group">
                    <button
                        v-for="thread in threads"
                        :key="thread.id"
                        type="button"
                        class="list-group-item list-group-item-action"
                        :class="{ active: openThread?.id === thread.id }"
                        @click="selectThread(thread)"
                    >
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="me-2">
                                <div class="fw-semibold">
                                    {{ thread.subject }}
                                    <span v-if="thread.unread" class="badge bg-primary ms-1">New</span>
                                </div>
                                <!--
                                    The scope IS the disclosure shape, so it is
                                    shown rather than hidden: "Everyone" reaches
                                    the feed audience, while a participant thread
                                    reaches the leaders and the ONE family it
                                    concerns. An admin should be able to see which
                                    they are about to write into.
                                -->
                                <div class="small">
                                    <span v-if="thread.scope === 'group'" class="text-muted">
                                        <i class="bi bi-people me-1"></i>Everyone in the group
                                    </span>
                                    <span v-else class="text-muted">
                                        <i class="bi bi-person me-1"></i>About {{ subjectName(thread) }}
                                    </span>
                                </div>
                            </div>
                            <div class="text-end">
                                <span v-if="thread.is_closed" class="badge bg-secondary-subtle text-secondary">Closed</span>
                                <div class="small text-muted mt-1">{{ thread.message_count }} msg</div>
                            </div>
                        </div>
                    </button>
                </div>

                <div class="d-flex justify-content-center mt-3">
                    <Pagination v-if="paginationOptions" :options="paginationOptions" @page-change="pageChange" />
                </div>
            </div>

            <!-- The conversation -->
            <div class="col-lg-7">
                <div v-if="!openThread" class="text-center py-5 text-muted border rounded">
                    <i class="bi bi-arrow-left-circle fs-3 d-block mb-2"></i>
                    <p class="mb-0">Choose a conversation to read it</p>
                </div>

                <div v-else class="card border">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-semibold">{{ openThread.subject }}</div>
                            <div class="small text-muted">Opened by {{ openThread.created_by?.name || 'Unknown' }}</div>
                        </div>
                        <button class="btn btn-sm btn-outline-secondary" @click="toggleClosed">
                            {{ openThread.is_closed ? 'Reopen' : 'Close' }}
                        </button>
                    </div>

                    <div class="card-body message-scroll">
                        <div v-if="messages.length === 0" class="text-muted small text-center py-3">
                            No messages yet
                        </div>
                        <div v-for="message in messages" :key="message.id" class="mb-3">
                            <div class="small text-muted">
                                {{ message.author?.name || 'Unknown' }} &middot; {{ formatDateTime(message.created_at) }}
                            </div>
                            <div v-if="message.body" class="message-body">{{ message.body }}</div>
                            <div v-if="message.attachments?.length" class="d-flex flex-wrap gap-2 mt-1">
                                <GroupMessagePhoto v-for="a in message.attachments" :key="a.id"
                                                   :src="a.download_path" :name="a.file_name"
                                                   :mime="a.mime_type" :is-video="a.is_video"
                                                   :playback-path="a.playback_ticket_path" />
                            </div>
                            <div v-else-if="message.media_withheld" class="small text-muted fst-italic mt-1">
                                An attachment in this message is hidden from you.
                            </div>
                            <!--
                                Read status on EVERY message here, not only the
                                viewer's own: this is the office's oversight view,
                                so "has the family seen the teacher's note?" is
                                the question it is opened to answer. A bookmark
                                moves only when somebody OPENS the thread.
                            -->
                            <MessageSignals v-model:reactions="message.reactions" :read-by="message.read_by"
                                            show-receipt :can-react="!openThread.is_closed"
                                            :send="(key: string, on: boolean) => reactTo(message, key, on)" />
                        </div>
                    </div>

                    <div class="card-footer bg-white">
                        <!--
                            Writing a message needs READ entitlement on top of
                            `manage contacts` — the one place the feed's
                            write-without-read asymmetry does NOT carry over,
                            because speaking in a conversation is not publishing
                            an announcement. So a send can still be refused, and
                            the error surfaces rather than being swallowed.
                        -->
                        <form v-if="!openThread.is_closed" @submit.prevent="submitMessage">
                            <div class="d-flex gap-2">
                                <input
                                    class="form-control"
                                    v-model.trim="messageBody"
                                    placeholder="Write a message…"
                                    :maxlength="maxMessageLength"
                                >
                                <!--
                                    Send is enabled by TEXT **or** an attachment:
                                    the server's rule is
                                    `required_without_all:images,videos`, so a
                                    photo- or video-only message is legal, and a
                                    button gated on `messageBody` alone would
                                    have made the picker look broken.
                                -->
                                <button class="btn btn-success" type="submit"
                                        :disabled="sending || !canSend">
                                    <span v-if="sending" class="spinner-border spinner-border-sm"></span>
                                    <i v-else class="bi bi-send"></i>
                                </button>
                            </div>
                            <!--
                                The same control the teacher screens use, not a
                                second copy of it: photos and one video through
                                one picker. Limits come from the server's own
                                `meta`, so the office is never offered something
                                the request would refuse.
                            -->
                            <GroupMediaPicker
                                v-model="replyMedia"
                                class="mt-2"
                                :disabled="sending"
                                :accept="acceptImages"
                                :video-accept="acceptVideos"
                                :max="maxImages"
                                :max-videos="maxVideos"
                            />
                        </form>
                        <div v-else class="small text-muted text-center">
                            This conversation is closed. Reopen it to continue.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- New conversation -->
        <Teleport to="body">
            <div v-if="showThreadModal" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);" @click.self="showThreadModal = false">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-chat-dots me-2"></i> New conversation</h5>
                            <button type="button" class="btn-close" @click="showThreadModal = false"></button>
                        </div>
                        <form @submit.prevent="submitThread">
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label">Subject <span class="text-danger">*</span></label>
                                    <input class="form-control" v-model.trim="threadForm.subject" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Who can read it <span class="text-danger">*</span></label>
                                    <select class="form-select" v-model="threadForm.scope">
                                        <option value="group">Everyone in the group</option>
                                        <option value="participant">One family only</option>
                                    </select>
                                    <div class="form-text">
                                        A group-wide conversation reaches the same people as the class story.
                                        A family conversation reaches the leaders plus that student and their guardians.
                                    </div>
                                </div>
                                <div v-if="threadForm.scope === 'participant'" class="mb-3">
                                    <label class="form-label">About <span class="text-danger">*</span></label>
                                    <select class="form-select" v-model="aboutMembershipId">
                                        <option :value="null" disabled>Choose a student…</option>
                                        <option v-for="participant in participants" :key="participant.id" :value="participant.id">
                                            {{ fullName(participant) }}
                                        </option>
                                    </select>
                                </div>
                                <div class="mb-1">
                                    <label class="form-label">First message</label>
                                    <textarea class="form-control" rows="3" v-model.trim="threadForm.body"></textarea>
                                    <!--
                                        A conversation may open WITH a photo or a
                                        clip — the thread and its first message
                                        are written in one transaction server
                                        side, so there is no half-opened state to
                                        design around.
                                    -->
                                    <GroupMediaPicker
                                        v-model="threadMedia"
                                        class="mt-2"
                                        :disabled="creating"
                                        :accept="acceptImages"
                                        :video-accept="acceptVideos"
                                        :max="maxImages"
                                        :max-videos="maxVideos"
                                    />
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" @click="showThreadModal = false" :disabled="creating">Cancel</button>
                                <button type="submit" class="btn btn-success" :disabled="creating || !canCreate">
                                    <span v-if="creating" class="spinner-border spinner-border-sm me-1"></span>
                                    Open
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
import { ref, computed, onBeforeMount, watch } from 'vue';
import Pagination from '@/components/partials/Pagination.vue';
import GroupForbiddenNotice from './GroupForbiddenNotice.vue';
import GroupMessagePhoto from './GroupMessagePhoto.vue';
import GroupMediaPicker from '@/components/partials/GroupMediaPicker.vue';
import MessageSignals from '@/components/common/MessageSignals.vue';
import { PageChangeData, PaginationOptions } from '@/core/types/elements/Pagination';
import { GroupMembership } from '@/core/types/data/masjid-related/Group';
import { GroupMessage, GroupThread, GroupThreadPayload } from '@/core/types/data/masjid-related/GroupThread';
import { useGroupThreadsStore } from '@/stores/masjid/groupThreadsStore';
import { apiErrorText, isForbidden } from '@/core/services/ApiErrors';
import Swal from 'sweetalert2';

/**
 * Teacher <-> guardian conversations for one group.
 *
 * The listing is already narrowed server-side to what this caller may read, so
 * the list never advertises a conversation they would then be refused. Opening
 * one moves their read bookmark, which is what "reading" means.
 */

const props = defineProps<{
    groupId: number;
    memberships: GroupMembership[];
}>();

// Stores
const threadsStore = useGroupThreadsStore();

// State
const loading = ref(false);
const forbidden = ref(false);
const loadError = ref('');
const showThreadModal = ref(false);
const creating = ref(false);
const sending = ref(false);
const messageBody = ref('');
/** Chosen attachments for the reply box and for a new conversation's first message. */
const replyMedia = ref<File[]>([]);
const threadMedia = ref<File[]>([]);
/** Kept apart from `threadForm` so switching back to a group-wide scope cannot leave a stale subject. */
const aboutMembershipId = ref<number | null>(null);

const emptyThreadForm = (): GroupThreadPayload => ({
    subject: '', scope: 'participant', about_membership_id: null, body: ''
});
const threadForm = ref<GroupThreadPayload>(emptyThreadForm());

// Computed
const threads = computed<GroupThread[]>(() => (threadsStore.threadsPaginated?.data as GroupThread[]) || []);
const openThread = computed<GroupThread | undefined>(() => threadsStore.openThread);
const messages = computed<GroupMessage[]>(() => (threadsStore.messagesPaginated?.data as GroupMessage[]) || []);

/** A thread is opened ABOUT a participant; a guardian edge names a relationship, not a person. */
const participants = computed<GroupMembership[]>(
    // …and one who is still in the class: this list is a picker, and offering a
    // child who has left invites a record to be written against a class they are
    // no longer in — which the server now refuses anyway.
    () => props.memberships.filter((m) => m.role !== 'guardian' && !m.left_on)
);

const maxMessageLength = computed<number>(() => threadsStore.threadsMeta?.max_message_length || 5000);

/**
 * Upload limits, from the server's own `meta` rather than literals — the office
 * must never be offered a file the request would then refuse. The two sets are
 * separate because the server holds them to separate rules: a different
 * allowlist, a 100MB ceiling instead of 8MB, and one file instead of eight.
 */
const acceptImages = computed<string>(() => (threadsStore.threadsMeta?.accepted_image_types ?? []).join(','));
const acceptVideos = computed<string>(() => (threadsStore.threadsMeta?.accepted_video_types ?? []).join(','));
const maxImages = computed<number>(() => threadsStore.threadsMeta?.max_images_per_message ?? 8);
const maxVideos = computed<number>(() => threadsStore.threadsMeta?.max_videos_per_message ?? 0);

/** Text OR an attachment is enough; the server refuses a message that is neither. */
const canSend = computed<boolean>(() => !!messageBody.value || replyMedia.value.length > 0);

const paginationOptions = computed<PaginationOptions | undefined>(() => {
    if (!threadsStore.threadsPaginated) return undefined;
    return {
        currentPage: threadsStore.threadsPaginated.current_page,
        itemsTotal: threadsStore.threadsPaginated.total,
        perPage: threadsStore.threadsPaginated.per_page
    };
});

const canCreate = computed<boolean>(() => {
    if (!threadForm.value.subject) return false;
    return threadForm.value.scope !== 'participant' || aboutMembershipId.value !== null;
});

// Lifecycle
onBeforeMount(async () => {
    await loadThreads(1);
});

// Methods
const fullName = (membership: GroupMembership): string =>
    membership.contact ? `${membership.contact.first_name} ${membership.contact.last_name}`.trim() : '—';

/** A participant thread whose target has left the roster keeps its subject line, honestly. */
const subjectName = (thread: GroupThread): string => {
    const contact = thread.about?.contact;
    return contact ? `${contact.first_name} ${contact.last_name}`.trim() : 'a former member';
};

const loadThreads = async (page: number) => {
    loading.value = true;
    loadError.value = '';
    forbidden.value = false;
    try {
        await threadsStore.fetchThreads(props.groupId, page);
    } catch (error) {
        if (isForbidden(error)) {
            forbidden.value = true;
        } else {
            loadError.value = apiErrorText(error, 'Failed to load the conversations.');
        }
    } finally {
        loading.value = false;
    }
};

const pageChange = async (data: PageChangeData) => {
    if (data.toPage === (paginationOptions.value?.currentPage ?? 1)) return;
    await loadThreads(data.toPage);
};

const selectThread = async (thread: GroupThread) => {
    try {
        await threadsStore.fetchThread(props.groupId, thread.id);
        messageBody.value = '';
        // Switching conversations clears the staged attachments with the draft
        // text, for the same reason: a photo chosen for one family's thread must
        // not be sitting in the box when the next one opens.
        replyMedia.value = [];
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Error!', text: apiErrorText(error, 'Failed to open the conversation.') });
    }
};

const submitThread = async () => {
    if (!canCreate.value) return;
    creating.value = true;
    try {
        const thread = await threadsStore.createThread(props.groupId, {
            ...threadForm.value,
            about_membership_id: threadForm.value.scope === 'participant' ? aboutMembershipId.value : null
        }, threadMedia.value);
        threadMedia.value = [];
        showThreadModal.value = false;
        await loadThreads(1);
        await selectThread(thread);
        Swal.fire({ icon: 'success', title: 'Opened', timer: 1600, showConfirmButton: false });
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Error!', text: apiErrorText(error, 'Failed to open the conversation.') });
    } finally {
        creating.value = false;
    }
};

const submitMessage = async () => {
    const thread = openThread.value;
    if (!thread || !canSend.value) return;
    sending.value = true;
    try {
        await threadsStore.postMessage(props.groupId, thread.id, messageBody.value, replyMedia.value);
        messageBody.value = '';
        // Cleared only after the send SUCCEEDS, so a refused message (a closed
        // conversation, a caller off the roster) does not silently discard the
        // photo the office had just chosen.
        replyMedia.value = [];
        await threadsStore.fetchThread(props.groupId, thread.id);
        await loadThreads(paginationOptions.value?.currentPage || 1);
    } catch (error) {
        Swal.fire({
            icon: 'error',
            title: 'Not sent',
            // nginx answers an oversized request itself, as HTML, so apiErrorText
            // would only have axios's "status code 413" to show.
            text: (error as any)?.response?.status === 413
                ? 'That is too large to send together. Try fewer photos, or a shorter video.'
                : apiErrorText(error, 'Failed to send the message.')
        });
    } finally {
        sending.value = false;
    }
};

/** 🤲 👍 💯 ❓ — refused (403) for an admin who may not read the thread, 422 once it is closed. */
const reactTo = async (message: GroupMessage, key: string, on: boolean) => {
    const thread = openThread.value;
    if (!thread) return null;
    try {
        return await threadsStore.setReaction(props.groupId, thread.id, message.id, key, on);
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Not saved', text: apiErrorText(error, 'That reaction could not be saved.') });
        return null;
    }
};

const toggleClosed = async () => {
    const thread = openThread.value;
    if (!thread) return;
    try {
        await threadsStore.setThreadClosed(props.groupId, thread.id, !thread.is_closed);
        await threadsStore.fetchThread(props.groupId, thread.id);
        await loadThreads(paginationOptions.value?.currentPage || 1);
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Error!', text: apiErrorText(error, 'Failed to update the conversation.') });
    }
};

const openThreadModal = () => {
    threadForm.value = emptyThreadForm();
    aboutMembershipId.value = null;
    // A clip chosen for a conversation that was then abandoned must not ride
    // along into the next one — the picker keeps File objects, not a form field
    // the reset above would clear.
    threadMedia.value = [];
    showThreadModal.value = true;
};

const formatDateTime = (iso: string | null): string => {
    if (!iso) return '—';
    const date = new Date(iso);
    return isNaN(date.getTime())
        ? iso
        : date.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
};

// A different group means a different set of conversations.
watch(() => props.groupId, async () => {
    threadsStore.clearOpenThread();
    // Staged attachments belong to the conversation they were chosen for. A
    // photo of one class must never be carried into another's compose box.
    replyMedia.value = [];
    threadMedia.value = [];
    await loadThreads(1);
});

// Lock body scroll while the modal is open
watch(showThreadModal, (open) => {
    document.body.style.overflow = open ? 'hidden' : '';
});
</script>

<style scoped>
.message-scroll {
    max-height: 45vh;
    overflow-y: auto;
}

.message-body {
    white-space: pre-wrap;
}

.modal {
    display: block;
    z-index: 1055;
}

.modal-dialog {
    margin: 1.75rem auto;
}
</style>
