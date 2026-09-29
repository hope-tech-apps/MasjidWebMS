import { computed, ref, watch, type Ref } from 'vue';
import { schoolNow, sendAtError, sendLaterFields, type SchoolLocal } from '@/core/helpers/scheduledSend';

/**
 * One compose box's "Send later" state (T-002.4): the switch, the school-clock time, why
 * that time is not acceptable, and the request fields.
 *
 * The zone and the horizon come from the SERVER's `meta.scheduling` and are passed in as
 * refs, so the field is labelled with the school's own zone and never the browser's. The
 * rules themselves live in core/helpers/scheduledSend.ts, where they are tested.
 */
export function useSendLater(
    timezone: Ref<string | null | undefined>,
    maxDays: Ref<number | null | undefined>,
) {
    const enabled = ref(false);
    const value = ref<SchoolLocal>('');

    const horizon = () => maxDays.value ?? 30;

    /** Why the chosen time will not do; null while the switch is off or the time is fine. */
    const error = computed<string | null>(() =>
        enabled.value ? sendAtError(value.value, timezone.value, horizon()) : null);

    /** True when the compose box may be submitted as far as scheduling is concerned. */
    const ready = computed(() => !enabled.value || error.value === null);

    // Turning it on offers tomorrow morning at the school, so the field is never blank
    // and never in the past.
    watch(enabled, (on) => {
        if (on && !value.value) {
            value.value = `${schoolNow(timezone.value, new Date(), 24 * 60).slice(0, 10)}T08:00`;
        }
    });

    /** Fields to merge into the request: `{ send_at }` when on, `{}` when off. */
    const fields = () => sendLaterFields(enabled.value, value.value);

    const reset = () => {
        enabled.value = false;
        value.value = '';
    };

    return { enabled, value, error, ready, fields, reset };
}
