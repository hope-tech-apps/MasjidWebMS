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
}
