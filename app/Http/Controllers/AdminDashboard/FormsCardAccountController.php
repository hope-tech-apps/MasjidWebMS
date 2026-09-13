<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Masjids\SetFormsCardAccountRequest;
use App\Models\Masjid;
use App\Models\MasjidFormsCardLinkLog;
use App\Services\Stripe\FormChargeAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where an organisation's FORM card payments are charged (DECISIONS.md 2026-09-15).
 *
 * A child program org (BISS) may take form card payments through its parent's
 * existing Connect account (Burlington Masjid) while keeping its own
 * `stripe_account_id` NULL. Three doors, and nothing else writes the link:
 *
 *  - update()  SuperAdmin sets or removes the link (SetFormsCardAccountRequest).
 *  - revoke()  the HOLDER's admin (manage donations, tenant-bound to the holder)
 *              removes a link that points at them. Revoke only: setting stays
 *              SuperAdmin-only.
 *  - show()    the org's own admins read, for the form builder, whether its
 *              forms can take a card, and through whom. Never an acct_ id.
 *
 * Every change writes a MasjidFormsCardLinkLog row in the same transaction.
 * Whether a link is usable is never stored: FormChargeAccount decides it on
 * every read, so this controller only refuses a link the resolver would not
 * honour at the moment it is made.
 */
class FormsCardAccountController extends Controller
{
    /** Human wording for each refusal code, keyed by the code the response carries. */
    private const MESSAGES = [
        // FormChargeAccount::linkProblem() codes.
        FormChargeAccount::PROBLEM_ORGANISATION_MISSING => 'This organisation has been archived.',
        FormChargeAccount::PROBLEM_HAS_OWN_ACCOUNT => 'This organisation has its own Stripe account, so it charges its forms on that.',
        FormChargeAccount::PROBLEM_SAME_ORGANISATION => 'An organisation cannot charge its form payments through itself.',
        FormChargeAccount::PROBLEM_NOT_PARENT => 'Form card payments can only be charged through the organisation this one belongs to.',
        FormChargeAccount::PROBLEM_HOLDER_MISSING => 'There is no live organisation with that id.',
        FormChargeAccount::PROBLEM_HOLDER_LINKED => 'That organisation charges its own form payments through another organisation.',
        FormChargeAccount::PROBLEM_HOLDER_NOT_ONBOARDED => 'That organisation has not connected a Stripe account.',
        FormChargeAccount::PROBLEM_HOLDER_CHARGES_DISABLED => 'Stripe does not let that organisation take charges right now.',
        FormChargeAccount::PROBLEM_IS_HOLDER => 'Other organisations already take form card payments through this one, so it cannot charge through another.',
        // This endpoint's own.
        'holder_name_mismatch' => 'The name typed does not match the holder organisation\'s name exactly.',
        'not_ready' => 'Form card payments would still be unavailable through that account.',
    ];

