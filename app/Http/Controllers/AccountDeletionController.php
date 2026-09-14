<?php

namespace App\Http\Controllers;

use App\Models\Masjid;
use App\Services\Member\MemberAccountDeletion;
use App\Services\Member\MemberSignupService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\Rule;

/**
 * The public account-deletion page, `/account-deletion`.
 *
 * Google Play requires a web link where a user of an app with accounts can ask
 * for deletion without the app, and the privacy policies point here too. It
 * does exactly what the app's "Delete account" does, by calling the same
 * MemberAccountDeletion service, once the visitor proves they control the
 * address with an emailed code.
 *
 * ---------------------------------------------------------------------------
 * TENANT-NEUTRAL, AND NOTHING HERE IS A DIRECTORY
 * ---------------------------------------------------------------------------
 *  - One page for every organisation, served on this app's own host. The
 *    picker lists the organisations in the app directory (`Masjid::listed()`),
 *    which `GET /api/mobile/masjids` already publishes. Nothing else about an
 *    organisation is read or shown: not whether its CRM is on (that flag is in
 *    PUBLIC_DIRECTORY_DENYLIST), not whether it has members.
 *  - Asking for a code answers the SAME page for every address, and a code is
 *    mailed to every address, account or not. So neither the page nor the time
 *    it takes says whether an address has an account here. Only the person who
 *    reads the mailbox learns that, at the last step.
 *  - The address is only ever compared inside the chosen organisation, with the
 *    tenant bound for the duration (.claude/rules/tenant-scoping.md: unbound
 *    means no filter).
 *
 * ---------------------------------------------------------------------------
 * THE CODE
 * ---------------------------------------------------------------------------
 * It is the app sign-in code machinery (MemberSignupService: HMAC at rest, TTL,
 * five guesses shared across every live code for the address, single use), with
 * the purpose bound into the digest so a sign-in code cannot confirm a deletion
 * and a deletion code cannot sign anybody in. The limiters are the app door's
 * own `member-login` and `member-verify`, keyed the same way, so asking at both
 * doors does not double anybody's allowance.
 *
 * GET renders and changes nothing. Both POSTs sit in the `web` group, so they
 * carry CSRF protection.
 */
class AccountDeletionController extends Controller
{
    private const CHOOSE_ORGANISATION = 'Choose the organisation whose app you use.';

    private const MESSAGES = [
        'masjid_id.required' => self::CHOOSE_ORGANISATION,
        'masjid_id.integer' => self::CHOOSE_ORGANISATION,
        'masjid_id.in' => self::CHOOSE_ORGANISATION,
        'email.required' => 'Enter the email address you sign in to the app with.',
        'email.string' => 'Enter the email address you sign in to the app with.',
        'email.email' => 'Enter a full email address, like name@example.com.',
        'email.max' => 'That email address is too long.',
        'code.required' => 'Enter the code from the email.',
        'code.string' => 'Enter the code from the email.',
        'code.max' => 'Enter the code from the email.',
        'confirm.accepted' => 'Tick the box to confirm that you want to delete your account.',
    ];

    private const CODE_REFUSED = 'That code is not right, or it has expired. Check the email, or ask for a new code.';

    public function __construct(
        private readonly MemberSignupService $signups,
        private readonly MemberAccountDeletion $deletion,
        private readonly TenantContext $tenant,
    ) {
    }

    /** GET — the form. Changes nothing. */
    public function show(): Response
    {
        return $this->page('start');
    }

    /** POST — mail a deletion code. The same page for every address. */
    public function requestCode(Request $request): Response
    {
        $organisations = $this->organisations();

        $identity = $this->validateIdentity($request, $organisations);

        if ($identity->fails()) {
            return $this->page('start', [
                'problems' => $identity->errors(),
                'old' => $this->old($request),
            ], 422, $organisations);
        }

        /** @var Masjid $masjid */
        $masjid = $organisations->firstWhere('id', (int) $request->input('masjid_id'));
        $email = mb_strtolower(trim((string) $request->input('email')));

        $this->withTenant($masjid, fn () => $this->signups->issueAccountDeletionCode($email, $request->ip()));

        return $this->page('code', ['org' => $masjid, 'email' => $email], 200, $organisations);
    }

