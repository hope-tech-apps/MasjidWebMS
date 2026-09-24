import { defineStore } from "pinia"
import { ref } from "vue"
import { useMasjidStore } from "../masjidStore";
import ApiService from "@/core/services/ApiService";
import { AxiosResponse } from "axios";
import { BackendApiRoute } from "@/core/types/config/BackendApiRoutes";
import { PaginatedData } from "@/core/types/data/interfaces/PaginatedData";
import {
    GroupMessage,
    GroupMessageReaction,
    GroupThread,
    GroupThreadPayload,
    GroupThreadsMeta
} from "@/core/types/data/masjid-related/GroupThread";

/**
 * Teacher <-> guardian conversations over
 * .../groups/{group_id}/threads.
 *
 * The listing is pre-filtered server-side to what THIS caller may read, and the
 * per-thread read makes the same decision — so the list never advertises a
 * conversation the caller would then be refused. A participant-scoped thread is
 * readable only by the group's leaders and the one member/guardian it concerns.
 *
 * Writing a message additionally requires being able to READ the thread, which
 * is the one place the feed's write/read asymmetry does NOT carry over: speaking
 * in a conversation is not publishing an announcement. So an admin off the
 * roster may open a thread and still get a 403 posting into it — the UI has to
 * survive that, not assume the compose box always works.
 *
 * Since 2026-09-24 the office may attach PHOTOS AND ONE VIDEO here, matching what
 * teachers could already do — no server change was needed, because the admin
 * `storeMessage` and the shared FormRequests already read both bags. Parents
 * still attach NOTHING: the family realm's own request validates `body` only.
 */
