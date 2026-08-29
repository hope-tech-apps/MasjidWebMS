<?php

namespace App\Http\Requests\Admin\Groups;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Validator;

/**
 * Confirm the roster claims a public registration form asserted.
 *
 * The operator's agreement is a DISCLOSURE DECISION about a named child, so this
 * request's whole job is to make the agreement bind to something. Three fields,
 * and each one exists because binding to the previous set of them was not enough.
 *
 * ## 1. `membership_ids` — bind to a SET (round five)
 *
 * It used to be optional, and an absent body meant "every pending claim in THIS
 * group at the moment the request LANDS" — which is not the same set as the one
 * the operator read. Measured: an operator loaded a roster showing eight claims,
 * an ordinary anonymous registration arrived while the confirmation dialog was
 * open, and the click returned `{"confirmed":9}`. The ninth was a stranger's
 * claim over a named child, and confirming it took his access from 403 to her
 * behaviour record, her ḥifẓ and the participant thread titled "Safeguarding:
 * incident on 3 Sept".
 *
 * That still holds and is still tested. It defeats INSERTION.
 *
 * ## 2. `fingerprints` — bind to the CONTENT of each row
 *
 * An id identifies a row; it does not identify what the row SAID. A merge
 * re-points `contact_id` on a pending claim and the id does not change, so the
 * operator confirms a relationship they never read; and a merge that
 * force-deletes an absorbed payer nulls `registrations.contact_id`, degrading
 * the provenance evidence with no change to the membership row at all.
 *
 * So the caller echoes back, per id, the fingerprint `index()` served with that
 * row (`App\Support\RosterClaimIdentity::fingerprint`). A row that no longer
 * matches its description is skipped and reported. REQUIRED, and required to
 * cover every named id, for the same reason `membership_ids` is: a guard a
 * client can decline to participate in is a guard that ends up asserted in a
 * docblock and absent from the wire — which is the pattern this area keeps
 * repeating. It costs the bulk affordance nothing; the client sends the
 * fingerprints it drew alongside the ids it drew, in the same one POST.
 *
 * ## 3. `contested_membership_ids` — bind the shape the operator CANNOT READ
 *
 * Measured, and the reason this field is not merely a nicety. Four public
 * registrations on a camp form wired to Grade 3: three real families, and one
 * stranger who knew a child's name and household address, typed the MOTHER's
 * name as payer and his own email.
 *
 *     GUARDIAN ROW #2  "Aisha Ahmed" -> "Fatima Ahmed"  aisha@household.test
 *     GUARDIAN ROW #7  "Aisha Ahmed" -> "Fatima Ahmed"  bilal@evil.test
 *
 * Naming both ids binds the agreement perfectly to two rows the operator could
 * not tell apart. Binding is not the same as understanding. So a pending
 * guardian claim that shares its ward AND its displayed name with another row is
 * refused a place in the sweep, and can only be confirmed by being named a
 * SECOND time here — which the SPA does only from a dialog that puts the rival
 * claimants' addresses side by side.
 *
 * This docblock previously argued that the agreement was safe because "ward
 * names, the claimed guardian, and which signup asserted it are all on the
 * screen the button lives on". Measured at the time: `GroupRosterTab.vue`
 * contained `source_registration` 0 times, `confirmed_by` 0 times, and its only
 * two `email` occurrences were in the add-to-roster directory picker. The
 * sentence had asserted the safeguard into existence. It is now a description of
 * what `index()` serves and what the roster tables draw, and there is a test
 * that fails if either stops.
 *
 * ## What is still NOT here
 *
 * Deliberately still not a tenant-wide verb. The group is the smallest scope in
 * which the person clicking can see what they are vouching for.
 *
 * The ids are only shape-checked. Whether they exist, belong to this group, and
 * are actually pending is settled in the controller against the tenant-scoped
 * group — an `exists:` rule here would duplicate the guardrail and would confirm
 * the existence of another organisation's row in a validation message. See
 * .claude/rules/tenant-scoping.md. The same reasoning keeps the FINGERPRINT
 * check out of this class: comparing it needs the row, and the row is only
 * safely reachable through the tenant-scoped group.
 */
class ConfirmGroupMembershipsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'membership_ids' => 'required|array|min:1',
            'membership_ids.*' => 'integer',

            // Keyed BY membership id, so a shifted array cannot pair a
            // fingerprint with the wrong row. `array` rather than a list.
            'fingerprints' => 'required|array|min:1',
            'fingerprints.*' => 'required|string',

            // A SUBSET of membership_ids, checked below. Nothing may be
            // confirmed that was not also named as an ordinary entry.
            'contested_membership_ids' => 'sometimes|array',
            'contested_membership_ids.*' => 'integer',
        ];
    }

    /**
     * The two cross-field rules, both of which are refusals of a MALFORMED
     * request rather than judgements about live rows.
     *
     * A row that is named and not described is a client that lost track of what
     * it drew — distinct from a row whose description no longer matches, which
     * is a live-data event the controller answers with a 200 and a skip. Those
     * two must not share an answer: one is a bug to fix, the other is a fact to
     * read.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Only meaningful once the shapes hold; otherwise the shape errors
            // already say what is wrong.
            if ($validator->errors()->hasAny(['membership_ids', 'fingerprints'])) {
                return;
            }

            $ids = array_map('intval', array_filter(
                (array) $this->input('membership_ids', []),
                'is_scalar',
            ));

            $described = array_map('intval', array_keys((array) $this->input('fingerprints', [])));

            if (array_diff($ids, $described) !== []) {
                $validator->errors()->add(
                    'fingerprints',
                    'Say what you were shown for every entry. Confirming grants access to a child\'s '
                        . 'records, and a roster row can be re-pointed at a different child between the '
                        . 'moment it is drawn and the moment it is agreed to.',
                );
            }

            $contested = array_map('intval', array_filter(
                (array) $this->input('contested_membership_ids', []),
                'is_scalar',
            ));

            if (array_diff($contested, $ids) !== []) {
                $validator->errors()->add(
                    'contested_membership_ids',
                    'Every entry decided individually must also be named among the entries being confirmed.',
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'membership_ids.required' => 'Name the roster entries to confirm. Confirming grants access to a '
                . "child's records, so it applies only to the entries you were shown.",
            'membership_ids.min' => 'Name the roster entries to confirm.',
            'fingerprints.required' => 'Say what you were shown for every entry. Confirming grants access to a '
                . "child's records, and a roster row can be re-pointed at a different child between the moment "
                . 'it is drawn and the moment it is agreed to.',
        ];
    }
}
