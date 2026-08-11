<template>
    <div class="staff-directory-editor">
        <div class="row">
            <div class="col-12 mb-3">
                <label class="form-label">Heading</label>
                <input
                    type="text"
                    class="form-control"
                    v-model="localContent.heading"
                    @input="emitUpdate"
                    placeholder="e.g., Faculty &amp; Staff"
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

            <div class="col-md-4 mb-3">
                <label class="form-label">Layout</label>
                <select
                    class="form-select"
                    v-model="localContent.layout"
                    @change="emitUpdate"
                >
                    <option value="grid">Grid (photo cards)</option>
                    <option value="list">List (one per row)</option>
                </select>
            </div>

            <div class="col-md-4 mb-3">
                <label class="form-label">Columns</label>
                <select
                    class="form-select"
                    v-model.number="localContent.columns"
                    @change="emitUpdate"
                    :disabled="localContent.layout === 'list'"
                >
                    <option :value="2">2 per row</option>
                    <option :value="3">3 per row</option>
                    <option :value="4">4 per row</option>
                </select>
                <div class="form-text">Only applies to the grid layout.</div>
            </div>

            <div class="col-md-4 mb-3">
                <label class="form-label">Background Color</label>
                <input
                    type="color"
                    class="form-control form-control-color flex-shrink-0"
                    v-model="localContent.background_color"
                    @input="emitUpdate"
                />
            </div>

            <div class="col-12 mb-3">
                <div class="form-check form-switch">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        id="staffShowContactSwitch"
                        v-model="localContent.show_contact"
                        @change="emitUpdate"
                    />
                    <label class="form-check-label" for="staffShowContactSwitch">
                        Publish email and phone on the public page
                    </label>
                </div>
                <div class="form-text">
                    Off by default. When off, the email and phone you enter below are
                    kept for your own records and are not shown to visitors.
                </div>
            </div>

            <div class="col-12 mb-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0">People</h6>
                    <button
                        type="button"
                        class="btn btn-sm btn-primary"
                        @click="addMember"
                    >
                        <i class="bi bi-plus-circle"></i> Add Person
                    </button>
                </div>

                <div
                    v-for="(member, index) in localContent.members"
                    :key="index"
                    class="card mb-3"
                >
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0">{{ member.name || `Person ${index + 1}` }}</h6>
                            <div class="btn-group">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary"
                                    @click="moveMemberUp(index)"
                                    :disabled="index === 0"
                                    title="Move Up"
                                >
                                    <i class="bi bi-arrow-up"></i>
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary"
                                    @click="moveMemberDown(index)"
                                    :disabled="index === localContent.members.length - 1"
                                    title="Move Down"
                                >
                                    <i class="bi bi-arrow-down"></i>
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-danger"
                                    @click="removeMember(index)"
                                    title="Remove Person"
                                >
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-12 mb-3">
                                <ImageDraggableInput
                                    :name="`staff_photo_${index}`"
                                    type="photo"
                                    label="Photo"
                                    :current-image-src="member.photo_url || undefined"
                                    @image-change="(data) => onMemberPhotoChange(index, data)"
                                />
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Name <span class="text-danger">*</span></label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="member.name"
                                    @input="emitUpdate"
                                    placeholder="e.g., Sr. Aisha Rahman"
                                    required
                                />
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Role <span class="text-danger">*</span></label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="member.role"
                                    @input="emitUpdate"
                                    placeholder="e.g., Kindergarten Lead Teacher"
                                    required
                                />
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Department</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="member.department"
                                    @input="emitUpdate"
                                    list="staffDepartmentSuggestions"
                                    placeholder="e.g., Administration"
                                />
                                <div class="form-text">
                                    People sharing a department are shown together. Leave
                                    blank to list this person on their own.
                                </div>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Credentials</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="member.credentials"
                                    @input="emitUpdate"
                                    placeholder="e.g., M.Ed., NC B-K Licensed"
                                />
                            </div>

                            <div class="col-12 mb-3">
                                <label class="form-label">Bio</label>
                                <textarea
                                    class="form-control"
                                    v-model="member.bio"
                                    @input="emitUpdate"
                                    rows="3"
                                    placeholder="A short paragraph about this person"
                                ></textarea>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input
                                    type="email"
                                    class="form-control"
                                    v-model="member.email"
                                    @input="emitUpdate"
                                    placeholder="teacher@school.org"
                                />
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Phone</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="member.phone"
                                    @input="emitUpdate"
                                    placeholder="+1 336 555 0100"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <datalist id="staffDepartmentSuggestions">
                    <option
                        v-for="department in usedDepartments"
                        :key="department"
                        :value="department"
                    ></option>
                </datalist>

                <div v-if="localContent.members.length === 0" class="alert alert-info">
                    No one added yet. Click "Add Person" to build the directory.
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { StaffDirectorySectionContent, StaffMember } from '@/core/types/data/masjid-related/PageSection';
import { UploadedImageInfo } from '@/core/types/elements/ImageInput';
import ImageDraggableInput from '@/components/form/ImageDraggableInput.vue';
import { ref, watch, inject, computed } from 'vue';
import { useSectionImages } from '@/composables/useSectionImages';

