<template>
    <PageDataContainer
        title="Pages Management"
        :paginationOptions="paginationOptions"
        @headerButtonClick="openCreateModal"
        @pageChange="pageChange"
    >
        <template #headerButtons>
            <button class="btn btn-outline-primary me-2" @click="router.push('/masjid/sections-library')">
                <i class="bi bi-collection me-2"></i>
                Sections Library
            </button>
        </template>

        <div class="container w-100">
            <div class="alert alert-info d-flex align-items-center mb-3" v-if="pages.length > 0">
                <i class="bi bi-info-circle me-2"></i>
                <span>Drag and drop rows to reorder pages</span>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th width="40"><i class="bi bi-grip-vertical"></i></th>
                            <th>Title</th>
                            <th>Slug</th>
                            <th>Order</th>
                            <th>Status</th>
                            <th>Show in Menu</th>
                            <th>Show as Button</th>
                            <th>Sections</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <Draggable
                        v-model="sortablePages"
                        tag="tbody"
                        item-key="id"
                        handle=".drag-handle"
                        @end="onDragEnd"
                        :animation="200"
                        ghost-class="ghost-row"
                    >
                        <template #item="{ element: page }">
                            <tr :key="page.id" class="draggable-row">
                                <td class="drag-handle" style="cursor: grab;">
                                    <i class="bi bi-grip-vertical text-muted"></i>
                                </td>
                                <td>
                                    <strong>{{ page.title }}</strong>
                                </td>
                                <td>
                                    <code>/{{ page.slug }}</code>
                                </td>
                                <td>
                                    <span class="badge bg-secondary">{{ page.order }}</span>
                                </td>
                                <td>
                                    <span
                                        class="badge"
                                        :class="page.is_active ? 'bg-success' : 'bg-danger'"
                                    >
                                        {{ page.is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td>
                                    <i
                                        class="bi"
                                        :class="page.show_in_menu ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-muted'"
                                    ></i>
                                </td>
                                <td>
                                    <i
                                        class="bi"
                                        :class="page.show_as_button ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-muted'"
                                    ></i>
                                </td>
                                <td>
                                    <button
                                        class="btn btn-sm btn-outline-primary"
                                        @click="router.push(`/masjid/pages/${page.id}/sections`)"
                                    >
                                        <i class="bi bi-layout-text-sidebar-reverse me-1"></i>
                                        Manage Sections
                                    </button>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button
                                            class="btn btn-outline-primary"
                                            @click="openEditModal(page)"
                                            title="Edit"
                                        >
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button
                                            class="btn btn-outline-danger"
                                            @click="confirmDelete(page)"
                                            title="Delete"
                                        >
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </Draggable>
                </table>

                <div v-if="pages.length === 0" class="text-center text-muted py-5">
                    <i class="bi bi-file-earmark-text fs-1 d-block mb-3"></i>
                    No pages found. Create your first page!
                </div>
            </div>
        </div>
    </PageDataContainer>

    <!-- Page Form Modal -->
    <PageFormModal
        v-if="showModal"
        :page="selectedPage"
        @close="closeModal"
        @saved="handlePageSaved"
    />
</template>

<script setup lang="ts">
import PageDataContainer from '@/components/PageDataContainer.vue';
import PageFormModal from '@/components/modals/PageFormModal.vue';
import { Page } from '@/core/types/data/masjid-related/Page';
import { PageChangeData, PaginationOptions } from '@/core/types/elements/Pagination';
import { usePagesStore } from '@/stores/masjid/pagesStore';
import { computed, onBeforeMount, ref } from 'vue';
import { useRouter } from 'vue-router';
import Swal from 'sweetalert2';
import Draggable from 'vuedraggable';

// Lifecycle hooks
onBeforeMount(async () => {
    await pagesStore.fetchMasjidPagesPaginated(1).then(() => {
        paginationOptions.value.itemsTotal = pagesStore.pagesPaginated?.total ?? 0;
        paginationOptions.value.currentPage = pagesStore.pagesPaginated?.current_page ?? 0;
        paginationOptions.value.perPage = pagesStore.pagesPaginated?.per_page ?? 0;
    });
});

// Routing
const router = useRouter();

// Stores
const pagesStore = usePagesStore();

// State
const showModal = ref(false);
const selectedPage = ref<Page | undefined>(undefined);

// Computed
const pages = computed(() => {
    return pagesStore.pagesPaginated?.data ?? [];
});

// Sortable pages (writable computed for v-model)
const sortablePages = computed({
    get: () => pages.value,
    set: (value) => {
        // Update the store data directly
        if (pagesStore.pagesPaginated) {
            pagesStore.pagesPaginated.data = value;
        }
    }
});

// Pagination
const paginationOptions = ref<PaginationOptions>({
    itemsTotal: 0,
    currentPage: 0,
    perPage: 15
});

const pageChange = async (data: PageChangeData) => {
    await pagesStore.fetchMasjidPagesPaginated(data.toPage).then(() => {
        paginationOptions.value.itemsTotal = pagesStore.pagesPaginated?.total ?? 0;
        paginationOptions.value.currentPage = pagesStore.pagesPaginated?.current_page ?? 0;
        paginationOptions.value.perPage = pagesStore.pagesPaginated?.per_page ?? 0;
    });
};

// Modal handlers
const openCreateModal = () => {
    selectedPage.value = undefined;
    showModal.value = true;
};

const openEditModal = (page: Page) => {
    selectedPage.value = page;
    showModal.value = true;
};

const closeModal = () => {
    showModal.value = false;
    selectedPage.value = undefined;
};

const handlePageSaved = async () => {
    closeModal();
    await pagesStore.fetchMasjidPagesPaginated(paginationOptions.value.currentPage || 1);

    Swal.fire({
        icon: 'success',
        title: 'Success!',
        text: selectedPage.value ? 'Page updated successfully' : 'Page created successfully',
        timer: 2000,
        showConfirmButton: false
    });
};

// Drag and drop handler
const onDragEnd = async () => {
    try {
        // Update order based on new positions
        const pageOrders = sortablePages.value.map((page, index) => ({
            id: page.id,
            order: index + 1
        }));

        await pagesStore.reorderPages(pageOrders);

        // Refresh the list
        await pagesStore.fetchMasjidPagesPaginated(paginationOptions.value.currentPage || 1);

        Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: 'Pages reordered successfully',
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
            text: 'Failed to reorder pages. Please try again.',
        });
        // Refresh to restore original order
        await pagesStore.fetchMasjidPagesPaginated(paginationOptions.value.currentPage || 1);
    }
};

// Delete handler
const confirmDelete = async (page: Page) => {
    const result = await Swal.fire({
        title: 'Are you sure?',
        text: `Do you want to delete "${page.title}"? This will also detach all sections from this page.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
    });

    if (result.isConfirmed) {
        try {
            await pagesStore.deletePage(page.id);
            await pagesStore.fetchMasjidPagesPaginated(paginationOptions.value.currentPage || 1);

            Swal.fire({
                icon: 'success',
                title: 'Deleted!',
                text: 'Page has been deleted.',
                timer: 2000,
                showConfirmButton: false
            });
        } catch (error) {
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: 'Failed to delete page. Please try again.',
            });
        }
    }
};
</script>

<style scoped>
.table {
    background: white;
    border-radius: 8px;
}

code {
    background: #f8f9fa;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 0.875rem;
}

.draggable-row {
    transition: all 0.3s ease;
}

.draggable-row:hover {
    background-color: #f8f9fa;
}

.drag-handle:active {
    cursor: grabbing !important;
}

.ghost-row {
    opacity: 0.5;
    background: #e3f2fd;
}

.alert-info {
    background-color: #e7f3ff;
    border-color: #b3d9ff;
    color: #004085;
}
</style>

