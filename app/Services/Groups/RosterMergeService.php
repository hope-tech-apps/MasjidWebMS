<?php

namespace App\Services\Groups;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupThread;
use App\Support\ContactIdentity;

/**
 * Carry one contact's ROSTER EDGES onto the contact that absorbed them.
 *
 * `ContactsController::merge` moves donations, moves card last-4 records and
 * reconciles SMS consent, then `forceDelete()`s the absorbed row. It did not
 * move `group_memberships`, and the force-delete does not leave them behind:
 * `contact_id` and `guardian_of_contact_id` are BOTH
 * `constrained()->cascadeOnDelete()` (see the create_group_memberships_table
 * migration), and a DB cascade fires no model events, so nothing in the
 * application even saw them go.
 *
 * ---------------------------------------------------------------------------
 * WHY THE EDGES ARE CARRIED AND THE CREDENTIAL IS NOT
 * ---------------------------------------------------------------------------
 *
 * `FamilyAccessService::absorbOnMerge()` argues at length that a family login
 * must NOT follow a merge. That argument is about a GRANT — a typed, deliberate
 * act that must never be produced as a side effect of a de-duplication. A roster
 * edge is not a grant. It is a fact about the human, in the same class as the
 * donations and the card the merge already carries, and a merge asserts that the
 * two rows ARE one human. Destroying it does not fail closed; it fails silently,
 * and it destroys the one fact the family portal's own guard reads.
 *
 * Measured before this existed:
 *
 *   P1 is guardian of C in Grade 3, family sign-in enabled on
 *   household@example.test. P2 is the same human's second CRM row with no roster
 *   edge — which is WHY it is the duplicate. `merge P1 -> P2` answered 200 with
 *   "…Enable sign-in on them if they should still have access", and doing
 *   exactly that answered 422 "This member is not listed as the guardian of any
 *   student". The merge removed the remedy in the same request that named it.
 *
 * And outside the family login, the same cascade was destroying records ABOUT
 * CHILDREN. `behavior_awards.group_membership_id` and
 * `hifz_entries.group_membership_id` are both `cascadeOnDelete`, so merging a
 * duplicated CHILD row deleted that child's entire behaviour and ḥifẓ history
 * with no event, no report and nothing on any screen;
 * `group_threads.about_membership_id` nulls, which degrades a participant thread
 * to leaders-only (GroupAudience fails closed on a null target). Carrying the
 * membership rows keeps every one of those foreign keys pointing at a row that
 * still exists.
 *
 * ---------------------------------------------------------------------------
 * BOTH DIRECTIONS, because a contact appears in this table twice over
 * ---------------------------------------------------------------------------
 *
 *   - the rows that are the source's OWN place in a group (`contact_id`), and
 *   - the guardian edges other people hold OVER the source (`guardian_of_contact_id`),
 *     which is the half that matters when the absorbed row is a child.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS DROPPED INSTEAD OF MOVED
 * ---------------------------------------------------------------------------
 *
 * A dropped row is simply left for the force-delete's cascade; nothing here
 * deletes anything ON THAT PATH, so no `deleting` hook fires half-way through a
 * merge. (The one thing this class does delete is described under RE-ISSUE
 * below, and it deletes a GUARDIAN row, whose `deleting` hook is a documented
 * no-op.)
 *
 *  1. AN EDGE THE SURVIVOR ALREADY HOLDS. Two CRM rows for one parent, both
 *     recorded against the same child in the same class, is precisely what a
 *     merge cleans up. Moving the second would hit
 *     `group_memberships_edge_unique` (group, contact, role, ward) and 500 the
 *     merge, and for a participant row — where the NULL ward makes the index
 *     blind — it would plant the duplicate roster line
 *     `GroupMembershipsController` refuses to create.
 *  2. AN EDGE THAT WOULD MAKE THE SURVIVOR THEIR OWN GUARDIAN. Merging a
 *     guardian into their own ward (or the reverse) is a mis-click, and
 *     `contact_id = guardian_of_contact_id` is a row no part of this application
 *     can mean anything by.
 *
 * THE ONE EXCEPTION TO (1): a duplicate row that CARRIES RECORDS ABOUT A CHILD —
 * behaviour awards, ḥifẓ entries, or a participant thread — is moved anyway, and
 * the duplicate roster line is accepted. A duplicated line is visible on the
 * roster screen and one click to remove; a deleted ḥifẓ history is neither. A
 * de-duplication must never be the thing that destroys a record about a child.
 *
 * A DROP IS NEVER SILENT ANY MORE. Dropping a CONFIRMED guardian edge destroys a
 * decision a staff member deliberately made, and until this round it was
 * reported as `unconfirmed: 0` — nothing at all. Measured, a real parent with an
 * office-confirmed edge, `media` consent and a live portal login, when the office
 * merged two CRM rows FOR HER CHILD in the direction that put the duplicate on
 * top:
 *
 *     MERGE (real child -> duplicate) -> 200
 *       roster {"moved":0,"dropped":2,"unconfirmed":0,
 *               "message":"0 roster entries moved onto this member and 2
 *                          duplicates dropped."}
 *     AFTER  /groups -> 200 {"status":"success","data":[]}
 *            family-login "state":"enabled","eligible":false
 *
 * `GroupMembershipsController::destroy` reaches the SAME cascade and narrates
 * `guardian_edges_removed` / `confirmed_guardian_edges_removed` /
 * `family_logins_left_without_a_ward`; the merge verb got none of it. It does
 * now, from the same three questions, and the DIRECTION of the merge no longer
 * changes what the operator is told.
 *
 * ---------------------------------------------------------------------------
 * A MERGE MOVES ROWS. IT NEVER LAUNDERS AUTHORITY.
 * ---------------------------------------------------------------------------
 *
 * This class was itself one of the doors. The full chain, measured, from a
 * stranger with no account: an anonymous registration named a real child by her
 * address and wrote a duplicate child plus a guardian edge over it; a registrar
 * saw two identical rows and merged them, which is exactly what the office is
 * supposed to do with duplicates; `carry()` re-pointed the edge onto the REAL
 * child; `…/contacts/{payer}/family-login` then answered `"eligible": true`,
 * `enable()` answered 200, and the stranger's own token read her behaviour
 * record ("Left the classroom without permission"), the participant thread
 * "Safeguarding: incident on 3 Sept", and her ḥifẓ. The rule text that shipped
 * with this class said the merge "is where that claim finally gets its
 * authenticated act", and that sentence was wrong: the registrar authenticated a
 * DE-DUPLICATION, and was never shown, asked about, or told of a guardianship.
 * The merge response body carried `{status, data, family_login}` and did not
 * contain the word "guardian"; this method's own return value was DISCARDED by
 * the caller.
 *
 * ===========================================================================
 * THE RULE THIS CLASS NOW HOLDS, IN ONE SENTENCE
 * ===========================================================================
 *
 * A merge may move a roster row. It may keep that row's AUTHORITY only when the
 * IDENTITY THAT AUTHORITY IS READ THROUGH has not changed, and where it has, the
 * row is re-opened as a claim AND SAID OUT LOUD, by role, by name.
 *
 * The previous round wrote half of that: a CONFIRMED edge whose holder or ward
 * changed went back to `self_asserted`. Two things were missing, and both were
 * measured on the wire.
 *
 * ---------------------------------------------------------------------------
 * (a) A PENDING CLAIM HAS AN IDENTITY TOO, AND THE ROW ID IS NOT IT
 * ---------------------------------------------------------------------------
 *
 * `unconfirm()` only fires on a row that IS confirmed. A pending claim that
 * changes holder is already pending, so nothing fired and nothing was counted —
 * and the row ID IS STABLE, so the operator's drawn list still named it.
 * `ConfirmGroupMembershipsRequest` binds the operator's agreement to a list of
 * ids, which is airtight against a row INSERTED after the draw and useless
 * against a row the operator cannot TELL APART because it changed underneath
 * one. Measured, from a stranger with no account:
 *
 *   1. anon POST …/register  payer {name:"Aisha Ahmed", email:"bilal@evil.test"}
 *      -> directory: #1 Fatima Ahmed | #2 Aisha Ahmed <bilal@evil.test>
 *   2. the real family signs up -> pending claim, membership #3, contact #3
 *   3. operator draws roster row #3:
 *        guardian=Aisha Ahmed <aisha@household.test> ward=Fatima Ahmed
 *   4. registrar de-duplicates two "Aisha Ahmed" rows (real -> the stranger's)
 *      MERGE -> 200 {"moved":1,"dropped":0,"unconfirmed":0}
 *   5. THE SAME ROW #3 NOW READS guardian=Aisha Ahmed <bilal@evil.test>
 *      confirm {"membership_ids":[3]} -> 200 {"confirmed":1}
 *   6. eligible=true; family-login -> 200 login_email=bilal@evil.test
 *   7. his own token: awards 200, ḥifẓ 200, "Safeguarding: incident on 3 Sept"
 *
 * So a guardian edge whose PAIR changes is not moved. It is RETIRED AND
 * RE-ISSUED: the old row is deleted and a fresh `self_asserted` claim is written
 * in its place, carrying the group, the role, the ward and the registration that
 * asserted it — and a NEW ID. Step 5 above then answers
 * `{"confirmed":0,"skipped":1}`, because the id the operator agreed to no longer
 * exists, which is exactly true: what they read is not what is there. The office
 * re-reads the roster and confirms the new line, with the guardian's address in
 * front of them, which is the only place that decision can honestly be made.
 *
 * A confirmation names a PAIR — this adult, over this ward. Both ends are now
 * treated the same way, because "the ward moved" and "the holder moved" are the
 * same fact from two sides.
 *
 * ---------------------------------------------------------------------------
 * (b) "THE IDENTITY CHANGED" IS A QUESTION WITH A DIFFERENT ANSWER PER END
 * ---------------------------------------------------------------------------
 *
 * ONE RULE ANSWERS BOTH ENDS, AND IT LIVES IN `App\Support\ContactIdentity`:
 * AN ABSENT VALUE IS NEVER AN IDENTITY MATCH.
 *
 * THE HOLDER END IS AN ADDRESS. A guardian edge authorises a PARENT PORTAL
 * CREDENTIAL, and a credential is minted against an address; a staff caller is
 * resolved to a person by `GroupAudience::identitiesFor()`, which is
 * `LOWER(email)` and nothing else. So when the absorbed row and the survivor
 * carry the SAME NON-EMPTY address, a credential opened on either reaches the
 * same mailbox and the merge has changed nothing that any read path can see —
 * the edge moves and keeps its confirmation. When the addresses differ, OR WHEN
 * EITHER SIDE HAS NONE, the merge has changed the only thing that matters and
 * the edge is re-issued as a claim.
 *
 * The "or when either side has none" is the half a previous round lost, and it
 * cost the whole class. `addressOf()` returned `''` for a contact with no email
 * and the comparison was a bare `!==`, so two ADDRESS-LESS adults compared equal
 * and a confirmed guardianship rode a de-duplication onto a stranger with its
 * consent intact. The measured transcript is in `ContactIdentity`'s docblock;
 * the reason it is stated THERE and not here is that this file had already
 * written the correct rule for the ward end, in prose, twenty lines from the
 * line that contradicted it.
 *
 * The same-address exemption is safe because of a property enforced one file
 * away, not because it is hoped for:
 * `Api\V1\OfferingRegistrationsController::createContact()` "NEVER takes an
 * address another contact already holds" — it nulls the address instead. So the
 * one UNAUTHENTICATED writer in this application cannot put a second row on a
 * real person's address. What it CAN do, and what the defect above turned into a
 * disclosure, is put a second row carrying NO address at all: nulling the
 * address is that method's refusal, and `registrants.*.email` is `nullable`
 * besides. An address-less row is therefore the one shape a stranger can plant,
 * which is precisely why it must never be the shape that matches.
 *
 * THE WARD END IS NOT AN ADDRESS, and must never be treated as one — not even
 * with the fixed comparison. Most children have none (the same file: "A child's
 * identity is not an address: most have none, and the siblings who do share the
 * household mailbox"), so an address test on the ward side would exempt every
 * pair of duplicate child rows that share a household mailbox, and re-open the
 * exact door this class exists to shut: confirm the claim over the phantom
 * child, then merge the phantom into the real one. There is therefore NO
 * exemption on the ward side, and `ContactIdentity` is deliberately not called
 * there. Any change of ward retires and re-issues.
 *
 * ---------------------------------------------------------------------------
 * (c) A PARTICIPANT ROW IS NOT A PAIR, AND MUST NOT BE NARRATED AS ONE
 * ---------------------------------------------------------------------------
 *
 * `leader` and `member` rows are the person's OWN place in a group. They name
 * nobody else, they authorise no disclosure ABOUT anybody else, and a family
 * credential is given nothing at all by one (`GroupAudience::membershipsFor()`
 * drops a parent-portal principal's participant rows). The previous round
 * unconfirmed them on ANY holder change and described what it had done in the
 * words "1 guardian entry now point at this member". Measured, on a teacher's
 * confirmed `leader` row merged onto a target CARRYING HER OWN ADDRESS:
 *
 *     leader row prov=confirmed -> AFTER: prov=self_asserted
 *     posts 403 "You are not entitled to read this group's feed."
 *     threads 403 · awards 403
 *
 * — a teacher locked out of her own classroom by an ordinary de-duplication,
 * told about it in a sentence that named a guardianship, a ward and a specific
 * person, none of which existed on that row.
 *
 * The rule above answers it without a special case: the identity a participant
 * row is read through is the address, so a merge between two rows carrying the
 * SAME address leaves her confirmed and working, and a merge that puts her row
 * on a DIFFERENT address re-opens it as a claim — which is the only case where
 * "somebody else can now be read into this classroom" is even expressible. The
 * row is not retired and re-issued: there is no pair to break, records about
 * children hang off participant ids (`behavior_awards.group_membership_id`,
 * `hifz_entries.group_membership_id`, `group_threads.about_membership_id`), and
 * rotating the id of a row that carries a child's ḥifẓ history to win a race
 * would be the exact trade this class refuses everywhere else.
 */
