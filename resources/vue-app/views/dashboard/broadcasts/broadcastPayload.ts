/**
 * What the broadcast composer sends, and the one combination it refuses, as
 * plain functions so they can be pinned without a browser
 * (resources/vue-app/tests/broadcast-payload.test.ts). The server validates
 * again (StoreBroadcastRequest); these decide what the page asks for.
 */

/** The composer's form fields these helpers read. */
export type BroadcastForm = {
    title: string;
    body: string;
    link: string;
    channels: string[];
    audience: string;
    service_id: string | number;
    tag_id: string | number;
    starts_on: string;
    ends_on: string;
    scheduled_at: string;
};

/**
 * The text fields of the multipart POST, in order, as [name, value] pairs. The
 * image file is appended by the page. The audience's id travels only with its
 * own audience: a `tag_id` beside `audience=everyone` would be ignored by the
 * server, and a tag audience WITHOUT one addresses nobody.
 */
export function broadcastFields(form: BroadcastForm, hasAnnouncement: boolean): [string, string][] {
    const fields: [string, string][] = [
        ['title', form.title],
        ['body', form.body],
    ];
    if (form.link) fields.push(['link', form.link]);
    form.channels.forEach(c => fields.push(['channels[]', c]));
    fields.push(['audience', form.audience]);
    if (form.audience === 'service') fields.push(['service_id', String(form.service_id)]);
    if (form.audience === 'tag') fields.push(['tag_id', String(form.tag_id)]);
    if (hasAnnouncement) {
        fields.push(['starts_on', form.starts_on]);
        fields.push(['ends_on', form.ends_on]);
    }
    if (form.scheduled_at) fields.push(['scheduled_at', new Date(form.scheduled_at).toISOString()]);
    return fields;
}

/**
 * The server refuses push to a hand-picked list of contacts or to a tag,
 * because most devices are not signed in and the send would quietly reach a
 * fraction of the people chosen. The sentence is the one the admin needs; an
 * empty string means the combination is allowed.
 */
export function pushAudienceWarning(audience: string, channels: string[]): string {
    if (!channels.includes('push')) return '';
    if (audience === 'contacts') {
        return 'Push cannot be narrowed to chosen contacts: most devices are not signed in, so it would reach only a few of them. Send push to everyone, address a service instead, or drop push.';
    }
    if (audience === 'tag') {
        return 'Push cannot be sent to a tag: most devices are not signed in, so it would reach only a few of the people tagged. Send push to everyone, or drop push.';
    }
    return '';
}
