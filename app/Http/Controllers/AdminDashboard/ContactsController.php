<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Contacts\StoreContactRequest;
use App\Http\Requests\Admin\Contacts\UpdateContactRequest;
use App\Models\Contact;
use App\Support\Errors;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Member directory — admin CRUD for CRM Contact (congregant) records.
 *
 * Tenant isolation is NOT enforced here by hand. The route keeps the
 * /masjids/{masjid_id}/... prefix by convention, but the `tenant`
 * middleware (ResolveMasjidTenant) binds TenantContext and the
 * App\Models\Concerns\BelongsToMasjid trait auto-scopes every Contact query
 * to the bound masjid — so we deliberately never filter by $masjid_id and
 * never set masjid_id from client input. See .claude/rules/tenant-scoping.md.
 */
class ContactsController extends Controller
{
    /**
     * Paginated list of the current masjid's contacts, optionally filtered by a
     * free-text ?search= over first name / last name / email / phone.
     *
     * ## `?trashed=` — because a soft delete was a one-way door
     *
     * `Contact` soft-deletes precisely so a mis-click on a congregant record is
     * recoverable, and nothing in this application could recover one: every
     * listing and every `findOrFail` here used the non-trashed scope, and there
     * was no restore route anywhere in routes/admin.php. "Recoverable" meant
     * recoverable from a database console.
     *
     * That turned a MEDIUM defect into a permanent one once `destroy()` learned
     * to revoke a family sign-in on the way out (below). The `revoked` row it
     * writes is the evidence the office needs to answer "who took my access
     * away" — and it was being written straight into a record no screen could
     * ever open again.
     *
     * `with` includes deleted members alongside live ones, `only` lists just
     * them; anything else (and the absent default) keeps the previous
     * behaviour exactly, so no existing caller sees a deleted member appear.
     */
    public function index(Request $request, $masjid_id)
    {
        $search = $request->query('search');
        $trashed = (string) $request->query('trashed', '');

        $contacts = Contact::query()
            ->when($trashed === 'with', fn ($query) => $query->withTrashed())
            ->when($trashed === 'only', fn ($query) => $query->onlyTrashed())
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        // Full name, so "Ahmad Fais" (first + last together) matches.
                        ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"])
                        ->orWhereRaw("CONCAT(last_name, ' ', first_name) LIKE ?", ["%{$search}%"]);
                });
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate($request->query('per_page', 15));

        return response()->json([
            'status' => 'success',
            'data' => $contacts
        ], Response::HTTP_OK);
    }

    /**
     * Store a new contact. masjid_id is intentionally omitted: the
     * BelongsToMasjid creating hook stamps it from the bound tenant, so a
     * client-supplied masjid_id can never plant a row in another masjid.
     */
    public function store(StoreContactRequest $request, $masjid_id)
    {
        try {
            $contact = Contact::create($request->validated());

            return response()->json([
                'status' => 'success',
                'data' => $contact
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Show a single contact. findOrFail is tenant-scoped, so another masjid's id
     * resolves to a 404 rather than leaking the row.
     */
    public function show($masjid_id, $contact_id)
    {
        // Card last-4 + giving history, newest gift first (by real gift date).
        $contact = Contact::with([
            'cards',
            'donations' => fn ($q) => $q->with('fund')->orderByRaw('COALESCE(donated_at, created_at) DESC'),
        ])->findOrFail($contact_id);

        $data = $contact->toArray();
        $data['giving_total'] = (int) $contact->donations
            ->where('status', 'succeeded')->sum('charged_amount');

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ], Response::HTTP_OK);
    }

    /**
     * Merge a contact (typically a placeholder "Unidentified Card ####") into
     * another member — existing (target_contact_id) or newly created. Moves the
     * source's donations and card last-4 onto the target, then removes the source.
     *
     * All queries run in the bound-tenant context, so BelongsToMasjid scopes every
     * move to this masjid — a merge can't reach across tenants.
     *
     * ## SMS consent is reconciled BEFORE the source is force-deleted (T-009)
     *
     * The `forceDelete()` below destroys everything held only on the source row,
     * and consent is held only on the source row. So the survivor takes the MORE
     * RESTRICTIVE of the two states — and only when the two contacts carry the
     * SAME phone number, because consent belongs to a number rather than to a
     * name, and transplanting it onto a survivor with a different number would
     * manufacture permission to text somebody who never gave it. The full rule
     * is on App\Services\Sms\SmsConsentService::reconcileOnMerge.
     *
     * The OPT-OUT needs no transplanting at all: it lives in `sms_suppressions`,
     * keyed on the number with no foreign key to `contacts`, precisely so that
     * this force-delete cannot un-say a STOP.
     *
     * ## The parent PORTAL is settled before the force-delete too (T-015d)
     *
     * The same `forceDelete()` used to end a live parent sign-in as a pure side
     * effect: no `revoked` event, no token deleted, a survivor that gained
     * nothing, and an audit trail orphaned beyond every screen — `contact_id`
     * nulls out, and the history panel reads `where('contact_id', …)`. The
     * `nullOnDelete` in that migration was chosen so a merge would not ERASE the
     * grant history; nothing carried it across, so it was preserved where nobody
     * could read it.
     *
     * `FamilyAccessService::absorbOnMerge()` ends the credential loudly (a real
     * `revoked` row, the tokens deleted) and re-points the trail onto the
     * survivor. It deliberately does NOT carry the login itself — the argument is
     * on that method — and the outcome is reported in the response below, because
     * an audit row nobody is told about is not "loudly". A source with no login
     * at all, which is the normal placeholder case, is untouched and writes
     * nothing.
     *
     * ## …and the ROSTER EDGES are carried, which is what made that report true
     *
     * The same `forceDelete()` also took `group_memberships` with it —
     * `contact_id` and `guardian_of_contact_id` are both `cascadeOnDelete`, and a
     * DB cascade fires no model event. So the message above told the operator to
     * "enable sign-in on them if they should still have access" in the same
     * request that destroyed the guardian edge `FamilyAccessService` requires
     * before it will do that; measured, the follow-up answered 422. The same
     * cascade destroyed a merged-away CHILD's behaviour and ḥifẓ records outright
     * (`behavior_awards.group_membership_id` and `hifz_entries.group_membership_id`
     * cascade too).
     *
     * `RosterMergeService::carry()` moves both directions of that table onto the
     * survivor — the source's own places in groups, and the guardian edges other
     * people held over the source — de-duplicated against what the survivor
     * already holds. A merge asserts these two rows are one human; a roster edge
     * is a fact about that human, in the same class as the donations and the card
     * this method already moves.
     *
     * ## …and what the merge is NOT allowed to be, which is an authorization
     *
     * `carry()` used to be documented as "where the guardianship claim finally
     * gets its authenticated act", and that was this method quietly answering a
     * question nobody asked it. Measured: an anonymous registration wrote a
     * duplicate child plus a guardian edge over it; a registrar merged the two
     * identical rows, which is exactly what this verb is for; the edge landed on
     * the REAL child and the stranger's portal opened her behaviour record, her
     * ḥifẓ and the safeguarding thread about her. The registrar authenticated a
     * DE-DUPLICATION. Nothing on the screen, in the request or in the response
     * below contained the word "guardian".
     *
     * Provenance settles it in `RosterMergeService` — a self-asserted edge stays
     * self-asserted through a merge, and a confirmed one re-pointed at a
     * DIFFERENT ward drops back to a claim, because the confirmation was made
     * about the person it used to name. This method's remaining job is to STOP
     * DISCARDING THE REPORT: `carry()`'s return value was thrown away, so a merge
     * that moved eleven roster rows and re-opened a guardian claim for
     * confirmation said nothing at all about any of it.
     */
    public function merge(\App\Http\Requests\Admin\Contacts\MergeContactRequest $request, $masjid_id, $contact_id)
    {
        $source = Contact::findOrFail($contact_id);   // tenant-scoped

        $target = $request->filled('target_contact_id')
            ? Contact::findOrFail($request->integer('target_contact_id'))
            : Contact::create($request->only(['first_name', 'last_name', 'email', 'phone']));

        if ($target->id === $source->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'A contact cannot be merged into itself.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $actor = $request->user() instanceof \App\Models\User ? $request->user() : null;
        $familyLogin = null;
        $roster = null;

        \Illuminate\Support\Facades\DB::transaction(function () use ($source, $target, $actor, $request, &$familyLogin, &$roster) {
            // Before anything is destroyed: the survivor takes the more
            // restrictive SMS consent state (T-009).
            app(\App\Services\Sms\SmsConsentService::class)->reconcileOnMerge($source, $target);

            // …and the absorbed contact's parent-portal access is ended on the
            // record, with its history carried onto the survivor. Before the
            // force-delete, because `contact_id` nulls out on it.
            $familyLogin = app(\App\Services\Family\FamilyAccessService::class)
                ->absorbOnMerge($source, $target, $actor, $request->ip());

            // The roster edges, in both directions. Before the force-delete,
            // because they cascade off it in silence.
            //
            // A carried leader/member row used to END the survivor's own family
            // credential here, on the theory that a participant edge cannot sit
            // beside one. That rule is gone: what a parent-portal credential
            // READS is now scoped to its wards inside GroupAudience, so gaining
            // a roster row widens nothing and there is nothing to re-derive. A
            // merge that quietly signed a parent out of their phone because
            // their duplicate row happened to be on a class list was a side
            // effect nobody asked for and no screen predicted.
            $roster = app(\App\Services\Groups\RosterMergeService::class)->carry($source, $target);

            \App\Models\Donation::where('contact_id', $source->id)
                ->update(['contact_id' => $target->id]);

            foreach ($source->cards as $card) {
                \App\Models\ContactCard::firstOrCreate(
                    ['contact_id' => $target->id, 'last4' => $card->last4],
                    ['masjid_id' => $target->masjid_id],
                );
            }
            $source->cards()->delete();
            $source->forceDelete();   // the placeholder is fully absorbed
        });

        return response()->json([
            'status' => 'success',
            'data' => $target->fresh(['cards']),
            'family_login' => $this->familyLoginReport($familyLogin, $target->fresh()),
            'roster' => $this->rosterReport($roster),
        ], Response::HTTP_OK);
    }

    /**
     * What the merge did to the roster, out loud.
     *
     * Null when it touched nothing, which is the ordinary placeholder merge and
     * where a report about nothing is noise.
     *
     * The `unconfirmed` count is the sentence that matters and the reason this
     * is not a debug counter. A merge that re-points a CONFIRMED guardian edge
     * at a different ward turns it back into a claim, because the confirmation
     * was recorded about the person it used to name — so a parent whose portal
     * worked this morning stops reading their child's records, and the office
     * has to hear that from the screen they did it on rather than from a phone
     * call. It names the door back: the roster's Confirm.
     */
    private function rosterReport(?array $roster): ?array
    {
        if ($roster === null || ($roster['moved'] + $roster['dropped']) === 0) {
            return null;
        }

        $message = sprintf(
            '%d roster entr%s moved onto this member and %d duplicate%s dropped.',
            $roster['moved'],
            $roster['moved'] === 1 ? 'y' : 'ies',
            $roster['dropped'],
            $roster['dropped'] === 1 ? '' : 's',
        );

        if ($roster['unconfirmed'] > 0) {
            $message .= sprintf(
                ' %d guardian entr%s now point at this member instead of the absorbed record, so %s '
                . 'gone back to being an unconfirmed claim — a confirmation names one specific person, '
                . 'and this is no longer that person. Confirm %s on the group\'s roster if the '
                . 'relationship is real; until then it opens nothing.',
                $roster['unconfirmed'],
                $roster['unconfirmed'] === 1 ? 'y' : 'ies',
                $roster['unconfirmed'] === 1 ? 'it has' : 'they have',
                $roster['unconfirmed'] === 1 ? 'it' : 'them',
            );
        }

        return $roster + ['message' => $message];
    }

    /**
     * What the operator has to be told out loud about parent-portal sign-in.
     *
     * Null for an ordinary placeholder merge — the overwhelmingly common case,
     * where nothing happened and a report about nothing is noise.
     *
     * ## The remedy is now CHECKED before it is offered
     *
     * The previous version ended every revocation notice with "Enable sign-in on
     * them if they should still have access." That sentence was false in the
     * exact case an operator hits it: the merge had just destroyed the guardian
     * edge `FamilyAccessService::enable()` requires, so the instruction led
     * straight to a 422. Carrying the roster edges makes it true most of the
     * time — and for the times it still is not (the source was nobody's
     * guardian; the survivor is on a class roster in their own right), the
     * survivor's actual eligibility is read from the same method `enable()`
     * refuses with, and the reason is quoted instead of the promise. A message
     * must never send somebody at a door that is shut.
     */
    private function familyLoginReport(?array $familyLogin, Contact $target): ?array
    {
        if ($familyLogin === null) {
            return null;
        }

        $access = app(\App\Services\Family\FamilyAccessService::class);
        $blocked = $access->ineligibilityReason($target);

        $remedy = $blocked === null
            ? 'Enable sign-in on them if they should still have access.'
            : 'The surviving member cannot be given sign-in as things stand: ' . $blocked;

        return [
            'login_email' => $familyLogin['login_email'],
            'access_ended' => $familyLogin['was_active'],
            'message' => $familyLogin['was_active']
                ? sprintf(
                    'Parent portal sign-in for %s was revoked by this merge — the surviving member '
                    . 'does NOT inherit it. %s',
                    $familyLogin['login_email'] ?? 'the absorbed record',
                    $remedy,
                )
                : 'The absorbed record\'s parent portal history was carried onto this member.',
        ];
    }

    /**
     * Update a contact. The scoped findOrFail runs OUTSIDE the try so a
     * cross-tenant / missing id surfaces as a clean 404 instead of being
     * swallowed into a 500 by the catch below.
     */
    public function update(UpdateContactRequest $request, $masjid_id, $contact_id)
    {
        $contact = Contact::findOrFail($contact_id);

        try {
            $contact->update($request->validated());

            return response()->json([
                'status' => 'success',
                'data' => $contact
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Soft-delete a contact (the model uses SoftDeletes) so a mis-click on a
     * congregant record is recoverable. Scoped findOrFail → 404 cross-tenant.
     *
     * ## Deleting a member ENDS their portal access, on the record
     *
     * `Contact::familyLoginIsActive()` already returns false for a trashed row,
     * so the access died here either way — silently, with `login_enabled_at` set
     * and `login_revoked_at` null, so the record went on reading "enabled"
     * forever, no `revoked` row was ever written, and the tokens stayed in
     * `personal_access_tokens` for the rest of their life (inert, because
     * Sanctum's tokenable lookup applies the SoftDeletes scope, but present).
     *
     * Revoking first makes the delete say what it did. It runs BEFORE the soft
     * delete because `revoke()` reads the row's own state, and it is deliberately
     * not conditional on anything else: same rule as the merge above — a contact
     * leaving the directory takes its portal access with it, loudly. The address
     * is NOT freed; that is still a separate, confirmed act (see
     * FamilyAccessService::releaseAddressFrom).
     */
    public function destroy(Request $request, $masjid_id, $contact_id)
    {
        $contact = Contact::findOrFail($contact_id);

        if ($contact->familyLoginIsActive()) {
            // Reassigned rather than discarded: the response below returns this
            // row, and returning the pre-revocation copy would tell the SPA the
            // login is still enabled on a member it has just deleted.
            $contact = app(\App\Services\Family\FamilyAccessService::class)->revoke(
                $contact,
                $request->user() instanceof \App\Models\User ? $request->user() : null,
                $request->ip(),
            );
        }

        $contact->delete();

        return response()->json([
            'status' => 'success',
            'data' => $contact
        ], Response::HTTP_OK);
    }

    /**
     * Put a soft-deleted member back in the directory.
     *
     * The other half of `destroy()`, and it did not exist. `Contact` uses
     * SoftDeletes so a mis-click is recoverable, but no route in this
     * application could reach a trashed row, so the delete was final in
     * practice and — since `destroy()` now revokes on the way out — it took the
     * `revoked` audit row out of reach with it.
     *
     * ## A RESTORE IS NOT A RE-GRANT
     *
     * `login_revoked_at` is deliberately left standing. Deleting the member
     * ended their portal access on the record; undeleting them returns them to
     * the directory and nothing else, so the member comes back with their
     * sign-in `revoked` and an operator has to re-open it with the same typed,
     * deliberate act every other grant takes. The alternative — a restore that
     * silently re-opens a credential — is exactly the "grant produced as a side
     * effect" that `absorbOnMerge()` argues against at length.
     *
     * `login_email` is likewise untouched: the unique index spans trashed rows
     * on purpose, so the address was never free while they were deleted and a
     * restore cannot collide with anything. If an operator DID reassign it in
     * the meantime (the confirmed `reassign_address` act), the column is already
     * null here and the restored member simply has no address — which is what
     * the `address_released` row on their own history says happened.
     *
     * `manage contacts`, `withTrashed()` on the tenant-scoped query so another
     * organisation's id is still a 404, and idempotent: restoring a member who
     * is not deleted is a no-op that answers 200 rather than an error, because
     * the one time it matters is somebody clicking twice.
     */
    public function restore(Request $request, $masjid_id, $contact_id)
    {
        $contact = Contact::withTrashed()->findOrFail($contact_id);

        if ($contact->trashed()) {
            $contact->restore();
        }

        return response()->json([
            'status' => 'success',
            'data' => $contact->fresh(),
        ], Response::HTTP_OK);
    }
}
