<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Users\TeacherInviteRequest;
use App\Http\Requests\Admin\Users\TeacherUpdateRequest;
use App\Models\Group;
use App\Models\GroupStaff;
use App\Models\MasjidUser;
use App\Models\User;
use App\Services\Auth\AccountAccessService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The school office onboarding a teacher: create their login, assign the classes
 * they lead, and email them an invite to set their own password.
 *
 * Mounted under the masjid-scoped admin group (`admin` + `tenant`), so BOTH a
 * SuperAdmin (via the route masjid) and a school's own MasjidAdmin (bound to
 * their masjid) can run it — "mine now, school staff later". It never accepts a
 * `type`: the login is ALWAYS created as 'Teacher', and its whole reach is the
 * masjid_user membership + group_staff rows written here, both bound to this
 * school. A MasjidAdmin therefore cannot mint anything but a teacher of their own
 * classes.
 *
 * $masjid_id is route-shape; the authoritative masjid is the BOUND tenant.
 */
class TeachersController extends Controller
{
    public function __construct(private TenantContext $tenant)
    {
    }

    /** The school's teachers, each with the classes they lead. */
    public function index($masjid_id)
    {
        // group_staff is tenant-scoped, so this is only THIS school's staff rows.
        $rows = GroupStaff::query()->where('role', GroupStaff::ROLE_TEACHER)->get();

        $users = User::whereIn('id', $rows->pluck('user_id')->unique())->get()->keyBy('id');
        $groups = Group::whereIn('id', $rows->pluck('group_id')->unique())->get()->keyBy('id');

        $teachers = $rows->pluck('user_id')->unique()->values()->map(function ($userId) use ($rows, $users, $groups) {
            $user = $users->get($userId);
            if ($user === null) {
                return null;
            }

            $classes = $rows->where('user_id', $userId)
                ->map(fn (GroupStaff $r) => $groups->get($r->group_id))
                ->filter()
                ->map(fn (Group $g) => ['id' => (int) $g->id, 'name' => $g->name])
                ->values();

            return [
                'id' => (int) $user->id,
                'name' => $user->name,
                'email' => $user->email,      // admin-facing: staff detail, not a student/guardian
                'invited' => $user->password !== null && $user->email_verified_at === null,
                'classes' => $classes,
            ];
        })->filter()->values();

        return response()->json([
            'status' => 'success',
            'data' => $teachers,
        ], Response::HTTP_OK);
    }