    /** POST — check the code and delete. */
    public function confirm(Request $request): Response
    {
        $organisations = $this->organisations();

        $identity = $this->validateIdentity($request, $organisations);

        if ($identity->fails()) {
            return $this->page('start', [
                'problems' => $identity->errors(),
                'old' => $this->old($request),
            ], 422, $organisations);
        }

        /** @var Masjid $masjid */
        $masjid = $organisations->firstWhere('id', (int) $request->input('masjid_id'));
        $email = mb_strtolower(trim((string) $request->input('email')));

        $answer = Validator::make($request->only(['code', 'confirm']), [
            'code' => ['required', 'string', 'max:20'],
            'confirm' => ['accepted'],
        ], self::MESSAGES);

        if ($answer->fails()) {
            return $this->page('code', [
                'org' => $masjid,
                'email' => $email,
                'problems' => $answer->errors(),
            ], 422, $organisations);
        }

        // People paste "123 456" and "123-456". Only the digits are the code.
        $code = (string) preg_replace('/\D+/', '', (string) $request->input('code'));

        // One transaction: a code is spent only if the deletion it authorised
        // committed. A wrong guess still commits its attempt count, because the
        // closure returns rather than throws.
        $result = $this->withTenant($masjid, fn () => DB::transaction(function () use ($email, $code, $request) {
            if (! $this->signups->redeemAccountDeletionCode($email, $code)) {
                return false;
            }

            return $this->deletion->deleteByAddress($email, MemberAccountDeletion::VIA_WEB, $request->ip());
        }));

        if ($result === false) {
            return $this->page('code', [
                'org' => $masjid,
                'email' => $email,
                'problems' => new MessageBag(['code' => [self::CODE_REFUSED]]),
            ], 422, $organisations);
        }

        // Past this line the visitor has proved they read this mailbox, so they
        // may be told whether there was an account and whether the office keeps
        // records about them.
        if ($result === null) {
            return $this->page('nothing', ['org' => $masjid, 'email' => $email], 200, $organisations);
        }

        return $this->page('removed', [
            'org' => $masjid,
            'email' => $email,
            'erased' => $result['outcome'] === MemberAccountDeletion::OUTCOME_ERASED,
        ], 200, $organisations);
    }

    /**
     * The page the shared sign-in limiters answer with for this page's routes
     * (see AppServiceProvider::tooManyLoginAttempts), so a visitor who hits the
     * ceiling reads a sentence rather than a JSON body.
     */
    public static function throttled(array $headers = []): Response
    {
        return response()->view('account-deletion.page', ['state' => 'throttled'], 429, $headers);
    }

    /** Organisation + address: the same rules at both steps. */
    private function validateIdentity(Request $request, Collection $organisations): \Illuminate\Validation\Validator
    {
        return Validator::make($request->only(['masjid_id', 'email']), [
            'masjid_id' => ['required', 'integer', Rule::in($organisations->pluck('id')->all())],
            'email' => ['required', 'string', 'email', 'max:255'],
        ], self::MESSAGES);
    }

    /** The organisations in the app directory, and nothing more about them. */
    private function organisations(): Collection
    {
        return Masjid::query()->listed()->orderBy('name')->get(['id', 'name']);
    }

    /** What the visitor typed, echoed back only as scalars. */
    private function old(Request $request): array
    {
        $masjidId = $request->input('masjid_id');
        $email = $request->input('email');

        return [
            'masjid_id' => is_scalar($masjidId) ? (string) $masjidId : '',
            'email' => is_scalar($email) ? (string) $email : '',
        ];
    }

    /**
     * Run with the tenant bound to the chosen organisation, then put back
     * whatever was bound before. The web routes run unbound, and every lookup
     * of an address must be scoped to one organisation.
     */
    private function withTenant(Masjid $masjid, callable $callback): mixed
    {
        $previous = $this->tenant->get();
        $this->tenant->set((int) $masjid->id);

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                $this->tenant->forgetTenant();
            } else {
                $this->tenant->set($previous);
            }
        }
    }

    private function page(string $state, array $data = [], int $status = 200, ?Collection $organisations = null): Response
    {
        return response()->view('account-deletion.page', array_merge([
            'state' => $state,
            'organisations' => $organisations ?? $this->organisations(),
            'problems' => new MessageBag(),
            'old' => ['masjid_id' => '', 'email' => ''],
            'org' => null,
            'email' => null,
            'erased' => false,
            'codeTtlMinutes' => max(1, (int) config('member.signup.code_ttl_minutes', 10)),
        ], $data), $status);
    }
}
