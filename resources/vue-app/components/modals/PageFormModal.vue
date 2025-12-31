<template>
    <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-file-earmark-text me-2"></i>
                        {{ isEdit ? 'Edit Page' : 'Create New Page' }}
                    </h5>
                    <button type="button" class="btn-close" @click="$emit('close')"></button>
                </div>
                <div class="modal-body">
                    <form @submit.prevent="handleSubmit">
                        <div class="row">
                            <!-- Title -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    Title <span class="text-danger">*</span>
                                </label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="formData.title"
                                    @input="generateSlug"
                                    required
                                    placeholder="e.g., About Us"
                                />
                            </div>

                            <!-- Slug -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    Slug <span class="text-danger">*</span>
                                </label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="formData.slug"
                                    required
                                    placeholder="e.g., about-us"
                                    pattern="[a-z0-9-]+"
                                    title="Only lowercase letters, numbers, and hyphens"
                                />
                                <small class="text-muted">URL: /{{ formData.slug || 'page-slug' }}</small>
                            </div>

                            <!-- Order -->
                            <div class="col-md-4 mb-3">
                                <label class="form-label">
                                    Order <span class="text-danger">*</span>
                                </label>
                                <input
                                    type="number"
                                    class="form-control"
                                    v-model.number="formData.order"
                                    required
                                    min="1"
                                />
                                <small class="text-muted">Display order in menu</small>
                            </div>

                            <!-- Is Active -->
                            <div class="col-md-4 mb-3">
                                <label class="form-label d-block">Status</label>
                                <div class="form-check form-switch mt-2">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        v-model="formData.is_active"
                                        id="isActiveSwitch"
                                    />
                                    <label class="form-check-label" for="isActiveSwitch">
                                        {{ formData.is_active ? 'Active' : 'Inactive' }}
                                    </label>
                                </div>
                            </div>

                            <!-- Show in Menu -->
                            <div class="col-md-4 mb-3">
                                <label class="form-label d-block">Menu Visibility</label>
                                <div class="form-check form-switch mt-2">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        v-model="formData.show_in_menu"
                                        id="showInMenuSwitch"
                                    />
                                    <label class="form-check-label" for="showInMenuSwitch">
                                        {{ formData.show_in_menu ? 'Show in Menu' : 'Hidden from Menu' }}
                                    </label>
                                </div>
                            </div>

                            <!-- Show as Button -->
                            <div class="col-md-4 mb-3">
                                <label class="form-label d-block">Button Display</label>
                                <div class="form-check form-switch mt-2">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        v-model="formData.show_as_button"
                                        id="showAsButtonSwitch"
                                    />
                                    <label class="form-check-label" for="showAsButtonSwitch">
                                        {{ formData.show_as_button ? 'Show as Button' : 'Regular Link' }}
                                    </label>
                                </div>
                            </div>

                            <!-- Meta Description -->
                            <div class="col-12 mb-3">
                                <label class="form-label">Meta Description</label>
                                <textarea
                                    class="form-control"
                                    v-model="formData.meta_description"
                                    rows="3"
                                    placeholder="SEO description for this page"
                                    maxlength="160"
                                ></textarea>
                                <small class="text-muted">
                                    {{ formData.meta_description?.length || 0 }}/160 characters
                                </small>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" @click="$emit('close')">
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="btn btn-primary"
                        @click="handleSubmit"
                        :disabled="loading"
                    >
                        <span v-if="loading" class="spinner-border spinner-border-sm me-2"></span>
                        {{ isEdit ? 'Update Page' : 'Create Page' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { Page } from '@/core/types/data/masjid-related/Page';
import { usePagesStore } from '@/stores/masjid/pagesStore';
import { ref, computed, onMounted } from 'vue';
import Swal from 'sweetalert2';

// Props
const props = defineProps<{
    page?: Page;
}>();

// Emits
const emit = defineEmits<{
    close: [];
    saved: [];
}>();

// Store
const pagesStore = usePagesStore();

// State
const loading = ref(false);
const formData = ref({
    title: '',
    slug: '',
    order: 1,
    is_active: true,
    show_in_menu: true,
    show_as_button: false,
    meta_description: ''
});

// Computed
const isEdit = computed(() => !!props.page);

// Lifecycle
onMounted(() => {
    if (props.page) {
        formData.value = {
            title: props.page.title,
            slug: props.page.slug,
            order: props.page.order,
            is_active: props.page.is_active,
            show_in_menu: props.page.show_in_menu,
            show_as_button: props.page.show_as_button,
            meta_description: props.page.meta_description || ''
        };
    }
});

// Methods
const generateSlug = () => {
    if (!isEdit.value && formData.value.title) {
        formData.value.slug = formData.value.title
            .toLowerCase()
            .replace(/[^a-z0-9\s-]/g, '')
            .replace(/\s+/g, '-')
            .replace(/-+/g, '-')
            .trim();
    }
};

const handleSubmit = async () => {
    loading.value = true;

    try {
        if (isEdit.value && props.page) {
            await pagesStore.updatePage(props.page.id, formData.value);
        } else {
            await pagesStore.createPage(formData.value);
        }

        emit('saved');
    } catch (error: any) {
        console.error('Error saving page:', error);

        let errorMessage = 'Failed to save page. Please try again.';
        if (error.response?.data?.message) {
            errorMessage = error.response.data.message;
        }

        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: errorMessage,
        });
    } finally {
        loading.value = false;
    }
};
</script>

<style scoped>
.modal {
    display: block;
}

.form-check-input:checked {
    background-color: #0d6efd;
    border-color: #0d6efd;
}
</style>