    /** Create + assign + invite, atomically; the email goes out AFTER commit. */
    public function store(TeacherInviteRequest $request, $masjid_id, AccountAccessService $access)
    {
        $masjidId = (int) $this->tenant->get();

        // The classes must be IN THIS SCHOOL. Group is tenant-scoped, so a
        // foreign id simply does not resolve here — a mismatch in count is an id
        // outside the bound tenant, refused with a 422 rather than silently
        // dropped.
        $classes = Group::whereIn('id', $request->validated('class_ids'))->get();

        if ($classes->count() !== count(array_unique($request->validated('class_ids')))) {
            return response()->json([
                'status' => 'failed',
                'data' => ['class_ids' => ['One or more of those classes are not in this school.']],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = null;

        DB::transaction(function () use ($request, $masjidId, $classes, &$user) {
            $user = User::create([
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                // users.phone is NOT NULL; an empty string is a real "none on file".
                'phone' => $request->validated('phone') ?? '',
                'type' => 'Teacher',
                'password' => Str::password(40),
            ]);

            // The teacher's school. role is advisory (mirrors the bridged role);
            // is_default is safe — a brand-new user holds no other membership.
            MasjidUser::create([
                'masjid_id' => $masjidId,
                'user_id' => $user->id,
                'role' => 'teacher',
                'is_default' => true,
            ]);

            // The classes they lead. masjid_id MUST be explicit — attach() bypasses
            // the BelongsToMasjid creating hook (see GroupStaff).
            foreach ($classes as $group) {
                $group->staff()->attach($user->id, [
                    'masjid_id' => $group->masjid_id,
                    'role' => GroupStaff::ROLE_TEACHER,
                    'assigned_by_user_id' => Auth::id(),
                    'assigned_at' => now(),
                ]);
            }
        });

        // Only after the row is committed — a rolled-back transaction must not
        // send an invite to a user that does not exist.
        $orgName = optional(\App\Models\Masjid::withoutGlobalScopes()->find($masjidId))->name;
        $sent = $access->invite($user, $orgName);

        return response()->json([
            'status' => 'success',
            'message' => $sent
                ? 'Teacher created. An invitation to set their password is on its way to '.$user->email.'.'
                : 'Teacher created, but no invitation could be sent (no email on file).',
            'data' => $this->serialize($user, $classes),
        ], Response::HTTP_CREATED);
    }

    /** One teacher, for the edit form — name, email (read-only), phone, class ids. */
    public function show($masjid_id, $user_id)
    {
        $user = $this->resolveTeacher($user_id);

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => (int) $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'class_ids' => $this->ledClassIds($user),
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Edit a teacher: their name/phone, and the set of classes they lead. The
     * email is immutable here (changing it is a re-invite, not an edit). Class
     * assignments are SYNCED — rows for dropped classes are removed, new ones
     * added — all within the bound school.
     */
    public function update(TeacherUpdateRequest $request, $masjid_id, $user_id)
    {
        $user = $this->resolveTeacher($user_id);
        $classIds = array_values(array_unique($request->validated('class_ids')));
        $classes = $this->resolveClassesInSchool($classIds);

        if ($classes === null) {
            return $this->foreignClassError();
        }

        DB::transaction(function () use ($request, $user, $classIds, $classes) {
            $user->update([
                'name' => $request->validated('name'),
                'phone' => $request->validated('phone') ?? '',
            ]);

            // Sync group_staff for THIS school. group_staff is tenant-scoped, so
            // these reads/writes only ever touch the bound masjid's rows.
            $current = $this->ledClassIds($user);
            $toRemove = array_diff($current, $classIds);
            $toAdd = array_diff($classIds, $current);

            if ($toRemove !== []) {
                GroupStaff::query()
                    ->where('user_id', $user->id)
                    ->whereIn('group_id', $toRemove)
                    ->delete();
            }

            foreach ($classes->whereIn('id', $toAdd) as $group) {
                $group->staff()->attach($user->id, [
                    'masjid_id' => $group->masjid_id,
                    'role' => GroupStaff::ROLE_TEACHER,
                    'assigned_by_user_id' => Auth::id(),
                    'assigned_at' => now(),
                ]);
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Teacher updated.',
            'data' => $this->serialize($user->fresh(), $classes),
        ], Response::HTTP_OK);
    }

    /**
     * Remove a teacher from THIS school: drop their class assignments and their
     * membership. If that leaves them belonging to no organisation at all, the
     * login is soft-deleted too — which frees the email for reuse and stops them
     * signing in. A teacher who somehow belonged to two schools keeps the other.
     */
    public function destroy($masjid_id, $user_id)
    {
        $user = $this->resolveTeacher($user_id);
        $masjidId = (int) $this->tenant->get();

        DB::transaction(function () use ($user, $masjidId) {
            // Tenant-scoped: only this school's assignments.
            GroupStaff::query()->where('user_id', $user->id)->delete();

            MasjidUser::where('masjid_id', $masjidId)->where('user_id', $user->id)->delete();

            // Belongs to no organisation any more -> retire the login.
            if (! MasjidUser::where('user_id', $user->id)->exists()) {
                $user->delete();
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Teacher removed from this school.',
        ], Response::HTTP_OK);
    }

    /** Re-send the set-your-own-password invite — the "they never got it" path. */
    public function invite($masjid_id, $user_id, AccountAccessService $access)
    {
        $user = $this->resolveTeacher($user_id);
        $orgName = optional(\App\Models\Masjid::withoutGlobalScopes()->find((int) $this->tenant->get()))->name;

        if (! $access->invite($user, $orgName)) {
            return response()->json([
                'status' => 'error',
                'message' => 'That teacher has no email address, so there is nowhere to send an invitation.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'An invitation is on its way to '.$user->email.'. It expires in an hour.',
        ], Response::HTTP_OK);
    }

    // ------------------------------------------------------------- helpers

    /**
     * A Teacher who belongs to the BOUND school, or a 404. This is what stops a
     * MasjidAdmin editing a teacher of another school, or a non-teacher user.
     */
    private function resolveTeacher($userId): User
    {
        $user = User::where('id', $userId)->where('type', 'Teacher')->first();

        if ($user === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $inSchool = MasjidUser::where('masjid_id', (int) $this->tenant->get())
                ->where('user_id', $user->id)->exists()
            // group_staff is tenant-scoped, so "any staff row" already means "in
            // this school".
            || GroupStaff::query()->where('user_id', $user->id)->exists();

        if (! $inSchool) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $user;
    }

    /** The ids of the classes this teacher leads in the bound school. */
    private function ledClassIds(User $user): array
    {
        return GroupStaff::query()
            ->where('user_id', $user->id)
            ->pluck('group_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Resolve class ids to Groups IN THE BOUND SCHOOL, or null when one is not.
     * Group is tenant-scoped, so a foreign id simply does not resolve.
     */
    private function resolveClassesInSchool(array $classIds)
    {
        $classes = Group::whereIn('id', $classIds)->get();

        return $classes->count() === count(array_unique($classIds)) ? $classes : null;
    }

    private function foreignClassError()
    {
        return response()->json([
            'status' => 'failed',
            'data' => ['class_ids' => ['One or more of those classes are not in this school.']],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function serialize(User $user, $classes): array
    {
        return [
            'id' => (int) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'classes' => $classes->map(fn (Group $g) => ['id' => (int) $g->id, 'name' => $g->name])->values(),
        ];
    }
}
