<template>
    <div>
        <!-- Compose -->
        <div class="card border mb-4">
            <div class="card-body">
                <form @submit.prevent="submitPost">
                    <input
                        type="text"
                        class="form-control form-control-sm mb-2"
                        placeholder="Title (optional)"
                        v-model.trim="composeTitle"
                    >
                    <textarea
                        class="form-control mb-2"
                        rows="3"
                        placeholder="Share what happened today…"
                        v-model.trim="composeBody"
                    ></textarea>

                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <label class="btn btn-sm btn-outline-secondary mb-0">
                            <i class="bi bi-image me-1"></i> Add photos
                            <input
                                type="file"
                                class="d-none"
                                multiple
                                :accept="acceptAttribute"
                                @change="onFilesChosen"
                            >
                        </label>
                        <span v-if="chosenFiles.length" class="small text-muted">
                            {{ chosenFiles.length }} selected
                            <button type="button" class="btn btn-link btn-sm p-0 ms-1" @click="chosenFiles = []">clear</button>
                        </span>
                        <span v-if="uploadHint" class="small text-muted ms-auto">{{ uploadHint }}</span>
                        <button type="submit" class="btn btn-sm btn-success ms-auto" :disabled="posting || !composeBody">
                            <span v-if="posting" class="spinner-border spinner-border-sm me-1"></span>
                            Post
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div v-if="loading" class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>

        <!--
            403 is ordinary here: publishing is administration, reading is
            disclosure, and an admin who is not on this roster gets one. The
            compose box above stays usable on purpose — that asymmetry is the
            design, not a bug.
        -->
        <GroupForbiddenNotice v-else-if="forbidden" what="Photos and notes" />

        <div v-else-if="loadError" class="alert alert-danger" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i>
            {{ loadError }}
            <button class="btn btn-sm btn-outline-danger ms-3" @click="loadPosts(1)">Retry</button>
        </div>

        <div v-else-if="posts.length === 0" class="text-center py-5 text-muted">
            <i class="bi bi-journal-text fs-1 d-block mb-3"></i>
            <p class="mb-0">Nothing has been shared with this group yet</p>
        </div>

        <div v-else>
            <div v-for="post in posts" :key="post.id" class="card border mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <div v-if="post.title" class="fw-semibold">{{ post.title }}</div>
                            <div class="small text-muted">
                                {{ post.author?.name || 'Unknown' }} &middot; {{ formatDateTime(post.created_at) }}
                            </div>
                        </div>
                        <button class="btn btn-sm btn-outline-danger" @click="confirmDelete(post)" title="Remove">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>

                    <p class="mb-2" style="white-space: pre-wrap;">{{ post.body }}</p>

                    <!--
                        Images are fetched through the AUTHENTICATED attachment
                        endpoint and rendered from an object URL. There is no
                        public URL for one of these: the bytes live on the
                        private disk, and a plain <img src="/storage/..."> would
                        404 (or 401 against the endpoint). See
                        .claude/rules/private-uploads.md.
                    -->
                    <div v-if="post.attachments.length" class="d-flex flex-wrap gap-2">
                        <div v-for="attachment in post.attachments" :key="attachment.id" class="story-thumb">
                            <img
                                v-if="imageUrls[attachment.id]"
                                :src="imageUrls[attachment.id]"
                                :alt="attachment.file_name"
                            >
                            <div v-else class="story-thumb-placeholder">
                                <span class="spinner-border spinner-border-sm text-secondary"></span>
                            </div>
                        </div>
                    </div>

                    <!--
                        Stated by the server, so "no photos this week" is never
                        confused with "not allowed to see them".
                    -->
                    <div v-else-if="post.media_withheld" class="small text-muted fst-italic">
                        <i class="bi bi-eye-slash me-1"></i>
                        This post has photos that are not shared with you.
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-center">
                <Pagination v-if="paginationOptions" :options="paginationOptions" @page-change="pageChange" />
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onBeforeMount, onBeforeUnmount, watch } from 'vue';
import Pagination from '@/components/partials/Pagination.vue';
import GroupForbiddenNotice from './GroupForbiddenNotice.vue';
import { PageChangeData, PaginationOptions } from '@/core/types/elements/Pagination';
import { GroupPost } from '@/core/types/data/masjid-related/GroupPost';
import { useGroupFeedStore } from '@/stores/masjid/groupFeedStore';
import { apiErrorText, isForbidden } from '@/core/services/ApiErrors';
import Swal from 'sweetalert2';

/**
 * The class story — this group's private feed.
 *
 * Two behaviours here exist because of the privacy model, not for looks:
 *
 * 1. **Images go through the authenticated endpoint.** Each attachment carries a
 *    `download_path` and nothing else; the bytes sit on the private disk with a
 *    randomised name and no public URL exists. We fetch each one as a blob (the
 *    axios instance carries the bearer token) and render an object URL, revoking
 *    it when the page changes so a long feed does not leak decoded images.
 * 2. **A 403 hides the feed instead of erroring.** Reading requires standing in
 *    the group; the compose box stays live because writing does not.
 */

