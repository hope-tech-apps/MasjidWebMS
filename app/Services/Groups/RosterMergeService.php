<?php

namespace App\Services\Groups;

use App\Models\Contact;
use App\Models\GroupMembership;
use App\Models\GroupThread;

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
 * deletes anything, so no `deleting` hook fires half-way through a merge.
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
 * The merge response body carries `{status, data, family_login}` and did not
 * contain the word "guardian"; this method's own return value was DISCARDED by
 * the caller.
 *
 * Provenance makes the rule expressible instead of aspirational
 * (`GroupMembership::PROVENANCES`), and it holds in both directions:
 *
 *  - A `self_asserted` row that moves STAYS `self_asserted`. Nothing here
 *    upgrades one, so re-pointing a manufactured edge onto a surviving contact
 *    yields a manufactured edge over the surviving contact, which grants
 *    nothing and sits in the office's pending queue naming the registration that
 *    asserted it.
 *  - A `confirmed` guardian edge that is re-pointed at a DIFFERENT WARD drops
 *    BACK to `self_asserted`. What a staff member confirmed was "this adult is
 *    the guardian of THAT NAMED PERSON"; re-pointing changes the person, so the
 *    confirmation stops describing the row it sits on. This is the same door one
 *    authenticated act further along — confirm the claim over the phantom child,
 *    then merge the phantom into the real one — and it is shut by the same
 *    sentence rather than by a fourth special case.
 *
 * And the opposite failure is closed too, because a de-duplication must not
 * QUIETLY REVOKE either: when the survivor already holds the same edge as an
 * unconfirmed claim and the absorbed row was confirmed, the confirmation is
 * carried onto the survivor's row instead of being dropped with it. The ward is
 * the same person in that case — only the guardian's own CRM row changed, which
 * is the thing the merge asserts was always one human.
 */
class RosterMergeService
{
    /**
     * Move `$source`'s roster edges onto `$target`. Call INSIDE the merge
     * transaction and BEFORE `forceDelete()`.
     *
     * @return array{moved: int, dropped: int, unconfirmed: int, confirmations_carried: int}
     */
    public function carry(Contact $source, Contact $target): array
    {
        $moved = 0;
        $dropped = 0;
        $unconfirmed = 0;
        $confirmationsCarried = 0;

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
                if ($membership->isConfirmed() && $twin->isPendingClaim()) {
                    $unconfirmed++;
                }

                $dropped++;

                continue;
            }

            // THE HOLDER IS CHANGING, so the confirmation does not travel with
            // the row.
            //
            // A confirmation names a PAIR — this guardian, over this ward — and
            // a staff member vouched for that pair. Re-pointing either end makes
            // it a statement about somebody they were never asked about. The
            // `$over` loop below already refuses this when the WARD moves; the
            // holder moving is the same fact from the other side, and treating
            // it as safe rested on an assumption nothing records: that the
            // surviving CRM row is one a human vouched for. `contacts` has no
            // provenance, so a row an anonymous registration authored is
            // byte-indistinguishable — on the directory screen, in the merge
            // search list, and here — from one a registrar typed.
            //
            // Measured on the version that carried it: an anonymous POST seeded
            // a second "Fatima Ahmed", a registrar merged the real one into it,
            // and the confirmed guardian edge arrived on the stranger's row
            // still confirmed, with the recorded media consent on it. That
            // credential then read the child's awards, her ḥifẓ, the thread
            // "Safeguarding: incident on 3 Sept", and the bytes of a class
            // photograph.
            //
            // The edge still MOVES. Destroying it is the opposite failure — a
            // de-duplication that quietly strips a family off a roster — so the
            // roster line survives as a claim the office can confirm, and the
            // merge response says how many went back to being claims rather
            // than narrating only the ward-side count.
            if ($membership->isConfirmed()) {
                $membership->unconfirm();
                $unconfirmed++;
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
            $isSelfEdge = (int) $edge->contact_id === (int) $target->getKey();

            if ($isSelfEdge || $this->wouldDuplicateWard($target, $edge)) {
                $dropped++;

                continue;
            }

            // THE WARD IS CHANGING. Whatever authority this edge carried was
            // recorded about the person it used to name.
            if ($edge->isConfirmed()) {
                $edge->unconfirm();
                $unconfirmed++;
            }

            $edge->guardian_of_contact_id = $target->getKey();
            $edge->save();
            $moved++;
        }

        return [
            'moved' => $moved,
            'dropped' => $dropped,
            'unconfirmed' => $unconfirmed,
            'confirmations_carried' => $confirmationsCarried,
        ];
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

    /** Does the survivor already hold a guardian edge over themselves here? */
    private function wouldDuplicateWard(Contact $target, GroupMembership $edge): bool
    {
        return GroupMembership::withoutMasjidScope()
            ->where('group_id', $edge->group_id)
            ->where('contact_id', $edge->contact_id)
            ->where('role', $edge->role)
            ->where('guardian_of_contact_id', $target->getKey())
            ->exists();
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
}
