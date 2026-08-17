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
     * Provenance settles it in `RosterMergeService`, whose one rule is that a
     * merge may keep a row's authority only when the identity that authority is
     * read through has not changed — and where either end of a guardian PAIR
     * changes, the entry is retired and re-issued as a fresh claim with a NEW ID,
     * so a Confirm click carrying ids drawn before the merge confirms nothing.
     *
     * This method's remaining job is to STOP DISCARDING THE REPORT: `carry()`'s
     * return value was thrown away, so a merge that moved eleven roster rows and
     * re-opened a guardian claim for confirmation said nothing at all about any
     * of it. `rosterReport()` below is that sentence, and it is now written per
     * ROLE — the previous text described a teacher's `leader` row to her office
     * as a guardianship over a named ward, which it was not.
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
     * ## WHY THIS IS NOT A DEBUG COUNTER
     *
     * A merge can change WHO a guardian claim is about. That is a disclosure
     * decision the operator did not make and was not shown, and the previous
     * version of this method could not describe it: it counted one number,
     * printed the word "guardian" over rows that were not guardian rows, and
     * printed NOTHING at all for the two shapes that mattered most.
     *
     * Three sentences now, and each one exists because a measured request had no
     * sentence for it:
     *
     *  1. GUARDIAN CLAIMS THIS MERGE RE-ISSUED. A pending claim whose holder
     *     changed used to move in total silence — same row id, different adult —
     *     and the operator's already-drawn Confirm list still named it. The
     *     entries are now named: the ward, the adult, and THE ADDRESS, because a
     *     confirmation on a guardian entry is what makes a parent portal sign-in
     *     possible and this is the last screen before somebody types one.
     *  2. PARTICIPANT ROWS THIS MERGE RE-OPENED. Said in words that are true for
     *     a `leader`/`member` row: it names one person, no ward, and no
     *     guardianship. The old text told a teacher's office that "1 guardian
     *     entry now point at this member … a confirmation names one specific
     *     person, and this is no longer that person" about a leader row that
     *     named no ward and moved none.
     *  3. CONFIRMED GUARDIAN ENTRIES THE MERGE DESTROYED, and how many parent
     *     portal sign-ins that leaves opening nothing. `destroy()` below the
     *     roster verbs already narrates the same three counts about the same
     *     cascade; a merge that reached it said `unconfirmed: 0`.
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

        $message .= $this->reissuedClaimsSentence($roster);
        $message .= $this->repointedClaimsSentence($roster);
        $message .= $this->namedClaimsSentence($roster);
        $message .= $this->reopenedParticipantsSentence($roster);
        $message .= $this->droppedParticipantTwinsSentence($roster);
        $message .= $this->destroyedEdgesSentence($roster);

        return $roster + ['message' => $message];
    }

    /**
     * The guardian entries whose PAIR this merge changed.
     *
     * Every one of them was retired and written back as a fresh claim with a NEW
     * ID, so an id drawn before the merge confirms nothing — and the office has
     * to be told that, or the honest `{"confirmed":0,"skipped":1}` they get for
     * clicking Confirm reads as a bug rather than as the refusal it is.
     */
    private function reissuedClaimsSentence(array $roster): string
    {
        $count = (int) ($roster['guardian_claims_reissued'] ?? 0);

        if ($count === 0) {
            return '';
        }

        $sentence = sprintf(
            ' %d guardian entr%s changed who %s about, so %s been re-issued as %s new unconfirmed '
            . 'claim%s — a confirmation names one pair, this adult over that named child, and after '
            . 'this merge %s no longer that pair. The earlier entr%s can no longer be confirmed: '
            . 're-read this group\'s roster and confirm the new line%s if the relationship is real. '
            . 'Until then %s open%s nothing, and any consent recorded on %s has been withdrawn.',
            $count,
            $count === 1 ? 'y' : 'ies',
            $count === 1 ? 'it is' : 'they are',
            $count === 1 ? 'it has' : 'they have',
            $count === 1 ? 'a' : '',
            $count === 1 ? '' : 's',
            $count === 1 ? 'it is' : 'they are',
            $count === 1 ? 'y' : 'ies',
            $count === 1 ? '' : 's',
            $count === 1 ? 'it' : 'they',
            $count === 1 ? 's' : '',
            $count === 1 ? 'it' : 'them',
        );

        return $sentence;
    }

    /**
     * The rare guardian entry whose pair changed but which could NOT be retired,
     * because retiring it would have deleted a record kept about a child. It is
     * counted apart from the re-issued ones on purpose: it keeps its id, so a
     * Confirm click drawn before the merge WILL still land on it, and an operator
     * must not be told the stronger guard held when it did not.
     */
    private function repointedClaimsSentence(array $roster): string
    {
        $count = (int) ($roster['guardian_claims_repointed_in_place'] ?? 0);

        if ($count === 0) {
            return '';
        }

        return sprintf(
            ' %d guardian entr%s changed who %s about but had to keep %s roster line, because removing '
            . 'it would have deleted a record kept about a child. %s back to being an unconfirmed '
            . 'claim%s — check %s on the group\'s roster before confirming anything there.',
            $count,
            $count === 1 ? 'y' : 'ies',
            $count === 1 ? 'it is' : 'they are',
            $count === 1 ? 'its' : 'their',
            $count === 1 ? 'It is' : 'They are',
            $count === 1 ? '' : 's',
            $count === 1 ? 'it' : 'them',
        );
    }

    /**
     * The entries themselves, named.
     *
     * `ConfirmGroupMembershipsRequest` says the operator decides on "ward names,
     * the claimed guardian, and which signup asserted it". A merge can change the
     * first two under them, and the ADDRESS is added because a confirmation on a
     * guardian entry is what makes a parent portal sign-in possible and this is
     * the last screen before somebody types one.
     */
    private function namedClaimsSentence(array $roster): string
    {
        $sentence = '';

        foreach (($roster['guardian_claims'] ?? []) as $claim) {
            $sentence .= sprintf(
                ' %s <%s> is now recorded as the guardian of %s in %s (it read %s), so confirming it '
                . 'would open that child\'s records to that address.',
                $claim['guardian'] ?? 'This member',
                $claim['guardian_email'] ?? 'no address on file',
                $claim['ward'] ?? 'a member of that group',
                $claim['group'] ?? 'that group',
                $claim['previously'] ?? 'the absorbed record',
            );
        }

        return $sentence;
    }

    /** Said in words that are true for a row that names one person and no ward. */
    private function reopenedParticipantsSentence(array $roster): string
    {
        $count = (int) ($roster['participant_rows_reopened'] ?? 0);

        if ($count === 0) {
            return '';
        }

        return sprintf(
            ' %d roster entr%s for this person %s moved onto a record with a different email address, '
            . 'so %s gone back to being an unconfirmed claim%s — an email address is how this '
            . 'application decides which person a roster row is about, and it is not the same one any '
            . 'more. The row names one person and no ward, so nobody else\'s records are involved. '
            . 'Confirm %s on the group\'s roster to put %s back.',
            $count,
            $count === 1 ? 'y' : 'ies',
            $count === 1 ? 'has' : 'have',
            $count === 1 ? 'it has' : 'they have',
            $count === 1 ? '' : 's',
            $count === 1 ? 'it' : 'them',
            $count === 1 ? 'it' : 'them',
        );
    }

    /**
     * A CONFIRMED enrolment dropped as a duplicate of an unconfirmed one.
     *
     * Nobody's records open on a participant row, so this is not a disclosure
     * event — but it is one more roster line that now needs a click, and every
     * number in this report has to have a sentence. `unconfirmed` counting two
     * while the text explained one is the shape of an unexplained count, which
     * is how the previous rounds' silences started.
     */
    private function droppedParticipantTwinsSentence(array $roster): string
    {
        $count = (int) ($roster['participant_rows_dropped_for_a_claim'] ?? 0);

        if ($count === 0) {
            return '';
        }

        return sprintf(
            ' %d roster entr%s this organisation had confirmed %s dropped as %s duplicate%s of %s this '
            . 'member already holds as an unconfirmed claim, so %s line%s now %s a confirmation on the '
            . 'group\'s roster. Nobody\'s records are opened by %s.',
            $count,
            $count === 1 ? 'y' : 'ies',
            $count === 1 ? 'was' : 'were',
            $count === 1 ? 'a' : '',
            $count === 1 ? '' : 's',
            $count === 1 ? 'one' : 'ones',
            $count === 1 ? 'that' : 'those',
            $count === 1 ? '' : 's',
            $count === 1 ? 'needs' : 'need',
            $count === 1 ? 'it' : 'them',
        );
    }

    /**
     * What the force-delete's cascade is taking, and what it costs.
     *
     * The same three questions `GroupMembershipsController::destroy()` answers
     * about the same cascade — because the operator's problem is identical
     * whichever verb reached it, and the merge used to answer none of them.
     */
    private function destroyedEdgesSentence(array $roster): string
    {
        $destroyed = (int) ($roster['confirmed_guardian_edges_dropped'] ?? 0);
        $stranded = (int) ($roster['family_logins_left_without_a_ward'] ?? 0);

        if ($destroyed === 0 && $stranded === 0) {
            return '';
        }

        $sentence = '';

        if ($destroyed > 0) {
            $sentence .= sprintf(
                ' %d guardian entr%s this organisation had CONFIRMED went with the absorbed record, '
                . 'because this member already holds an entry for the same pair. That confirmation is '
                . 'not carried across — a merge is a de-duplication, not an authorization — so what is '
                . 'left is an unconfirmed claim until somebody confirms it on the group\'s roster.',
                $destroyed,
                $destroyed === 1 ? 'y' : 'ies',
            );
        }

        if ($stranded > 0) {
            $sentence .= sprintf(
                ' %d parent portal sign-in%s now open%s nothing — confirm the remaining roster entr%s, '
                . 'or revoke the sign-in%s if that was not intended.',
                $stranded,
                $stranded === 1 ? '' : 's',
                $stranded === 1 ? 's' : '',
                $stranded === 1 ? 'y' : 'ies',
                $stranded === 1 ? '' : 's',
            );
        }

        return $sentence;
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
