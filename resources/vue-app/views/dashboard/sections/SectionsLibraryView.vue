<template>
    <div class="container-fluid py-4">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <button class="btn btn-outline-secondary mb-2" @click="router.push('/masjid/pages')">
                    <i class="bi bi-arrow-left"></i> Back to Pages
                </button>
                <h2 class="mb-0">
                    <i class="bi bi-collection me-2"></i>
                    Sections Library
                </h2>
                <p class="text-muted mb-0">
                    Manage reusable sections across all pages
                </p>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card border-primary">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <i class="bi bi-collection fs-1 text-primary"></i>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h6 class="text-muted mb-0">Total Sections</h6>
                                <h3 class="mb-0">{{ sections.length }}</h3>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-success">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <i class="bi bi-check-circle fs-1 text-success"></i>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h6 class="text-muted mb-0">Active Sections</h6>
                                <h3 class="mb-0">{{ activeSectionsCount }}</h3>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-info">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <i class="bi bi-diagram-3 fs-1 text-info"></i>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h6 class="text-muted mb-0">Section Types</h6>
                                <h3 class="mb-0">{{ uniqueTypesCount }}</h3>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sections List -->
        <div class="card">
            <div class="card-header bg-white">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">All Sections</h5>
                    <div class="input-group" style="max-width: 300px;">
                        <span class="input-group-text">
                            <i class="bi bi-search"></i>
                        </span>
                        <input 
                            type="text" 
                            class="form-control" 
                            placeholder="Search sections..."
                            v-model="searchQuery"
                        >
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div v-if="loading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>

                <div v-else-if="filteredSections.length === 0" class="text-center py-5 text-muted">
                    <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                    <p>{{ searchQuery ? 'No sections found matching your search' : 'No sections in library yet' }}</p>
                </div>

                <div v-else class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Used in Pages</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="section in filteredSections" :key="section.id">
                                <td>
                                    <strong>{{ section.title || 'Untitled Section' }}</strong>
                                </td>
                                <td>
                                    <span class="badge bg-info">{{ section.section_type_label }}</span>
                                </td>
                                <td>
                                    <span 
                                        class="badge" 
                                        :class="section.is_active ? 'bg-success' : 'bg-danger'"
                                    >
                                        {{ section.is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-secondary">
                                        {{ section.pages?.length || 0 }} pages
                                    </span>
                                </td>
                                <td>
                                    <small class="text-muted">
                                        {{ formatDate(section.created_at) }}
                                    </small>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button 
                                            class="btn btn-outline-info"
                                            @click="viewSectionPages(section)"
                                            title="View Pages"
                                        >
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <button 
                                            class="btn btn-outline-danger"
                                            @click="confirmDelete(section)"
                                            title="Delete"
                                        >
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Section Pages Modal -->
    <div v-if="showPagesModal" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-file-earmark-text me-2"></i>
                        Pages Using This Section
                    </h5>
                    <button type="button" class="btn-close" @click="showPagesModal = false"></button>
                </div>
                <div class="modal-body">
                    <h6 class="mb-3">{{ selectedSection?.title || 'Untitled Section' }}</h6>
                    <div v-if="selectedSection?.pages && selectedSection.pages.length > 0">
                        <ul class="list-group">
                            <li 
                                v-for="page in selectedSection.pages" 
                                :key="page.id"
                                class="list-group-item d-flex justify-content-between align-items-center"
                            >
                                <div>
                                    <strong>{{ page.title }}</strong>
                                    <br>
                                    <small class="text-muted">/{{ page.slug }}</small>
                                </div>
                                <button 
                                    class="btn btn-sm btn-outline-primary"
                                    @click="goToPage(page.id)"
                                >
                                    <i class="bi bi-arrow-right"></i>
                                </button>
                            </li>
                        </ul>
                    </div>
                    <div v-else class="text-center text-muted py-3">
                        <i class="bi bi-info-circle me-2"></i>
                        This section is not used in any pages yet
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" @click="showPagesModal = false">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { PageSection } from '@/core/types/data/masjid-related/PageSection';
import { usePagesStore } from '@/stores/masjid/pagesStore';
import { ref, onBeforeMount, computed } from 'vue';
import { useRouter } from 'vue-router';
import Swal from 'sweetalert2';

// Routing
const router = useRouter();

// Store
const pagesStore = usePagesStore();

// State
const loading = ref(false);
const sections = ref<PageSection[]>([]);
const searchQuery = ref('');
const showPagesModal = ref(false);
const selectedSection = ref<PageSection | null>(null);

// Computed
const filteredSections = computed(() => {
    if (!searchQuery.value) return sections.value;
    
    const query = searchQuery.value.toLowerCase();
    return sections.value.filter(section => 
        (section.title?.toLowerCase().includes(query)) ||
        (section.section_type?.toLowerCase().includes(query)) ||
        (section.section_type_label?.toLowerCase().includes(query))
    );
});

const activeSectionsCount = computed(() => 
    sections.value.filter(s => s.is_active).length
);

const uniqueTypesCount = computed(() => 
    new Set(sections.value.map(s => s.section_type)).size
);

// Lifecycle
onBeforeMount(async () => {
    await loadData();
});

// Methods
const loadData = async () => {
    loading.value = true;
    try {
        sections.value = await pagesStore.fetchSectionsLibrary();
    } catch (error) {
        console.error('Error loading sections library:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: 'Failed to load sections library.',
        });
    } finally {
        loading.value = false;
    }
};

const formatDate = (dateString: string | undefined) => {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { 
        year: 'numeric', 
        month: 'short', 
        day: 'numeric' 
    });
};

const viewSectionPages = (section: PageSection) => {
    selectedSection.value = section;
    showPagesModal.value = true;
};

const goToPage = (pageId: number) => {
    showPagesModal.value = false;
    router.push(`/masjid/pages/${pageId}/sections`);
};

const confirmDelete = async (section: PageSection) => {
    const pagesCount = section.pages?.length || 0;
    
    const result = await Swal.fire({
        title: 'Are you sure?',
        html: `Do you want to delete "${section.title || 'Untitled Section'}"?<br><br>
               ${pagesCount > 0 ? `<strong class="text-danger">This section is used in ${pagesCount} page(s) and will be removed from all of them.</strong>` : ''}`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
    });

    if (result.isConfirmed) {
        try {
            await pagesStore.deleteSectionFromLibrary(section.id);
            await loadData();
            
            Swal.fire({
                icon: 'success',
                title: 'Deleted!',
                text: 'Section has been deleted from library.',
                timer: 2000,
                showConfirmButton: false
            });
        } catch (error) {
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: 'Failed to delete section.',
            });
        }
    }
};
</script>

<style scoped>
.card {
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.card-header {
    border-bottom: 2px solid #f0f0f0;
}

.table {
    background: white;
}

.modal {
    display: block;
}
</style>

