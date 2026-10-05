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
 *
 * `handOver` is called once with a function that hands the editor OTHER content from outside, as a
 * parent does when it replaces what it holds. After its own first edit an editor is only ever handed
 * its own object back, so this is the one way a test reaches the editor's `watch` with content that
 * is still the caller's.
 */
import { provide, ref } from 'vue';

const props = defineProps<{
    editor: any;
    initial: any;
    onChange?: (value: any) => void;
    provided?: Record<string, any>;
    handOver?: (give: (value: any) => void) => void;
}>();

for (const [key, value] of Object.entries(props.provided ?? {})) {
    provide(key, value);
}

const model = ref(props.initial);

props.handOver?.((value: any) => { model.value = value; });

const onUpdate = (value: any) => {
    model.value = value;
    props.onChange?.(JSON.parse(JSON.stringify(value)));
};
</script>