class RosterMergeService
{
    /**
     * Move `$source`'s roster edges onto `$target`. Call INSIDE the merge
     * transaction and BEFORE `forceDelete()`.
     *
     * @return array{
     *     moved: int,
     *     dropped: int,
     *     unconfirmed: int,
     *     guardian_claims_reissued: int,
     *     participant_rows_reopened: int,
     *     confirmed_guardian_edges_dropped: int,
     *     family_logins_left_without_a_ward: int,
     *     guardian_claims: array<int,array<string,mixed>>,
     * }
     */
    public function carry(Contact $source, Contact $target): array
    {
        $moved = 0;
        $dropped = 0;
        $reissued = 0;
        $repointed = 0;
        $reopened = 0;
        $strandedTwins = 0;
        $participantTwins = 0;

        /** @var array<int,array<string,mixed>> $claims */
        $claims = [];

        /** @var array<int,int> $doomed ids the force-delete's cascade will take */
        $doomed = [];

        /** @var array<int,int> $lockedOut contact ids whose confirmed edge stopped granting */
        $lockedOut = [];

        // THE HOLDER'S IDENTITY, resolved once. `GroupAudience::identitiesFor()`
        // is `LOWER(email)` and nothing else, so this is the whole of what a
        // change of holder can change about who reads through a row.
        //
        // ASKED THROUGH `ContactIdentity` AND NOT WITH AN OPERATOR. The question
        // "are these the same person" has an answer for two real addresses and
        // NO answer for two absences, and a `!==` between two strings cannot
        // express the difference — which is the defect this line used to be.
        // See that class for the rule and for the transcript.
        $holderIdentityChanged = ContactIdentity::changed($source, $target);

        // The source's own place in each group.
        $own = GroupMembership::withoutMasjidScope()
            ->where('contact_id', $source->getKey())
            ->orderBy('id')
            ->get();

        foreach ($own as $membership) {
            // Guardian of the survivor themselves — a self-edge after the move.
            $isSelfEdge = $membership->guardian_of_contact_id !== null
                && (int) $membership->guardian_of_contact_id === (int) $target->getKey();

            if ($isSelfEdge) {
                $doomed[] = (int) $membership->getKey();

                if ($membership->isConfirmed() && $membership->isGuardian()) {
                    // The SURVIVOR is who is left holding whatever this edge
                    // used to grant — the absorbed row's own credential is
                    // `absorbOnMerge()`'s subject and is reported there.
                    $lockedOut[] = (int) $target->getKey();
                }

                $dropped++;

                continue;
            }

            $twin = $this->survivorsTwinOf($target, $membership);

            if ($twin !== null && ! $this->carriesRecordsAboutAChild($membership)) {
                // The row is dropped and its authority is NOT carried onto the
                // twin. The twin belongs to the survivor, and the survivor may
                // be a row nobody vouched for — see the paragraph below.
                //
                // "But the survivor already held a claim over this same ward, so
                // it is evidently the same relationship." It is not evidence: a
                // claim is exactly what an anonymous registration writes, so the
                // same caller who authored the survivor row can author its claim
                // over the ward and collect the confirmation the merge carries.
                // One extra POST, same outcome.
                //
                // So the confirmation stops here — and is COUNTED, because the
                // objection this branch was written for is real and is about
                // SILENCE: an ordinary de-duplication must not end a working
                // parent sign-in with nothing on any screen. It now ends it on
                // the screen the operator did it on, with the count and the door
                // back. Losing a click is recoverable; the other direction is a
                // stranger reading a child's safeguarding record.
                $doomed[] = (int) $membership->getKey();

                if ($membership->isConfirmed() && $twin->isPendingClaim()) {
                    $lockedOut[] = (int) $target->getKey();
                    $strandedTwins++;

                    if (! $membership->isGuardian()) {
                        // A confirmed ENROLMENT dropped in favour of the
                        // survivor's unconfirmed one. Nobody's records open on
                        // it, but the child's roster line is now a claim, and it
                        // is one of the entries `unconfirmed` counts — so it
                        // gets its own sentence rather than being a number the
                        // message does not explain.
                        $participantTwins++;
                    }
                }

                $dropped++;

                continue;
            }

            // ------------------------------------------------- THE ROW MOVES.
            //
            // What travels with it depends on what the row IS, and the two
            // answers are the subject of (b) and (c) in this class's docblock.
            if ($membership->isGuardian()) {
                if ($holderIdentityChanged && ! $this->carriesRecordsAboutAChild($membership)) {
                    // The pair changed at the guardian end: retire and re-issue,
                    // so no agreement made about the old pair — given, or drawn
                    // on a screen and still in flight — can attach to the new
                    // one by naming an id.
                    $claims[] = $this->describe($membership, $source, $target, 'guardian');

                    if ($membership->isConfirmed()) {
                        $lockedOut[] = (int) $target->getKey();
                    }

                    // The ward is copied as it stands, NULL included: a guardian
                    // row with no ward is malformed, and `(int) null` would make
                    // it a foreign key to contact 0 rather than leaving it as
                    // the malformed row it already was.
                    $this->reissue(
                        $membership,
                        (int) $target->getKey(),
                        $membership->guardian_of_contact_id === null
                            ? null
                            : (int) $membership->guardian_of_contact_id,
                    );

                    $reissued++;
                    $moved++;

                    continue;
                }

                if ($holderIdentityChanged) {
                    // A guardian row carrying a record about a child is not a
                    // shape this application writes (`mayReceiveRecordAbout()`
                    // refuses a guardian subject outright), but deleting one
                    // would destroy that record through `cascadeOnDelete`. So it
                    // is moved in place and merely re-opened as a claim: the
                    // weaker guard, taken deliberately, because a de-duplication
                    // must never be the thing that deletes a child's history.
                    $claims[] = $this->describe($membership, $source, $target, 'guardian');

                    if ($membership->isConfirmed()) {
                        $lockedOut[] = (int) $target->getKey();
                    }

                    $membership->unconfirm();
                    $repointed++;
                }
            } elseif ($holderIdentityChanged && $membership->isConfirmed()) {
                // A participant row names one person and nobody else. It goes
                // back to being a claim only because the address it is read
                // through changed — never because "a merge happened".
                $membership->unconfirm();
                $reopened++;
            }

            $membership->contact_id = $target->getKey();
            $membership->save();
            $moved++;
        }

        // The guardian edges other people hold OVER the source. Re-read after the
        // loop above, because a row the source held over ITSELF is impossible but
        // a row it held over someone else has just changed owner.
        $over = GroupMembership::withoutMasjidScope()
            ->where('guardian_of_contact_id', $source->getKey())
            ->orderBy('id')
            ->get();

        foreach ($over as $edge) {
            // `$edge->contact_id !== null` is belt and braces against the shape
            // this round is named after, not a live case: the column is a
            // non-nullable foreign key (create_group_memberships_table). Were it
            // ever relaxed, `(int) null === (int) null` is `0 === 0`, and a real
            // guardian edge would be dropped as a "self-edge" — destroying a
            // staff decision rather than laundering one, but on the same
            // comparison. Said in code so the next reader cannot write it.
            $isSelfEdge = $edge->contact_id !== null
                && (int) $edge->contact_id === (int) $target->getKey();
            $wardTwin = $this->survivorsWardTwinOf($target, $edge);

            if ($isSelfEdge || $wardTwin !== null) {
                // DROPPED, AND NO LONGER IN SILENCE. This is the branch F3 was
                // measured on: `wouldDuplicateWard()` true meant `$dropped++`
                // and nothing else, and the force-delete's cascade then
                // destroyed a staff-confirmed guardianship with `unconfirmed: 0`
                // on the wire. The twin the survivor already holds is a PENDING
                // claim in the case that matters, so the parent is left with a
                // roster line that opens nothing.
                //
                // The confirmation is still not carried onto that twin, for the
                // reason the `$own` branch above gives at length — and here the
                // objection is sharper, not weaker: the twin is held by the SAME
                // contact as the absorbed edge, so an attacker holding a
                // confirmed edge over a phantom child and a pending one over the
                // real child would collect the real one by merging the phantom
                // into her. That is the original door, one authenticated act
                // further along.
                $doomed[] = (int) $edge->getKey();

                if ($edge->isConfirmed()) {
                    $lockedOut[] = (int) $edge->contact_id;

                    if ($wardTwin !== null && $wardTwin->isPendingClaim()) {
                        // The survivor's own line over this pair is a CLAIM, so
                        // there is one roster entry left that needs confirming —
                        // which is what `unconfirmed` counts for the operator.
                        $strandedTwins++;
                    }
                }

                $dropped++;

                continue;
            }

            // THE WARD IS CHANGING. Whatever authority this edge carried was
            // recorded about the person it used to name — and whatever CONSENT
            // was recorded on it was given about that person too, which is why
            // the re-issued row carries neither.
            //
            // No same-address exemption here, deliberately: see (b) in the class
            // docblock. A ward is a child, most have no address at all, and
            // `'' == ''` would make this fire on every pair of duplicate child
            // rows.
            // `isGuardian()` is belt and braces with the invariant that only a
            // guardian row carries a ward: retiring a row is safe BECAUSE the
            // `deleting` hook is a no-op on a guardian row and awards/ḥifẓ/
            // participant threads hang off participant ids. A malformed row that
            // reached this loop some other way is moved in place instead, which
            // is weaker and cannot delete a child's history.
            if ($edge->isGuardian() && ! $this->carriesRecordsAboutAChild($edge)) {
                $claims[] = $this->describe($edge, $source, $target, 'ward');

                if ($edge->isConfirmed()) {
                    $lockedOut[] = (int) $edge->contact_id;
                }

                $this->reissue($edge, (int) $edge->contact_id, (int) $target->getKey());

                $reissued++;
                $moved++;

                continue;
            }

            $claims[] = $this->describe($edge, $source, $target, 'ward');

            if ($edge->isConfirmed()) {
                $lockedOut[] = (int) $edge->contact_id;
                $edge->unconfirm();
            }

            $repointed++;

            $edge->guardian_of_contact_id = $target->getKey();
            $edge->save();
            $moved++;
        }

        return [
            'moved' => $moved,
            'dropped' => $dropped,
            // The umbrella the SPA and the operator read: roster entries that
            // are back to being unconfirmed claims because of this merge.
            // ROSTER ENTRIES THIS MERGE LEFT NEEDING A CONFIRMATION — the
            // number the SPA warns on and the operator acts on. Re-issued
            // claims, rows re-opened in place, and the surviving twin left
            // behind when a confirmed edge went into the cascade: each of those
            // is one line somebody now has to press Confirm on.
            'unconfirmed' => $reissued + $repointed + $reopened + $strandedTwins,
            'guardian_claims_reissued' => $reissued,
            // Guardian edges whose pair changed but which could NOT be retired,
            // because retiring them would have deleted a record kept about a
            // child. Re-pointed in place and re-opened as claims instead — the
            // weaker of the two guards, taken deliberately and counted
            // separately so nobody reads it as the stronger one.
            'guardian_claims_repointed_in_place' => $repointed,
            'participant_rows_reopened' => $reopened,
            'participant_rows_dropped_for_a_claim' => $participantTwins,
            'confirmed_guardian_edges_dropped' => $this->confirmedGuardianEdgesAmong($doomed),
            'family_logins_left_without_a_ward' => $this->strandedLogins($lockedOut, $doomed, $source),
            'guardian_claims' => $claims,
        ];
    }

