/**
 * An organisation's own label on its contacts ("Volunteer", "MEC emaillist 1"),
 * as `GET /api/admin/masjids/{id}/contact-tags` returns it.
 *
 * A LABEL, not consent: a broadcast addressed to a tag still goes through the
 * email opt-out list and the SMS consent record on the server, exactly as
 * "everyone" does.
 */
export type ContactTag = {
    id: number;
    masjid_id: number;
    name: string;
    /** Contacts carrying the tag, deleted members not counted. */
    contacts_count?: number;
    created_at?: string;
    updated_at?: string;
};

/** The narrow projection a contact row carries (`contact.tags`). */
export type ContactTagRef = {
    id: number;
    name: string;
};
