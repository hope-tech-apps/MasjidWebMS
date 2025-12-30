<template>
    <div class="container-fluid py-4">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <button class="btn btn-outline-secondary mb-2" @click="router.back()">
                    <i class="bi bi-arrow-left"></i> Back to Pages
                </button>
                <h2 class="mb-0">
                    <i class="bi bi-layout-text-sidebar-reverse me-2"></i>
                    Sections for "{{ currentPage?.title }}"
                </h2>
                <p class="text-muted mb-0">
                    <code>/{{ currentPage?.slug }}</code>
                </p>
            </div>
            <button class="btn btn-primary" @click="openCreateModal">
                <i class="bi bi-plus-circle me-2"></i>
                Add Section
            </button>
        </div>

        <!-- Sections List -->
        <div class="card">
            <div class="card-body">
                <div v-if="loading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>

                <div v-else-if="sections.length === 0" class="text-center py-5 text-muted">
                    <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                    <p>No sections yet. Add your first section!</p>
                </div>

                <div v-else>
                    <div class="alert alert-info d-flex align-items-center mb-3">
                        <i class="bi bi-info-circle me-2"></i>
                        <span>Drag and drop sections to reorder them</span>
                    </div>

                    <Draggable
                        v-model="sortableSections"
                        item-key="id"
                        handle=".drag-handle"
                        @end="onDragEnd"
                        :animation="200"
                        ghost-class="ghost-section"
                        class="sections-list"
                    >
                        <template #item="{ element: section }">
                            <div
                                :key="section.id"
                                class="section-item mb-3 p-3 border rounded"
                                :class="{ 'opacity-50': !section.is_active }"
                            >
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="drag-handle me-3" style="cursor: grab;">
                                        <i class="bi bi-grip-vertical text-muted fs-4"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="d-flex align-items-center mb-2">
                                            <span class="badge bg-secondary me-2">{{ section.order }}</span>
                                            <span class="badge bg-info me-2">{{ section.section_type_label }}</span>
                                            <span
                                                v-if="section.uses_external_data"
                                                class="badge bg-warning text-dark me-2"
                                                title="Data from external API"
                                            >
                                                <i class="bi bi-link-45deg"></i> External Data
                                            </span>
                                            <span
                                                class="badge"
                                                :class="section.is_active ? 'bg-success' : 'bg-danger'"
                                            >
                                                {{ section.is_active ? 'Active' : 'Inactive' }}
                                            </span>
                                        </div>
                                        <h5 class="mb-1">{{ section.title || 'Untitled Section' }}</h5>
                                        <small class="text-muted">
                                            Type: {{ section.section_type }}
                                        </small>
                                    </div>
                                    <div class="btn-group">
                                        <button
                                            class="btn btn-sm btn-outline-primary"
                                            @click="openEditModal(section)"
                                            title="Edit"
                                        >
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button
                                            class="btn btn-sm btn-outline-danger"
                                            @click="confirmDelete(section)"
                                            title="Detach"
                                        >
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </Draggable>
                </div>
            </div>
        </div>
    </div>

    <!-- Section Form Modal -->
    <SectionFormModal
        v-if="showModal"
        :section="selectedSection"
        :pageId="pageId"
        @close="closeModal"
        @saved="handleSectionSaved"
    />
</template>

<script setup lang="ts">
import SectionFormModal from '@/components/modals/SectionFormModal.vue';
import { PageSection } from '@/core/types/data/masjid-related/PageSection';
import { usePagesStore } from '@/stores/masjid/pagesStore';
import { ref, onBeforeMount, computed } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import Swal from 'sweetalert2';
import Draggable from 'vuedraggable';

// Routing
const router = useRouter();
const route = useRoute();
const pageId = computed(() => Number(route.params.pageId));

// Store
const pagesStore = usePagesStore();

// State
const loading = ref(false);
const sections = ref<PageSection[]>([]);
const showModal = ref(false);
const selectedSection = ref<PageSection | undefined>(undefined);

// Computed
const currentPage = computed(() => pagesStore.currentPage);

// Sortable sections (writable computed for v-model)
const sortableSections = computed({
    get: () => sections.value,
    set: (value) => {
        sections.value = value;
    }
});

// Lifecycle
onBeforeMount(async () => {
    await loadData();
});

// Methods
const loadData = async () => {
    loading.value = true;
    try {
        await pagesStore.fetchPage(pageId.value);
        sections.value = await pagesStore.fetchPageSections(pageId.value);
        sections.value.sort((a, b) => a.order - b.order);
    } catch (error) {
        console.error('Error loading page sections:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: 'Failed to load page sections.',
        });
    } finally {
        loading.value = false;
    }
};

const openCreateModal = () => {
    selectedSection.value = undefined;
    showModal.value = true;
};

const openEditModal = (section: PageSection) => {
    selectedSection.value = section;
    showModal.value = true;
};

const closeModal = () => {
    showModal.value = false;
    selectedSection.value = undefined;
};

const handleSectionSaved = async () => {
    closeModal();
    await loadData();

    Swal.fire({
        icon: 'success',
        title: 'Success!',
        text: selectedSection.value ? 'Section updated successfully' : 'Section created successfully',
        timer: 2000,
        showConfirmButton: false
    });
};

const onDragEnd = async () => {
    try {
        // Update order for all sections based on new positions
        const updates = sortableSections.value.map((section, index) =>
            pagesStore.updateSection(pageId.value, section.id, { order: index + 1 })
        );

        await Promise.all(updates);
        await loadData();

        Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: 'Sections reordered successfully',
            timer: 1500,
            showConfirmButton: false,
            toast: true,
            position: 'top-end'
        });
    } catch (error) {
        console.error('Reorder error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: 'Failed to reorder sections.',
        });
        // Refresh to restore original order
        await loadData();
    }
};

const confirmDelete = async (section: PageSection) => {
    const result = await Swal.fire({
        title: 'Are you sure?',
        text: `Do you want to detach this section from the page? The section will remain in the library.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, detach it!'
    });

    if (result.isConfirmed) {
        try {
            await pagesStore.deleteSection(pageId.value, section.id);
            await loadData();

            Swal.fire({
                icon: 'success',
                title: 'Detached!',
                text: 'Section has been detached from this page.',
                timer: 2000,
                showConfirmButton: false
            });
        } catch (error) {
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: 'Failed to detach section.',
            });
        }
    }
};
</script>

<style scoped>
.section-item {
    background: white;
    transition: all 0.3s ease;
}

.section-item:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    transform: translateY(-2px);
}

.drag-handle:active {
    cursor: grabbing !important;
}

.ghost-section {
    opacity: 0.5;
    background: #e3f2fd;
}

.alert-info {
    background-color: #e7f3ff;
    border-color: #b3d9ff;
    color: #004085;
}

code {
    background: #f8f9fa;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 0.875rem;
}
</style>

