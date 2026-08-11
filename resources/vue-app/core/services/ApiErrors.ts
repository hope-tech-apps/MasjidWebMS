import { AxiosError } from "axios";

/**
 * Reading an error out of this API's two response envelopes.
 *
 * The backend answers failures in two shapes and both are load-bearing:
 *
 *   - `{ status: 'failed', data: ... }` — the legacy envelope. `data` is either a
 *     validation bag (`{field: [messages]}`) or a single public message string.
 *   - `{ status: 'error', message: '...' }` — the newer envelope the group
 *     surfaces use for a refusal that is not a validation failure ("that member
 *     is not in this group", "this conversation is closed").
 *
 * Abort responses (403/404 from `abort()`) carry only `message`, so that case is
 * the same branch as the second envelope.
 *
 * Written once here because the classroom screens consume six controllers, and a
 * per-view copy of this ladder is how one of them ends up silently swallowing a
 * message the user needed.
 */

type ApiErrorEnvelope = {
    status?: string;
    message?: string;
    data?: unknown;
};

/** Flatten a Laravel validation bag into one readable line. */
function flattenValidationBag(bag: Record<string, unknown>): string {
    return Object.values(bag)
        .flatMap((messages) => (Array.isArray(messages) ? messages : [messages]))
        .map((message) => String(message))
        .join(' ');
}

/**
 * The best message this API gave us for a failed request, or `fallback` when it
 * gave us nothing usable (a network drop, a 500 with no body).
 */
export function apiErrorText(error: unknown, fallback: string): string {
    const response = (error as AxiosError<ApiErrorEnvelope>)?.response;
    const body = response?.data;

    if (body && typeof body === 'object') {
        const data = body.data;

        // `{status:'failed', data:{field:[...]}}` — the validation bag.
        if (data && typeof data === 'object' && !Array.isArray(data)) {
            const flattened = flattenValidationBag(data as Record<string, unknown>);
            if (flattened) return flattened;
        }

        // `{status:'failed', data:'...'}` — a single public message.
        if (typeof data === 'string' && data) return data;

        // `{status:'error', message:'...'}` and every abort() refusal.
        if (typeof body.message === 'string' && body.message) return body.message;
    }

    return (error as Error)?.message || fallback;
}

/**
 * Whether the caller was refused this disclosure.
 *
 * A 403 on a group surface is ORDINARY, not a bug: reading a class story, a
 * child's points or a hifz record additionally requires standing IN the group,
 * so an admin who is not on the roster gets one. The UI hides the panel and says
 * why; it never treats this as an error to shout about. See
 * .claude/rules/groups.md ("Disclosure is not administration").
 */
export function isForbidden(error: unknown): boolean {
    return (error as AxiosError)?.response?.status === 403;
}
