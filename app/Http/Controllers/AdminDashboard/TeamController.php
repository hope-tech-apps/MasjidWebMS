<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Team\StoreTeamMemberRequest;
use App\Http\Requests\Admin\Team\UpdateTeamMemberRequest;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Services\Auth\AccountAccessService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Layer 2 of the access model: WHO in an organisation can do WHAT.
 *
 * Layer 1 (config/capabilities.php) is what the organisation HAS. This is every
 * staff login it has, whichever door created it, on one screen — and one place
 * to add or remove them. The access levels are the realms that already exist;
 * this controller adds no new kind of login:
 *
 *   admin         users.type MasjidAdmin + masjid_user 'masjid-admin'.
 *                 Everything the organisation has. Owner or member.
 *   jummah_lunch  users.type LunchStaff + masjid_user 'lunch-staff'.
 *                 The Friday lunch board and nothing else (routes/lunch.php).
 *                 Only offered while the organisation has `jummah_lunch`.
 *   teacher       users.type Teacher + group_staff: their own classes only.
 *                 LISTED here, but created, edited and removed on the Teachers
 *                 screen, because a teacher is defined by the classes they lead.
 *
 * Deliberately OUTSIDE the `crm` group: running an organisation's team is not a
 * CRM feature. The administrators endpoints sit inside `crm`, so an
 * organisation without the member directory (IntelliCor today) could not add a
 * second administrator at all; lunch staff provisioning already sat outside it
 * for the same reason.
 *
 * `type` is never read from the request. The access level selects one of two
 * fixed types, the login is bound to the BOUND tenant, and nothing here can
 * create a SuperAdmin, reach another organisation, or change an existing
 * login's type. Any administrator of the organisation may use it — the same
 * people who could already add administrators and lunch staff.
 */
class TeamController extends Controller
{
    public const ACCESS_ADMIN = 'admin';
    public const ACCESS_LUNCH = 'jummah_lunch';
    public const ACCESS_TEACHER = 'teacher';

    /** The levels POST .../team can create. Teachers come from their own screen. */
    public const CREATABLE_ACCESS = [self::ACCESS_ADMIN, self::ACCESS_LUNCH];

    private const TYPE_FOR_ACCESS = [
        self::ACCESS_ADMIN => 'MasjidAdmin',
        self::ACCESS_LUNCH => User::TYPE_LUNCH_STAFF,
    ];

    // Mirrors User::TYPE_ROLE_MAP, like the other staff doors.
    private const ROLE_FOR_ACCESS = [
        self::ACCESS_ADMIN => 'masjid-admin',
        self::ACCESS_LUNCH => 'lunch-staff',
    ];

    private const STAFF_TYPES = ['MasjidAdmin', User::TYPE_LUNCH_STAFF, 'Teacher'];

    private const ORDER = [self::ACCESS_ADMIN => 0, self::ACCESS_LUNCH => 1, self::ACCESS_TEACHER => 2];

    public function __construct(private TenantContext $tenant)
    {
    }

