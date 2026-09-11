<template>
    <Teleport to="body">
        <div
            v-if="show"
            ref="root"
            class="modal d-block staff-codes-modal"
            role="dialog"
            aria-modal="true"
            :aria-labelledby="titleId"
            tabindex="-1"
            @click.self="close"
            @keydown="onKeydown"
        >
            <div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-md-down">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 :id="titleId" class="modal-title">
                            <i class="bi bi-key me-2" aria-hidden="true"></i>Staff codes<span
                                v-if="formName"
                                class="text-muted fw-normal"
                            >: {{ formName }}</span>
                        </h5>
                        <button ref="closeButton" type="button" class="btn-close" aria-label="Close staff codes" @click="close"></button>
                    </div>
                    <div class="modal-body">
                        <!-- Keyed on the form: another form's codes are never listed under this one. -->
                        <FormStaffCodesPanel
                            :key="formId"
                            :form-id="formId"
                            @changed="emit('changed')"
                            @revealing="locked = $event"
                        />
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" @click="close">Close</button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';
import FormStaffCodesPanel from '@/components/forms/FormStaffCodesPanel.vue';
import { trapTab } from '@/core/helpers/focusTrap';

/**
 * The staff codes panel in a dialog, for the screens that reach it from a form: the
 * form builder and Form Responses. Mounted only while open, so every opening reads the
 * codes afresh.
 *
 * Its z-index sits above the page-section editor's modal (1055) and below SweetAlert2
 * (1060), so a revoke confirmation still shows on top of it.
 */
const props = defineProps<{
    show: boolean;
    formId: number;
    formName?: string | null;
}>();

const emit = defineEmits<{
    close: [];
    changed: [];
}>();

const titleId = `staff-codes-title-${Math.random().toString(36).slice(2, 8)}`;
const root = ref<HTMLElement | null>(null);
const closeButton = ref<HTMLButtonElement | null>(null);

/**
 * Set while the panel shows a newly added code, which exists nowhere else. Closing then
 * would unmount the panel and lose the code before it was copied, so nothing closes.
 */
const locked = ref(false);

// Whatever had focus before the dialog opened gets it back when it closes.
let returnFocusTo: HTMLElement | null = null;

// A panel mounted afresh (reopened, or keyed onto another form) has no code on show.
watch(() => props.formId, () => {
    locked.value = false;
});

watch(() => props.show, async (open) => {
    locked.value = false;

    if (open) {
        returnFocusTo = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        await nextTick();
        closeButton.value?.focus();
    } else if (returnFocusTo) {
        returnFocusTo.focus();
        returnFocusTo = null;
    }
}, { immediate: true });

const close = () => {
    if (locked.value) return;
    emit('close');
};

/** Escape closes (unless locked); Tab and Shift+Tab stay inside the dialog. */
const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
        event.stopPropagation();
        close();
        return;
    }

    trapTab(event, root.value);
};
</script>

<style scoped>
/* Two classes, so a host view's own scoped `.modal { z-index: 1055 }` cannot win. */
.modal.staff-codes-modal {
    z-index: 1057;
    background: rgba(0, 0, 0, 0.5);
}
</style>
