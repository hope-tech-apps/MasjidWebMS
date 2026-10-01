import { defineStore } from "pinia"
import { ref } from "vue"
import { useMasjidStore } from "../masjidStore";
import ApiService from "@/core/services/ApiService";
import { isVideoFile } from "@/core/helpers/mediaPick";
import { AxiosResponse } from "axios";
import { BackendApiRoute } from "@/core/types/config/BackendApiRoutes";
import type { MessageEditRow } from "@/core/helpers/messageEdit";
import { PaginatedData } from "@/core/types/data/interfaces/PaginatedData";
import { MESSAGE_PAGE_SIZE, openWholeThread } from "@/core/helpers/threadUnread";
import {
    GroupMessage,
    GroupMessageReaction,
    GroupThread,
    GroupThreadPayload,
    GroupThreadsMeta,
    ScheduledMessage
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
 * Since 2026-09-24 the office may attach PHOTOS AND VIDEOS here, matching what
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
            `${isVideoFile(file) ? videoKey : imageKey}[]`,
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

        // Every page of it, oldest first. The server moves the bookmark to the newest
        // message it SERVED, so a conversation longer than one page is only read, and
        // only stops counting as unread, once its last page has been fetched.
        let lastPage: PaginatedData<GroupMessage> | undefined;
        let lastMeta: GroupThreadsMeta | undefined;
        const opened = await openWholeThread<GroupMessage, GroupThread>(async (page) => {
            const res: AxiosResponse = await ApiService.get(
                `/api/admin/masjids/${masjidStore.masjid!.id}/groups/${groupId}/threads/${threadId}?per_page=${MESSAGE_PAGE_SIZE}&page=${page}` as BackendApiRoute
            );
            if (res.data?.status !== 'success' || !res.data?.data) return null;
            lastPage = res.data.data.messages;
            lastMeta = res.data.meta;
            return res.data.data;
        });
        if (opened.thread && lastPage) {
            openThread.value = opened.thread;
            messagesPaginated.value = { ...lastPage, data: opened.messages } as PaginatedData<GroupMessage>;
            threadsMeta.value = lastMeta;
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
     * "Send later" for a NEW conversation (T-002.4): write it now, and it is opened at
     * `sendAt` (the SCHOOL's wall clock, `2026-10-05T10:00`). TEXT ONLY: a scheduled
     * conversation cannot carry a photo, and the server refuses one rather than drop it.
     * Until then it is a schedule row and nothing a family can see.
     */
    async function scheduleThread(
        groupId: number | string,
        payload: GroupThreadPayload,
        sendAt: string
    ): Promise<ScheduledMessage> {
        if (!masjidStore.masjid?.id) throw new Error('Masjid not specified.');

        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${masjidStore.masjid.id}/groups/${groupId}/scheduled-messages` as BackendApiRoute,
            {
                subject: payload.subject,
                scope: payload.scope,
                ...(payload.scope === 'participant' && payload.about_membership_id !== null
                    ? { about_membership_id: payload.about_membership_id } : {}),
                body: payload.body,
                send_at: sendAt,
            }
        );
        if (res.data?.status === 'success' && res.data?.data) return res.data.data;

        throw new Error('Failed to schedule the conversation.');
    }

    /** The conversations still ahead: waiting, being sent, or refused at send time. */
    async function fetchScheduled(groupId: number | string): Promise<ScheduledMessage[]> {
        if (!masjidStore.masjid?.id) return [];

        const res: AxiosResponse = await ApiService.get(
            `/api/admin/masjids/${masjidStore.masjid.id}/groups/${groupId}/scheduled-messages` as BackendApiRoute
        );

        if (res.data?.meta?.scheduling) {
            threadsMeta.value = { ...(threadsMeta.value as GroupThreadsMeta), scheduling: res.data.meta.scheduling };
        }

        return (res.data?.data?.data as ScheduledMessage[]) ?? [];
    }

    /**
     * Edit, reschedule or send now. Form-encoded, as every admin PUT is: `send_now`
     * arrives as the string "true" and the server's request coerces it. A new
     * `send_at` puts a failed item back in the queue.
     */
    async function updateScheduled(
        groupId: number | string,
        id: number | string,
        fields: { subject?: string; body?: string; send_at?: string; send_now?: boolean }
    ): Promise<ScheduledMessage> {
        if (!masjidStore.masjid?.id) throw new Error('Masjid not specified.');

        const body = new URLSearchParams();
        Object.entries(fields).forEach(([key, value]) => {
            if (value !== undefined) body.append(key, String(value));
        });

        const res: AxiosResponse = await ApiService.put(
            `/api/admin/masjids/${masjidStore.masjid.id}/groups/${groupId}/scheduled-messages/${id}` as BackendApiRoute,
            body
        );
        if (res.data?.status === 'success' && res.data?.data) return res.data.data;

        throw new Error('Failed to update the scheduled conversation.');
    }

    /** Cancel. The row is kept as `cancelled`; nothing is ever sent. */
    async function cancelScheduled(groupId: number | string, id: number | string): Promise<boolean> {
        if (!masjidStore.masjid?.id) return false;

        const res: AxiosResponse = await ApiService.delete(
            `/api/admin/masjids/${masjidStore.masjid.id}/groups/${groupId}/scheduled-messages/${id}` as BackendApiRoute
        );

        return res.data?.status === 'success';
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
     * Change the words of a message this caller sent (W7). 403 if it is not
     * theirs or they may no longer read the thread, 422 if the conversation is
     * closed or the text is empty/too long, 503 for the seconds a deploy runs
     * ahead of its migration. Throws on any of them, so the editor can show the
     * server's sentence and keep the draft. Resolves with the fresh message.
     */
    async function editMessage(
        groupId: number | string,
        threadId: number | string,
        messageId: number | string,
        messageBody: string
    ): Promise<GroupMessage> {
        if (!masjidStore.masjid?.id) {
            throw new Error('Masjid not specified.');
        }

        const res: AxiosResponse = await ApiService.put(
            `/api/admin/masjids/${masjidStore.masjid.id}/groups/${groupId}/threads/${threadId}/messages/${messageId}` as BackendApiRoute,
            { body: messageBody }
        );
        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }
        throw new Error('Failed to save the edit.');
    }

    /**
     * What an edited message said before each edit, oldest first — the office's
     * audit, admin realm only. Refused (403) unless the caller may read the thread.
     */
    async function fetchMessageEdits(
        groupId: number | string,
        threadId: number | string,
        messageId: number | string
    ): Promise<MessageEditRow[]> {
        if (!masjidStore.masjid?.id) return [];

        const res: AxiosResponse = await ApiService.get(
            `/api/admin/masjids/${masjidStore.masjid.id}/groups/${groupId}/threads/${threadId}/messages/${messageId}/edits` as BackendApiRoute
        );
        if (res.data?.status === 'success') {
            return res.data.data?.edits ?? [];
        }
        throw new Error('Failed to load the earlier versions.');
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
        scheduleThread,
        fetchScheduled,
        updateScheduled,
        cancelScheduled,
        postMessage,
        editMessage,
        fetchMessageEdits,
        setReaction,
        setThreadClosed
    }
})
