<template>
    <!--
        "Send later" for a class story or a new conversation (T-002.4).

        The time is the SCHOOL's wall clock, and the label says which zone that is: the
        one the server sent (`meta.scheduling.timezone`), never the browser's. A teacher
        in another city, or an administrator on a holiday, means the school's ten o'clock.
        The `min` and `max` are a convenience; the server refuses a time in the past or
        more than `max_days_ahead` days out and is the authority.
    -->
    <div class="send-later">
        <div v-if="!alwaysOn" class="form-check form-switch mb-0">
            <input :id="uid" class="form-check-input" type="checkbox" role="switch"
                   :checked="enabled" :disabled="disabled"
                   @change="$emit('update:enabled', ($event.target as HTMLInputElement).checked)">
            <label class="form-check-label small" :for="uid">Send later</label>
        </div>

        <div v-if="enabled || alwaysOn" :class="alwaysOn ? '' : 'mt-2'">
            <label :for="`${uid}-at`" class="form-label small text-muted mb-1">
                Send at <span data-test="send-later-zone">({{ timezone || 'school time' }})</span>
            </label>
            <input :id="`${uid}-at`" type="datetime-local" class="form-control form-control-sm"
                   style="max-width: 15rem" :class="{ 'is-invalid': !!error }"
                   :min="min" :max="max" :value="modelValue" :disabled="disabled"
                   @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)">
            <div v-if="error" class="invalid-feedback d-block">{{ error }}</div>
            <div v-else-if="modelValue" class="small text-muted mt-1" data-test="send-later-summary">
                Goes out {{ describeSchoolTime(modelValue, timezone) }}.
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed, useId } from 'vue';
import { describeSchoolTime, schoolMax, schoolNow } from '@/core/helpers/scheduledSend';

const props = defineProps<{
    enabled: boolean;
    modelValue: string;
    /** `meta.scheduling.timezone`. */
    timezone?: string | null;
    /** `meta.scheduling.max_days_ahead`. */
    maxDays?: number | null;
    /** Why the time is not acceptable, from useSendLater. */
    error?: string | null;
    disabled?: boolean;
    /** Editing a scheduled item: the time is the whole point, so there is no switch. */
    alwaysOn?: boolean;
}>();

defineEmits<{
    (e: 'update:enabled', value: boolean): void;
    (e: 'update:modelValue', value: string): void;
}>();

const uid = `sendlater-${useId()}`;

const min = computed(() => schoolNow(props.timezone));
const max = computed(() => schoolMax(props.timezone, props.maxDays ?? 30));
</script>