    /**
     * Retire a guardian edge and write the same pairing back as a FRESH CLAIM.
     *
     * The old id is gone on purpose — it is the entire point. A stale list of
     * ids drawn before the merge names rows that described a different pair, and
     * `GroupMembershipsController::confirm()` skips an id it cannot find as a
     * pending claim of the group, so such a click becomes an honest
     * `{"confirmed":0,"skipped":1}` instead of a silent grant.
     *
     * WHY DELETING IS SAFE ON THIS ROW AND ON NO OTHER. `GroupMembership`'s
     * `deleting` hook cascades the guardian edges pointing at a PARTICIPANT and
     * returns immediately for a guardian row, so retiring one removes exactly one
     * row. The three foreign keys that would take something with them
     * (`behavior_awards`, `hifz_entries`, `group_threads.about_membership_id`)
     * are all keyed to participant rows by construction, and the caller checks
     * for them anyway before choosing this path.
     *
     * `source_registration_id` travels: it is the office's evidence — "which
     * signup asserted this, and into what program" — and re-opening a claim must
     * not also blind the person being asked to judge it.
     */
    private function reissue(GroupMembership $edge, int $contactId, ?int $wardId): GroupMembership
    {
        $fresh = new GroupMembership([
            'masjid_id' => $edge->masjid_id,
            'group_id' => $edge->group_id,
            'contact_id' => $contactId,
            'role' => $edge->role,
            'guardian_of_contact_id' => $wardId,
            'joined_at' => $edge->joined_at,
        ]);

        // No confirmation, and NO CONSENT: a recorded consent is permission to
        // disclose something about ONE named child to ONE named adult, and this
        // row is no longer that pair.
        $fresh->selfAssertedFrom(null);
        $fresh->forceFill([
            'source_registration_id' => $edge->source_registration_id,
            'masjid_id' => $edge->masjid_id,
        ]);

        // The old row first, so the unique index (group, contact, role, ward)
        // cannot be met by the row being replaced.
        $edge->delete();

        $fresh->save();

        return $fresh;
    }

