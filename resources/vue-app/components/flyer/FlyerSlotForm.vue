<template>
    <div class="flyer-slot-form">
        <div v-for="slot in slots" :key="slot.name" class="mb-4">

            <label class="form-label d-flex align-items-center gap-2 mb-1" :for="`slot-${slot.name}`">
                <span class="fw-semibold">{{ slot.label }}</span>
                <span v-if="slot.required" class="text-danger">*</span>

                <!-- The phone number, the food disclaimer and the name of the deceased
                     are asked every time. Saying so in the form is the point. -->
                <span v-if="slot.ask_always" class="badge bg-warning-subtle text-warning-emphasis fw-normal">
                    <i class="bi bi-question-circle me-1"></i>Ask every time
                </span>
            </label>

            <!-- text -->
            <input
                v-if="slot.type === 'text'"
                :id="`slot-${slot.name}`"
                type="text"
                class="form-control"
                :maxlength="slot.maxLength ?? undefined"
                :value="stringValue(slot.name)"
                @input="onText(slot.name, $event)"
            >

            <!-- multiline -->
            <textarea
                v-else-if="slot.type === 'multiline'"
                :id="`slot-${slot.name}`"
                class="form-control"
                rows="3"
                :maxlength="slot.maxLength ?? undefined"
                :value="stringValue(slot.name)"
                @input="onText(slot.name, $event)"
            ></textarea>

            <!-- image -->
            <div v-else-if="slot.type === 'image'">
                <div v-if="imageState(slot.name)?.original" class="d-flex align-items-center gap-3">
                    <img class="slot-thumb" :src="previewFor(slot.name) ?? ''" alt="">
                    <div class="flex-grow-1">
                        <div class="small text-truncate">{{ imageState(slot.name)?.fileName }}</div>
                        <div class="d-flex gap-2 mt-2">
                            <label class="btn btn-sm btn-outline-secondary mb-0">
                                Replace
                                <input type="file" class="d-none" accept="image/*" @change="onImage(slot.name, $event)">
                            </label>
                            <button type="button" class="btn btn-sm btn-outline-danger" @click="emits('clear-image', slot.name)">
                                Remove
                            </button>
                        </div>
                    </div>
                </div>

                <label v-else class="slot-drop w-100 text-center">
                    <i class="bi bi-image fs-3 d-block mb-1"></i>
                    <span class="small">Choose an image</span>
                    <input type="file" class="d-none" accept="image/*" @change="onImage(slot.name, $event)">
                </label>

                <!-- Background removal, for the one image the flyer row can store. -->
                <div v-if="slot.name === cutoutSlot && imageState(slot.name)?.original" class="mt-2">
                    <div v-if="uploading || pending" class="d-flex align-items-center gap-2 small text-muted">
                        <span class="spinner-border spinner-border-sm" role="status"></span>
                        <span>{{ uploading ? 'Sending the photo…' : 'Removing the background…' }}</span>
                    </div>

                    <div v-else-if="cutoutStatus === 'done'" class="form-check form-switch">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            :id="`cutout-${slot.name}`"
                            :checked="imageState(slot.name)?.useCutout"
                            @change="onUseCutout(slot.name, $event)"
                        >
                        <label class="form-check-label small" :for="`cutout-${slot.name}`">
                            Use the cut-out
                            <span class="text-muted d-block">
                                A transparent cut-out reads best against the gradient. Switch it off to use the photo as taken.
                            </span>
                        </label>
                    </div>

                    <!-- Something went wrong on the way up. Say which photo it was about,
                         and keep the file in reach so "again" does not mean "find it again". -->
                    <div v-else-if="cutoutStatus === 'failed'" class="alert alert-warning py-2 px-3 mb-0 small">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        {{ cutoutError || 'The background could not be removed. The photo is being used as uploaded.' }}
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-secondary mt-2 d-block"
                            :disabled="uploading"
                            @click="emits('retry-photo')"
                        >
                            <i class="bi bi-arrow-clockwise me-1"></i>Try again
                        </button>
                    </div>

                    <div v-else-if="!cutoutAvailable" class="small text-muted">
                        Background removal is not switched on for this server — the photo will be used as uploaded.
                    </div>
                </div>

                <!-- Every other image belongs to this browser: the flyer row has room for
                     one picture, and it is the one background removal is for. -->
                <div v-else-if="imageState(slot.name)?.original" class="form-text mt-2">
                    Drawn on the flyer and included in the export, but not stored with the draft.
                </div>
            </div>

            <!-- list -->
            <div v-else-if="slot.type === 'list'">
                <div
                    v-for="(item, index) in listValue(slot.name)"
                    :key="index"
                    class="border rounded p-3 mb-2 bg-light-subtle"
                >
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="small text-muted fw-semibold">{{ index + 1 }}</span>
                        <button
                            type="button"
                            class="btn btn-sm btn-link text-danger p-0"
                            @click="emits('remove-item', slot.name, index)"
                        >
                            Remove
                        </button>
                    </div>

                    <div class="row g-2">
                        <div v-for="field in slot.item_slots ?? []" :key="field.name" class="col-12">
                            <label class="form-label small mb-1">
                                {{ field.label }}
                                <span v-if="field.required" class="text-danger">*</span>
                            </label>
                            <input
                                type="text"
                                class="form-control form-control-sm"
                                :maxlength="field.maxLength ?? undefined"
                                :value="item[field.name] ?? ''"
                                @input="onItem(slot.name, index, field.name, $event)"
                            >
                        </div>
                    </div>
                </div>

                <button
                    type="button"
                    class="btn btn-sm btn-outline-secondary"
                    :disabled="atItemCeiling(slot)"
                    @click="emits('add-item', slot.name)"
                >
                    <i class="bi bi-plus-lg me-1"></i>Add
                </button>

                <div v-if="atItemCeiling(slot)" class="form-text">{{ slot.help }}</div>
            </div>

            <div class="d-flex justify-content-between gap-3 mt-1">
                <div v-if="slot.help && slot.type !== 'list'" class="form-text">{{ slot.help }}</div>
                <div v-if="slot.maxLength" class="form-text text-nowrap ms-auto" :class="{ 'text-danger': nearLimit(slot) }">
                    {{ stringValue(slot.name).length }} / {{ slot.maxLength }}
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { PropType, computed, toRefs } from 'vue';
import {
    FlyerContent,
    FlyerCutoutStatus,
    FlyerImageSlotState,
    FlyerListItem,
    FlyerSlot,
    FLYER_CUTOUT_PENDING
} from '@/core/types/data/masjid-related/Flyer';

