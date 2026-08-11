import { defineStore } from "pinia"
import { ref } from "vue"
import { useMasjidStore } from "../masjidStore";
import ApiService from "@/core/services/ApiService";
import { AxiosResponse } from "axios";
import { BackendApiRoute } from "@/core/types/config/BackendApiRoutes";
import { PaginatedData } from "@/core/types/data/interfaces/PaginatedData";
import { GroupFeedMeta, GroupPost, GroupPostPayload } from "@/core/types/data/masjid-related/GroupPost";

/**
 * The class story — the group's PRIVATE feed, over
 * /api/admin/masjids/{masjid_id}/groups/{group_id}/posts.
 *
 * Two things here are not incidental:
 *
 * 1. **Reading can be refused.** `view contacts` is necessary but not
 *    sufficient: the feed is about children, so the server additionally requires
 *    the caller to be IN the group and answers 403 otherwise. The caller of
 *    `fetchPosts` is expected to catch that and hide the panel — see
 *    `isForbidden` in core/services/ApiErrors.
 * 2. **Images have no public URL.** The bytes live on the private disk and the
 *    only way to read one is the authenticated `download_path` on the
 *    attachment. `attachmentObjectUrl` below is that fetch; a plain `<img src>`
 *    pointed at the path would 401, and that is the design, not a bug.
 */
export const useGroupFeedStore = defineStore('groupFeedStore', () => {

    // State
    const postsPaginated = ref<PaginatedData<GroupPost>>();
    const feedMeta = ref<GroupFeedMeta>();

    // Stores
    const masjidStore = useMasjidStore();

    /** A page of the feed, newest first. Throws 403 when the caller is off the roster. */
    async function fetchPosts(groupId: number | string, page: number = 1): Promise<void> {
        if (!masjidStore.masjid?.id) return;

        if (postsPaginated.value) {
            postsPaginated.value.data = [];
        }

        const res: AxiosResponse = await ApiService.get(
            `/api/admin/masjids/${masjidStore.masjid.id}/groups/${groupId}/posts?page=${page}` as BackendApiRoute
        );
        if (res.data?.status === 'success' && res.data?.data) {
            postsPaginated.value = res.data.data;
            feedMeta.value = res.data.meta;
        }
    }

    /**
     * Publish a post, with any images in the SAME multipart request — the server
     * writes the row and the bytes in one transaction, so a half-published story
     * never exists.
     *
     * The upload field name comes from `meta.upload_key` rather than a literal,
     * so the client cannot drift from `GroupPostFormRequest::UPLOAD_KEY`.
     */
    async function createPost(
        groupId: number | string,
        payload: GroupPostPayload,
        images: File[]
    ): Promise<GroupPost> {
        if (!masjidStore.masjid?.id) {
            throw new Error('Masjid not specified.');
        }

        const uploadKey = feedMeta.value?.upload_key ?? 'images';

        const body = new FormData();
        if (payload.title) body.append('title', payload.title);
        body.append('body', payload.body);
        images.forEach((image) => body.append(`${uploadKey}[]`, image));

        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${masjidStore.masjid.id}/groups/${groupId}/posts` as BackendApiRoute,
            body
        );
        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }
        throw new Error('Failed to publish the post.');
    }

    /** Soft-delete a post. The bytes go when the retention window closes. */
    async function deletePost(groupId: number | string, postId: number | string): Promise<boolean> {
        if (!masjidStore.masjid?.id) return false;

        const res: AxiosResponse = await ApiService.delete(
            `/api/admin/masjids/${masjidStore.masjid.id}/groups/${groupId}/posts/${postId}` as BackendApiRoute
        );
        return res.data?.status === 'success';
    }

    /**
     * Pull one attachment down and hand back an object URL for `<img src>`.
     *
     * Through the axios instance rather than `ApiService.get`, for the same
     * reason flyersStore does it: these files are served by an authenticated
     * endpoint, so the request needs the Authorization header ApiService already
     * carries, and `ApiService.get()` cannot ask for a blob.
     *
     * The CALLER owns the returned URL and must `URL.revokeObjectURL` it, or a
     * long-lived feed leaks a decoded image per scroll.
     */
    async function attachmentObjectUrl(downloadPath: string): Promise<string> {
        const res: AxiosResponse = await ApiService.VueApp.axios.get(downloadPath, { responseType: 'blob' });

        return URL.createObjectURL(res.data as Blob);
    }

    return {
        postsPaginated,
        feedMeta,
        fetchPosts,
        createPost,
        deletePost,
        attachmentObjectUrl
    }
})
