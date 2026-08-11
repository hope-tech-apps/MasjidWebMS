import { defineStore } from "pinia"
import { ref } from "vue"
import { useMasjidStore } from "../masjidStore";
import ApiService from "@/core/services/ApiService";
import { AxiosResponse } from "axios";
import { BackendApiRoute } from "@/core/types/config/BackendApiRoutes";
import { PaginatedData } from "@/core/types/data/interfaces/PaginatedData";
import {
    GroupMessage,
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
 */
export const useGroupThreadsStore = defineStore('groupThreadsStore', () => {

    // State
    const threadsPaginated = ref<PaginatedData<GroupThread>>();
    const openThread = ref<GroupThread>();
    const messagesPaginated = ref<PaginatedData<GroupMessage>>();
    const threadsMeta = ref<GroupThreadsMeta>();

    // Stores
    const masjidStore = useMasjidStore();

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
        payload: GroupThreadPayload
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

        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${masjidStore.masjid.id}/groups/${groupId}/threads` as BackendApiRoute,
            body
        );
        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }
        throw new Error('Failed to open the conversation.');
    }

    /** Post a message. Refused (403) if the caller may not read the thread, 422 if it is closed. */
    async function postMessage(
        groupId: number | string,
        threadId: number | string,
        messageBody: string
    ): Promise<GroupMessage> {
        if (!masjidStore.masjid?.id) {
            throw new Error('Masjid not specified.');
        }

        const body = new FormData();
        body.append('body', messageBody);

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
        setThreadClosed
    }
})