    /**
     * One line of the report, read BEFORE the row changes.
     *
     * The three facts `ConfirmGroupMembershipsRequest` says the operator decides
     * on — "ward names, the claimed guardian, and which signup asserted it" —
     * plus the ADDRESS, because that is what a parent portal credential is minted
     * against and the merge response is the last screen before somebody types it.
     *
     * @return array<string,mixed>
     */
    private function describe(GroupMembership $edge, Contact $source, Contact $target, string $end): array
    {
        $guardian = $end === 'guardian'
            ? $target
            : $this->contactById((int) $edge->contact_id);

        $ward = $end === 'ward'
            ? $target
            : $this->contactById($edge->guardian_of_contact_id);

        // WHAT SEPARATES THE TWO ENDS, said in the terms each end HAS.
        //
        // At the guardian end the address is the discriminator and is the thing
        // a credential is minted against, so it is printed. At the WARD end
        // there is usually no address at all, and the two rows carry the SAME
        // NAME — that is precisely why they looked like duplicates — so the only
        // thing that tells them apart is the directory record number. Printing
        // the name on its own there produces "guardian of Ibrahim Nur (it read
        // Ibrahim Nur)", which is true and tells the office nothing.
        $wardLabel = $this->nameOf($ward);
        $previously = $end === 'guardian'
            ? $this->nameOf($source) . ' <' . $this->addressLabelOf($source) . '>'
            : $this->nameOf($source) . ', member record #' . $source->getKey();

        if ($end === 'ward' && $ward instanceof Contact) {
            $wardLabel .= ', member record #' . $ward->getKey();
        }

        return [
            'end_changed' => $end,
            'group' => $this->groupNameOf($edge),
            'guardian' => $this->nameOf($guardian),
            'guardian_email' => $guardian?->email,
            // Said rather than left null, for the reason `addressLabelOf()`
            // gives: this line is read by an operator deciding whether a
            // guardianship should have moved, and "" is not an answer.
            'guardian_address' => $this->addressLabelOf($guardian),
            'guardian_contact_id' => $guardian?->getKey(),
            'ward' => $wardLabel,
            'ward_contact_id' => $ward?->getKey(),
            // What the row used to say at the end that moved. The operator read
            // THIS on the roster; the row now says the line above.
            'previously' => $previously,
            'previous_contact_id' => (int) $source->getKey(),
            'was_confirmed' => $edge->isConfirmed(),
            // The COLUMNS, not the permission. This line says what the merge is
            // about to erase from the record; `hasConsent()` would answer false
            // for a row whose consent already granted nothing, and the office
            // would watch a `consent_scope` disappear while being told nothing
            // was withdrawn. See `GroupMembership::consentColumnsAreSet()`.
            'consent_withdrawn' => $edge->consentColumnsAreSet(),
            'source_registration_id' => $edge->source_registration_id,
        ];
    }