/**
 * The editor, generated from the design's manifest rather than hand-written per
 * template: the manifest is what the renderer reads, so a form built from anything
 * else would drift from what actually ends up on the flyer.
 */
const props = defineProps({
    slots: {
        type: Array as PropType<FlyerSlot[]>,
        required: true
    },
    content: {
        type: Object as PropType<FlyerContent>,
        required: true
    },
    images: {
        type: Object as PropType<Record<string, FlyerImageSlotState>>,
        required: true
    },
    /**
     * The one image slot the server stores, because it is the one background removal is
     * for. Named by the DESIGN, not by declaration order — a logo and a full-bleed
     * background are image slots too, and neither wants its background taken out.
     */
    cutoutSlot: {
        type: String as PropType<string | null>,
        required: false,
        default: null
    },
    cutoutStatus: {
        type: String as PropType<FlyerCutoutStatus>,
        required: false,
        default: 'none'
    },
    cutoutError: {
        type: String as PropType<string | null>,
        required: false,
        default: null
    },
    /** Whether this host can remove a background at all — the server's answer, not a guess. */
    cutoutAvailable: {
        type: Boolean,
        required: false,
        default: false
    },
    uploading: {
        type: Boolean,
        required: false,
        default: false
    }
});

// Emits
const emits = defineEmits([
    'update-slot',
    'update-list',
    'add-item',
    'remove-item',
    'image',
    'clear-image',
    'use-cutout',
    'retry-photo'
]);

const {
    slots,
    content,
    images,
    cutoutSlot,
    cutoutStatus,
    cutoutError,
    cutoutAvailable,
    uploading
} = toRefs(props);

const pending = computed(() => FLYER_CUTOUT_PENDING.includes(cutoutStatus.value));

// Methods
function stringValue(name: string): string {
    const value = content.value[name];
    return typeof value === 'string' ? value : '';
}

function listValue(name: string): FlyerListItem[] {
    const value = content.value[name];
    return Array.isArray(value) ? value : [];
}

function imageState(name: string): FlyerImageSlotState | undefined {
    return images.value[name];
}

/** What the flyer is actually using right now — cut-out when there is one and it is wanted. */
function previewFor(name: string): string | null {
    const state = imageState(name);
    if (!state) return null;

    return (state.useCutout && state.cutout) ? state.cutout : state.original;
}

function nearLimit(slot: FlyerSlot): boolean {
    if (!slot.maxLength) return false;
    return stringValue(slot.name).length >= slot.maxLength;
}

function atItemCeiling(slot: FlyerSlot): boolean {
    if (!slot.maxItems) return false;
    return listValue(slot.name).length >= slot.maxItems;
}

function onText(name: string, event: Event): void {
    const target = event.target as HTMLInputElement | HTMLTextAreaElement;
    emits('update-slot', name, target.value);
}

function onItem(name: string, index: number, field: string, event: Event): void {
    const target = event.target as HTMLInputElement;
    const items = listValue(name).map((item, i) => i === index ? { ...item, [field]: target.value } : item);
    emits('update-list', name, items);
}

function onImage(name: string, event: Event): void {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0];
    if (file) emits('image', name, file);

    // Cleared so choosing the same file twice still fires a change event — replacing a
    // photo with itself after a failed cut-out is a real thing admins do.
    target.value = '';
}

function onUseCutout(name: string, event: Event): void {
    const target = event.target as HTMLInputElement;
    emits('use-cutout', name, target.checked);
}
</script>

<style scoped>
.slot-thumb {
    width: 84px;
    height: 84px;
    object-fit: contain;
    border-radius: 8px;
    border: 1px solid #dee2e6;
    background: #f8f9fa;
    flex-shrink: 0;
}

.slot-drop {
    display: block;
    padding: 1.25rem;
    border: 2px dashed #ced4da;
    border-radius: 10px;
    color: #6c757d;
    cursor: pointer;
    transition: border-color 0.15s, color 0.15s;
}

.slot-drop:hover {
    border-color: #adb5bd;
    color: #495057;
}
</style>
