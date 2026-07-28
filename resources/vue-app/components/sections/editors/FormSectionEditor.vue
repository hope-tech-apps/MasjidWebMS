<template>
    <div class="form-section-editor">
        <div class="row">
            <!-- Which form this section shows -->
            <div class="col-12 mb-3">
                <label class="form-label">Form <span class="text-danger">*</span></label>
                <div class="d-flex gap-2">
                    <select
                        class="form-select"
                        :value="localContent.form_id"
                        @change="onFormSelected(($event.target as HTMLSelectElement).value)"
                        :disabled="builderOpen"
                    >
                        <option :value="null">-- Select a form --</option>
                        <option v-for="option in formsStore.formOptions" :key="option.id" :value="option.id">
                            {{ option.name }} ({{ option.slug }})
                            · {{ option.response_count }} response{{ option.response_count === 1 ? '' : 's' }}
                            <template v-if="!option.is_active"> · switched off</template>
                        </option>
                    </select>

                    <button
                        type="button"
                        class="btn btn-outline-primary flex-shrink-0"
                        @click="startCreate"
                        :disabled="builderOpen"
                    >
                        <i class="bi bi-plus-circle me-1"></i> New Form
                    </button>

                    <button
                        type="button"
                        class="btn btn-outline-secondary flex-shrink-0"
                        @click="startEdit"
                        :disabled="builderOpen || !localContent.form_id"
                    >
                        <i class="bi bi-pencil me-1"></i> Edit
                    </button>
                </div>
                <small class="form-text text-muted">
                    This section stores only a reference. The questions and every response live with the
                    form itself, so removing this section from the page never deletes a registration list.
                </small>
            </div>

            <!-- What the reference currently points at -->
            <div class="col-12 mb-3" v-if="!builderOpen">
                <div v-if="loadingForm" class="text-muted small">
                    <span class="spinner-border spinner-border-sm me-2"></span>Loading form…
                </div>

                <div v-else-if="!localContent.form_id" class="alert alert-warning mb-0">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    No form selected — this section renders nothing on the site until one is chosen.
                </div>

                <div v-else-if="selectedForm" class="alert alert-info mb-0">
                    <h6 class="alert-heading">
                        <i class="bi bi-ui-checks me-2"></i>{{ selectedForm.name }}
                    </h6>
                    <p class="mb-1">
                        <strong>Status:</strong>
                        <span :class="selectedForm.accepting ? 'text-success' : 'text-danger'">
                            {{ selectedForm.accepting ? 'Accepting submissions' : (selectedForm.closed_reason || 'Closed') }}
                        </span>
                    </p>
                    <p class="mb-1">
                        <strong>Questions:</strong>
                        {{ questionCount }} across {{ sectionCount }} section{{ sectionCount === 1 ? '' : 's' }}
                        <template v-if="repeatableTitle"> · repeats "{{ repeatableTitle }}"</template>
                    </p>
                    <p class="mb-1"><strong>Responses:</strong> {{ selectedForm.response_count }}</p>
                    <p class="mb-0" v-if="feeSummary"><strong>Fee:</strong> {{ feeSummary }}</p>
                </div>

                <div v-else class="alert alert-danger mb-0">
                    <i class="bi bi-x-octagon me-2"></i>
                    This section points at a form that no longer exists. Pick another one — the site
                    renders nothing for a missing form.
                </div>
            </div>

            <!-- Section-level heading + intro (the form carries its own wording) -->
            <div class="col-12 mb-3">
                <label class="form-label">Section heading</label>
                <input
                    type="text"
                    class="form-control"
                    v-model="localContent.title"
                    @input="emitUpdate"
                    placeholder="e.g. Register for the retreat"
                />
            </div>

            <div class="col-12 mb-3">
                <label class="form-label">Section intro</label>
                <textarea
                    class="form-control"
                    v-model="localContent.intro"
                    @input="emitUpdate"
                    rows="3"
                    placeholder="Shown above the form on the page."
                ></textarea>
                <small class="form-text text-muted">
                    Page-level wording. The form's own introduction, submit button and thank-you
                    message are edited inside the form.
                </small>
            </div>

            <!-- The builder itself -->
            <div class="col-12" v-if="builderOpen">
                <div class="card border-primary">
                    <div class="card-header bg-primary-subtle d-flex justify-content-between align-items-center">
                        <strong>
                            <i class="bi bi-tools me-2"></i>
                            {{ editingFormId ? 'Editing form' : 'New form' }}
                        </strong>
                    </div>
                    <div class="card-body">
                        <FormBuilder
                            :form-id="editingFormId"
                            @saved="onFormSaved"
                            @cancel="builderOpen = false"
                        />
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { FormSectionContent } from '@/core/types/data/masjid-related/PageSection';
import { Form } from '@/core/types/data/masjid-related/Form';
import FormBuilder from '@/components/forms/FormBuilder.vue';
import { useFormsStore } from '@/stores/masjid/formsStore';
import { computed, onMounted, ref, watch } from 'vue';

