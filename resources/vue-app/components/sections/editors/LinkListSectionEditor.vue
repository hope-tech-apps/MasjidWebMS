<template>
    <div class="link-list-editor">
        <div class="row">
            <div class="col-12 mb-3">
                <label class="form-label">Heading</label>
                <input
                    type="text"
                    class="form-control"
                    v-model="localContent.heading"
                    @input="emitUpdate"
                    placeholder="Optional heading above the buttons"
                />
            </div>

            <div class="col-12 mb-3">
                <label class="form-label">Description</label>
                <textarea
                    class="form-control"
                    v-model="localContent.description"
                    @input="emitUpdate"
                    rows="2"
                    placeholder="Optional text shown under the heading"
                ></textarea>
            </div>

            <div class="col-md-6 mb-3">
                <label class="form-label">Layout</label>
                <select
                    class="form-select"
                    v-model="localContent.layout"
                    @change="emitUpdate"
                >
                    <option value="stack">Stack (one per row)</option>
                    <option value="inline">Inline (side by side)</option>
                    <option value="grid">Grid (wrapping tiles)</option>
                </select>
            </div>

            <div class="col-md-6 mb-3">
                <label class="form-label">Background Color</label>
                <input
                    type="color"
                    class="form-control form-control-color flex-shrink-0"
                    v-model="localContent.background_color"
                    @input="emitUpdate"
                />
            </div>

            <div class="col-12 mb-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0">Links</h6>
                    <button
                        type="button"
                        class="btn btn-sm btn-primary"
                        @click="addLink"
                    >
                        <i class="bi bi-plus-circle"></i> Add Link
                    </button>
                </div>

                <div
                    v-for="(link, index) in localContent.links"
                    :key="index"
                    class="card mb-3"
                >
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0">Link {{ index + 1 }}</h6>
                            <div class="btn-group">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary"
                                    @click="moveLinkUp(index)"
                                    :disabled="index === 0"
                                    title="Move Up"
                                >
                                    <i class="bi bi-arrow-up"></i>
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary"
                                    @click="moveLinkDown(index)"
                                    :disabled="index === localContent.links.length - 1"
                                    title="Move Down"
                                >
                                    <i class="bi bi-arrow-down"></i>
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-danger"
                                    @click="removeLink(index)"
                                    title="Remove Link"
                                >
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Label <span class="text-danger">*</span></label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="link.label"
                                    @input="emitUpdate"
                                    placeholder="e.g., Email Us"
                                    required
                                />
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">URL <span class="text-danger">*</span></label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="link.url"
                                    @input="emitUpdate"
                                    placeholder="https://example.com, mailto:info@masjid.org, tel:+15551234567"
                                    required
                                />
                                <div class="form-text">
                                    Use <code>mailto:</code> for email and <code>tel:</code> for phone
                                    numbers; anything else should start with <code>https://</code>.
                                </div>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Icon</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="link.icon"
                                    @input="emitUpdate"
                                    placeholder="Icon class, e.g., bi-envelope"
                                />
                                <div class="form-text">
                                    Bootstrap icon class. Leave blank for a text-only button.
                                </div>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Style</label>
                                <select
                                    class="form-select"
                                    v-model="link.style"
                                    @change="emitUpdate"
                                >
                                    <option value="primary">Primary</option>
                                    <option value="secondary">Secondary</option>
                                    <option value="outline">Outline</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="localContent.links.length === 0" class="alert alert-info">
                    No links added yet. Click "Add Link" to create your first button.
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { LinkListSectionContent, LinkListItem } from '@/core/types/data/masjid-related/PageSection';
import { ref, watch } from 'vue';

const props = defineProps<{
    modelValue: LinkListSectionContent;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: LinkListSectionContent];
}>();

const newLink = (): LinkListItem => ({
    label: '',
    url: '',
    icon: '',
    style: 'primary',
});

const localContent = ref<LinkListSectionContent>({
    heading: props.modelValue?.heading || '',
    description: props.modelValue?.description || '',
    links: props.modelValue?.links ? [...props.modelValue.links] : [],
    layout: props.modelValue?.layout || 'stack',
    background_color: props.modelValue?.background_color || '#ffffff',
});

watch(() => props.modelValue, (newVal) => {
    if (newVal) {
        localContent.value = {
            heading: newVal.heading || '',
            description: newVal.description || '',
            links: newVal.links ? [...newVal.links] : [],
            layout: newVal.layout || 'stack',
            background_color: newVal.background_color || '#ffffff',
        };
    }
}, { deep: true });

const emitUpdate = () => {
    emit('update:modelValue', localContent.value);
};

const addLink = () => {
    localContent.value.links.push(newLink());
    emitUpdate();
};

const removeLink = (index: number) => {
    localContent.value.links.splice(index, 1);
    emitUpdate();
};

const moveLinkUp = (index: number) => {
    if (index > 0) {
        const links = [...localContent.value.links];
        [links[index - 1], links[index]] = [links[index], links[index - 1]];
        localContent.value.links = links;
        emitUpdate();
    }
};

const moveLinkDown = (index: number) => {
    if (index < localContent.value.links.length - 1) {
        const links = [...localContent.value.links];
        [links[index], links[index + 1]] = [links[index + 1], links[index]];
        localContent.value.links = links;
        emitUpdate();
    }
};
</script>

<style scoped>
.card {
    border: 1px solid #dee2e6;
}

.card-body {
    background-color: #f8f9fa;
}
</style>
