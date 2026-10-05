import { defineStore } from "pinia";
import { ref } from "vue";
import { Page } from "@/core/types/data/masjid-related/Page";
import { PageSection, SectionTypeInfo } from "@/core/types/data/masjid-related/PageSection";
import { useMasjidStore } from "../masjidStore";
import ApiService from "@/core/services/ApiService";
import { AxiosResponse } from "axios";
import { PaginatedData } from "@/core/types/data/interfaces/PaginatedData";
import { SECTION_DOCUMENT_UPLOAD_FAILED, sectionDocumentUploadProblem } from "@/core/helpers/sectionDocumentFile";

export const usePagesStore = defineStore('pagesStore', () => {

    // State
    const pagesPaginated = ref<PaginatedData<Page>>();
    const currentPage = ref<Page>();
    const sectionTypes = ref<SectionTypeInfo[]>([]);
    const sectionsLibrary = ref<PageSection[]>([]);

    // Stores
    const masjidStore = useMasjidStore();

    /**
     * Fetch paginated pages for the current masjid
     */
    async function fetchMasjidPagesPaginated(page: number = 1) {
        if (masjidStore.masjid?.id) {
            if (pagesPaginated.value) {
                pagesPaginated.value.data = [];
            }
            await ApiService.get(`/api/admin/masjids/${masjidStore.masjid.id}/pages?page=${page}`)
                .then((res: AxiosResponse) => {
                    if (res.data?.status === 'success' && res.data?.data) {
                        pagesPaginated.value = res.data.data;
                    }
                })
                .catch((e: Error) => {
                    console.error('Fetch masjid pages error: ', e);
                });
        }
    }

    /**
     * Fetch a single page by ID
     */
    async function fetchPage(pageId: number | string): Promise<Page | null> {
        if (masjidStore.masjid?.id) {
            try {
                const res: AxiosResponse = await ApiService.get(
                    `/api/admin/masjids/${masjidStore.masjid.id}/pages/${pageId}`
                );
                if (res.data?.status === 'success' && res.data?.data) {
                    currentPage.value = res.data.data;
                    return res.data.data;
                }
            } catch (e) {
                console.error('Fetch page error: ', e);
            }
        }
        return null;
    }

    /**
     * Create a new page
     */
    async function createPage(pageData: Partial<Page>): Promise<Page | null> {
        if (masjidStore.masjid?.id) {
            try {
                const res: AxiosResponse = await ApiService.post(
                    `/api/admin/masjids/${masjidStore.masjid.id}/pages`,
                    pageData
                );
                if (res.data?.status === 'success' && res.data?.data) {
                    return res.data.data;
                }
            } catch (e) {
                console.error('Create page error: ', e);
                throw e;
            }
        }
        return null;
    }

    /**
     * Update an existing page
     */
    async function updatePage(pageId: number, pageData: Partial<Page>): Promise<Page | null> {
        if (masjidStore.masjid?.id) {
            try {
                const res: AxiosResponse = await ApiService.put(
                    `/api/admin/masjids/${masjidStore.masjid.id}/pages/${pageId}`,
                    pageData
                );
                if (res.data?.status === 'success' && res.data?.data) {
                    return res.data.data;
                }
            } catch (e) {
                console.error('Update page error: ', e);
                throw e;
            }
        }
        return null;
    }

    /**
     * Delete a page
     */
    async function deletePage(pageId: number): Promise<boolean> {
        if (masjidStore.masjid?.id) {
            try {
                const res: AxiosResponse = await ApiService.delete(
                    `/api/admin/masjids/${masjidStore.masjid.id}/pages/${pageId}`
                );
                return res.data?.status === 'success';
            } catch (e) {
                console.error('Delete page error: ', e);
                throw e;
            }
        }
        return false;
    }

    /**
     * Fetch sections for a specific page
     */
    async function fetchPageSections(pageId: number): Promise<PageSection[]> {
        if (masjidStore.masjid?.id) {
            try {
                const res: AxiosResponse = await ApiService.get(
                    `/api/admin/masjids/${masjidStore.masjid.id}/pages/${pageId}/sections`
                );
                if (res.data?.status === 'success' && res.data?.data) {
                    return res.data.data;
                }
            } catch (e) {
                console.error('Fetch page sections error: ', e);
            }
        }
        return [];
    }

    /**
     * Create a new section
     */
    async function createSection(pageId: number, sectionData: Partial<PageSection>): Promise<PageSection | null> {
        if (masjidStore.masjid?.id) {
            try {
                const res: AxiosResponse = await ApiService.post(
                    `/api/admin/masjids/${masjidStore.masjid.id}/pages/${pageId}/sections`,
                    sectionData
                );
                if (res.data?.status === 'success' && res.data?.data) {
                    return res.data.data;
                }
            } catch (e) {
                console.error('Create section error: ', e);
                throw e;
            }
        }
        return null;
    }

    /**
     * Update an existing section
     */
    async function updateSection(pageId: number, sectionId: number, sectionData: Partial<PageSection>): Promise<PageSection | null> {
        if (masjidStore.masjid?.id) {
            try {
                const res: AxiosResponse = await ApiService.put(
                    `/api/admin/masjids/${masjidStore.masjid.id}/pages/${pageId}/sections/${sectionId}`,
                    sectionData
                );
                if (res.data?.status === 'success' && res.data?.data) {
                    return res.data.data;
                }
            } catch (e) {
                console.error('Update section error: ', e);
                throw e;
            }
        }
        return null;
    }

    /**
     * Create a new section with images (using FormData)
     */
    async function createSectionWithImages(pageId: number, formData: FormData): Promise<PageSection | null> {
        if (masjidStore.masjid?.id) {
            try {
                const res: AxiosResponse = await ApiService.post(
                    `/api/admin/masjids/${masjidStore.masjid.id}/pages/${pageId}/sections`,
                    formData,
                    {
                        headers: {
                            'Content-Type': 'multipart/form-data',
                        },
                    }
                );
                if (res.data?.status === 'success' && res.data?.data) {
                    return res.data.data;
                }
            } catch (e) {
                console.error('Create section with images error: ', e);
                throw e;
            }
        }
        return null;
    }

    /**
     * Update an existing section with images (using FormData)
     */
    async function updateSectionWithImages(pageId: number, sectionId: number, formData: FormData): Promise<PageSection | null> {
        if (masjidStore.masjid?.id) {
            try {
                const res: AxiosResponse = await ApiService.post(
                    `/api/admin/masjids/${masjidStore.masjid.id}/pages/${pageId}/sections/${sectionId}`,
                    formData,
                    {
                        headers: {
                            'Content-Type': 'multipart/form-data',
                        },
                    }
                );
                if (res.data?.status === 'success' && res.data?.data) {
                    return res.data.data;
                }
            } catch (e) {
                console.error('Update section with images error: ', e);
                throw e;
            }
        }
        return null;
    }

    /**
     * Delete a section
     */
    async function deleteSection(pageId: number, sectionId: number): Promise<boolean> {
        if (masjidStore.masjid?.id) {
            try {
                const res: AxiosResponse = await ApiService.delete(
                    `/api/admin/masjids/${masjidStore.masjid.id}/pages/${pageId}/sections/${sectionId}`
                );
                return res.data?.status === 'success';
            } catch (e) {
                console.error('Delete section error: ', e);
                throw e;
            }
        }
        return false;
    }

    /**
     * Upload one PDF for a web page and return its public address (PageDocumentsController).
     *
     * The file is stored, and PUBLIC, the moment this resolves: it is not part of a section's save.
     * The caller puts `url` into a link field; nothing else keeps the document. Throws an Error whose
     * message is a sentence for the office (the server's own, for a refused file).
     *
     * `url` must be absolute. The public website is another host, so a root-relative address would
     * be looked up there and open nothing; one that came back is treated as a failed upload rather
     * than written into a page.
     */
    async function uploadPageDocument(file: File): Promise<{ url: string; name: string; size: number }> {
        if (!masjidStore.masjid?.id) {
            throw new Error(SECTION_DOCUMENT_UPLOAD_FAILED);
        }

        const body = new FormData();
        body.append('document', file);

        let res: AxiosResponse;
        try {
            res = await ApiService.post(`/api/admin/masjids/${masjidStore.masjid.id}/pages/documents`, body);
        } catch (e) {
            throw new Error(sectionDocumentUploadProblem(e));
        }

        const data = res.data?.data;
        if (res.data?.status === 'success' && typeof data?.url === 'string' && /^https?:\/\//i.test(data.url)) {
            return { url: data.url, name: typeof data.name === 'string' ? data.name : '', size: Number(data.size) || 0 };
        }

        throw new Error(SECTION_DOCUMENT_UPLOAD_FAILED);
    }

    /**
     * Fetch available section types
     */
    async function fetchSectionTypes(): Promise<void> {
        if (masjidStore.masjid?.id) {
            try {
                const res: AxiosResponse = await ApiService.get(
                    `/api/admin/masjids/${masjidStore.masjid.id}/section-types`
                );
                if (res.data?.status === 'success' && res.data?.data) {
                    sectionTypes.value = res.data.data;
                }
            } catch (e) {
                console.error('Fetch section types error: ', e);
            }
        }
    }

    /**
     * Fetch all sections in the library
     */
    async function fetchSectionsLibrary(): Promise<PageSection[]> {
        if (masjidStore.masjid?.id) {
            try {
                const res: AxiosResponse = await ApiService.get(
                    `/api/admin/masjids/${masjidStore.masjid.id}/sections`
                );
                if (res.data?.status === 'success' && res.data?.data) {
                    sectionsLibrary.value = res.data.data;
                    return res.data.data;
                }
            } catch (e) {
                console.error('Fetch sections library error: ', e);
            }
        }
        return [];
    }

    /**
     * Create a section in the library (not attached to any page)
     */
    async function createSectionInLibrary(sectionData: Partial<PageSection>): Promise<PageSection | null> {
        if (masjidStore.masjid?.id) {
            try {
                const res: AxiosResponse = await ApiService.post(
                    `/api/admin/masjids/${masjidStore.masjid.id}/sections`,
                    sectionData
                );
                if (res.data?.status === 'success' && res.data?.data) {
                    return res.data.data;
                }
            } catch (e) {
                console.error('Create section in library error: ', e);
                throw e;
            }
        }
        return null;
    }

    /**
     * Update a section in the library (affects all pages using it)
     */
    async function updateSectionInLibrary(sectionId: number, sectionData: Partial<PageSection>): Promise<PageSection | null> {
        if (masjidStore.masjid?.id) {
            try {
                const res: AxiosResponse = await ApiService.put(
                    `/api/admin/masjids/${masjidStore.masjid.id}/sections/${sectionId}`,
                    sectionData
                );
                if (res.data?.status === 'success' && res.data?.data) {
                    return res.data.data;
                }
            } catch (e) {
                console.error('Update section in library error: ', e);
                throw e;
            }
        }
        return null;
    }

    /**
     * Delete a section from the library (removes from all pages)
     */
    async function deleteSectionFromLibrary(sectionId: number): Promise<boolean> {
        if (masjidStore.masjid?.id) {
            try {
                const res: AxiosResponse = await ApiService.delete(
                    `/api/admin/masjids/${masjidStore.masjid.id}/sections/${sectionId}`
                );
                return res.data?.status === 'success';
            } catch (e) {
                console.error('Delete section from library error: ', e);
                throw e;
            }
        }
        return false;
    }

    /**
     * Attach an existing section to a page
     */
    async function attachSectionToPage(pageId: number, sectionId: number, order?: number, platforms?: string[]): Promise<PageSection | null> {
        if (masjidStore.masjid?.id) {
            try {
                const res: AxiosResponse = await ApiService.post(
                    `/api/admin/masjids/${masjidStore.masjid.id}/pages/${pageId}/sections/attach`,
                    { section_id: sectionId, order, platforms }
                );
                if (res.data?.status === 'success' && res.data?.data) {
                    return res.data.data;
                }
            } catch (e) {
                console.error('Attach section to page error: ', e);
                throw e;
            }
        }
        return null;
    }

    /**
     * Reorder pages
     */
    async function reorderPages(pageOrders: { id: number; order: number }[]): Promise<boolean> {
        if (masjidStore.masjid?.id) {
            try {
                const res: AxiosResponse = await ApiService.post(
                    `/api/admin/masjids/${masjidStore.masjid.id}/pages/reorder`,
                    { pages: pageOrders }
                );
                return res.data?.status === 'success';
            } catch (e) {
                console.error('Reorder pages error: ', e);
                throw e;
            }
        }
        return false;
    }

    return {
        // State
        pagesPaginated,
        currentPage,
        sectionTypes,
        sectionsLibrary,

        // Pages methods
        fetchMasjidPagesPaginated,
        fetchPage,
        createPage,
        updatePage,
        deletePage,
        reorderPages,

        // Sections methods
        fetchPageSections,
        createSection,
        updateSection,
        createSectionWithImages,
        updateSectionWithImages,
        deleteSection,
        fetchSectionTypes,
        uploadPageDocument,

        // Sections Library methods
        fetchSectionsLibrary,
        createSectionInLibrary,
        updateSectionInLibrary,
        deleteSectionFromLibrary,
        attachSectionToPage,
    };
});