    /**
     * PATCH /api/admin/masjids/{masjid_id}/forms-card-account
     *
     * Body: via_masjid_id (int|null), typed_holder_name, consent_reference
     * (both required when linking). `via_masjid_id: null` removes the link.
     */
    public function update(SetFormsCardAccountRequest $request, string $masjid_id): JsonResponse
    {
        $viaId = $request->input('via_masjid_id');
        $actorId = Auth::id() !== null ? (int) Auth::id() : null;

        try {
            $child = DB::transaction(function () use ($request, $masjid_id, $viaId, $actorId) {
                if ($viaId === null) {
                    return $this->unlinkLocked((int) $masjid_id, $actorId, $request->input('consent_reference'));
                }

                return $this->linkLocked(
                    (int) $masjid_id,
                    (int) $viaId,
                    $actorId,
                    (string) $request->input('typed_holder_name'),
                    (string) $request->input('consent_reference'),
                );
            });
        } catch (DomainException $e) {
            if (! str_starts_with($e->getMessage(), self::REFUSAL_PREFIX)) {
                throw $e;
            }

            $code = substr($e->getMessage(), strlen(self::REFUSAL_PREFIX));
            $field = $code === 'holder_name_mismatch' ? 'typed_holder_name' : 'via_masjid_id';

            return response()->json([
                'status' => 'failed',
                'code' => $code,
                'data' => [$field => [self::message($code)]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'status' => 'success',
            'data' => ['forms_card_via' => self::viaSummary($child->fresh())],
        ], Response::HTTP_OK);
    }

    /**
     * DELETE /api/admin/masjids/{masjid_id}/connect/forms-card-for/{child_id}
     *
     * `{masjid_id}` is the HOLDER, so the tenant middleware binds the caller to
     * it. Only a link pointing at that holder is removed; anything else is a 404
     * that says nothing about the child.
     */
    public function revoke(Request $request, string $masjid_id, string $child_id): JsonResponse
    {
        $holderId = (int) $masjid_id;
        $actorId = Auth::id() !== null ? (int) Auth::id() : null;

        $revoked = DB::transaction(function () use ($holderId, $child_id, $actorId) {
            $child = Masjid::withTrashed()->whereKey((int) $child_id)->lockForUpdate()->first();

            if ($child === null
                || $child->forms_card_via_masjid_id === null
                || (int) $child->forms_card_via_masjid_id !== $holderId) {
                return false;
            }

            $holder = Masjid::withTrashed()->find($holderId);

            $child->forceFill([
                'forms_card_via_masjid_id' => null,
                'forms_card_via_set_at' => now(),
                'forms_card_via_set_by' => $actorId,
            ])->save();

            MasjidFormsCardLinkLog::record(
                (int) $child->id,
                $holderId,
                MasjidFormsCardLinkLog::ACTION_REVOKE,
                $actorId,
                $holder?->stripe_account_id,
            );

            Log::warning('Forms card link revoked by the holder organisation.', [
                'child_masjid_id' => $child->id,
                'holder_masjid_id' => $holderId,
                'actor_user_id' => $actorId,
            ]);

            return true;
        });

        if (! $revoked) {
            return response()->json([
                'status' => 'failed',
                'data' => 'No organisation takes form card payments through this account under that id.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json(['status' => 'success'], Response::HTTP_OK);
    }

    /**
     * GET /api/admin/masjids/{masjid_id}/forms/card-account
     *
     * `own`: the org's own account charges. `linked`: another org's does (named).
     * `unavailable`: no card now, with the holder named when a link exists and a
     * reason code.
     */
    public function show(Request $request, string $masjid_id): JsonResponse
    {
        $org = Masjid::findOrFail($masjid_id);
        $charge = FormChargeAccount::for($org);

        if ($charge !== null) {
            return $this->cardAccountAnswer(
                $charge->linked ? 'linked' : 'own',
                $charge->linked ? self::orgName($charge->holder) : null,
                null,
            );
        }

        if ($org->forms_card_via_masjid_id !== null) {
            $holder = Masjid::withTrashed()->find($org->forms_card_via_masjid_id);

            return $this->cardAccountAnswer(
                'unavailable',
                $holder ? self::orgName($holder) : null,
                FormChargeAccount::linkProblem($org, $holder) ?? 'unavailable',
            );
        }

        return $this->cardAccountAnswer('unavailable', null, self::ownProblem($org));
    }

    // ------------------------------------------------------------------
    // Payload blocks shared with StripeConnectController::status (D14)
    // ------------------------------------------------------------------

    /**
     * `{holder:{id,name}, ready, problem}` for an org that is linked, else null.
     * Never carries an account id.
     */
    public static function viaSummary(Masjid $org): ?array
    {
        if ($org->forms_card_via_masjid_id === null) {
            return null;
        }

        $holder = Masjid::withTrashed()->find($org->forms_card_via_masjid_id);
        $charge = FormChargeAccount::for($org);
        $ready = $charge !== null && $charge->linked;

        return [
            'holder' => $holder ? self::orgName($holder) : ['id' => (int) $org->forms_card_via_masjid_id, 'name' => null],
            'ready' => $ready,
            'problem' => $ready ? null : (FormChargeAccount::linkProblem($org, $holder) ?? 'unavailable'),
        ];
    }

    /**
     * `[{id,name}]`: every organisation whose link points at this one, archived
     * ones included. An archived child cannot charge now, but its link comes
     * back with it on restore, so the holder must still see it and be able to
     * revoke it (revoke() is withTrashed too).
     */
    public static function forSummary(Masjid $holder): array
    {
        return Masjid::withTrashed()
            ->where('forms_card_via_masjid_id', $holder->id)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Masjid $child) => self::orgName($child))
            ->values()
            ->all();
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function linkLocked(int $childId, int $viaId, ?int $actorId, string $typedName, string $consentReference): Masjid
    {
        // Both rows, one statement, id order: two concurrent requests take the
        // locks in the same order and cannot deadlock.
        $rows = Masjid::withTrashed()
            ->whereIn('id', array_unique([$childId, $viaId]))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $child = $rows->get($childId);

        if ($child === null || $child->trashed()) {
            abort(Response::HTTP_NOT_FOUND);
        }

        // A missing or trashed holder is passed through as it is: the resolver's
        // own linkProblem() names it (holder_missing), as it names the same
        // organisation, a holder that is not the parent or not onboarded, and a
        // child that is itself a holder. One authority for every refusal code,
        // so this endpoint can never accept a link the charge path would refuse.
        $holder = $rows->get($viaId);

        $problem = FormChargeAccount::linkProblem($child, $holder);

        if ($problem !== null) {
            throw self::refused($problem);
        }

        // Exact apart from surrounding whitespace. Laravel's global TrimStrings
        // middleware trims the typed name before it arrives here, so comparing
        // it to an untrimmed stored name (a seeded or imported "Name ") would
        // refuse the link forever. Case and inner spacing still must match.
        if (trim($typedName) !== trim((string) $holder->name)) {
            throw self::refused('holder_name_mismatch');
        }

        $child->forceFill([
            'forms_card_via_masjid_id' => $holder->id,
            'forms_card_via_set_at' => now(),
            'forms_card_via_set_by' => $actorId,
        ])->save();

        // Belt and braces: the saved row must resolve to the holder's account,
        // or the whole write (and its audit row) rolls back.
        $charge = FormChargeAccount::for($child->fresh());

        if ($charge === null || ! $charge->linked || (int) $charge->holder->id !== (int) $holder->id) {
            throw self::refused('not_ready');
        }

        MasjidFormsCardLinkLog::record(
            (int) $child->id,
            (int) $holder->id,
            MasjidFormsCardLinkLog::ACTION_LINK,
            $actorId,
            $holder->stripe_account_id,
            $typedName,
            $consentReference,
        );

        Log::warning('Forms card link set: form card payments now charge through another organisation.', [
            'child_masjid_id' => $child->id,
            'holder_masjid_id' => $holder->id,
            'actor_user_id' => $actorId,
        ]);

        return $child;
    }

    private function unlinkLocked(int $childId, ?int $actorId, ?string $consentReference): Masjid
    {
        // withTrashed: an archived child keeps its link (and gets it back on
        // restore), so a SuperAdmin must be able to remove it while archived.
        $child = Masjid::withTrashed()->whereKey($childId)->lockForUpdate()->first();

        if ($child === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        // Nothing linked: nothing changes and nothing is written.
        if ($child->forms_card_via_masjid_id === null) {
            return $child;
        }

        $holderId = (int) $child->forms_card_via_masjid_id;
        $holder = Masjid::withTrashed()->find($holderId);

        $child->forceFill([
            'forms_card_via_masjid_id' => null,
            'forms_card_via_set_at' => now(),
            'forms_card_via_set_by' => $actorId,
        ])->save();

        MasjidFormsCardLinkLog::record(
            (int) $child->id,
            $holderId,
            MasjidFormsCardLinkLog::ACTION_UNLINK,
            $actorId,
            $holder?->stripe_account_id,
            null,
            $consentReference,
        );

        Log::warning('Forms card link removed by a super admin.', [
            'child_masjid_id' => $child->id,
            'holder_masjid_id' => $holderId,
            'actor_user_id' => $actorId,
        ]);

        return $child;
    }

    private function cardAccountAnswer(string $state, ?array $holder, ?string $problem): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'state' => $state,
                'holder' => $holder,
                'problem' => $problem,
            ],
        ], Response::HTTP_OK);
    }

    /** Why an UNLINKED org cannot take a card now. */
    private static function ownProblem(Masjid $org): string
    {
        $account = (string) ($org->stripe_account_id ?? '');

        if (! str_starts_with($account, 'acct_')) {
            return 'not_connected';
        }

        return 'charges_disabled';
    }

    /** @return array{id:int,name:string} */
    private static function orgName(Masjid $org): array
    {
        return ['id' => (int) $org->id, 'name' => (string) $org->name];
    }

    /**
     * A refusal thrown INSIDE the transaction, so the link write and its audit row
     * roll back together. A prefixed DomainException rather than a class of its
     * own, so this file declares one class (PSR-4).
     */
    private const REFUSAL_PREFIX = 'forms-card-link-refused:';

    private static function refused(string $code): DomainException
    {
        return new DomainException(self::REFUSAL_PREFIX . $code);
    }

    public static function message(string $code): string
    {
        return self::MESSAGES[$code]
            ?? "Form card payments cannot be charged through that organisation ({$code}).";
    }
}

