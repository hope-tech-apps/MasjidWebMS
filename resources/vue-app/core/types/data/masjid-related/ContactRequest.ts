/**
 * One reply staff sent back on a contact-us thread.
 *
 * `sent_at` is the delivery fact and is NOT `created_at`: a reply whose
 * `sent_at` is null was written down but never left the building. The screen
 * has to be able to say that — a reply that silently looks sent is how a member
 * of the public gets ignored while the office believes they were answered.
 *
 * `sending_at` separates the two reasons `sent_at` can be null. Set with
 * `sent_at` still null, it means a request is at the relay with this reply
 * RIGHT NOW (the server claims the right to send before it sends, so that a
 * second tab or a retried click cannot send a second copy); null with `sent_at`
 * null means nobody is sending it and the last attempt was refused. Showing
 * both as "saved but not sent" would have staff retyping a reply that is on its
 * way.
 *
 * `actor_name` is a snapshot taken when the reply was written, not a join, so
 * it still reads correctly after that staff account is deleted.
 */
export type ContactRequestReply = {
    id: number;
    body: string;
    actor_name: string | null;
    sent_to: string;
    sending_at: string | null;
    sent_at: string | null;
    created_at: string;
};

export type ContactRequest = {
    id: number;
    contact_us_account_id: number;
    contact_us_reason_id: number | null;
    message: string;
    /**
     * When the message was answered, or null while it is still waiting. Set by
     * a delivered reply and by the manual toggle, and clearable again — triage
     * is a label, not a state machine.
     */
    answered_at: string | null;
    answered_by_name: string | null;
    created_at: string;
    updated_at: string;
    contacter: {
        id: number;
        mobile_app_user_id: number;
        email: string;
        name: string;
        phone: string | null;
    };
    reason: {
        id: number;
        text: string;
    } | null;
    replies?: ContactRequestReply[];
};

/**
 * What a write to a contact request hands back, so the screen can flip the
 * badge and redraw the history in place rather than re-fetching the page.
 */
export type ContactRequestState = {
    id: number;
    answered_at: string | null;
    answered_by_name: string | null;
    replies: ContactRequestReply[];
};
