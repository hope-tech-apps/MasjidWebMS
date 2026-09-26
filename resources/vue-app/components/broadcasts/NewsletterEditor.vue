<template>
    <div class="d-flex flex-column gap-3">
        <div v-if="modelValue.length === 0" class="text-muted small border rounded p-3 bg-light">
            No blocks yet. Add a heading, text, pictures and buttons below; they appear in the email in this order,
            under your message.
        </div>

        <ol class="list-unstyled d-flex flex-column gap-3 mb-0" aria-label="Newsletter blocks">
            <li v-for="(block, index) in modelValue" :key="block.uid" class="border rounded bg-white">
                <div class="d-flex align-items-center justify-content-between gap-2 px-3 py-2 border-bottom bg-light">
                    <span class="fw-semibold small">{{ index + 1 }}. {{ BLOCK_LABELS[block.type] }}</span>
                    <div class="btn-group btn-group-sm" role="group" :aria-label="`Block ${index + 1} actions`">
                        <button type="button" class="btn btn-light" :disabled="index === 0"
                            :aria-label="`Move block ${index + 1} up`" title="Move up" @click="move(index, -1)">
                            <i class="bi bi-arrow-up" aria-hidden="true"></i>
                        </button>
                        <button type="button" class="btn btn-light" :disabled="index === modelValue.length - 1"
                            :aria-label="`Move block ${index + 1} down`" title="Move down" @click="move(index, 1)">
                            <i class="bi bi-arrow-down" aria-hidden="true"></i>
                        </button>
                        <button type="button" class="btn btn-light text-danger"
                            :aria-label="`Remove block ${index + 1}`" title="Remove" @click="remove(index)">
                            <i class="bi bi-trash" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>

                <div class="p-3 d-flex flex-column gap-2">
                    <template v-if="block.type === 'heading'">
                        <label class="form-label small mb-0" :for="`${block.uid}-text`">Heading</label>
                        <input :id="`${block.uid}-text`" class="dashboard-input" type="text" maxlength="200"
                            :value="block.text" @input="update(index, { text: value($event) })">
                        <AlignPicker :id="`${block.uid}-align`" :value="block.align" @change="update(index, { align: $event })" />
                    </template>

                    <template v-else-if="block.type === 'text'">
                        <RichTextInput :model-value="block.html" :label="`Block ${index + 1} text`"
                            @update:model-value="update(index, { html: $event })" />
                    </template>

                    <template v-else-if="block.type === 'image'">
                        <ImageFields :uid="block.uid" :image="block" :thumbnail="images[block.image]"
                            @change="update(index, $event)" @pick="emit('pick', { key: block.image, file: $event })" />
                    </template>

                    <template v-else-if="block.type === 'image_row'">
                        <div class="row g-3">
                            <div v-for="(image, side) in block.images" :key="image.image" class="col-12 col-md-6">
                                <div class="small fw-semibold mb-1">{{ side === 0 ? 'Left' : 'Right' }} picture</div>
                                <ImageFields :uid="`${block.uid}-${side}`" :image="image" :thumbnail="images[image.image]"
                                    @change="updateRowImage(index, side, $event)"
                                    @pick="emit('pick', { key: image.image, file: $event })" />
                            </div>
                        </div>
                        <div class="text-muted small">Side by side on a computer; one above the other on a phone.</div>
                    </template>

                    <template v-else-if="block.type === 'button'">
                        <label class="form-label small mb-0" :for="`${block.uid}-label`">Button text</label>
                        <input :id="`${block.uid}-label`" class="dashboard-input" type="text" maxlength="80"
                            placeholder="e.g. Register now" :value="block.label" @input="update(index, { label: value($event) })">
                        <label class="form-label small mb-0" :for="`${block.uid}-url`">Opens</label>
                        <input :id="`${block.uid}-url`" class="dashboard-input" type="url" placeholder="https://"
                            :value="block.url" @input="update(index, { url: value($event) })">
                        <AlignPicker :id="`${block.uid}-align`" :value="block.align" @change="update(index, { align: $event })" />
                    </template>

                    <template v-else-if="block.type === 'divider'">
                        <div class="text-muted small">A thin line between two sections.</div>
                    </template>

                    <template v-else-if="block.type === 'spacer'">
                        <label class="form-label small mb-0" :for="`${block.uid}-size`">Space</label>
                        <select :id="`${block.uid}-size`" class="dashboard-input w-auto" :value="block.size"
                            @change="update(index, { size: value($event) as SpacerSize })">
                            <option value="small">Small</option>
                            <option value="medium">Medium</option>
                            <option value="large">Large</option>
                        </select>
                    </template>
                </div>
            </li>
        </ol>

        <div>
            <div class="small fw-semibold mb-2">Add a block</div>
            <div class="d-flex flex-wrap gap-2">
                <button v-for="type in TYPES" :key="type" type="button" class="btn btn-sm btn-outline-success"
                    :disabled="modelValue.length >= MAX_BLOCKS" @click="add(type)">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> {{ BLOCK_LABELS[type] }}
                </button>
            </div>
            <div v-if="modelValue.length >= MAX_BLOCKS" class="text-muted small mt-1">
                A newsletter can have at most {{ MAX_BLOCKS }} blocks.
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
/**
 * The newsletter layout editor: an ordered list of blocks, each edited in place,
 * moved with up/down buttons (reachable by keyboard and screen reader, which a
 * drag handle alone is not), removed, or added from the row of block types.
 *
 * The list is never mutated: every change emits a new array through the rules in
 * core/helpers/newsletterBlocks.ts, so the composer's preview watcher sees each
 * edit. Picture FILES are not held here — a pick is emitted with the block's
 * upload key and the composer keeps the file, because it is the composer that
 * sends it.
 */
