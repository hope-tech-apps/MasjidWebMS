<template>
    <component :is="editor" :model-value="model" @update:model-value="onUpdate" />
</template>

<script setup lang="ts">
/**
 * What SectionFormModal is to a section editor, for a mounted test: it holds the content, hands it
 * down as `modelValue`, and takes back every `update:modelValue` (the modal's `v-model`). An editor
 * mounted bare never has its own emit fed back to it, so its `watch` on `modelValue` never runs; here
 * it does, on every edit, as in the page tool. `onChange` is given a plain copy of each value sent up.
 *
 * `provided` is whatever the modal provides to its editors that the test wants present, by the
 * modal's own keys (`sectionDocumentUploads`, `sectionSavedDocuments`). Left out, an editor's
 * subtree finds none of it, as anywhere outside the modal.
 */
import { provide, ref } from 'vue';

const props = defineProps<{
    editor: any;
    initial: any;
    onChange?: (value: any) => void;
    provided?: Record<string, any>;
}>();

for (const [key, value] of Object.entries(props.provided ?? {})) {
    provide(key, value);
}

const model = ref(props.initial);

const onUpdate = (value: any) => {
    model.value = value;
    props.onChange?.(JSON.parse(JSON.stringify(value)));
};
</script>
