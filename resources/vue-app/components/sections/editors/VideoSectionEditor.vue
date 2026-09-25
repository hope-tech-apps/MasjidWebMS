<template>
    <div class="video-editor">
        <div class="row">
            <div class="col-12 mb-3">
                <label class="form-label" for="video-section-file">Video file (MP4, up to 25 MB)</label>
                <!--
                  A bare file input, not ImageDraggableInput: that component reads the file
                  into a data: URL to preview it, which for an 18 MB clip is a 24 MB string
                  held in the page, and it only accepts images.
                -->
                <input
                    id="video-section-file"
                    type="file"
                    class="form-control"
                    :class="{ 'is-invalid': fileProblem }"
                    :accept="SECTION_VIDEO_MIME"
                    @change="onVideoChange"
                />
                <div v-if="fileProblem" class="invalid-feedback d-block">{{ fileProblem }}</div>
                <div class="form-text">
                    An MP4 (H.264 video, AAC sound) plays in every browser. Keep it short: a
                    banner loops it for as long as the page is open.
                </div>
                <video
                    v-if="previewSrc"
                    :src="previewSrc"
                    :poster="localContent.poster_url || undefined"
                    class="mt-2 w-100 rounded border"
                    style="max-height: 240px; background: #000"
                    controls
                    muted
                    playsinline
                    preload="metadata"
                ></video>
            </div>

            <div class="col-12 mb-3">
                <ImageDraggableInput
                    name="poster_url"
                    type="photo"
                    label="Poster image"
                    :current-image-src="localContent.poster_url || undefined"
                    @image-change="onPosterChange"
                />
                <div class="form-text">
                    Shown before the video plays, to visitors who have asked their device for
                    less motion, and if the video cannot load.
                </div>
            </div>

            <div class="col-12 mb-3">
                <label class="form-label d-block">Show it as</label>
                <div class="form-check">
                    <input
                        id="video-layout-player"
                        v-model="localContent.layout"
                        class="form-check-input"
                        type="radio"
                        value="player"
                        @change="emitUpdate"
                    />
                    <label class="form-check-label" for="video-layout-player">
                        Player: visitors press play, with sound and the usual controls
                    </label>
                </div>
                <div class="form-check">
                    <input
                        id="video-layout-banner"
                        v-model="localContent.layout"
                        class="form-check-input"
                        type="radio"
                        value="banner"
                        @change="emitUpdate"
                    />
                    <label class="form-check-label" for="video-layout-banner">
                        Banner: full width, plays silently on a loop, with pause and sound buttons
                    </label>
                </div>
            </div>

            <div v-if="localContent.layout === 'player'" class="col-md-6 mb-3">
                <label class="form-label" for="video-max-width">Width</label>
                <select
                    id="video-max-width"
                    v-model="localContent.max_width"
                    class="form-select"
                    @change="emitUpdate"
                >
                    <option value="full">Full width (edge to edge)</option>
                    <option value="container">Container (matches page content)</option>
                    <option value="narrow">Narrow (centered column)</option>
                </select>
            </div>

            <div class="col-md-6 mb-3">
                <label class="form-label" for="video-background">Background Color</label>
                <input
                    id="video-background"
                    v-model="localContent.background_color"
                    type="color"
                    class="form-control form-control-color flex-shrink-0"
                    @input="emitUpdate"
                />
            </div>

            <div class="col-12 mb-3">
                <label class="form-label" for="video-title">Title</label>
                <input
                    id="video-title"
                    v-model="localContent.title"
                    type="text"
                    class="form-control"
                    placeholder="What the video shows, for screen readers"
                    @input="emitUpdate"
                />
                <div class="form-text">
                    Read aloud to visitors using a screen reader; not shown on the page. Left
                    blank, the video is announced simply as "Video".
                </div>
            </div>

            <div class="col-12 mb-3">
                <label class="form-label" for="video-caption">Caption</label>
                <input
                    id="video-caption"
                    v-model="localContent.caption"
                    type="text"
                    class="form-control"
                    placeholder="Optional caption shown under the video"
                    @input="emitUpdate"
                />
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
/**
 * Editor for a `video` section: one MP4 uploaded to this site, and its poster.
 *
 * The file travels the same way an image does: queued on the injected `sectionImages`
 * under the content key the backend writes its URL into (SectionType::VIDEO =>
 * ['video_url', 'poster_url']), sent in the modal's FormData, stored in `section_images`.
 * Single fields, so nothing is keyed by position and nothing needs re-keying.
 *
 * `video_url` in the content is NEVER set from the chosen file. The preview plays an
 * object URL held only here; a `blob:` URL written into the content would be saved as
 * the section's video if the upload were dropped, and it means nothing outside this tab.
 * The server replaces `video_url` with the stored file's URL when the upload lands.
 *
 * The 25 MB / MP4 check here mirrors the server's (ValidatesVideoSection), so an admin is
 * told before uploading; the server's check is the one that counts.
 */