    /**
     * How many of the rows this merge leaves for the cascade were CONFIRMED
     * guardian edges — the count `GroupMembershipsController::destroy()` calls
     * `confirmed_guardian_edges_removed`, asked of the same cascade by the other
     * verb that reaches it.
     *
     * @param  array<int,int>  $doomed
     */
    private function confirmedGuardianEdgesAmong(array $doomed): int
    {
        if ($doomed === []) {
            return 0;
        }

        return GroupMembership::withoutMasjidScope()
            ->whereIn('id', $doomed)
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->confirmed()
            ->count();
    }

    /**
     * Parent portal sign-ins that this merge leaves opening NOTHING.
     *
     * The same question `destroy()` asks, of the same people, one verb over: a
     * guardian whose confirmed edge this merge destroyed or re-opened, who still
     * holds a live credential, and who has no OTHER confirmed guardian edge over
     * a ward anywhere in this organisation once the force-delete has run.
     *
     * Stricter than `destroy()`'s version by one clause, deliberately: it counts
     * only CONFIRMED remaining edges, because a pending claim is precisely a row
     * that opens nothing (`GroupAudience::membershipsFor()` filters on
     * `confirmed()`), so a parent left holding only claims is a parent whose
     * sign-in opens nothing — which is the sentence this number is printed
     * inside.
     *
     * The absorbed contact is excluded: `FamilyAccessService::absorbOnMerge()`
     * has already revoked its credential loudly and reported that separately, so
     * counting it here would say the same thing twice.
     *
     * @param  array<int,int>  $lockedOut
     * @param  array<int,int>  $doomed
     */
    private function strandedLogins(array $lockedOut, array $doomed, Contact $source): int
    {
        $candidates = array_values(array_unique(array_filter(
            $lockedOut,
            fn (int $contactId): bool => $contactId !== (int) $source->getKey(),
        )));

        if ($candidates === []) {
            return 0;
        }

        return collect($candidates)
            ->filter(function (int $contactId) use ($doomed): bool {
                $contact = Contact::withoutMasjidScope()->whereKey($contactId)->first();

                if (! $contact instanceof Contact || ! $contact->familyLoginIsActive()) {
                    return false;
                }

                return ! GroupMembership::withoutMasjidScope()
                    ->where('contact_id', $contactId)
                    ->where('role', GroupMembership::ROLE_GUARDIAN)
                    ->whereNotNull('guardian_of_contact_id')
                    ->confirmed()
                    ->when($doomed !== [], fn ($q) => $q->whereNotIn('id', $doomed))
                    ->exists();
            })
            ->count();
    }

