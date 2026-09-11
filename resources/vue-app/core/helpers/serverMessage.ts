/**
 * The server's own words for a failed admin request, never axios's
 * "Request failed with status code 422".
 *
 * The API answers a refusal in one of two envelopes, and this reads them in the
 * order the house rule gives (data.data, then data.message):
 *
 *   { status: 'failed', data: { field: ['message', …] } }  a FormRequest's 422
 *   { status: 'failed', data: 'message' }                   a controller refusal
 *   { status: 'failed'|'error', message: 'message' }        a door refusal, a 503, the JSON renderer
 *
 * Falls back to the Error's own message, then to `fallback` — never to a blank.
 */
export function serverMessage(error: any, fallback: string): string {
    const body = error?.response?.data;

    if (body && typeof body === 'object') {
        const data = body.data;

        if (typeof data === 'string' && data.trim()) return data;

        if (data && typeof data === 'object' && !Array.isArray(data)) {
            const messages = Object.values(data)
                .flat()
                .filter((message) => typeof message === 'string' && message.trim() !== '');
            if (messages.length) return messages.join(' ');
        }

        if (typeof body.message === 'string' && body.message.trim()) return body.message;
    }

    // An axios error with no body is a network failure: its own text is no better than ours.
    if (error && !error.response && typeof error.message === 'string' && error.message && !error.isAxiosError) {
        return error.message;
    }

    return fallback;
}

/**
 * A 422's field errors, keyed as the server named them ("settings.fee.tiers.1.amount"),
 * or {} when the answer carried none.
 */
export function serverFieldErrors(error: any): Record<string, string[]> {
    const data = error?.response?.status === 422 ? error?.response?.data?.data : null;

    if (!data || typeof data !== 'object' || Array.isArray(data)) return {};

    const fields: Record<string, string[]> = {};

    Object.entries(data).forEach(([key, value]) => {
        const messages = (Array.isArray(value) ? value : [value])
            .filter((message) => typeof message === 'string' && message.trim() !== '')
            .map((message) => String(message));
        if (messages.length) fields[key] = messages;
    });

    return fields;
}