import { defineComponent, h, type PropType } from 'vue'
import RichTextInput from '@/components/broadcasts/RichTextInput.vue'
import { MSwal } from '@/core/plugins/SweetAlerts2'
import {
    BLOCK_LABELS,
    MAX_BLOCKS,
    MAX_IMAGE_BYTES,
    moveBlock,
    newBlock,
    removeBlock,
    type Align,
    type EditorBlock,
    type NewsletterBlockType,
    type NewsletterImage,
    type SpacerSize,
} from '@/core/helpers/newsletterBlocks'

const props = defineProps<{
    modelValue: EditorBlock[];
    /** upload key => data URL of the picked file, for thumbnails */
    images: Record<string, string | undefined>;
    nextKey: () => string;
}>()

const emit = defineEmits<{
    (e: 'update:modelValue', blocks: EditorBlock[]): void;
    (e: 'pick', payload: { key: string; file: File }): void;
}>()

const TYPES: NewsletterBlockType[] = ['heading', 'text', 'image', 'image_row', 'button', 'divider', 'spacer']

let uidSeq = 0
const uid = () => `nlb-${Date.now().toString(36)}-${++uidSeq}`

function add(type: NewsletterBlockType) {
    emit('update:modelValue', [...props.modelValue, newBlock(type, uid(), props.nextKey)])
}

function move(index: number, direction: -1 | 1) {
    emit('update:modelValue', moveBlock(props.modelValue, index, index + direction))
}

function remove(index: number) {
    emit('update:modelValue', removeBlock(props.modelValue, index))
}

function update(index: number, patch: Record<string, unknown>) {
    emit('update:modelValue', props.modelValue.map((b, i) => (i === index ? { ...b, ...patch } as EditorBlock : b)))
}

function updateRowImage(index: number, side: number, patch: Partial<NewsletterImage>) {
    const block = props.modelValue[index]
    if (block.type !== 'image_row') return
    const images = block.images.map((img, i) => (i === side ? { ...img, ...patch } : img)) as [NewsletterImage, NewsletterImage]
    update(index, { images })
}

function value(event: Event): string {
    return (event.target as HTMLInputElement | HTMLSelectElement).value
}

/** Left / centre, as a labelled select. */
const AlignPicker = defineComponent({
    props: { id: { type: String, required: true }, value: { type: String as PropType<Align>, required: true } },
    emits: ['change'],
    setup(p, { emit: e }) {
        return () => h('div', { class: 'd-flex align-items-center gap-2' }, [
            h('label', { class: 'form-label small mb-0', for: p.id }, 'Align'),
            h('select', {
                id: p.id,
                class: 'dashboard-input w-auto',
                value: p.value,
                onChange: (ev: Event) => e('change', (ev.target as HTMLSelectElement).value as Align),
            }, [h('option', { value: 'left' }, 'Left'), h('option', { value: 'center' }, 'Centre')]),
        ])
    },
})

/**
 * One picture: the file, its description (required — the server refuses a
 * picture without one) and an optional link.
 */
const ImageFields = defineComponent({
    props: {
        uid: { type: String, required: true },
        image: { type: Object as PropType<NewsletterImage>, required: true },
        thumbnail: { type: String, required: false },
    },
    emits: ['change', 'pick'],
    setup(p, { emit: e }) {
        const onFile = async (ev: Event) => {
            const input = ev.target as HTMLInputElement
            const file = input.files?.[0]
            input.value = ''
            if (!file) return
            if (file.size > MAX_IMAGE_BYTES) {
                await MSwal.fire({ title: 'Picture too large', text: 'Pictures can be up to 8 MB. A web-sized copy (under 1 MB) also loads faster in people\'s inboxes.', icon: 'warning' })
                return
            }
            e('pick', file)
        }

        return () => h('div', { class: 'd-flex flex-column gap-2' }, [
            h('div', { class: 'd-flex align-items-center gap-3' }, [
                p.thumbnail
                    ? h('img', { src: p.thumbnail, alt: '', class: 'rounded border', style: 'width:96px;height:64px;object-fit:cover;' })
                    : h('div', { class: 'rounded border bg-light d-flex align-items-center justify-content-center text-muted', style: 'width:96px;height:64px;' }, [h('i', { class: 'bi bi-image', 'aria-hidden': 'true' })]),
                h('label', { class: 'btn btn-sm btn-outline-secondary mb-0', for: `${p.uid}-file` }, p.thumbnail ? 'Change picture' : 'Choose picture'),
                h('input', { id: `${p.uid}-file`, type: 'file', class: 'd-none', accept: 'image/png,image/jpeg,image/gif,image/webp', onChange: onFile }),
            ]),
            h('label', { class: 'form-label small mb-0', for: `${p.uid}-alt` }, 'Description (required)'),
            h('input', {
                id: `${p.uid}-alt`, class: 'dashboard-input', type: 'text', maxlength: 300,
                placeholder: 'What the picture shows, for people who cannot see it',
                value: p.image.alt,
                onInput: (ev: Event) => e('change', { alt: (ev.target as HTMLInputElement).value }),
            }),
            h('label', { class: 'form-label small mb-0', for: `${p.uid}-link` }, 'Link (optional)'),
            h('input', {
                id: `${p.uid}-link`, class: 'dashboard-input', type: 'url', placeholder: 'https://',
                value: p.image.link,
                onInput: (ev: Event) => e('change', { link: (ev.target as HTMLInputElement).value }),
            }),
        ])
    },
})
</script>
