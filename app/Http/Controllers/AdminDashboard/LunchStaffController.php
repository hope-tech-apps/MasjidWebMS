<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LunchStaff\StoreLunchStaffRequest;
use App\Http\Requests\Admin\LunchStaff\UpdateLunchStaffRequest;
use App\Mail\StaffAddedToOrganisation;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Services\Auth\AccountAccessService;
use App\Support\ContactIdentity;
use App\Support\Errors;
use App\Support\OrganisationAccess;
use App\Support\StaffArea;
use App\Support\TenantContext;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The masjid onboarding someone to run the Jummah lunch, and nothing else.
 *
 * Mounted inside the masjid-scoped admin group (`admin` + `tenant`), so BOTH a
 * SuperAdmin (via the route masjid) and the masjid's own MasjidAdmin (bound to
 * theirs) can run it — which is exactly the control this was asked for.
 *
 * ## Why no `permission:` gate
 *
 * It sits beside the Jummah-lunch routes, OUTSIDE the `crm` group, and carries
 * no permission for the same reason they don't: a masjid running a food sale
 * must not first have to switch on the member directory. `admin` already means
 * MasjidAdmin-or-SuperAdmin, which is the whole audience for this endpoint, and
 * minting a permission would break the `Permission::count() === 8` invariant
 * four tests pin.
 *
 * ## The escalation this deliberately cannot do
 *
 * `type` is NEVER read from the request. Every login created here is
 * User::TYPE_LUNCH_STAFF, and its entire reach is the `masjid_user` row written
 * in the same transaction — bound to the BOUND tenant, not to any id in the
 * body. So a MasjidAdmin holding this endpoint cannot mint an admin, cannot mint
 * a login at another masjid, and cannot widen one they already made: `update()`
 * touches name and phone only, and `resolve()` refuses any user that is not
 * already lunch staff of this masjid. Ownership (`masjids.user_id`) is never
 * touched.
 *
 * ## One login, a role at each organisation
 *
 * While App\Support\StaffArea::open() is true, store() is CREATE-OR-ATTACH for
 * exactly one kind of existing login: a live TEACHER. A person who teaches at a
 * school may be given Friday lunch access here with the login and the password
 * they already have. The attach writes a `lunch-staff` row at THIS organisation
 * and nothing else: `users` is not written (the login's type stays Teacher; the
 * ROW is what makes it lunch staff here, MasjidUser::kindFor), no password link is
 * sent, and the person is told with the "added" notice. Every other address that
 * has a login is refused as it always was. With the switch off, store() is the
 * create it always was, statement for statement.
 *
 * Who counts as "lunch staff of this masjid" is therefore decided by the row here
 * and not by `users.type`: a teacher given lunch access here is listed, edited and
 * removed on this screen, and a lunch login that a school made a teacher is NOT
 * lunch staff of that school.
 *
 * $masjid_id is route-shape; the authoritative masjid is the BOUND tenant.
 */
class LunchStaffController extends Controller
{
    public function __construct(private TenantContext $tenant)
    {
    }

