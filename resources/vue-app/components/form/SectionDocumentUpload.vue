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

        <!-- Never `disabled` while a file is in flight: a button that is disabled while it has the
             keyboard's focus drops that focus to the page, and a person using the keyboard is left at
             the top of the screen. It is marked busy for a screen reader and for the eye, and
             chooseFile() does nothing. -->
        <button
            type="button"
            class="btn btn-sm btn-outline-primary"
            :class="{ disabled: uploading }"
            :aria-disabled="uploading ? 'true' : undefined"
            :aria-label="label ? `${buttonText} for ${label}` : undefined"
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
            <a
                :href="value || undefined"
                target="_blank"
                rel="noopener noreferrer"
                :aria-label="label ? `Open the PDF for ${label}` : undefined"
            >Open</a>
        </div>

        <div v-if="justUploaded" class="form-text" role="status">
            Uploaded. It is already online, even before you save.
            <template v-if="filledNote">{{ filledNote }}</template>
            <template v-if="replacedSomething">Check that the wording beside it still describes this file.</template>
        </div>

        <!-- A file that was replaced before it was ever saved is linked from nowhere and deleted by
             nothing, and this is the last screen that knows its address. -->
        <div v-for="left in leftOnline" :key="left.url" class="form-text" role="status">
            The PDF this one replaced ({{ left.name }}) was not in the saved section, so replacing it
            did not take it offline: it is still online. To take it offline, save a section with its
            address in a link, then clear the address and save again. Its address:
            <span class="user-select-all text-break">{{ left.url }}</span>
        </div>

        <div v-if="documentName && savedDocument" class="form-text">
            It stays online while a saved section links to it. To take it offline, clear the address
            and save; uploading another PDF in its place does the same to this one.
        </div>
        <div v-else-if="documentName" class="form-text">
            This file is not in the saved section yet. Clearing its address, or uploading another
            PDF in its place, before you save leaves it online. To take it offline, save the section
            with it first, then clear the address and save again.
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
 * WHAT IT SAYS ABOUT TAKING A FILE OFFLINE DEPENDS ON WHETHER THE FILE IS SAVED. The server deletes a
 * document when a save stops linking it, and compares with the SAVED section: a file that was
 * uploaded since the section was last saved is in nothing saved, so clearing or replacing it deletes
 * nothing. SectionFormModal provides the documents the saved section links (`sectionSavedDocuments`),
 * which stays true when a row moves and this control is made anew. Without the modal, only what this
 * control itself uploaded is known to be unsaved.
 *
 * The modal also provides a count of uploads in flight (`sectionDocumentUploads`), which this raises
 * and lowers, so Save waits: a section saved mid-upload would be saved without the address.
 *
 * It never shows the PDF inside the admin (the admin's content policy forbids embedding one); the
 * link opens a new tab.
 */
import { computed, inject, onBeforeUnmount, ref, type Ref } from 'vue';
import { usePagesStore } from '@/stores/masjid/pagesStore';
import {
    SECTION_DOCUMENT_ACCEPT,
    SECTION_DOCUMENT_UPLOAD_FAILED,
    sectionDocumentFileProblem,
    sectionDocumentName,
    sectionDocumentPath,
    type SectionDocument,
} from '@/core/helpers/sectionDocumentFile';

const props = defineProps<{
    /** What the link field holds now. */
    value?: string | null;
    /**
     * What this row is called (a button's label, a program's name), for the accessible names of the
     * button and the Open link: a section with four rows otherwise has four buttons called "Upload a
     * PDF" and four links called "Open".
     */
    label?: string | null;
}>();

const emit = defineEmits<{
    busy: [busy: boolean];
    /**
     * `filled` is for the editor to call with one sentence when it filled a blank field beside the
     * link from the file's name (a label, an icon, a link's text), so the office is told what changed
     * besides the address.
     */
    uploaded: [document: { url: string; name: string; filled: (sentence: string) => void }];
}>();

const pagesStore = usePagesStore();

// Both provided by SectionFormModal, beside `sectionImages`; absent anywhere else.
const uploadsInFlight = inject<Ref<number> | null>('sectionDocumentUploads', null);
const savedDocuments = inject<readonly SectionDocument[] | null>('sectionSavedDocuments', null);

const fileInput = ref<HTMLInputElement | null>(null);
const uploading = ref(false);
const problem = ref('');
// The address the last upload here was given. Only ever compared with `value`: once the field holds
// anything else, nothing is said about it.
const uploadedUrl = ref('');
const replacedSomething = ref(false);
const filledNote = ref('');
// Every address this control was given by an upload, and the unsaved documents its uploads replaced.
const uploadedHere = ref<string[]>([]);
const replacedUnsaved = ref<Array<{ url: string; name: string }>>([]);
let mounted = true;
let counted = false;

/** The stored file's name, when the field holds the address of a page document. */
const documentName = computed(() => sectionDocumentName(props.value));

const justUploaded = computed(() => uploadedUrl.value !== '' && uploadedUrl.value === props.value);

/** Whether this address is a document the SAVED section links, so that letting go of it and saving takes it offline. */
const isSaved = (value: unknown): boolean => {
    const path = sectionDocumentPath(value);
    if (path === null) {
        return false;
    }

    return savedDocuments
        ? savedDocuments.some((document) => document.path === path)
        : !uploadedHere.value.includes(String(value).trim());
};

const savedDocument = computed(() => isSaved(props.value));

/** The replaced files that are still online and no longer in this field. */
const leftOnline = computed(() => replacedUnsaved.value.filter((left) => left.url !== (props.value ?? '').trim()));

const buttonText = computed(() => {
    if (uploading.value) {
        return 'Uploading…';
    }

    return documentName.value ? 'Replace PDF' : 'Upload a PDF';
});

/** Tell the modal an upload started or ended here, once each. */
const count = (running: boolean) => {
    if (!uploadsInFlight || counted === running) {
        return;
    }
    counted = running;
    uploadsInFlight.value = Math.max(0, uploadsInFlight.value + (running ? 1 : -1));
};

const chooseFile = () => {
    if (uploading.value) {
        return;
    }
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

    const replaced = typeof props.value === 'string' ? props.value.trim() : '';
    const replacedName = sectionDocumentName(replaced);
    const replacedWasUnsaved = replacedName !== null && !isSaved(replaced);

    uploading.value = true;
    emit('busy', true);
    count(true);
    try {
        const stored = await pagesStore.uploadPageDocument(file);

        if (mounted) {
            uploadedUrl.value = stored.url;
            uploadedHere.value.push(stored.url);
            replacedSomething.value = replaced !== '';
            filledNote.value = '';
            if (replacedWasUnsaved && replaced !== stored.url && !replacedUnsaved.value.some((left) => left.url === replaced)) {
                replacedUnsaved.value.push({ url: replaced, name: replacedName });
            }
            emit('uploaded', {
                url: stored.url,
                name: stored.name || file.name,
                filled: (sentence: string) => { filledNote.value = sentence; },
            });
        }
    } catch (e) {
        // The store's message is already a sentence for the office (the server's own, for a refusal).
        problem.value = (e as Error)?.message || SECTION_DOCUMENT_UPLOAD_FAILED;
    } finally {
        uploading.value = false;
        count(false);
        if (mounted) {
            emit('busy', false);
        }
    }
};

onBeforeUnmount(() => {
    // The editor and the modal count uploads in flight, to hold the rows still and to hold Save. One
    // that outlives this control must not be counted for ever; its file is stored all the same, and
    // linked from nowhere.
    if (uploading.value) {
        emit('busy', false);
        count(false);
    }
    mounted = false;
});
</script>