/**
 * The `form` page section: it points at a sign-up form and adds page-level wording.
 *
 * An admin can attach a form that already exists, build a new one without leaving the
 * page, or edit the attached one in place. Only `form_id`, `title` and `intro` are ever
 * stored on the section — SectionContentBinder inlines the form's definition when the
 * site fetches the page.
 */
const props = defineProps<{
    modelValue: FormSectionContent;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: FormSectionContent];
}>();

const formsStore = useFormsStore();

const localContent = ref<FormSectionContent>({
    form_id: props.modelValue?.form_id ?? null,
    title: props.modelValue?.title || '',
    intro: props.modelValue?.intro || '',
});

const selectedForm = ref<Form | null>(null);
const loadingForm = ref(false);
const builderOpen = ref(false);
const editingFormId = ref<number | null>(null);

watch(() => props.modelValue, (newVal) => {
    if (newVal) {
        localContent.value = {
            form_id: newVal.form_id ?? null,
            title: newVal.title || '',
            intro: newVal.intro || '',
        };
    }
}, { deep: true });

const emitUpdate = () => {
    emit('update:modelValue', localContent.value);
};

const sectionCount = computed(() => selectedForm.value?.schema?.sections?.length ?? 0);

const questionCount = computed(() =>
    (selectedForm.value?.schema?.sections ?? []).reduce((total, section) => total + (section.fields?.length ?? 0), 0)
);

const repeatableTitle = computed(() => {
    const repeatable = (selectedForm.value?.schema?.sections ?? []).find(section => section.repeatable);
    return repeatable ? (repeatable.title || repeatable.id) : null;
});

const feeSummary = computed(() => {
    const fee = selectedForm.value?.settings?.fee;
    if (!fee) return null;

    const amount = `${fee.currency || 'USD'} ${fee.amount}`;

    return fee.perEntryOfSection ? `${amount} per entry of "${fee.perEntryOfSection}"` : `${amount} per submission`;
});

/** Load the full definition so the summary reflects the form, not just its name. */
const loadSelectedForm = async () => {
    if (!localContent.value.form_id) {
        selectedForm.value = null;
        return;
    }

    loadingForm.value = true;

    try {
        selectedForm.value = await formsStore.fetchForm(localContent.value.form_id);
    } catch (error) {
        console.error('Load section form error: ', error);
        selectedForm.value = null;
    } finally {
        loadingForm.value = false;
    }
};

const onFormSelected = async (value: string) => {
    localContent.value.form_id = value ? Number(value) : null;
    emitUpdate();
    await loadSelectedForm();
};

const startCreate = () => {
    editingFormId.value = null;
    builderOpen.value = true;
};

const startEdit = () => {
    if (!localContent.value.form_id) return;

    editingFormId.value = localContent.value.form_id;
    builderOpen.value = true;
};

/**
 * A form built from here is attached straight away — otherwise an admin would save the
 * section still pointing at nothing.
 */
const onFormSaved = async (form: Form) => {
    builderOpen.value = false;
    editingFormId.value = null;

    localContent.value.form_id = form.id;
    emitUpdate();

    selectedForm.value = form;
    await formsStore.fetchFormOptions();
};

onMounted(async () => {
    await formsStore.fetchFormOptions();
    await loadSelectedForm();
});
</script>

<style scoped>
.form-section-editor .alert-info {
    background-color: #e7f3ff;
    border-color: #b3d9ff;
    color: #004085;
}
</style>
