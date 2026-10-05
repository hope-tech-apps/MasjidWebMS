<template>
    <div class="section-document-upload mt-2">
        <input
            ref="fileInput"
            type="file"
            class="d-none"
            :accept="SECTION_DOCUMENT_ACCEPT"
            tabindex="-1"
            aria-hidden="true"
            @change="onFileChosen"
        />

        <button
            type="button"
            class="btn btn-sm btn-outline-primary"
            :disabled="uploading"
            @click="chooseFile"
        >
            <i class="bi bi-file-earmark-arrow-up"></i> {{ buttonText }}
        </button>

        <div class="form-text">
            Or upload a PDF (up to 25 MB) and its address is filled in for you. Anyone with the
            address can open it as soon as it is uploaded.
        </div>

        <div v-if="problem" class="text-danger small mt-1" role="alert">{{ problem }}</div>

        <div v-if="documentName" class="form-text">
            Document: {{ documentName }}
            <a :href="value || undefined" target="_blank" rel="noopener noreferrer">Open</a>
        </div>

        <div v-if="justUploaded" class="form-text" role="status">
            Uploaded. It is already online, even before you save.
            <template v-if="replacedSomething">Check that the wording beside it still describes this file.</template>
            A file uploaded by mistake stays online until you save this section with it, then clear
            the address and save again.
        </div>

        <div v-if="documentName" class="form-text">
            It stays online while a saved section links to it. To take it offline, clear the address
            and save; uploading another PDF in its place does the same to this one.
        </div>
    </div>
</template>

<script setup lang="ts">
/**
 * "Upload a PDF", beside a link field of a page-builder section.
 *
 * A document is NOT queued with the section's images and does not wait for Save: the file is sent at
 * once (pagesStore.uploadPageDocument), and what comes back is an ADDRESS, which the editor writes
 * into its link field as if the office had pasted it. So the live preview can open the document
 * before Save, and the address moves with its row because it is only text.
 *
 * This component keeps NO file and nothing about a row. Editors key their rows by position, so
 * anything remembered here would stay behind when a row moves; what it shows is read from `value`
 * (the link field), and an editor that moves or removes rows gives each control a new key so its
 * last message goes too. While an upload is in flight it says so (`busy`), and the editor holds its
 * rows still until it ends: the address is written to the row the upload was started from.
 *
 * It never shows the PDF inside the admin (the admin's content policy forbids embedding one); the
 * link opens a new tab.
 */
import { computed, onBeforeUnmount, ref } from 'vue';
import { usePagesStore } from '@/stores/masjid/pagesStore';
import {
    SECTION_DOCUMENT_ACCEPT,
    SECTION_DOCUMENT_UPLOAD_FAILED,
    sectionDocumentFileProblem,
    sectionDocumentName,
} from '@/core/helpers/sectionDocumentFile';

const props = defineProps<{
    /** What the link field holds now. */
    value?: string | null;
}>();

const emit = defineEmits<{
    busy: [busy: boolean];
    uploaded: [document: { url: string; name: string }];
}>();

const pagesStore = usePagesStore();

const fileInput = ref<HTMLInputElement | null>(null);
const uploading = ref(false);
const problem = ref('');
// The address the last upload here was given. Only ever compared with `value`: once the field holds
// anything else, nothing is said about it.
const uploadedUrl = ref('');
const replacedSomething = ref(false);
let mounted = true;

/** The stored file's name, when the field holds the address of a page document. */
const documentName = computed(() => sectionDocumentName(props.value));

const justUploaded = computed(() => uploadedUrl.value !== '' && uploadedUrl.value === props.value);

const buttonText = computed(() => {
    if (uploading.value) {
        return 'Uploading…';
    }

    return documentName.value ? 'Replace PDF' : 'Upload a PDF';
});

const chooseFile = () => {
    fileInput.value?.click?.();
};

const onFileChosen = async (event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    // Emptied at once, so choosing the same file again after a refusal is still a change.
    input.value = '';

    if (!file || uploading.value) {
        return;
    }

    // Told here, before anything is sent: a wrong or oversize file never reaches the server.
    problem.value = sectionDocumentFileProblem(file) ?? '';
    if (problem.value) {
        return;
    }

    const hadSomething = typeof props.value === 'string' && props.value.trim() !== '';

    uploading.value = true;
    emit('busy', true);
    try {
        const stored = await pagesStore.uploadPageDocument(file);

        if (mounted) {
            uploadedUrl.value = stored.url;
            replacedSomething.value = hadSomething;
            emit('uploaded', { url: stored.url, name: stored.name || file.name });
        }
    } catch (e) {
        // The store's message is already a sentence for the office (the server's own, for a refusal).
        problem.value = (e as Error)?.message || SECTION_DOCUMENT_UPLOAD_FAILED;
    } finally {
        uploading.value = false;
        if (mounted) {
            emit('busy', false);
        }
    }
};

onBeforeUnmount(() => {
    // The editor counts uploads in flight to hold its rows still. One that outlives this control
    // must not be counted for ever; its file is stored all the same, and linked from nowhere.
    if (uploading.value) {
        emit('busy', false);
    }
    mounted = false;
});
</script>
