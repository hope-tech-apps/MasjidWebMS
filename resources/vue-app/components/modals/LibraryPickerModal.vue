<template>
    <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);" @click.self="$emit('close')">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content">

                <!-- Header -->
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-collection me-2"></i>
                        Choose from Library — {{ typeLabel }}
                    </h5>
                    <button type="button" class="btn-close" @click="$emit('close')"></button>
                </div>

                <!-- Body -->
                <div class="modal-body">

                    <!-- Search -->
                    <div class="mb-3">
                        <input
                            type="text"
                            v-model="search"
                            class="dashboard-input form-control"
                            :placeholder="`Search ${typeLabel.toLowerCase()} presets…`"
                            @input="onSearchInput"
                        />
                    </div>

                    <!-- Loading -->
                    <div v-if="isLoading" class="text-center py-5 text-muted">
                        <span class="spinner-border spinner-border-sm me-2"></span>
                        Loading presets…
                    </div>

                    <!-- Empty -->
                    <div v-else-if="presets.length === 0" class="text-center py-5 text-muted">
                        No presets found.
                    </div>

                    <!-- Preset list -->
                    <div v-else class="d-flex flex-column gap-2">
                        <div
                            v-for="preset in presets"
                            :key="preset.id"
                            class="library-preset-card border rounded p-3"
                            :class="{ 'border-primary': selectedId === preset.id }"
                            @click="selectedId = preset.id"
                        >
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div class="flex-grow-1">
                                    <div class="fw-semibold arabic-text">{{ presetArabic(preset) }}</div>
                                    <div class="text-muted small">{{ presetSubtitle(preset) }}</div>
                                    <span v-if="presetTag(preset)" class="badge bg-light text-dark mt-1">
                                        {{ presetTag(preset) }}
                                    </span>
                                </div>
                                <div class="d-flex flex-column gap-1 flex-shrink-0">
                                    <button
                                        type="button"
                                        class="btn btn-outline-primary btn-sm"
                                        @click.stop="usePreset(preset)"
                                    >
                                        Use in form
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-success btn-sm"
                                        :disabled="addingId === preset.id"
                                        @click.stop="addPreset(preset)"
                                    >
                                        <span v-if="addingId === preset.id" class="spinner-border spinner-border-sm me-1"></span>
                                        Add directly
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div class="modal-footer">
                    <small class="text-muted me-auto">
                        "Use in form" fills the fields so you can edit before saving. "Add directly" copies the preset into your collection now.
                    </small>
                    <button type="button" class="btn btn-secondary" @click="$emit('close')">
                        Close
                    </button>
                </div>

            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { getMessageFromObj } from '@/assets/ts/swalMethods';
import { MSwal } from '@/core/plugins/SweetAlerts2';
import ApiService from '@/core/services/ApiService';
import { BackendResponseData } from '@/core/types/config/AxiosCustom';
import { BackendApiRoute } from '@/core/types/config/BackendApiRoutes';
import {
    LibraryAzkarPreset,
    LibraryHadithPreset,
    LibraryTasbeehPreset,
    LibraryType,
} from '@/core/types/data/LibraryPresets';
import { AxiosError, AxiosResponse } from 'axios';
import { onBeforeMount, ref } from 'vue';

type AnyPreset = LibraryHadithPreset | LibraryTasbeehPreset | LibraryAzkarPreset;

// Props
const props = defineProps<{
    type: LibraryType;
}>();

// Emits
const emit = defineEmits<{
    close: [];
    // Fired by "Use in form" — parent prefills its fields from the preset.
    prefill: [preset: AnyPreset];
    // Fired after "Add directly" succeeds — parent refreshes its list.
    added: [];
}>();

// State
const presets = ref<AnyPreset[]>([]);
const isLoading = ref(false);
const search = ref('');
const selectedId = ref<number | null>(null);
const addingId = ref<number | null>(null);
let searchTimer: ReturnType<typeof setTimeout> | null = null;

// Per-type endpoint roots (kept literal so they satisfy BackendApiRoute typing).
const typeLabel = props.type === 'hadith' ? 'Hadith'
    : props.type === 'tasbeeh' ? 'Tasbeeh'
    : 'Adhkar';

// Lifecycle
onBeforeMount(() => {
    fetchPresets();
});

