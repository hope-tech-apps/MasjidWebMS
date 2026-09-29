<?php

namespace App\Enums;

/**
 * The moments a class generates a notification. One discriminator the
 * fan-out job branches on; it mirrors GroupMessage::authorIsParent() — who wrote
 * the thing decides who hears about it.
 */
enum GroupNotificationEvent: string
{
    /** A teacher posted to the class story -> the feed-consented guardians. */
    case CLASS_STORY = 'class_story';

    /** A STAFF message in a thread -> the guardian(s) it concerns. */
    case GUARDIAN_THREAD_MESSAGE = 'guardian_thread_message';

    /** A PARENT replied in a thread -> the class's teacher(s). */
    case TEACHER_THREAD_MESSAGE = 'teacher_thread_message';

    /**
     * A file was SHARED WITH FAMILIES -> the feed-consented guardians.
     *
     * Only on the transition to `families`. A staff-only upload notifies nobody,
     * and re-editing the title of an already-shared file does not re-announce it.
     */
    case RESOURCE_SHARED = 'resource_shared';

    /**
     * A child's mark was recorded or CHANGED -> that child's guardian(s) only.
     *
     * Deliberately per-child rather than per-save: one teacher pressing Save
     * writes the whole class, and a class-wide notification would tell every
     * family that somebody's mark exists. It is also fired only for marks that
     * actually MOVED, so correcting one child's typo does not re-mail the other
     * five families.
     */
    case GRADE_POSTED = 'grade_posted';

    /**
     * Somebody REACTED to a class story or a message -> its AUTHOR only
     * (owner, 2026-09-29; `groups:notify-reactions`, the hourly digest).
     *
     * Never fired by the tap itself — a push per 👍 would bury the replies. One
     * content-free email per author per class per run ("You have new
     * reactions"): no names, no emoji, no counts. It reaches the author alone, so
     * the job is told WHO (recipientUserId / recipientContactId) rather than
     * resolving an audience, and re-checks at send time that they may still read
     * what was reacted to.
     */
    case REACTION = 'reaction';
}