const props = defineProps<{
    modelValue: StaffDirectorySectionContent;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: StaffDirectorySectionContent];
}>();

// Get the section images composable from parent (if provided)
const sectionImages = inject<ReturnType<typeof useSectionImages> | null>('sectionImages', null);

// Pending photo uploads are keyed by position (members.<i>.photo_url), so every
// reorder/remove has to re-key them or the photo lands on the wrong person —
// which on a staff page means publishing someone's face under another name.
const MEMBER_PHOTO_KEY = /^members\.(\d+)\.photo_url$/;

const newMember = (): StaffMember => ({
    name: '',
    role: '',
    department: '',
    credentials: '',
    bio: '',
    email: '',
    phone: '',
    photo_url: null,
});

const normalize = (value?: StaffDirectorySectionContent): StaffDirectorySectionContent => ({
    heading: value?.heading || '',
    description: value?.description || '',
    members: value?.members ? [...value.members] : [],
    layout: value?.layout || 'grid',
    columns: value?.columns || 3,
    show_contact: value?.show_contact ?? false,
    background_color: value?.background_color || '#ffffff',
});

const localContent = ref<StaffDirectorySectionContent>(normalize(props.modelValue));

watch(() => props.modelValue, (newVal) => {
    if (newVal) {
        localContent.value = normalize(newVal);
    }
}, { deep: true });

// Feeds the department datalist so the second teacher in a department is typed
// consistently with the first — a typo here silently splits a group in two.
const usedDepartments = computed<string[]>(() => {
    const seen = new Set<string>();
    localContent.value.members.forEach((member) => {
        const name = (member.department || '').trim();
        if (name) {
            seen.add(name);
        }
    });
    return Array.from(seen);
});

const emitUpdate = () => {
    emit('update:modelValue', localContent.value);
};

/**
 * Re-key the not-yet-uploaded photos after the list order changes.
 * `mapIndex` returns the person's new position, or null when they are removed.
 */
const remapMemberPhotoFiles = (mapIndex: (oldIndex: number) => number | null) => {
    if (!sectionImages) {
        return;
    }

    const pending = sectionImages.getImageFiles()
        .map((entry) => {
            const match = entry.fieldName.match(MEMBER_PHOTO_KEY);
            return match ? { oldIndex: Number(match[1]), file: entry.file } : null;
        })
        .filter((entry): entry is { oldIndex: number; file: File } => entry !== null);

    if (pending.length === 0) {
        return;
    }

    // Clear every member entry first so a swap cannot overwrite its own counterpart.
    pending.forEach(({ oldIndex }) => {
        sectionImages.addImageFile(`members.${oldIndex}.photo_url`, undefined);
    });

    pending.forEach(({ oldIndex, file }) => {
        const newIndex = mapIndex(oldIndex);
        if (newIndex !== null) {
            sectionImages.addImageFile(`members.${newIndex}.photo_url`, file);
        }
    });
};

const addMember = () => {
    localContent.value.members.push(newMember());
    emitUpdate();
};

const removeMember = (index: number) => {
    localContent.value.members.splice(index, 1);
    remapMemberPhotoFiles((oldIndex) => {
        if (oldIndex === index) return null;
        return oldIndex > index ? oldIndex - 1 : oldIndex;
    });
    emitUpdate();
};

const swapMembers = (a: number, b: number) => {
    const members = [...localContent.value.members];
    [members[a], members[b]] = [members[b], members[a]];
    localContent.value.members = members;
    remapMemberPhotoFiles((oldIndex) => {
        if (oldIndex === a) return b;
        if (oldIndex === b) return a;
        return oldIndex;
    });
    emitUpdate();
};

const moveMemberUp = (index: number) => {
    if (index > 0) {
        swapMembers(index - 1, index);
    }
};

const moveMemberDown = (index: number) => {
    if (index < localContent.value.members.length - 1) {
        swapMembers(index, index + 1);
    }
};

const onMemberPhotoChange = (index: number, data: UploadedImageInfo) => {
    localContent.value.members[index].photo_url = data.src || null;

    // Array notation the backend maps back onto content
    // (SectionType::STAFF_DIRECTORY => ['members.*.photo_url']). Called with an
    // undefined file when the admin clears the photo, which drops the pending
    // upload instead of leaving it queued against a person who no longer wants it.
    if (sectionImages) {
        sectionImages.addImageFile(`members.${index}.photo_url`, data.file);
    }

    emitUpdate();
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
