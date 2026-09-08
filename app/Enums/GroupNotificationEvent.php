<?php

namespace App\Enums;

/**
 * The three moments a class generates a notification. One discriminator the
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
}