const props = defineProps<{ groupId: number }>();

// Stores
const feedStore = useGroupFeedStore();

// State
const loading = ref(false);
const posting = ref(false);
const forbidden = ref(false);
const loadError = ref('');
const composeTitle = ref('');
const composeBody = ref('');
const chosenFiles = ref<File[]>([]);
/** Object URLs keyed by attachment id, revoked on page change / unmount. */
const imageUrls = ref<Record<number, string>>({});

// Computed
const posts = computed<GroupPost[]>(() => (feedStore.postsPaginated?.data as GroupPost[]) || []);

const paginationOptions = computed<PaginationOptions | undefined>(() => {
    if (!feedStore.postsPaginated) return undefined;
    return {
        currentPage: feedStore.postsPaginated.current_page,
        itemsTotal: feedStore.postsPaginated.total,
        perPage: feedStore.postsPaginated.per_page
    };
});

/** The file picker's accept list, from the server's own allowlist. */
const acceptAttribute = computed<string>(() => (feedStore.feedMeta?.accepted_image_types ?? []).join(','));

const uploadHint = computed<string>(() => {
    const meta = feedStore.feedMeta;
    if (!meta || !meta.max_images_per_post) return '';
    return `Up to ${meta.max_images_per_post} images, ${meta.max_image_size_kb}KB each`;
});

// Lifecycle
onBeforeMount(async () => {
    await loadPosts(1);
});

onBeforeUnmount(() => {
    releaseImageUrls();
});

// Methods
/** Hand every decoded image back to the browser. */
const releaseImageUrls = () => {
    Object.values(imageUrls.value).forEach((url) => URL.revokeObjectURL(url));
    imageUrls.value = {};
};

const loadPosts = async (page: number) => {
    loading.value = true;
    loadError.value = '';
    forbidden.value = false;
    releaseImageUrls();
    try {
        await feedStore.fetchPosts(props.groupId, page);
        await hydrateImages();
    } catch (error) {
        if (isForbidden(error)) {
            forbidden.value = true;
        } else {
            loadError.value = apiErrorText(error, 'Failed to load the feed.');
        }
    } finally {
        loading.value = false;
    }
};

/**
 * Fetch each visible attachment. Failures are swallowed per image on purpose: a
 * single missing file must not blank a whole page of a class's term, and the
 * placeholder already says the image did not arrive.
 */
const hydrateImages = async () => {
    const attachments = posts.value.flatMap((post) => post.attachments);

    await Promise.all(attachments.map(async (attachment) => {
        try {
            const url = await feedStore.attachmentObjectUrl(attachment.download_path);
            imageUrls.value = { ...imageUrls.value, [attachment.id]: url };
        } catch (error) {
            // Left as a placeholder; nothing to report to the admin per image.
        }
    }));
};

/**
 * Pagination emits once on its own mount with the page it is already showing.
 * Ignoring that echo keeps the tab from fetching the same page twice and
 * re-decoding every image on it.
 */
const pageChange = async (data: PageChangeData) => {
    if (data.toPage === (paginationOptions.value?.currentPage ?? 1)) return;
    await loadPosts(data.toPage);
};

const onFilesChosen = (event: Event) => {
    const input = event.target as HTMLInputElement;
    chosenFiles.value = Array.from(input.files ?? []);
    // Reset so re-picking the same file still fires a change event.
    input.value = '';
};

const submitPost = async () => {
    if (!composeBody.value) return;
    posting.value = true;
    try {
        await feedStore.createPost(
            props.groupId,
            { title: composeTitle.value, body: composeBody.value },
            chosenFiles.value
        );
        composeTitle.value = '';
        composeBody.value = '';
        chosenFiles.value = [];
        await loadPosts(1);
        Swal.fire({ icon: 'success', title: 'Posted', timer: 1600, showConfirmButton: false });
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Error!', text: apiErrorText(error, 'Failed to publish the post.') });
    } finally {
        posting.value = false;
    }
};

const confirmDelete = async (post: GroupPost) => {
    const result = await Swal.fire({
        title: 'Remove this post?',
        text: 'It disappears from the feed straight away. The images are deleted when the retention window closes.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, remove'
    });

    if (!result.isConfirmed) return;

    try {
        await feedStore.deletePost(props.groupId, post.id);
        await loadPosts(paginationOptions.value?.currentPage || 1);
        Swal.fire({ icon: 'success', title: 'Removed', timer: 1600, showConfirmButton: false });
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Error!', text: apiErrorText(error, 'Failed to remove the post.') });
    }
};

const formatDateTime = (iso: string | null): string => {
    if (!iso) return '—';
    const date = new Date(iso);
    return isNaN(date.getTime())
        ? iso
        : date.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
};

// A different group means a different feed.
watch(() => props.groupId, async () => {
    await loadPosts(1);
});
</script>

<style scoped>
.story-thumb {
    width: 120px;
    height: 120px;
    border-radius: 8px;
    overflow: hidden;
    background-color: #f8f9fa;
    border: 1px solid #e9ecef;
}

.story-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.story-thumb-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
}
</style>
