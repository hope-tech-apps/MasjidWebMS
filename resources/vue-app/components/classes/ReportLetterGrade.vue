<template>
    <section class="card border-0 shadow-sm mb-2" :aria-label="`Term grade for ${grade.subject}`">
        <div class="card-header bg-white fw-semibold small">{{ grade.subject }} · Term grade</div>
        <div class="card-body small">
            <p v-if="grade.can_fill === false">Filled in by this subject's teacher.</p>
            <template v-else>
                <p>Worked out {{ grade.calculated_letter ?? 'No letter' }}<span v-if="grade.calculated_percentage !== null"> ({{ grade.calculated_percentage }}%)</span></p>
                <p v-if="grade.override_letter">Set by the teacher: {{ grade.override_letter }} ({{ grade.override_reason }})<span v-if="grade.override_by_name"> · {{ grade.override_by_name }}</span><span v-if="grade.override_at"> · {{ grade.override_at }}</span></p>
                <div v-if="!readonly" class="d-flex flex-column gap-2">
                    <label>Set a different letter
                        <select :value="(draft ? draft.override_letter : grade.override_letter) ?? ''" :aria-label="`Set letter for ${grade.subject}`" class="form-select form-select-sm" :disabled="published" @change="edit('override_letter', ($event.target as HTMLSelectElement).value || null)">
                            <option value="">Use the worked-out letter</option>
                            <option v-for="row in scale" :key="row.letter" :value="row.letter">{{ row.letter }}</option>
                        </select>
                    </label>
                    <label>Reason for setting a letter
                        <input :value="draft?.override_reason ?? grade.override_reason ?? ''" :aria-label="`Reason for ${grade.subject}`" class="form-control form-control-sm" maxlength="1000" :required="!!(draft ? draft.override_letter : grade.override_letter)" :disabled="published" @input="edit('override_reason', ($event.target as HTMLInputElement).value)">
                    </label>
                    <label>Comment for the family
                        <textarea :value="draft?.comment ?? grade.comment ?? ''" :aria-label="`Family comment for ${grade.subject}`" class="form-control form-control-sm" maxlength="1000" :disabled="published" @input="edit('comment', ($event.target as HTMLTextAreaElement).value)"></textarea>
                    </label>
                </div>
                <p v-else-if="grade.comment">{{ grade.comment }}</p>
                <ul v-if="grade.excluded_work?.length" class="mt-2 mb-0">
                    <li v-for="piece in grade.excluded_work" :key="`${piece.source}-${piece.id}`">{{ piece.title }}: {{ piece.reason }}</li>
                </ul>
            </template>
        </div>
    </section>
</template>
<script setup lang="ts">
const props = defineProps<{ grade: any; draft?: any; scale?: any[]; published?: boolean; readonly?: boolean }>();
const emit = defineEmits<{ edit: [id: number, field: string, value: string | null] }>();
const edit = (field: string, value: string | null) => emit('edit', props.grade.id, field, value);
</script>
