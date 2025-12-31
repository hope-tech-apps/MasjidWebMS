import { ref, Ref } from 'vue';

export interface SectionImageFile {
    fieldName: string;
    file: File;
}

export function useSectionImages() {
    const imageFiles = ref<SectionImageFile[]>([]);

    const addImageFile = (fieldName: string, file: File | undefined) => {
        if (!file) {
            // Remove the image file if it exists
            imageFiles.value = imageFiles.value.filter(img => img.fieldName !== fieldName);
            return;
        }

        // Check if image already exists for this field
        const existingIndex = imageFiles.value.findIndex(img => img.fieldName === fieldName);
        
        if (existingIndex >= 0) {
            // Update existing
            imageFiles.value[existingIndex].file = file;
        } else {
            // Add new
            imageFiles.value.push({ fieldName, file });
        }
    };

    const clearImageFiles = () => {
        imageFiles.value = [];
    };

    const getImageFiles = () => {
        return imageFiles.value;
    };

    return {
        imageFiles,
        addImageFile,
        clearImageFiles,
        getImageFiles,
    };
}