// Build the list endpoint with an optional search query, typed per library kind.
function listRoute(): BackendApiRoute {
    const q = search.value.trim();
    if (props.type === 'hadith') {
        return q ? `/api/admin/hadiths/library?search=${q}` : `/api/admin/hadiths/library`;
    }
    if (props.type === 'tasbeeh') {
        return q ? `/api/admin/tasabih/library?search=${q}` : `/api/admin/tasabih/library`;
    }
    return q ? `/api/admin/azkar/library?search=${q}` : `/api/admin/azkar/library`;
}

function addRoute(): BackendApiRoute {
    if (props.type === 'hadith') return `/api/admin/hadiths/library/add`;
    if (props.type === 'tasbeeh') return `/api/admin/tasabih/library/add`;
    return `/api/admin/azkar/library/add`;
}

function idFieldName(): string {
    if (props.type === 'hadith') return 'library_hadith_id';
    if (props.type === 'tasbeeh') return 'library_tasbeeh_id';
    return 'library_azkar_id';
}

// Fetch presets (paginated payload — we read .data.data).
async function fetchPresets() {
    isLoading.value = true;
    await ApiService.get(listRoute())
        .then((res: AxiosResponse) => {
            if (res.data?.status === 'success') {
                presets.value = res.data.data?.data ?? [];
            }
        })
        .catch((e: AxiosError<BackendResponseData>) => {
            console.log('Fetch library presets error: ', e);
            presets.value = [];
        })
        .finally(() => {
            isLoading.value = false;
        });
}

// Debounced search.
function onSearchInput() {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => fetchPresets(), 300);
}

// "Use in form" — hand the preset to the parent to prefill, then close.
function usePreset(preset: AnyPreset) {
    emit('prefill', preset);
    emit('close');
}

// "Add directly" — copy the preset into the live collection server-side.
async function addPreset(preset: AnyPreset) {
    addingId.value = preset.id;
    const data = new FormData();
    data.append(idFieldName(), preset.id + '');

    await ApiService.post(addRoute(), data)
        .then((res: AxiosResponse) => {
            if (res.data?.status === 'success') {
                MSwal.fire('Success', `${typeLabel} added to your collection.`, 'success');
                emit('added');
                emit('close');
            } else {
                MSwal.fire('Sorry', getMessageFromObj(res), 'warning');
            }
        })
        .catch((e: AxiosError<BackendResponseData>) => {
            console.log('Add from library error: ', e);
            MSwal.fire('Error', getMessageFromObj(e), 'error');
        })
        .finally(() => {
            addingId.value = null;
        });
}

// --- Per-type display helpers (kept defensive against missing fields) ---
function presetArabic(preset: AnyPreset): string {
    if (props.type === 'hadith') return (preset as LibraryHadithPreset).matn;
    if (props.type === 'tasbeeh') return (preset as LibraryTasbeehPreset).text?.ar ?? '';
    return (preset as LibraryAzkarPreset).text?.ar ?? (preset as LibraryAzkarPreset).title?.ar ?? '';
}

function presetSubtitle(preset: AnyPreset): string {
    if (props.type === 'hadith') {
        const h = preset as LibraryHadithPreset;
        return h.title;
    }
    if (props.type === 'tasbeeh') {
        const t = preset as LibraryTasbeehPreset;
        return `${t.pronunciation} — ${t.text?.en ?? ''}`;
    }
    const a = preset as LibraryAzkarPreset;
    return `${a.title?.en ?? ''} — ${a.pronunciation}`;
}

function presetTag(preset: AnyPreset): string {
    if (props.type === 'hadith') {
        const h = preset as LibraryHadithPreset;
        return [h.category, h.muhaddith?.en].filter(Boolean).join(' · ');
    }
    if (props.type === 'tasbeeh') {
        const t = preset as LibraryTasbeehPreset;
        return t.default_count != null ? `Default count: ${t.default_count}` : '';
    }
    const a = preset as LibraryAzkarPreset;
    return a.category ? a.category : '';
}
</script>

<style scoped>
.modal {
    display: block;
}

.library-preset-card {
    cursor: pointer;
    transition: border-color 0.15s, background-color 0.15s;
}

.library-preset-card:hover {
    background-color: #f8f9fa;
}

.arabic-text {
    direction: rtl;
    text-align: right;
    font-size: 1.05rem;
    line-height: 1.9;
}
</style>