    /**
     * The survivor's own row for this exact (group, role, ward) edge, or null.
     *
     * Was `wouldDuplicate(): bool`. It returns the ROW now because dropping a
     * duplicate is no longer only a question of rows — the authority on the two
     * halves can differ, and the caller has to be able to read the survivor's.
     */
    private function survivorsTwinOf(Contact $target, GroupMembership $membership): ?GroupMembership
    {
        return GroupMembership::withoutMasjidScope()
            ->where('group_id', $membership->group_id)
            ->where('contact_id', $target->getKey())
            ->where('role', $membership->role)
            ->when(
                $membership->guardian_of_contact_id === null,
                fn ($q) => $q->whereNull('guardian_of_contact_id'),
                fn ($q) => $q->where('guardian_of_contact_id', $membership->guardian_of_contact_id),
            )
            ->first();
    }

    /**
     * The survivor-side row this edge would collide with — the same holder, over
     * the SURVIVOR as ward — or null.
     *
     * Was `wouldDuplicateWard(): bool`, and it returns the ROW for the same
     * reason `survivorsTwinOf()` does: dropping a duplicate is not only a
     * question of rows. The authority on the two halves can differ, and the
     * caller has to be able to say whether what is left behind is a confirmed
     * entry (nothing to do) or a claim somebody now has to press Confirm on.
     */
    private function survivorsWardTwinOf(Contact $target, GroupMembership $edge): ?GroupMembership
    {
        return GroupMembership::withoutMasjidScope()
            ->where('group_id', $edge->group_id)
            ->where('contact_id', $edge->contact_id)
            ->where('role', $edge->role)
            ->where('guardian_of_contact_id', $target->getKey())
            ->first();
    }