import { VideoSectionContent } from '@/core/types/data/masjid-related/PageSection';
import { UploadedImageInfo } from '@/core/types/elements/ImageInput';
import ImageDraggableInput from '@/components/form/ImageDraggableInput.vue';
import { computed, inject, onBeforeUnmount, ref, watch } from 'vue';
import { useSectionImages } from '@/composables/useSectionImages';
import { SECTION_VIDEO_MIME, sectionVideoFileProblem } from '@/core/helpers/sectionVideoFile';

const props = defineProps<{
    modelValue: VideoSectionContent;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: VideoSectionContent];
}>();

const sectionImages = inject<ReturnType<typeof useSectionImages> | null>('sectionImages', null);

/** Every field defaulted, so content saved before a field existed still edits. */
const normalize = (value: Partial<VideoSectionContent> | undefined): VideoSectionContent => ({
    video_url: value?.video_url || null,
    poster_url: value?.poster_url || null,
    title: value?.title || '',
    caption: value?.caption || '',
    layout: value?.layout === 'banner' ? 'banner' : 'player',
    max_width: value?.max_width === 'full' || value?.max_width === 'narrow' ? value.max_width : 'container',
    background_color: value?.background_color || '#ffffff',
});

const localContent = ref<VideoSectionContent>(normalize(props.modelValue));

watch(() => props.modelValue, (newVal) => {
    if (newVal) {
        localContent.value = normalize(newVal);
    }
}, { deep: true });

const emitUpdate = () => {
    emit('update:modelValue', localContent.value);
};

const fileProblem = ref<string | null>(null);

/** An object URL for a file chosen in this session; revoked when replaced or unmounted. */
const chosenPreview = ref<string | null>(null);

const previewSrc = computed(() => chosenPreview.value || localContent.value.video_url || null);

const releasePreview = () => {
    if (chosenPreview.value) {
        URL.revokeObjectURL(chosenPreview.value);
        chosenPreview.value = null;
    }
};

const onVideoChange = (event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    releasePreview();

    if (!file) {
        fileProblem.value = null;
        sectionImages?.addImageFile('video_url', undefined);
        return;
    }

    fileProblem.value = sectionVideoFileProblem(file);
    if (fileProblem.value) {
        // Not queued: the server would refuse it, and the rest of the section can still
        // be saved while the admin finds a smaller file.
        sectionImages?.addImageFile('video_url', undefined);
        input.value = '';
        return;
    }

    chosenPreview.value = URL.createObjectURL(file);
    sectionImages?.addImageFile('video_url', file);
};

const onPosterChange = (data: UploadedImageInfo) => {
    localContent.value.poster_url = data.src || null;
    // The backend writes the stored still's URL into `poster_url`.
    sectionImages?.addImageFile('poster_url', data.file);
    emitUpdate();
};

onBeforeUnmount(releasePreview);
</script>
