<template>
    <div class="rt-input border rounded bg-white">
        <div class="d-flex flex-wrap gap-1 p-1 border-bottom bg-light" role="toolbar" :aria-label="`${label}: formatting`">
            <!-- mousedown.prevent keeps the selection in the text box while a button is pressed. -->
            <button v-for="c in COMMANDS" :key="c.command" type="button" class="btn btn-sm btn-light"
                :title="c.label" :aria-label="c.label" @mousedown.prevent @click="exec(c.command)">
                <i :class="`bi ${c.icon}`" aria-hidden="true"></i>
            </button>
            <button type="button" class="btn btn-sm btn-light" title="Add a link" aria-label="Add a link"
                @mousedown.prevent @click="addLink">
                <i class="bi bi-link-45deg" aria-hidden="true"></i>
            </button>
            <button type="button" class="btn btn-sm btn-light" title="Remove the link" aria-label="Remove the link"
                @mousedown.prevent @click="exec('unlink')">
                <i class="bi bi-link-45deg text-decoration-line-through" aria-hidden="true"></i>
            </button>
        </div>
        <div ref="box" class="rt-box p-2" contenteditable="true" role="textbox" aria-multiline="true"
            :aria-label="label" @input="emitValue" @blur="emitValue" @paste="onPaste"></div>
    </div>
</template>

<script setup lang="ts">
/**
 * A small rich-text box for the newsletter's text block: bold, italic, underline,
 * links and lists — the whole of what the email's RichText allows, and nothing it
 * would throw away.
 *
 * Paste is PLAIN TEXT. Text copied from Word, Google Docs or another email carries
 * fonts, colours, tracking pixels and markup the server would strip anyway; taking
 * only the words means what the admin sees here is what the email will say, and no
 * pasted <img onerror> ever lands in the admin's own page.
 *
 * This is not the security boundary. The server re-parses whatever this emits
 * (App\Services\Broadcast\Newsletter\RichText) before storing it and again before
 * rendering it; the initial value is passed through DOMPurify here only because it
 * is being written into the admin's own DOM.
 */
import DOMPurify from 'dompurify'
import { onMounted, ref } from 'vue'
import { MSwal } from '@/core/plugins/SweetAlerts2'
import { isLinkAddress } from '@/core/helpers/newsletterBlocks'

const props = defineProps<{ modelValue: string; label: string }>()
const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>()

const COMMANDS = [
    { command: 'bold', label: 'Bold', icon: 'bi-type-bold' },
    { command: 'italic', label: 'Italic', icon: 'bi-type-italic' },
    { command: 'underline', label: 'Underline', icon: 'bi-type-underline' },
    { command: 'insertUnorderedList', label: 'Bulleted list', icon: 'bi-list-ul' },
    { command: 'insertOrderedList', label: 'Numbered list', icon: 'bi-list-ol' },
]

const box = ref<HTMLDivElement>()

onMounted(() => {
    if (box.value) {
        box.value.innerHTML = DOMPurify.sanitize(props.modelValue || '', {
            ALLOWED_TAGS: ['p', 'br', 'div', 'strong', 'b', 'em', 'i', 'u', 'a', 'ul', 'ol', 'li'],
            ALLOWED_ATTR: ['href'],
        })
    }
})

function emitValue() {
    emit('update:modelValue', box.value?.innerHTML ?? '')
}

// execCommand is deprecated but is still the only editing API every browser
// implements for contenteditable; the alternative is a dependency this form
// does not need.
function exec(command: string, value?: string) {
    box.value?.focus()
    document.execCommand(command, false, value)
    emitValue()
}

function onPaste(event: ClipboardEvent) {
    event.preventDefault()
    const text = event.clipboardData?.getData('text/plain') ?? ''
    document.execCommand('insertText', false, text)
    emitValue()
}

/**
 * The selection is saved before the dialog opens (it takes focus) and restored
 * after, so the link lands on the words the admin had selected.
 */
async function addLink() {
    const selection = window.getSelection()
    const range = selection && selection.rangeCount > 0 && box.value?.contains(selection.anchorNode)
        ? selection.getRangeAt(0).cloneRange()
        : null

    if (!range || range.collapsed) {
        await MSwal.fire({ title: 'Select some words first', text: 'Highlight the words that should become the link, then press the link button.', icon: 'info' })
        return
    }

    const result = await MSwal.fire({
        title: 'Link address',
        input: 'text',
        inputPlaceholder: 'https://',
        showCancelButton: true,
        cancelButtonText: 'Cancel',
        confirmButtonText: 'Add link',
        inputValidator: (value: string) => isLinkAddress(value)
            ? null
            : 'Enter a full web address starting https://, or mailto: and an email address.',
    })

    if (!result.isConfirmed || !result.value) return

    selection!.removeAllRanges()
    selection!.addRange(range)
    exec('createLink', String(result.value).trim())
}
</script>

<style scoped>
.rt-box {
    min-height: 7rem;
    line-height: 1.55;
    outline: none;
}

.rt-box:focus {
    box-shadow: inset 0 0 0 2px rgba(31, 122, 65, .35);
}

.rt-box :deep(p) {
    margin: 0 0 .5rem;
}
</style>
