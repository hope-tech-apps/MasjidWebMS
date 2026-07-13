<?php

namespace App\Observers;

use App\Models\User;

/**
 * Keeps each user's bridged Spatie role in sync with the legacy `users.type`.
 *
 * This is the "keep the mapping in sync going forward" half of the bridge: the
 * seeder backfills existing rows once, and this observer maintains the mirror
 * on every create/update (e.g. when UsersController changes a user's `type`).
 *
 * It is purely ADDITIVE — `type` is untouched and remains the source of truth
 * for the `admin`/`super` middleware and all existing checks. syncRoleFromType()
 * is defensive and never throws, so this observer can never break a user write.
 */
class UserObserver
{
    public function saved(User $user): void
    {
        $user->syncRoleFromType();
    }
}