    /**
     * Would dropping this row delete something kept about a person?
     *
     * Awards and ḥifẓ entries cascade off `group_membership_id`; a participant
     * thread's `about_membership_id` nulls, which is a conversation about one
     * child degraded to leaders-only. Any of the three is worth a duplicate line
     * on a roster screen.
     */
    private function carriesRecordsAboutAChild(GroupMembership $membership): bool
    {
        return $membership->behaviorAwards()->exists()
            || $membership->hifzEntries()->exists()
            || GroupThread::withoutMasjidScope()
                // withTrashed: a thread soft-deleted today can be restored, and
                // the FK nulls at the database regardless of that column.
                ->withTrashed()
                ->where('about_membership_id', $membership->getKey())
                ->exists();
    }

    /**
     * The address a merge report PRINTS for a contact, or an explicit refusal to
     * print a blank.
     *
     * NOT AN IDENTITY, and it must never be used as one — that is why it is a
     * display helper on this class rather than a method on `ContactIdentity`,
     * which deliberately hands back no string at all. Two contacts with no
     * address both label as "no address on file", and comparing those two labels
     * would be the very bug this round removed.
     *
     * The blank is the thing being refused. `GroupRosterTab.vue` learned the
     * same lesson on the roster ("a blank is the thing the operator reads
     * straight past") and this report is the last screen an operator sees before
     * a guardianship changes hands.
     */
    private function addressLabelOf(?Contact $contact): string
    {
        $address = trim((string) $contact?->email);

        return $address === '' ? 'no address on file' : $address;
    }

    private function contactById(?int $id): ?Contact
    {
        return $id === null
            ? null
            : Contact::withoutMasjidScope()->withTrashed()->whereKey($id)->first();
    }

    private function nameOf(?Contact $contact): ?string
    {
        return $contact instanceof Contact
            ? trim($contact->first_name . ' ' . $contact->last_name)
            : null;
    }

    private function groupNameOf(GroupMembership $edge): ?string
    {
        return Group::withoutMasjidScope()->whereKey($edge->group_id)->value('name');
    }
}