    /** This masjid's lunch logins. */
    public function index($masjid_id)
    {
        $masjidId = (int) $this->tenant->get();

        $rows = MasjidUser::where('masjid_id', $masjidId)->get(['user_id', 'role'])->keyBy('user_id');

        $staff = User::whereIn('id', $rows->keys())
            ->whereIn('type', OrganisationAccess::SCOPED_TYPES)
            ->orderBy('name')
            ->get()
            ->filter(fn (User $u): bool => $rows->get($u->id)?->kindFor($u->type) === User::TYPE_LUNCH_STAFF)
            ->map(fn (User $u) => $this->serialize($u))
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => $staff,
        ], Response::HTTP_OK);
    }

    public function store(StoreLunchStaffRequest $request, $masjid_id, AccountAccessService $access)
    {
        if (StaffArea::open()) {
            return $this->createOrAttachLogin($request, $access);
        }

        $masjidId = (int) $this->tenant->get();
        $user = null;

        try {
            DB::transaction(function () use ($request, $masjidId, &$user) {
                $user = User::create([
                    'name' => $request->validated('name'),
                    'email' => $request->validated('email'),
                    // users.phone is NOT NULL; an empty string is a real "none on file".
                    'phone' => $request->validated('phone') ?? '',
                    // NOT from the request. See the class docblock.
                    'type' => User::TYPE_LUNCH_STAFF,
                    // A random password nobody holds — they set their own from
                    // the invite. Never a default or a shared one.
                    'password' => Str::password(40),
                ]);

                // Their one masjid. This membership is the whole of their reach:
                // ResolveMasjidTenant binds LunchStaff from here, never from a
                // URL, so there is no id for them to change.
                MasjidUser::create([
                    'masjid_id' => $masjidId,
                    'user_id' => $user->id,
                    'role' => 'lunch-staff',
                    'is_default' => true,
                ]);
            });
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Only after the row is committed — a rolled-back transaction must not
        // send an invite to a user that does not exist.
        $orgName = optional(Masjid::withoutGlobalScopes()->find($masjidId))->name;
        $sent = $access->invite($user, $orgName);

        return response()->json([
            'status' => 'success',
            'message' => $sent
                ? 'Lunch access created. An invitation to set their password is on its way to ' . $user->email . '.'
                : 'Lunch access created, but no invitation could be sent.',
            'data' => $this->serialize($user),
        ], Response::HTTP_CREATED);
    }

    /**
     * store() while one login may hold a role at each organisation: create the
     * login, or give a live teacher's existing login lunch access here.
     *
     * The procedure is TeachersController::createOrAttach's, for the same reasons
     * (they are written out there): the address is looked up outside the
     * transaction and without a lock, only the row whose address IS the typed one
     * counts, that row is locked by key before anything is decided, and the whole
     * decision runs once more if two requests raced to the same insert.
     *
     * Every success answers with ONE sentence and with data built from what the
     * office typed. The stored row is never read back into the reply: a name or a
     * phone a school entered must not reach this organisation through the answer
     * to "add", and neither may the fact that the address already had a login.
     */
    private function createOrAttachLogin(StoreLunchStaffRequest $request, AccountAccessService $access)
    {
        $masjidId = (int) $this->tenant->get();

        try {
            try {
                $outcome = $this->createOrAttach($request, $masjidId);
            } catch (QueryException|DeadlockException $e) {
                if (! ($e instanceof UniqueConstraintViolationException) && ! self::isDeadlock($e)) {
                    throw $e;
                }

                $outcome = $this->createOrAttach($request, $masjidId);
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        if (isset($outcome['refusal'])) {
            return response()->json([
                'status' => 'failed',
                // Under `email`, where the form shows it, in the shape validation uses.
                'data' => ['email' => [$outcome['refusal']]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var User $user */
        $user = $outcome['user'];
        $email = (string) $request->validated('email');

        // Only after the rows are committed.
        $org = Masjid::withoutGlobalScopes()->find($masjidId);

        if ($outcome['mail'] === 'invite') {
            $sent = $access->invite($user, $org?->name);
        } else {
            $this->sendAddedNotice($user, $org);
            $sent = true;
        }

        return response()->json([
            'status' => 'success',
            'message' => $sent
                ? 'Lunch access added for ' . $email . '. They have been emailed how to sign in.'
                : 'Lunch access added, but no email could be sent.',
            'data' => [
                'id' => (int) $user->id,
                'name' => (string) $request->validated('name'),
                'email' => $email,
                'phone' => $request->validated('phone') ?: null,
                'invited' => true,
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * The branch table. Returns `['user' => User, 'mail' => 'invite'|'added']` or
     * `['refusal' => string]`.
     *
     *   no such login           create it as lunch staff, with its row here; invite
     *   live Teacher            attach: a `lunch-staff` row here and nothing else,
     *                           unless one of the three lines below applies
     *     has a row here        422: one login holds one role at an organisation
     *     has lunch somewhere   422 "taken": a login runs lunch at ONE organisation
     *                           (the lunch shell reads one, from `user.masjid`)
     *     gate shut, belongs    422: the same switch that stops a teacher being
     *     somewhere             added to a second school (tenancy.multi_membership)
     *   anything else           422 "taken", which validation already answered for
     *                           every such address; this is the same answer for a
     *                           login that changed between validation and the lock
     *
     * @return array{user?: User, mail?: string, refusal?: string}
     */
    private function createOrAttach(StoreLunchStaffRequest $request, int $masjidId): array
    {
        $email = (string) $request->validated('email');

        // Candidates, then the one whose address is the typed one (the collation
        // also matches accents: see User::scopeWhereEmailIs).
        $candidates = User::withTrashed()->whereEmailIs($email)->get(['id', 'email']);
        $foundId = $candidates
            ->first(fn (User $candidate) => ContactIdentity::sameAddress($candidate->email, $email))
            ?->id;

        if ($foundId === null && $candidates->isNotEmpty()) {
            // A look-alike of somebody's address. Creating a login at it would
            // collide with theirs on the unique index.
            return ['refusal' => self::taken()];
        }

        return DB::transaction(function () use ($request, $masjidId, $email, $foundId) {
            $existing = $foundId === null
                ? null
                : User::withTrashed()->whereKey($foundId)->lockForUpdate()->first();

            if ($existing === null) {
                $user = User::create([
                    'name' => $request->validated('name'),
                    'email' => $request->validated('email'),
                    // users.phone is NOT NULL; an empty string is a real "none on file".
                    'phone' => $request->validated('phone') ?? '',
                    // NOT from the request. See the class docblock.
                    'type' => User::TYPE_LUNCH_STAFF,
                    // A random password nobody holds: they set their own from the invite.
                    'password' => Str::password(40),
                ]);

                $this->grantLunchAccess($user, $masjidId);

                return ['user' => $user, 'mail' => 'invite'];
            }

            // Validation looked past a live Teacher and nobody else. Read again
            // under the lock, because the row may not be what it was a moment ago.
            if ($existing->trashed() || $existing->type !== 'Teacher') {
                return ['refusal' => self::taken()];
            }

            $rows = MasjidUser::where('user_id', $existing->id)->get();

            if ($rows->contains(fn (MasjidUser $row): bool => $row->kindFor($existing->type) === User::TYPE_LUNCH_STAFF)) {
                return ['refusal' => self::taken()];
            }

            // A teacher of THIS organisation (a row here, or a class here: group_staff
            // is tenant-scoped, as TeachersController reads it).
            if ($rows->contains('masjid_id', $masjidId) || GroupStaff::query()->where('user_id', $existing->id)->exists()) {
                return ['refusal' => $email . ' is already a teacher at this organisation. One login can hold one role at an organisation.'];
            }

            // The switch for NEW cross-organisation grants, with the meaning it has
            // at the Teachers door: a login that belongs nowhere needs no gate.
            if (! (bool) config('tenancy.multi_membership', false) && $rows->isNotEmpty()) {
                return ['refusal' => 'Adding an existing login to a second organisation is switched off.'];
            }

            // ATTACH. `users` is not written: not name, phone, password, tokens, type.
            $this->grantLunchAccess($existing, $masjidId);

            return ['user' => $existing, 'mail' => 'added'];
        });
    }

    /**
     * The lunch row. `is_default` is DERIVED under the lock on the user row, never
     * asserted: the database allows one default per user, and a teacher being
     * attached already has theirs. True for a brand-new login, which is the value
     * this door always wrote.
     */
    private function grantLunchAccess(User $user, int $masjidId): void
    {
        MasjidUser::create([
            'masjid_id' => $masjidId,
            'user_id' => $user->id,
            // What this login IS at this organisation (MasjidUser::kindFor).
            'role' => 'lunch-staff',
            'is_default' => ! MasjidUser::where('user_id', $user->id)->where('is_default', true)->exists(),
        ]);
    }

    /**
     * What validation says for an address that has a login, from the same
     * translation line, so that the controller's refusals of such an address cannot
     * be told from validation's.
     */
    private static function taken(): string
    {
        return (string) trans('validation.unique', ['attribute' => 'email']);
    }

    /**
     * "{organisation} added you as a member of the Friday lunch team": to a login
     * that exists, with no password link. A notice, not part of the write: a mail
     * transport failing after the commit must not turn a completed attach into a
     * 500 the office would retry.
     */
    private function sendAddedNotice(User $user, ?Masjid $org): void
    {
        try {
            Mail::to($user->email)->send(new StaffAddedToOrganisation(
                $user,
                (string) ($org?->name ?: config('app.name')),
                'member of the Friday lunch team',
                [],
                $org?->email ?: null,
                StaffArea::isMixed($user),
            ));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** InnoDB's "you were the deadlock victim" (see TeachersController::isDeadlock). */
    private static function isDeadlock(\Throwable $e): bool
    {
        return $e instanceof DeadlockException
            || (string) $e->getCode() === '40001'
            || str_contains($e->getMessage(), 'Deadlock found');
    }

    /**
     * Name and phone only — nothing here can widen what the login reaches.
     *
     * A person who also belongs to another organisation is SHARED: the `users` row
     * is one record every organisation holds, so renaming or re-phoning them here
     * would rewrite a school's record of its teacher. For a shared person nothing
     * is written; the name must be the one on file and no phone may be typed, as on
     * the Teachers screen.
     */
    public function update(UpdateLunchStaffRequest $request, $masjid_id, $user_id)
    {
        $user = $this->resolve($user_id);

        if ($user->belongsOutside((int) $this->tenant->get())) {
            $typedName = trim((string) $request->validated('name'));
            $typedPhone = trim((string) ($request->validated('phone') ?? ''));

            if ($typedName !== trim((string) $user->name) || $typedPhone !== '') {
                $message = 'This person\'s name and phone are shared with another Manara organisation; ask them or Manara support to change them.';

                return response()->json([
                    'status' => 'failed',
                    'message' => $message,
                    'data' => [($typedPhone !== '' ? 'phone' : 'name') => [$message]],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return response()->json([
                'status' => 'success',
                'data' => $this->serialize($user),
            ], Response::HTTP_OK);
        }

        $user->name = $request->validated('name');
        $user->phone = $request->validated('phone') ?? '';
        $user->save();

        return response()->json([
            'status' => 'success',
            'data' => $this->serialize($user),
        ], Response::HTTP_OK);
    }

    /**
     * Re-send the set-your-own-password invite ("they never got it").
     *
     * Refused for a person who belongs to another organisation, as on the Teachers
     * screen: completing the link overwrites the password and deletes EVERY token
     * (AccountAccessService::reset), which would sign a teacher out of their school,
     * and merely minting it deletes a Forgot-password link they were waiting on.
     */
    public function invite($masjid_id, $user_id, AccountAccessService $access)
    {
        $user = $this->resolve($user_id);

        if ($user->belongsOutside((int) $this->tenant->get())) {
            return response()->json([
                'status' => 'failed',
                'message' => 'They already have a Manara login. They can use "Forgot password" on the sign-in page.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $orgName = optional(Masjid::withoutGlobalScopes()->find((int) $this->tenant->get()))->name;

        $sent = $access->invite($user, $orgName);

        return response()->json([
            'status' => $sent ? 'success' : 'failed',
            'message' => $sent
                ? 'Invitation re-sent to ' . $user->email . '.'
                : 'No invitation could be sent.',
        ], $sent ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Remove the login from this masjid, and from nothing else.
     *
     * A login that belonged ONLY here (every lunch login there was before one login
     * could hold two roles) ends as it always did: the row goes, every token is
     * deleted so a session already sitting in a browser stops existing rather than
     * merely being refused, and the login is retired.
     *
     * A person who still belongs to another organisation keeps their sessions: a
     * token names no organisation, so deleting it would sign a teacher out of their
     * school because a masjid took their lunch access away. The lunch area ends for
     * them all the same, on their very next request: the tenant resolver binds an
     * organisation only through a row, and this one is gone. If the row removed was
     * their default, the lowest live organisation left is promoted, as the Teachers
     * door does.
     */
    public function destroy($masjid_id, $user_id)
    {
        $user = $this->resolve($user_id);
        $masjidId = (int) $this->tenant->get();

        DB::transaction(function () use ($user, $masjidId) {
            // Serialise with a door at another organisation attaching or removing
            // the same person, which derives is_default under this lock.
            User::withTrashed()->whereKey($user->id)->lockForUpdate()->first();

            $wasDefault = (bool) MasjidUser::where('masjid_id', $masjidId)->where('user_id', $user->id)->value('is_default');

            MasjidUser::where('masjid_id', $masjidId)->where('user_id', $user->id)->delete();

            // Belongs to no organisation any more -> retire the login.
            if (! MasjidUser::where('user_id', $user->id)->exists()) {
                $user->tokens()->delete();
                $user->delete();

                return;
            }

            if ($wasDefault) {
                MasjidUser::where('user_id', $user->id)
                    ->whereHas('masjid')
                    ->orderBy('masjid_id')
                    ->first()
                    ?->update(['is_default' => true]);
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Lunch access removed.',
        ], Response::HTTP_OK);
    }

    /**
     * The user, if they really are lunch staff OF THE BOUND MASJID.
     *
     * Both halves matter. Without the kind check this endpoint would edit or
     * delete a MasjidAdmin by id; without the membership check it would reach
     * another masjid's volunteer. A miss is a 404, not a 403 — an admin has no
     * business learning that some other id exists.
     *
     * "Lunch staff here" is what the ROW here says (MasjidUser::kindFor), for a
     * login of either scoped kind: a teacher given lunch access here is lunch staff
     * of this masjid, and a lunch login whose row here is a teacher's is not. An
     * administrator is never resolved, whatever their row says.
     */
    private function resolve($userId): User
    {
        $masjidId = (int) $this->tenant->get();

        $row = MasjidUser::where('masjid_id', $masjidId)
            ->where('user_id', $userId)
            ->first();

        abort_unless($row !== null, Response::HTTP_NOT_FOUND);

        $user = User::where('id', $userId)
            ->whereIn('type', OrganisationAccess::SCOPED_TYPES)
            ->firstOrFail();

        abort_unless($row->kindFor($user->type) === User::TYPE_LUNCH_STAFF, Response::HTTP_NOT_FOUND);

        return $user;
    }

    private function serialize(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone ?: null,
            // Created but never signed in — the office's "did they get it?".
            'invited' => $user->email_verified_at === null,
        ];
    }
}