export const useGroupThreadsStore = defineStore('groupThreadsStore', () => {

    // State
    const threadsPaginated = ref<PaginatedData<GroupThread>>();
    const openThread = ref<GroupThread>();
    const messagesPaginated = ref<PaginatedData<GroupMessage>>();
    const threadsMeta = ref<GroupThreadsMeta>();

    // Stores
    const masjidStore = useMasjidStore();

    /**
     * Append chosen files into the server's TWO bags, by mime.
     *
     * The office compose boxes hold ONE list, exactly as the teacher screens do,
     * because a person attaching "two photos and the clip" should not have to
     * find two controls. The server keeps two rules — different allowlist,
     * different ceiling (8MB against 100MB), different count — so the split has
     * to happen before the request, and a clip left in the `images` bag is a 422
     * nobody can act on.
     *
     * Both field names come from `meta` rather than literals, so the client
     * cannot drift from GroupPostFormRequest's constants.
     */
    function appendMedia(body: FormData, media: File[]): void {
        const imageKey = threadsMeta.value?.upload_key ?? 'images';
        const videoKey = threadsMeta.value?.video_upload_key ?? 'videos';

        media.forEach((file) => body.append(
            `${(file.type || '').startsWith('video/') ? videoKey : imageKey}[]`,
            file,
        ));
    }

    /** Threads for a group, most recently active first. */
    async function fetchThreads(groupId: number | string, page: number = 1): Promise<void> {
        if (!masjidStore.masjid?.id) return;

        if (threadsPaginated.value) {
            threadsPaginated.value.data = [];
        }

        const res: AxiosResponse = await ApiService.get(
            `/api/admin/masjids/${masjidStore.masjid.id}/groups/${groupId}/threads?page=${page}` as BackendApiRoute
        );
        if (res.data?.status === 'success' && res.data?.data) {
            threadsPaginated.value = res.data.data;
            threadsMeta.value = res.data.meta;
        }
    }

    /**
     * One conversation, oldest message first (it reads top-down like one).
     * Viewing moves the caller's read bookmark server-side, which is what
     * "reading" means — the returned thread therefore reports itself read.
     */
    async function fetchThread(groupId: number | string, threadId: number | string): Promise<void> {
        if (!masjidStore.masjid?.id) return;

        const res: AxiosResponse = await ApiService.get(
            `/api/admin/masjids/${masjidStore.masjid.id}/groups/${groupId}/threads/${threadId}` as BackendApiRoute
        );
        if (res.data?.status === 'success' && res.data?.data) {
            openThread.value = res.data.data.thread;
            messagesPaginated.value = res.data.data.messages;
            threadsMeta.value = res.data.meta;
        }
    }

    /** Close whatever conversation is open locally, without touching the server. */
    function clearOpenThread(): void {
        openThread.value = undefined;
        messagesPaginated.value = undefined;
    }

    /**
     * Open a thread, optionally with its first message in the same transaction.
     *
     * `about_membership_id` is sent ONLY for a participant thread — the request
     * rejects it with `prohibited_unless` on a group-wide one.
     */
    async function createThread(
        groupId: number | string,
        payload: GroupThreadPayload,
        media: File[] = []
    ): Promise<GroupThread> {
        if (!masjidStore.masjid?.id) {
            throw new Error('Masjid not specified.');
        }

        const body = new FormData();
        body.append('subject', payload.subject);
        body.append('scope', payload.scope);
        if (payload.scope === 'participant' && payload.about_membership_id !== null) {
            body.append('about_membership_id', String(payload.about_membership_id));
        }
        if (payload.body) body.append('body', payload.body);
        appendMedia(body, media);

        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${masjidStore.masjid.id}/groups/${groupId}/threads` as BackendApiRoute,
            body
        );
        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }
        throw new Error('Failed to open the conversation.');
    }

    /**
     * Post a message — text, attachments, or both. Refused (403) if the caller
     * may not read the thread, 422 if it is closed.
     *
     * The body is sent even when empty: the server's rule is
     * `required_without_all:images,videos`, so a photo- or video-only message is
     * legal and an empty one is refused there rather than here.
     */
    async function postMessage(
        groupId: number | string,
        threadId: number | string,
        messageBody: string,
        media: File[] = []
    ): Promise<GroupMessage> {
        if (!masjidStore.masjid?.id) {
            throw new Error('Masjid not specified.');
        }

        const body = new FormData();
        body.append('body', messageBody);
        appendMedia(body, media);

        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${masjidStore.masjid.id}/groups/${groupId}/threads/${threadId}/messages` as BackendApiRoute,
            body
        );
        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }
        throw new Error('Failed to send the message.');
    }

    /**
     * Add (`on`) or remove this caller's reaction. Two idempotent verbs server
     * side; resolves with the message's fresh reactions.
     */
    async function setReaction(
        groupId: number | string,
        threadId: number | string,
        messageId: number | string,
        key: string,
        on: boolean
    ): Promise<GroupMessageReaction[] | null> {
        if (!masjidStore.masjid?.id) return null;

        const url = `/api/admin/masjids/${masjidStore.masjid.id}/groups/${groupId}/threads/${threadId}/messages/${messageId}/reactions/${key}` as BackendApiRoute;
        const res: AxiosResponse = on ? await ApiService.put(url, {}) : await ApiService.delete(url);
        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data.reactions;
        }
        return null;
    }

    /**
     * Close or reopen a conversation. State, not deletion: it stays readable, it
     * just takes no further messages. Both verbs are idempotent server-side.
     */
    async function setThreadClosed(
        groupId: number | string,
        threadId: number | string,
        closed: boolean
    ): Promise<GroupThread | null> {
        if (!masjidStore.masjid?.id) return null;

        const action = closed ? 'close' : 'reopen';
        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${masjidStore.masjid.id}/groups/${groupId}/threads/${threadId}/${action}` as BackendApiRoute,
            new FormData()
        );
        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }
        return null;
    }

    return {
        threadsPaginated,
        openThread,
        messagesPaginated,
        threadsMeta,
        fetchThreads,
        fetchThread,
        clearOpenThread,
        createThread,
        postMessage,
        setReaction,
        setThreadClosed
    }
})
