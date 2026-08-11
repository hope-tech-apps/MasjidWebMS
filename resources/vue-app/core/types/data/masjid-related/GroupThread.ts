/**
 * Group messaging threads — the teacher <-> guardian channel.
 *
 * The thread's `scope` IS its disclosure shape, decided server-side and never
 * re-derived here:
 *   - `group`      — the feed audience (leaders, members, consented guardians);
 *   - `participant`— the group's leaders plus the ONE member/guardian it
 *                    concerns, named by `about`.
 *
 * Text only. Attachments are deliberately deferred; the feed owns media.
 */

import { GroupContact } from "./Group";

/** Mirrors `GroupThread::SCOPES`. An unrecognized stored scope fails closed to participant. */
export type ThreadScope = 'group' | 'participant';

/** Whom a participant-scoped thread concerns. Null on a group-wide thread. */
export type ThreadSubject = {
    membership_id: number;
    /** Null when the target has since left the roster — the record stays honest about having a subject. */
    contact: GroupContact | null;
};

export type GroupThread = {
    id: number;
    group_id: number;
    subject: string;
    scope: ThreadScope;
    about: ThreadSubject | null;
    created_by: { id: number; name: string } | null;
    is_closed: boolean;
    closed_at: string | null;
    retained_until: string | null;
    message_count: number;
    latest_message_at: string | null;
    last_read_at: string | null;
    /** A bookmark comparison, never an authorization record. */
    unread: boolean;
    created_at: string | null;
    updated_at: string | null;
};

export type GroupMessage = {
    id: number;
    thread_id: number;
    body: string;
    author: { id: number; name: string } | null;
    created_at: string | null;
};

/** Shape submitted when opening a thread, optionally with its first message. */
export type GroupThreadPayload = {
    subject: string;
    scope: ThreadScope;
    /** Required for a participant thread, prohibited on a group-wide one. */
    about_membership_id: number | null;
    body: string;
};

/** `meta` on the thread endpoints. */
export type GroupThreadsMeta = {
    group_label: string;
    thread_scopes: ThreadScope[];
    max_message_length: number;
};
