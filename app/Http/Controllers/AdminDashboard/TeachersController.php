<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Users\TeacherInviteRequest;
use App\Http\Requests\Admin\Users\TeacherUpdateRequest;
use App\Mail\StaffAddedToOrganisation;
use App\Models\Group;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Services\Auth\AccountAccessService;
use App\Support\ContactIdentity;
use App\Support\MembershipSeen;
use App\Support\TenantContext;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The school office onboarding a teacher: give them a login at THIS school, assign
 * the classes they lead, and tell them.
 *
 * Mounted under the masjid-scoped admin group (`admin` + `tenant`), so BOTH a
 * SuperAdmin (via the route masjid) and a school's own MasjidAdmin (bound to
 * their masjid) can run it — "mine now, school staff later". It never accepts a
 * `type`: the login is ALWAYS a 'Teacher', and its whole reach is the
 * masjid_user membership + group_staff rows written here, both bound to this
 * school. A MasjidAdmin therefore cannot mint anything but a teacher of their own
 * classes.
 *
 * ------------------------------------------------------------------------------
 * store() is CREATE-OR-ATTACH (docs/multi-tenant-admin-design.md §6, DECISIONS.md
 * 2026-09-29)
 * ------------------------------------------------------------------------------
 *
 * One person teaches at two schools with ONE login and ONE password. So an email
 * that already belongs to a live Teacher elsewhere is ATTACHED to this school, not
 * refused, and the branch table is:
 *
 *   no such user            create the login, membership, classes; set-password invite
 *   live Teacher elsewhere  attach: membership + classes ONLY. `users` is not
 *                           written at all (not name, phone, password or tokens);
 *                           the "added to {school}" notice goes out, never a
 *                           password link. Gated on tenancy.multi_membership.
 *   live Teacher, here      422, nothing written (Edit is the door for classes)
 *   trashed Teacher         restored ONLY if they hold no masjid_user row (a row
 *                           means a SuperAdmin trashed them on purpose); then they
 *                           are treated as new: password rotated, tokens dropped,
 *                           name and phone overwritten, set-password invite
 *   any other type          422. A MasjidAdmin, LunchStaff or SuperAdmin is never
 *                           attached, and never restored.
 *
 * Every success answers with the SAME message and the SAME data shape, built from
 * what the inviter typed, so the reply itself cannot tell the inviter whether the
 * email already existed at another school. Other things can, and the owner has
 * accepted that an office may infer an email teaches elsewhere (2026-09-29,
 * "acceptable"; DECISIONS.md, "What the add itself discloses"): the refusals
 * distinguish the KIND of login (gate shut: a live teacher elsewhere is "switched
 * off"; another type, or a teacher a SuperAdmin trashed, is CANNOT_ADD; a teacher
 * already here has its own line), which is more than today's `unique` rule said,
 * and the next list read shows the stored name and phone.
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
        if ($this->subjectsOn()) {
            return $this->indexWithClassSubjects($masjid_id);
        }

        // group_staff is tenant-scoped, so this is only THIS school's staff rows.
        $rows = GroupStaff::query()->where('role', GroupStaff::ROLE_TEACHER)->get();

        $users = User::whereIn('id', $rows->pluck('user_id')->unique())->get()->keyBy('id');
        // When each last opened THIS school, from this school's membership rows only.
        $seen = MembershipSeen::forOrganisation((int) $this->tenant->get());
        $groups = Group::whereIn('id', $rows->pluck('group_id')->unique())->get()->keyBy('id');

        $teachers = $rows->pluck('user_id')->unique()->values()->map(function ($userId) use ($rows, $users, $groups, $seen) {
            $user = $users->get($userId);
            if ($user === null) {
                return null;
            }

            $classes = $rows->where('user_id', $userId)
                ->map(function (GroupStaff $r) use ($groups) {
                    $g = $groups->get($r->group_id);

                    // null = every subject; see GroupStaff::SUBJECTS.
                    return $g === null ? null : ['id' => (int) $g->id, 'name' => $g->name, 'subjects' => $r->subjects ?: null];
                })
                ->filter()
                ->values();

            return [
                'id' => (int) $user->id,
                'name' => $user->name,
                'email' => $user->email,      // admin-facing: staff detail, not a student/guardian
                'phone' => $user->phone,      // shown to every school that has the teacher (owner, 2026-09-29)
                // This school's own "last opened this school", never another school's.
                'last_seen_at' => MembershipSeen::iso($seen->get($user->id)),
                'invited' => $user->password !== null && $user->email_verified_at === null,
                'classes' => $classes,
            ];
        })->filter()->values();

        return response()->json([
            'status' => 'success',
            'data' => $teachers,
            // The subjects an assignment may be narrowed to, in the order and words
            // the screen shows them, so the admin form never carries its own copy.
            'meta' => [
                'subjects' => collect(GroupStaff::SUBJECTS)
                    ->map(fn (string $s) => ['value' => $s, 'label' => GroupStaff::SUBJECT_LABELS[$s]])
                    ->values(),
                // EVERY live class of this school, for the form's picker: id and
                // name, in the order every other screen lists them. The picker
                // used to read the Classes screen's paginated list, which is one
                // page of 15, so a class past the first page could be neither
                // ticked nor unticked. A picker needs the whole set, so it is
                // served here, whole, and the paginated list is left to its screen.
                'classes' => Group::query()->inDisplayOrder()->get(['id', 'name'])
                    ->map(fn (Group $g) => ['id' => (int) $g->id, 'name' => $g->name])
                    ->values(),
            ],
        ], Response::HTTP_OK);
    }

    private function indexWithClassSubjects($masjid_id)
    {
        // group_staff is tenant-scoped, so this is only THIS school's staff rows.
        $rows = GroupStaff::query()->where('role', GroupStaff::ROLE_TEACHER)->get();

        $users = User::whereIn('id', $rows->pluck('user_id')->unique())->get()->keyBy('id');
        // When each last opened THIS school, from this school's membership rows only.
        $seen = MembershipSeen::forOrganisation((int) $this->tenant->get());
        $groups = Group::whereIn('id', $rows->pluck('group_id')->unique())->get()->keyBy('id');

        $subjectsOn = $this->subjectsOn();
        $teachers = $rows->pluck('user_id')->unique()->values()->map(function ($userId) use ($rows, $users, $groups, $seen, $subjectsOn) {
            $user = $users->get($userId);
            if ($user === null) {
                return null;
            }

            $classes = $rows->where('user_id', $userId)
                ->map(function (GroupStaff $r) use ($groups, $subjectsOn) {
                    $g = $groups->get($r->group_id);

                    // null = every subject; see GroupStaff::SUBJECTS.
                    return $g === null ? null : ['id' => (int) $g->id, 'name' => $g->name, 'subjects' => $r->subjects ?: null] + ($subjectsOn ? self::subjectIdFields($r) : []);
                })
                ->filter()
                ->values();

            return [
                'id' => (int) $user->id,
                'name' => $user->name,
                'email' => $user->email,      // admin-facing: staff detail, not a student/guardian
                'phone' => $user->phone,      // shown to every school that has the teacher (owner, 2026-09-29)
                // This school's own "last opened this school", never another school's.
                'last_seen_at' => MembershipSeen::iso($seen->get($user->id)),
                'invited' => $user->password !== null && $user->email_verified_at === null,
                'classes' => $classes,
            ];
        })->filter()->values();

        return response()->json([
            'status' => 'success',
            'data' => $teachers,
            // The subjects an assignment may be narrowed to, in the order and words
            // the screen shows them, so the admin form never carries its own copy.
            'meta' => [
                'subjects' => collect(GroupStaff::SUBJECTS)
                    ->map(fn (string $s) => ['value' => $s, 'label' => GroupStaff::SUBJECT_LABELS[$s]])
                    ->values(),
                // EVERY live class of this school, for the form's picker: id and
                // name, in the order every other screen lists them. The picker
                // used to read the Classes screen's paginated list, which is one
                // page of 15, so a class past the first page could be neither
                // ticked nor unticked. A picker needs the whole set, so it is
                // served here, whole, and the paginated list is left to its screen.
                'classes' => Group::query()->inDisplayOrder()->get(['id', 'name'])
                    ->map(fn (Group $g) => ['id' => (int) $g->id, 'name' => $g->name])
                    ->values(),
            ],
        ], Response::HTTP_OK);
    }

    /** What a refusal to add an existing login says, whatever the reason was. */
    private const CANNOT_ADD = 'This email already has a Manara login that can\'t be added as a teacher. Contact Manara support.';

    /** Create-or-attach, atomically; the email goes out AFTER commit. */
    public function store(TeacherInviteRequest $request, $masjid_id, AccountAccessService $access)
    {
        if ($this->subjectsOn()) {
            return $this->storeWithClassSubjects($request, $masjid_id, $access);
        }

        $masjidId = (int) $this->tenant->get();

        // The classes must be IN THIS SCHOOL. Group is tenant-scoped, so a
        // foreign id simply does not resolve here — a mismatch in count is an id
        // outside the bound tenant, refused with a 422 rather than silently
        // dropped.
        $classes = Group::whereIn('id', $request->validated('class_ids'))->get();

        if ($classes->count() !== count(array_unique($request->validated('class_ids')))) {
            return $this->foreignClassError();
        }

        // Two requests adding the same brand-new address at once both find no
        // user and both insert; the unique index throws for the loser. Trying the
        // whole decision once more lets it see the winner's row and take the
        // attach branch instead of returning a 500. The same second try covers
        // InnoDB picking this request as the victim of a deadlock between two
        // concurrent inserts (SQLSTATE 40001): the transaction rolled back whole,
        // so nothing was written and running it again is safe.
        try {
            $outcome = $this->createOrAttach($request, $masjidId, $classes);
        } catch (QueryException|DeadlockException $e) {
            if (! ($e instanceof UniqueConstraintViolationException) && ! self::isDeadlock($e)) {
                throw $e;
            }

            $outcome = $this->createOrAttach($request, $masjidId, $classes);
        }

        if (isset($outcome['refusal'])) {
            return $this->refuseAdd($outcome['refusal']);
        }

        /** @var User $user */
        $user = $outcome['user'];
        $email = (string) $request->validated('email');

        // Only after the rows are committed — a rolled-back transaction must not
        // mail somebody about a school they were never added to.
        $org = Masjid::withoutGlobalScopes()->find($masjidId);

        if ($outcome['mail'] === 'invite') {
            $access->invite($user, $org?->name);
        } else {
            $this->sendAddedNotice($user, $org, $classes);
        }

        // ONE message for every branch, and data built from what the inviter
        // TYPED. The stored row is never read back into this response: a name or
        // phone another school entered must not reach this school through the
        // reply to "add", and neither may the fact that they exist.
        return response()->json([
            'status' => 'success',
            'message' => 'Invitation sent to '.$email.'.',
            'data' => $this->serialize(
                (int) $user->id,
                (string) $request->validated('name'),
                $email,
                $classes
            ),
        ], Response::HTTP_CREATED);
    }

    private function storeWithClassSubjects(TeacherInviteRequest $request, $masjid_id, AccountAccessService $access)
    {
        $masjidId = (int) $this->tenant->get();

        // The classes must be IN THIS SCHOOL. Group is tenant-scoped, so a
        // foreign id simply does not resolve here — a mismatch in count is an id
        // outside the bound tenant, refused with a 422 rather than silently
        // dropped.
        $classes = Group::whereIn('id', $request->validated('class_ids'))->get();

        if ($classes->count() !== count(array_unique($request->validated('class_ids')))) {
            return $this->foreignClassError();
        }

        // Two requests adding the same brand-new address at once both find no
        // user and both insert; the unique index throws for the loser. Trying the
        // whole decision once more lets it see the winner's row and take the
        // attach branch instead of returning a 500. The same second try covers
        // InnoDB picking this request as the victim of a deadlock between two
        // concurrent inserts (SQLSTATE 40001): the transaction rolled back whole,
        // so nothing was written and running it again is safe.
        try {
            $outcome = $this->createOrAttach($request, $masjidId, $classes);
        } catch (QueryException|DeadlockException $e) {
            if (! ($e instanceof UniqueConstraintViolationException) && ! self::isDeadlock($e)) {
                throw $e;
            }

            $outcome = $this->createOrAttach($request, $masjidId, $classes);
        }

        if (isset($outcome['refusal'])) {
            return $this->refuseAdd($outcome['refusal']);
        }

        /** @var User $user */
        $user = $outcome['user'];
        $email = (string) $request->validated('email');

        // Only after the rows are committed — a rolled-back transaction must not
        // mail somebody about a school they were never added to.
        $org = Masjid::withoutGlobalScopes()->find($masjidId);

        if ($outcome['mail'] === 'invite') {
            $access->invite($user, $org?->name);
        } else {
            $this->sendAddedNotice($user, $org, $classes);
        }

        // ONE message for every branch, and data built from what the inviter
        // TYPED. The stored row is never read back into this response: a name or
        // phone another school entered must not reach this school through the
        // reply to "add", and neither may the fact that they exist.
        return response()->json([
            'status' => 'success',
            'message' => 'Invitation sent to '.$email.'.',
            'data' => $this->serialize(
                (int) $user->id,
                (string) $request->validated('name'),
                $email,
                $classes
            ),
        ], Response::HTTP_CREATED);
    }

    /**
     * The branch table. Returns `['user' => User, 'mail' => 'invite'|'added']` or
     * `['refusal' => string]`. Everything that writes does so inside one
     * transaction, holding a lock on the user row.
     *
     * @return array{user?: User, mail?: string, refusal?: string}
     */
    private function createOrAttach(TeacherInviteRequest $request, int $masjidId, $classes): array
    {
        if ($this->subjectsOn()) {
            return $this->createOrAttachWithClassSubjects($request, $masjidId, $classes);
        }

        $email = (string) $request->validated('email');

        // Trashed rows INCLUDED: the unique index on users.email covers them, so
        // validation that ignored them (the old Rule::unique) let an archived
        // address through to a 500. Case-insensitive because legacy rows are not
        // all lowercased (User::scopeWhereEmailIs picks the form the driver can
        // answer from the unique index); `email` itself is already normalised by
        // the request.
        //
        // Looked up WITHOUT a lock and OUTSIDE the transaction. A locking read on a
        // predicate that matches nothing takes gap locks on InnoDB, so two schools
        // adding two different new addresses would hold overlapping gaps and
        // deadlock at their INSERTs. And it must not be a plain read INSIDE the
        // transaction either: under REPEATABLE READ the first plain read fixes the
        // snapshot, and a request that then waits for another school's row lock
        // would afterwards derive is_default from rows that predate the commit it
        // waited for. Outside, it takes no snapshot; the first statement inside is
        // the lock, and the reads after it see everything committed before it.
        //
        // `whereEmailIs()` returns CANDIDATES. `users.email` is utf8mb4_unicode_ci,
        // so on MySQL it also returns a login whose address differs only by an
        // accent or an expansion (`sara@gmaíl.com` for `sara@gmail.com`), and
        // attaching that one would hand this school somebody else's account under
        // an address the inviter never typed. Only the row whose address IS the
        // typed one, byte for byte after lower-casing, is the teacher being
        // added. A candidate that matched only through the collation is refused
        // like any other login this flow cannot add: creating a new user at that
        // address would collide with it on the unique index, and a 500 there
        // would answer "somebody holds a near-identical address".
        $candidates = User::withTrashed()->whereEmailIs($email)->get(['id', 'email']);
        $foundId = $candidates
            ->first(fn (User $candidate) => ContactIdentity::sameAddress($candidate->email, $email))
            ?->id;

        if ($foundId === null && $candidates->isNotEmpty()) {
            return ['refusal' => self::CANNOT_ADD];
        }

        return DB::transaction(function () use ($request, $masjidId, $classes, $email, $foundId) {
            // The ONE row found, locked by key (nothing to lock for a new address).
            $existing = $foundId === null
                ? null
                : User::withTrashed()->whereKey($foundId)->lockForUpdate()->first();

            if ($existing === null) {
                $user = User::create([
                    'name' => $request->validated('name'),
                    'email' => $email,
                    // users.phone is NOT NULL; an empty string is a real "none on file".
                    'phone' => $request->validated('phone') ?? '',
                    'type' => 'Teacher',
                    'password' => Str::password(40),
                ]);

                $this->grantMembershipAndClasses($user, $masjidId, $request, $classes);

                return ['user' => $user, 'mail' => 'invite'];
            }

            // Never attach, restore or touch any other kind of login — trashed or
            // live. A SuperAdmin is the case that matters most.
            if ($existing->type !== 'Teacher') {
                return ['refusal' => self::CANNOT_ADD];
            }

            if ($existing->trashed()) {
                // H1. A trashed Teacher who STILL has membership rows can only
                // be one a SuperAdmin trashed on purpose (moveToTrash leaves the
                // rows; TeachersController::destroy trashes only once none
                // remain). Restoring them from here would let any school undo
                // that lockout, for every school they belong to.
                if (MasjidUser::where('user_id', $existing->id)->exists()) {
                    return ['refusal' => self::CANNOT_ADD];
                }

                // No memberships anywhere: a retired login, effectively new
                // again. Whatever the old password was, whoever held it, is over.
                $existing->restore();
                $existing->forceFill([
                    'name' => $request->validated('name'),
                    'phone' => $request->validated('phone') ?? '',
                    'password' => Str::password(40),
                ])->save();
                $existing->tokens()->delete();

                $this->grantMembershipAndClasses($existing, $masjidId, $request, $classes);

                return ['user' => $existing, 'mail' => 'invite'];
            }

            // A live Teacher. Already here? (group_staff is tenant-scoped, so any
            // staff row means "in this school", as resolveTeacher() reads it.)
            $alreadyHere = MasjidUser::where('masjid_id', $masjidId)->where('user_id', $existing->id)->exists()
                || GroupStaff::query()->where('user_id', $existing->id)->exists();

            if ($alreadyHere) {
                return ['refusal' => $email.' is already a teacher at this school. Use Edit to change their classes.'];
            }

            // The switch for NEW cross-organisation grants, same meaning as
            // MasjidAdminsController::grantMembership. Attaching a teacher who
            // holds no membership at all (a live login with nowhere to go)
            // creates no cross-organisation grant, so it does not need the gate.
            if (! (bool) config('tenancy.multi_membership', false)
                && MasjidUser::where('user_id', $existing->id)->exists()) {
                return ['refusal' => 'Adding an existing login to a second school is switched off.'];
            }

            // ATTACH. `users` is not written: not name, phone, password, tokens.
            $this->grantMembershipAndClasses($existing, $masjidId, $request, $classes);

            return ['user' => $existing, 'mail' => 'added'];
        });
    }

    private function createOrAttachWithClassSubjects(TeacherInviteRequest $request, int $masjidId, $classes): array
    {
        $email = (string) $request->validated('email');

        // Trashed rows INCLUDED: the unique index on users.email covers them, so
        // validation that ignored them (the old Rule::unique) let an archived
        // address through to a 500. Case-insensitive because legacy rows are not
        // all lowercased (User::scopeWhereEmailIs picks the form the driver can
        // answer from the unique index); `email` itself is already normalised by
        // the request.
        //
        // Looked up WITHOUT a lock and OUTSIDE the transaction. A locking read on a
        // predicate that matches nothing takes gap locks on InnoDB, so two schools
        // adding two different new addresses would hold overlapping gaps and
        // deadlock at their INSERTs. And it must not be a plain read INSIDE the
        // transaction either: under REPEATABLE READ the first plain read fixes the
        // snapshot, and a request that then waits for another school's row lock
        // would afterwards derive is_default from rows that predate the commit it
        // waited for. Outside, it takes no snapshot; the first statement inside is
        // the lock, and the reads after it see everything committed before it.
        //
        // `whereEmailIs()` returns CANDIDATES. `users.email` is utf8mb4_unicode_ci,
        // so on MySQL it also returns a login whose address differs only by an
        // accent or an expansion (`sara@gmaíl.com` for `sara@gmail.com`), and
        // attaching that one would hand this school somebody else's account under
        // an address the inviter never typed. Only the row whose address IS the
        // typed one, byte for byte after lower-casing, is the teacher being
        // added. A candidate that matched only through the collation is refused
        // like any other login this flow cannot add: creating a new user at that
        // address would collide with it on the unique index, and a 500 there
        // would answer "somebody holds a near-identical address".
        $candidates = User::withTrashed()->whereEmailIs($email)->get(['id', 'email']);
        $foundId = $candidates
            ->first(fn (User $candidate) => ContactIdentity::sameAddress($candidate->email, $email))
            ?->id;

        if ($foundId === null && $candidates->isNotEmpty()) {
            return ['refusal' => self::CANNOT_ADD];
        }

        return DB::transaction(function () use ($request, $masjidId, $classes, $email, $foundId) {
            // Serialize assignment writes with activation before taking a consistent read.
            Masjid::whereKey($masjidId)->lockForUpdate()->firstOrFail();
            // The ONE row found, locked by key (nothing to lock for a new address).
            $existing = $foundId === null
                ? null
                : User::withTrashed()->whereKey($foundId)->lockForUpdate()->first();

            if ($existing === null) {
                $user = User::create([
                    'name' => $request->validated('name'),
                    'email' => $email,
                    // users.phone is NOT NULL; an empty string is a real "none on file".
                    'phone' => $request->validated('phone') ?? '',
                    'type' => 'Teacher',
                    'password' => Str::password(40),
                ]);

                $this->grantMembershipAndClasses($user, $masjidId, $request, $classes);

                return ['user' => $user, 'mail' => 'invite'];
            }

            // Never attach, restore or touch any other kind of login — trashed or
            // live. A SuperAdmin is the case that matters most.
            if ($existing->type !== 'Teacher') {
                return ['refusal' => self::CANNOT_ADD];
            }

            if ($existing->trashed()) {
                // H1. A trashed Teacher who STILL has membership rows can only
                // be one a SuperAdmin trashed on purpose (moveToTrash leaves the
                // rows; TeachersController::destroy trashes only once none
                // remain). Restoring them from here would let any school undo
                // that lockout, for every school they belong to.
                if (MasjidUser::where('user_id', $existing->id)->exists()) {
                    return ['refusal' => self::CANNOT_ADD];
                }

                // No memberships anywhere: a retired login, effectively new
                // again. Whatever the old password was, whoever held it, is over.
                $existing->restore();
                $existing->forceFill([
                    'name' => $request->validated('name'),
                    'phone' => $request->validated('phone') ?? '',
                    'password' => Str::password(40),
                ])->save();
                $existing->tokens()->delete();

                $this->grantMembershipAndClasses($existing, $masjidId, $request, $classes);

                return ['user' => $existing, 'mail' => 'invite'];
            }

            // A live Teacher. Already here? (group_staff is tenant-scoped, so any
            // staff row means "in this school", as resolveTeacher() reads it.)
            $alreadyHere = MasjidUser::where('masjid_id', $masjidId)->where('user_id', $existing->id)->exists()
                || GroupStaff::query()->where('user_id', $existing->id)->exists();

            if ($alreadyHere) {
                return ['refusal' => $email.' is already a teacher at this school. Use Edit to change their classes.'];
            }

            // The switch for NEW cross-organisation grants, same meaning as
            // MasjidAdminsController::grantMembership. Attaching a teacher who
            // holds no membership at all (a live login with nowhere to go)
            // creates no cross-organisation grant, so it does not need the gate.
            if (! (bool) config('tenancy.multi_membership', false)
                && MasjidUser::where('user_id', $existing->id)->exists()) {
                return ['refusal' => 'Adding an existing login to a second school is switched off.'];
            }

            // ATTACH. `users` is not written: not name, phone, password, tokens.
            $this->grantMembershipAndClasses($existing, $masjidId, $request, $classes);

            return ['user' => $existing, 'mail' => 'added'];
        });
    }

    /**
     * The membership and the classes, for a user this school is now adding.
     *
     * `is_default` is DERIVED under a lock on the user row, never asserted. The
     * database allows one default per user (`masjid_user_default_unique`), so a
     * hardcoded true is a constraint violation the moment the user belongs
     * anywhere else — and two schools attaching the same default-less person at
     * once would both compute "no default" and both write one. The caller already
     * holds the row lock (lockForUpdate in createOrAttach; a fresh user has no
     * competing writer), so the read and the write are one step.
     */
    private function grantMembershipAndClasses(User $user, int $masjidId, TeacherInviteRequest $request, $classes): void
    {
        if ($this->subjectsOn()) {
            $this->grantMembershipAndClassesWithClassSubjects($user, $masjidId, $request, $classes);
            return;
        }

        MasjidUser::create([
            'masjid_id' => $masjidId,
            'user_id' => $user->id,
            // Advisory (mirrors the bridged role); authorization runs on users.type.
            'role' => 'teacher',
            'is_default' => ! MasjidUser::where('user_id', $user->id)->where('is_default', true)->exists(),
        ]);

        // The classes they lead. masjid_id is explicit (see GroupStaff). Who
        // assigned them is recorded by GroupStaff's creating hook: attach()
        // runs the extras through the pivot's fill(), which drops that column.
        foreach ($classes as $group) {
            $group->staff()->attach($user->id, [
                'masjid_id' => $group->masjid_id,
                'role' => GroupStaff::ROLE_TEACHER,
                'subjects' => $request->subjectsFor((int) $group->id),
                'assigned_at' => now(),
            ]);
        }
    }

    private function grantMembershipAndClassesWithClassSubjects(User $user, int $masjidId, TeacherInviteRequest $request, $classes): void
    {
        MasjidUser::create([
            'masjid_id' => $masjidId,
            'user_id' => $user->id,
            // Advisory (mirrors the bridged role); authorization runs on users.type.
            'role' => 'teacher',
            'is_default' => ! MasjidUser::where('user_id', $user->id)->where('is_default', true)->exists(),
        ]);

        // The classes they lead. masjid_id is explicit (see GroupStaff). Who
        // assigned them is recorded by GroupStaff's creating hook: attach()
        // runs the extras through the pivot's fill(), which drops that column.
        foreach ($classes as $group) {
            GroupStaff::withOfficeSubjectChoice(fn () => $group->staff()->attach($user->id, [
                'masjid_id' => $group->masjid_id,
                'role' => GroupStaff::ROLE_TEACHER,
                'assigned_at' => now(),
            ] + $request->subjectAssignmentFields($group)));
        }
    }

    /**
     * "{school} added you as a teacher" — to an existing login, with no password
     * link. A notice, not part of the write: a mail transport failing after the
     * commit must not turn a completed attach into a 500 the office would retry
     * (and then be told the teacher is already there).
     */
    private function sendAddedNotice(User $user, ?Masjid $org, $classes): void
    {
        try {
            Mail::to($user->email)->send(new StaffAddedToOrganisation(
                $user,
                (string) ($org?->name ?: config('app.name')),
                'teacher',
                $classes->pluck('name')->values()->all(),
                $org?->email ?: null,
            ));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * InnoDB's "you were the deadlock victim": SQLSTATE 40001, error 1213. Laravel
     * re-throws one raised inside a NESTED transaction as its own DeadlockException
     * (the outer transaction is dead too), so both spellings are the same fact here.
     */
    private static function isDeadlock(\Throwable $e): bool
    {
        return $e instanceof DeadlockException
            || (string) $e->getCode() === '40001'
            || str_contains($e->getMessage(), 'Deadlock found');
    }

    private function refuseAdd(string $message)
    {
        return response()->json([
            'status' => 'failed',
            'message' => $message,
            // Under `email`, where the form shows it next to the field the
            // inviter typed, like every other 422 on this screen.
            'data' => ['email' => [$message]],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** One teacher, for the edit form — name, email (read-only), phone, class ids. */
    public function show($masjid_id, $user_id)
    {
        if ($this->subjectsOn()) {
            return $this->showWithClassSubjects($masjid_id, $user_id);
        }

        $user = $this->resolveTeacher($user_id);

        // A teacher who also belongs to another school is SHARED: the `users` row is
        // one record every school holds, so the edit form is told the name and phone
        // are read-only (see update()). The phone itself is shown: the owner decided
        // (2026-09-29) that every school that has the teacher may see it.
        $shared = $user->belongsOutside((int) $this->tenant->get());
        // Live classes only, and the SAME set update() syncs against.
        $assignments = $this->liveAssignments($user);

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => (int) $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'shared' => $shared,
                'last_seen_at' => MembershipSeen::iso(
                    MembershipSeen::forOrganisation((int) $this->tenant->get(), [(int) $user->id])->get($user->id)
                ),
                'class_ids' => $assignments->map(fn (GroupStaff $r): int => (int) $r->group_id)->values()->all(),
                // Per class, as stored: null for "teaches everything". The edit
                // form round-trips this, so an admin editing a name cannot
                // silently widen a Sunday School teacher back to every subject.
                'class_subjects' => $assignments
                    ->mapWithKeys(fn (GroupStaff $r) => [(int) $r->group_id => $r->subjects ?: null]),
            ],
            'meta' => [
                'subjects' => collect(GroupStaff::SUBJECTS)
                    ->map(fn (string $s) => ['value' => $s, 'label' => GroupStaff::SUBJECT_LABELS[$s]])
                    ->values(),
            ],
        ], Response::HTTP_OK);
    }

    private function showWithClassSubjects($masjid_id, $user_id)
    {
        $user = $this->resolveTeacher($user_id);

        // A teacher who also belongs to another school is SHARED: the `users` row is
        // one record every school holds, so the edit form is told the name and phone
        // are read-only (see update()). The phone itself is shown: the owner decided
        // (2026-09-29) that every school that has the teacher may see it.
        $shared = $user->belongsOutside((int) $this->tenant->get());
        // Live classes only, and the SAME set update() syncs against.
        $assignments = $this->liveAssignments($user);

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => (int) $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'shared' => $shared,
                'last_seen_at' => MembershipSeen::iso(
                    MembershipSeen::forOrganisation((int) $this->tenant->get(), [(int) $user->id])->get($user->id)
                ),
                'class_ids' => $assignments->map(fn (GroupStaff $r): int => (int) $r->group_id)->values()->all(),
                // Per class, as stored: null for "teaches everything". The edit
                // form round-trips this, so an admin editing a name cannot
                // silently widen a Sunday School teacher back to every subject.
                'class_subjects' => $assignments
                    ->mapWithKeys(fn (GroupStaff $r) => [(int) $r->group_id => $r->subjects ?: null]),
            ] + $this->assignmentMap($assignments),
            'meta' => [
                'subjects' => collect(GroupStaff::SUBJECTS)
                    ->map(fn (string $s) => ['value' => $s, 'label' => GroupStaff::SUBJECT_LABELS[$s]])
                    ->values(),
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Edit a teacher: their name/phone, and the set of classes they lead. The
     * email is immutable here (changing it is a re-invite, not an edit). Class
     * assignments are SYNCED — rows for dropped classes are removed, new ones
     * added — all within the bound school, and among its LIVE classes only: the
     * row for a class that has been deleted is not part of the set (see
     * liveAssignments()), so a save neither asks for it nor removes it.
     *
     * A teacher who also belongs to another school is SHARED, and the `users` row
     * is global: renaming or re-phoning them here rewrites every other school's
     * records of them. So for a shared teacher this school may edit CLASSES only.
     * The name must be the one already on file (the form shows it read-only), and
     * any phone is refused: the phone is one record every school shares and none of
     * them may change it, so the form displays it and never sends it back. (Since
     * 2026-09-29 the phone is visible to every school that has the teacher, so this
     * is a rule about who may EDIT it, not about who may know it.)
     */
    public function update(TeacherUpdateRequest $request, $masjid_id, $user_id)
    {
        if ($this->subjectsOn()) {
            return $this->updateWithClassSubjects($request, $masjid_id, $user_id);
        }

        $user = $this->resolveTeacher($user_id);
        $classIds = array_values(array_unique($request->validated('class_ids')));
        $classes = $this->resolveClassesInSchool($classIds);

        if ($classes === null) {
            return $this->foreignClassError();
        }

        $shared = $user->belongsOutside((int) $this->tenant->get());

        if ($shared) {
            $typedName = trim((string) $request->validated('name'));
            $typedPhone = trim((string) ($request->validated('phone') ?? ''));

            if ($typedName !== trim((string) $user->name) || $typedPhone !== '') {
                $message = 'This teacher\'s name and phone are shared with another Manara school; ask them or Manara support to change them.';

                return response()->json([
                    'status' => 'failed',
                    'message' => $message,
                    'data' => [($typedPhone !== '' ? 'phone' : 'name') => [$message]],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        DB::transaction(function () use ($request, $user, $classIds, $classes, $shared) {
            if (! $shared) {
                $user->update([
                    'name' => $request->validated('name'),
                    'phone' => $request->validated('phone') ?? '',
                ]);
            }

            // Sync group_staff for THIS school. group_staff is tenant-scoped, so
            // these reads/writes only ever touch the bound masjid's rows. $current
            // is the live classes only, so the row of a deleted class is never in
            // $toRemove: it stays, and is the teacher's again if the class is restored.
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
                $subjects = $request->subjectsFor((int) $group->id);

                $group->staff()->attach($user->id, [
                    'masjid_id' => $group->masjid_id,
                    'role' => GroupStaff::ROLE_TEACHER,
                    'subjects' => $subjects,
                    'assigned_at' => now(),
                ]);
            }

            // A class the teacher ALREADY leads keeps its row, and may have its
            // subjects changed — only when the request speaks about it, so a
            // client that never sends `class_subjects` leaves every existing
            // assignment exactly as it was.
            if ($request->has('class_subjects')) {
                foreach (array_intersect($classIds, $current) as $keptId) {
                    $subjects = $request->subjectsFor((int) $keptId);

                    GroupStaff::query()
                        ->where('user_id', $user->id)
                        ->where('group_id', $keptId)
                        // A query update skips the model's casts, so this path
                        // encodes for itself. attach() above goes through the
                        // pivot model (->using(GroupStaff)) and must NOT encode,
                        // or the list is stored twice and read back as a string.
                        ->update(['subjects' => $subjects === null ? null : json_encode(array_values($subjects))]);
                }
            }
        });

        $fresh = $user->fresh();

        return response()->json([
            'status' => 'success',
            'message' => 'Teacher updated.',
            // A shared teacher's stored name is this school's own typed value by
            // now (it had to equal it), so nothing foreign is echoed either way.
            'data' => $this->serialize((int) $fresh->id, (string) $fresh->name, (string) $fresh->email, $classes),
        ], Response::HTTP_OK);
    }

    private function updateWithClassSubjects(TeacherUpdateRequest $request, $masjid_id, $user_id)
    {
        $user = $this->resolveTeacher($user_id);
        $classIds = array_values(array_unique($request->validated('class_ids')));
        $classes = $this->resolveClassesInSchool($classIds);

        if ($classes === null) {
            return $this->foreignClassError();
        }

        $shared = $user->belongsOutside((int) $this->tenant->get());

        if ($shared) {
            $typedName = trim((string) $request->validated('name'));
            $typedPhone = trim((string) ($request->validated('phone') ?? ''));

            if ($typedName !== trim((string) $user->name) || $typedPhone !== '') {
                $message = 'This teacher\'s name and phone are shared with another Manara school; ask them or Manara support to change them.';

                return response()->json([
                    'status' => 'failed',
                    'message' => $message,
                    'data' => [($typedPhone !== '' ? 'phone' : 'name') => [$message]],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        GroupStaff::withOfficeSubjectChoice(fn () => DB::transaction(function () use ($request, $user, $classIds, $classes, $shared) {
            Masjid::whereKey($this->tenant->get())->lockForUpdate()->firstOrFail();
            if (! $shared) {
                $user->update([
                    'name' => $request->validated('name'),
                    'phone' => $request->validated('phone') ?? '',
                ]);
            }

            // Sync group_staff for THIS school. group_staff is tenant-scoped, so
            // these reads/writes only ever touch the bound masjid's rows. $current
            // is the live classes only, so the row of a deleted class is never in
            // $toRemove: it stays, and is the teacher's again if the class is restored.
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
                    'assigned_at' => now(),
                ] + $request->subjectAssignmentFields($group));
            }

            // Omitted retained IDs stay exactly as stored. No legacy translation on this branch.
            $idMap = $request->validated('class_subject_ids', []);
            foreach ($classes->whereIn('id', array_intersect($classIds, $current)) as $group) {
                if (! array_key_exists($group->id, $idMap)) continue;
                GroupStaff::where('user_id', $user->id)->where('group_id', $group->id)->firstOrFail()
                    ->update($request->subjectAssignmentFields($group));
            }
        }));

        $fresh = $user->fresh();

        return response()->json([
            'status' => 'success',
            'message' => 'Teacher updated.',
            // A shared teacher's stored name is this school's own typed value by
            // now (it had to equal it), so nothing foreign is echoed either way.
            'data' => $this->serialize((int) $fresh->id, (string) $fresh->name, (string) $fresh->email, $classes),
        ], Response::HTTP_OK);
    }

    /**
     * Remove a teacher from THIS school: drop their class assignments and their
     * membership — and nothing of any other school's.
     *
     * - If the removed row was their DEFAULT, the lowest remaining live school is
     *   promoted, so a person who still belongs somewhere never has memberships
     *   and no default (the picker and the login header read `is_default`).
     * - Sessions are left alone while another membership remains. A token names no
     *   organisation; the removed school is refused on the person's next request
     *   by the resolver ("route masjid is outside memberships"), and their other
     *   school keeps working without a sign-in.
     * - If that leaves them belonging to no organisation at all, the login is
     *   soft-deleted AND its tokens deleted: nothing revokes a trashed user's
     *   tokens for us (whether Sanctum rejects them is unverified), so we do.
     *
     * This NEVER consults `tenancy.multi_membership`. The rollback runbook for
     * that flag is "remove the extra memberships first", so the door that removes
     * them must work with the gate shut (MasjidAdminsController::revokeMembership
     * refuses then; this must not).
     */
    public function destroy($masjid_id, $user_id)
    {
        if ($this->subjectsOn()) {
            return $this->destroyWithClassSubjects($masjid_id, $user_id);
        }

        $user = $this->resolveTeacher($user_id);
        $masjidId = (int) $this->tenant->get();

        // An organisation's owner is not removed by an office screen; transferring
        // an organisation is its own act (TeamController::destroy says the same).
        // A same-type teacher cannot own one today, so this costs nothing and
        // keeps the door safe for mixed roles.
        if (Masjid::withoutGlobalScopes()->where('id', $masjidId)->where('user_id', $user->id)->exists()) {
            return response()->json([
                'status' => 'failed',
                'data' => ['user_id' => ['That account owns this organisation, so its access cannot be removed here.']],
            ], Response::HTTP_CONFLICT);
        }

        DB::transaction(function () use ($user, $masjidId) {
            // Serialise with a concurrent attach from another school, which
            // derives its own is_default under the same lock.
            User::withTrashed()->whereKey($user->id)->lockForUpdate()->first();

            // Tenant-scoped: only this school's assignments.
            GroupStaff::query()->where('user_id', $user->id)->delete();

            $membership = MasjidUser::where('masjid_id', $masjidId)->where('user_id', $user->id)->first();
            $wasDefault = (bool) $membership?->is_default;
            $membership?->delete();

            if (! MasjidUser::where('user_id', $user->id)->exists()) {
                // Belongs to no organisation any more -> retire the login.
                $user->tokens()->delete();
                $user->delete();

                return;
            }

            if ($wasDefault) {
                // Same deterministic tie-break as the resolver and the login
                // payload: the lowest live masjid_id.
                MasjidUser::where('user_id', $user->id)
                    ->whereHas('masjid')
                    ->orderBy('masjid_id')
                    ->first()
                    ?->update(['is_default' => true]);
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Teacher removed from this school.',
        ], Response::HTTP_OK);
    }

    private function destroyWithClassSubjects($masjid_id, $user_id)
    {
        $user = $this->resolveTeacher($user_id);
        $masjidId = (int) $this->tenant->get();

        // An organisation's owner is not removed by an office screen; transferring
        // an organisation is its own act (TeamController::destroy says the same).
        // A same-type teacher cannot own one today, so this costs nothing and
        // keeps the door safe for mixed roles.
        if (Masjid::withoutGlobalScopes()->where('id', $masjidId)->where('user_id', $user->id)->exists()) {
            return response()->json([
                'status' => 'failed',
                'data' => ['user_id' => ['That account owns this organisation, so its access cannot be removed here.']],
            ], Response::HTTP_CONFLICT);
        }

        DB::transaction(function () use ($user, $masjidId) {
            // Serialise with a concurrent attach from another school, which
            // derives its own is_default under the same lock.
            Masjid::whereKey($masjidId)->lockForUpdate()->firstOrFail();
            User::withTrashed()->whereKey($user->id)->lockForUpdate()->first();

            // Tenant-scoped: only this school's assignments.
            GroupStaff::query()->where('user_id', $user->id)->delete();

            $membership = MasjidUser::where('masjid_id', $masjidId)->where('user_id', $user->id)->first();
            $wasDefault = (bool) $membership?->is_default;
            $membership?->delete();

            if (! MasjidUser::where('user_id', $user->id)->exists()) {
                // Belongs to no organisation any more -> retire the login.
                $user->tokens()->delete();
                $user->delete();

                return;
            }

            if ($wasDefault) {
                // Same deterministic tie-break as the resolver and the login
                // payload: the lowest live masjid_id.
                MasjidUser::where('user_id', $user->id)
                    ->whereHas('masjid')
                    ->orderBy('masjid_id')
                    ->first()
                    ?->update(['is_default' => true]);
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Teacher removed from this school.',
        ], Response::HTTP_OK);
    }

    /**
     * Re-send the set-your-own-password invite — the "they never got it" path.
     *
     * Refused for a teacher who belongs to another school. The invite is an
     * "account created" link, and completing it overwrites the password and
     * deletes EVERY token (AccountAccessService::reset): school B pressing this
     * would sign the person out of school A, and even minting the link deletes
     * any Forgot-password link they were waiting on. They already have a login;
     * "Forgot password" is theirs to use.
     */
    public function invite($masjid_id, $user_id, AccountAccessService $access)
    {
        $user = $this->resolveTeacher($user_id);

        if ($user->belongsOutside((int) $this->tenant->get())) {
            return response()->json([
                'status' => 'error',
                'message' => 'They already have a Manara login. They can use "Forgot password" on the sign-in page.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $orgName = optional(Masjid::withoutGlobalScopes()->find((int) $this->tenant->get()))->name;

        if (! $access->invite($user, $orgName)) {
            return response()->json([
                'status' => 'error',
                'message' => 'That teacher has no email address, so there is nowhere to send an invitation.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'status' => 'success',
            // The lifetime comes from the same config the link is minted with, so
            // the sentence cannot go stale again (it said "an hour" for a
            // seven-day link).
            'message' => 'An invitation is on its way to '.$user->email.'. The link works for '
                .\App\Mail\AccountAccessMail::inWords((int) config('auth.passwords.invites.expire', 60 * 24 * 7)).'.',
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

    /**
     * This teacher's staff rows for the LIVE classes of the bound school.
     *
     * Deleting a class is a soft delete that keeps its staff rows on purpose
     * (the group_staff migration says so), and the form lists live classes only.
     * Reading a deleted class's row out to the form made the screen send back an
     * id it had no checkbox for, and update() refused it: the teacher could not be
     * saved at all. So what the form is given and what a save syncs against are
     * this ONE set. Group is tenant-scoped and soft-deleting, so the subquery is
     * "live, in this school".
     */
    private function liveAssignments(User $user)
    {
        if ($this->subjectsOn()) {
            return $this->liveAssignmentsWithClassSubjects($user);
        }

        return GroupStaff::query()
            ->where('user_id', $user->id)
            ->whereIn('group_id', Group::query()->select('id'))
            ->get(['group_id', 'subjects']);
    }

    private function liveAssignmentsWithClassSubjects(User $user)
    {
        return GroupStaff::query()
            ->where('user_id', $user->id)
            ->whereIn('group_id', Group::query()->select('id'))
            ->get(['group_id', 'subjects', 'class_subject_ids', 'class_subjects_mapped_at', 'class_subject_ids_edited_at']);
    }

    /** The ids of the LIVE classes this teacher leads in the bound school. */
    private function ledClassIds(User $user): array
    {
        return $this->liveAssignments($user)
            ->map(fn (GroupStaff $r): int => (int) $r->group_id)
            ->values()
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

    /**
     * The reply to add and edit. Name and email are passed IN — what this school
     * typed — rather than read off the stored row, so a shared teacher's stored
     * data never rides back in a response (see store()).
     */
    private function serialize(int $userId, string $name, string $email, $classes): array
    {
        if ($this->subjectsOn()) {
            return $this->serializeWithClassSubjects($userId, $name, $email, $classes);
        }

        // What was actually stored, read back — not what the request asked for,
        // so the screen shows the assignment as it now is. group_staff is
        // tenant-scoped: these are THIS school's rows.
        $subjects = GroupStaff::query()
            ->where('user_id', $userId)
            ->get(['group_id', 'subjects'])
            ->mapWithKeys(fn (GroupStaff $r) => [(int) $r->group_id => $r->subjects ?: null]);

        return [
            'id' => $userId,
            'name' => $name,
            'email' => $email,
            'classes' => $classes->map(fn (Group $g) => [
                'id' => (int) $g->id,
                'name' => $g->name,
                'subjects' => $subjects->get((int) $g->id),
            ])->values(),
        ];
    }

    private function serializeWithClassSubjects(int $userId, string $name, string $email, $classes): array
    {
        // What was actually stored, read back — not what the request asked for,
        // so the screen shows the assignment as it now is. group_staff is
        // tenant-scoped: these are THIS school's rows.
        $subjectsOn = $this->subjectsOn();
        $rows = GroupStaff::query()
            ->where('user_id', $userId)
            ->get(['group_id', 'subjects', 'class_subject_ids', 'class_subjects_mapped_at', 'class_subject_ids_edited_at'])
            ->keyBy('group_id');

        return [
            'id' => $userId,
            'name' => $name,
            'email' => $email,
            'classes' => $classes->map(fn (Group $g) => [
                'id' => (int) $g->id,
                'name' => $g->name,
                'subjects' => $rows->get((int) $g->id)?->subjects ?: null,
            ] + ($subjectsOn ? self::subjectIdFields($rows->get((int) $g->id)) : []))->values(),
        ];
    }

    private static function subjectIdFields(?GroupStaff $row): array
    {
        return ['class_subject_ids' => $row === null || ($row->class_subjects_mapped_at === null && $row->class_subject_ids_edited_at === null) ? [] : $row->class_subject_ids];
    }

    private function subjectsOn(): bool
    {
        return \App\Support\ClassSubjectMode::enabled($this->tenant->get());
    }

    private function assignmentMap($rows): array
    {
        return ['class_subject_ids' => $rows->mapWithKeys(fn ($r) => [(int) $r->group_id => self::subjectIdFields($r)['class_subject_ids']])];
    }

}