    /** GET .../team — everyone who can sign in to this organisation, and what it has. */
    public function index(Request $request, $masjid_id)
    {
        $masjid = $this->boundMasjid();

        $ids = MasjidUser::where('masjid_id', $masjid->id)->pluck('user_id')
            ->push($masjid->user_id)
            ->filter()
            ->unique();

        $people = User::whereIn('id', $ids)
            ->whereIn('type', self::STAFF_TYPES)
            ->get()
            ->map(fn (User $u) => $this->serialize($u, $masjid, $request->user()))
            ->sort(fn (array $a, array $b) => [! $a['is_owner'], self::ORDER[$a['access']], mb_strtolower($a['name'])]
                <=> [! $b['is_owner'], self::ORDER[$b['access']], mb_strtolower($b['name'])])
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => [
                'people' => $people,
                'capabilities' => $this->capabilityList($masjid),
                'can_add' => $this->creatableFor($masjid),
            ],
        ], Response::HTTP_OK);
    }

    /** POST .../team — add an administrator or a lunch-only login. */
    public function store(StoreTeamMemberRequest $request, $masjid_id, AccountAccessService $access)
    {
        $masjid = $this->boundMasjid();
        $level = $request->validated('access');

        if (! in_array($level, $this->creatableFor($masjid), true)) {
            return response()->json([
                'status' => 'failed',
                'data' => ['access' => ['Friday lunch ordering is not switched on for this organisation.']],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = null;

        DB::transaction(function () use ($request, $masjid, $level, &$user) {
            $user = User::create([
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                // users.phone is NOT NULL; an empty string is a real "none on file".
                'phone' => $request->validated('phone') ?? '',
                // From the access level, never from the request.
                'type' => self::TYPE_FOR_ACCESS[$level],
                // Nobody's password: they set their own from the invite.
                'password' => Str::password(40),
            ]);

            MasjidUser::create([
                'masjid_id' => $masjid->id,
                'user_id' => $user->id,
                'role' => self::ROLE_FOR_ACCESS[$level],
                // Safe: a brand-new user holds no other membership.
                'is_default' => true,
            ]);
        });

        // Only after the commit: a rolled-back transaction must not email an
        // invitation to a login that does not exist.
        $sent = $access->invite($user, $masjid->name);

        return response()->json([
            'status' => 'success',
            'message' => $sent
                ? 'Added. ' . $user->email . ' has been emailed a link to set their own password.'
                : 'Added, but no invitation could be sent — check the address.',
            'data' => $this->serialize($user->fresh(), $masjid, $request->user()),
        ], Response::HTTP_CREATED);
    }

    /**
     * PATCH .../team/{user_id} — switch someone between Administrator and
     * Friday lunch only.
     *
     * users.type IS the realm boundary, so it moves together with the
     * membership role in one transaction, and the person's sessions end: a
     * token minted for the lunch board must not stay live as an admin session,
     * or the other way round. Refused for the owner (always an administrator),
     * for yourself, for teachers (defined by their classes), for a login that
     * belongs to more than one organisation (its type is global, so changing it
     * here would change it everywhere), and for lunch access where the
     * organisation has no lunch.
     */
    public function update(UpdateTeamMemberRequest $request, $masjid_id, $user_id)
    {
        $masjid = $this->boundMasjid();
        $user = $this->member($masjid, $user_id);
        $level = $request->validated('access');

        if ((int) $user->id === (int) $masjid->user_id) {
            return $this->refuse('The owner is always an administrator.', Response::HTTP_CONFLICT);
        }

        if ((int) $user->id === (int) $request->user()?->id) {
            return $this->refuse('You cannot change your own access. Ask another administrator.', Response::HTTP_CONFLICT);
        }

        if ($user->type === 'Teacher') {
            return $this->refuse('Teachers are managed on the Teachers screen, with their classes.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $elsewhere = MasjidUser::where('user_id', $user->id)->where('masjid_id', '!=', $masjid->id)->exists()
            || Masjid::withoutGlobalScopes()->where('user_id', $user->id)->where('id', '!=', $masjid->id)->exists();

        if ($elsewhere) {
            return $this->refuse('This login also belongs to another organisation, and its access applies to both. Change it with Manara.', Response::HTTP_CONFLICT);
        }

        if (! in_array($level, $this->creatableFor($masjid), true)) {
            return response()->json([
                'status' => 'failed',
                'data' => ['access' => ['Friday lunch ordering is not switched on for this organisation.']],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($user->type !== self::TYPE_FOR_ACCESS[$level]) {
            DB::transaction(function () use ($user, $masjid, $level) {
                $user->forceFill(['type' => self::TYPE_FOR_ACCESS[$level]])->save();

                MasjidUser::where('masjid_id', $masjid->id)->where('user_id', $user->id)
                    ->update(['role' => self::ROLE_FOR_ACCESS[$level]]);

                $user->tokens()->delete();
            });
        }

        $label = $level === self::ACCESS_ADMIN ? 'an Administrator' : 'Friday lunch only';

        return response()->json([
            'status' => 'success',
            'message' => $user->name . ' is now ' . $label . '. They have been signed out and will sign in again with their new access.',
            'data' => $this->serialize($user->fresh(), $masjid, $request->user()),
        ], Response::HTTP_OK);
    }

    /** POST .../team/{user_id}/invite — send the set-your-password link again. */
    public function invite(Request $request, $masjid_id, $user_id, AccountAccessService $access)
    {
        $masjid = $this->boundMasjid();
        $user = $this->member($masjid, $user_id);

        $sent = $access->invite($user, $masjid->name);

        return response()->json([
            'status' => $sent ? 'success' : 'failed',
            'message' => $sent
                ? 'Invitation sent again to ' . $user->email . '. The link works for 60 minutes.'
                : 'No invitation could be sent.',
        ], $sent ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * DELETE .../team/{user_id} — take this person's access to the organisation away.
     *
     * Refused for the owner (transferring an organisation is its own act, and
     * the ownership fallback would keep binding them anyway — a removal that
     * did not happen), for yourself (an office must not be able to lock itself
     * out by accident), and for teachers (the Teachers screen owns their
     * classes). Sessions die first; a login left belonging to no organisation
     * and owning none is retired, as the lunch-staff door already did.
     */
    public function destroy(Request $request, $masjid_id, $user_id)
    {
        $masjid = $this->boundMasjid();
        $user = $this->member($masjid, $user_id);

        if ((int) $user->id === (int) $masjid->user_id) {
            return $this->refuse('That account owns this organisation, so its access cannot be removed here.', Response::HTTP_CONFLICT);
        }

        if ((int) $user->id === (int) $request->user()?->id) {
            return $this->refuse('You cannot remove your own access. Ask another administrator.', Response::HTTP_CONFLICT);
        }

        if ($user->type === 'Teacher') {
            return $this->refuse('Teachers are removed on the Teachers screen, with their classes.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        DB::transaction(function () use ($user, $masjid) {
            $user->tokens()->delete();

            MasjidUser::where('masjid_id', $masjid->id)->where('user_id', $user->id)->delete();

            $stillBelongs = MasjidUser::where('user_id', $user->id)->exists()
                || Masjid::withoutGlobalScopes()->where('user_id', $user->id)->exists();

            if (! $stillBelongs) {
                $user->delete();
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Removed. They can no longer sign in to ' . $masjid->name . '.',
        ], Response::HTTP_OK);
    }

    private function boundMasjid(): Masjid
    {
        return Masjid::withoutGlobalScopes()->findOrFail((int) $this->tenant->get());
    }

    /**
     * A staff login of THIS organisation — by membership or ownership — or 404.
     * A 404 rather than a 403: an administrator has no business learning which
     * ids exist elsewhere.
     */
    private function member(Masjid $masjid, $userId): User
    {
        $belongs = (int) $userId === (int) $masjid->user_id
            || MasjidUser::where('masjid_id', $masjid->id)->where('user_id', $userId)->exists();

        abort_unless($belongs, Response::HTTP_NOT_FOUND);

        return User::where('id', $userId)->whereIn('type', self::STAFF_TYPES)->firstOrFail();
    }

    /** @return string[] access levels this organisation can add right now */
    private function creatableFor(Masjid $masjid): array
    {
        return array_values(array_filter(self::CREATABLE_ACCESS, fn (string $level) =>
            $level !== self::ACCESS_LUNCH || $masjid->hasCapability('jummah_lunch')));
    }

    private function capabilityList(Masjid $masjid): array
    {
        $out = [];

        foreach (config('capabilities', []) as $key => $definition) {
            $out[] = [
                'key' => $key,
                'label' => $definition['label'] ?? $key,
                'description' => $definition['description'] ?? '',
                'enabled' => $masjid->hasCapability($key),
            ];
        }

        return $out;
    }

    private function serialize(User $user, Masjid $masjid, ?User $viewer): array
    {
        $access = match ($user->type) {
            'MasjidAdmin' => self::ACCESS_ADMIN,
            User::TYPE_LUNCH_STAFF => self::ACCESS_LUNCH,
            default => self::ACCESS_TEACHER,
        };

        $isOwner = (int) $user->id === (int) $masjid->user_id;
        $isYou = $viewer !== null && (int) $viewer->id === (int) $user->id;

        return [
            'user_id' => (int) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone ?: null,
            'access' => $access,
            'is_owner' => $isOwner,
            'is_you' => $isYou,
            // How many classes a teacher leads here (GroupStaff is tenant-scoped).
            'classes' => $access === self::ACCESS_TEACHER
                ? GroupStaff::where('user_id', $user->id)->count()
                : null,
            // A token is minted at every sign-in, so its newest creation time is
            // the last sign-in — the only reliable "did they get the invite?"
            // (nothing writes users.email_verified_at).
            'last_sign_in_at' => optional($user->tokens()->max('created_at'), fn ($t) => \Illuminate\Support\Carbon::parse($t)->toIso8601String()),
            'removable' => ! $isOwner && ! $isYou && $access !== self::ACCESS_TEACHER,
        ];
    }

    private function refuse(string $message, int $status)
    {
        return response()->json([
            'status' => 'failed',
            'data' => ['user_id' => [$message]],
        ], $status);
    }
}
