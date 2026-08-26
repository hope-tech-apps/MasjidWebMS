<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Contacts\SetAvatarRequest;
use App\Models\Contact;
use App\Support\Avatar;
use Symfony\Component\HttpFoundation\Response;

/**
 * A person's avatar: the catalogue to choose from, and the choice itself.
 *
 * Gated by the CONTACTS permissions, like the rest of the directory: an avatar
 * is an attribute of a person's record, and anyone trusted to edit the directory
 * is trusted to set one. It mints no new permission, which would change the
 * seeded set RolePermissionBridgeTest pins.
 *
 * `findOrFail` is tenant-scoped, so another organisation's contact id resolves
 * to a 404 rather than letting one school dress another school's child.
 */
class ContactAvatarController extends Controller
{
    /**
     * The forty drawings that ship, plus the swatches a picker needs.
     *
     * Served rather than hardcoded in the client so the two cannot drift: when
     * a colour is added, the picker gains it without a front-end release.
     */
    public function catalogue()
    {
        return response()->json([
            'status' => 'success',
            'data' => Avatar::catalogue(),
        ], Response::HTTP_OK);
    }

    /**
     * Staff setting an avatar writes an OVERRIDE, never the child's own choice.
     *
     * A child choosing how they are represented is theirs. A teacher's change is
     * laid on top — so `destroyOverride()` below restores what the child picked,
     * because it was never touched. Overwriting it would make a roster tidy-up
     * silently destroy something a seven-year-old made.
     */
    public function update(SetAvatarRequest $request, $masjid_id, $contact_id)
    {
        $contact = Contact::findOrFail($contact_id);

        $contact->fill([
            'staff_avatar_character' => $request->input('character') ?: null,
            'staff_avatar_tone' => $request->input('tone') ?: null,
            'staff_avatar_color' => $request->input('color') ?: null,
        ])->save();

        return response()->json([
            'status' => 'success',
            'message' => $contact->hasStaffAvatarOverride()
                ? 'Avatar set for this person.'
                : ($contact->studentAvatarParts() !== null
                    ? 'Override removed — showing the student’s own avatar again.'
                    : 'Avatar cleared.'),
            'data' => $contact->fresh(),
        ], Response::HTTP_OK);
    }

    /**
     * Put back whatever the student chose.
     *
     * Modelled on ClassDojo's "Restore student-created monster": the override is
     * dropped and the child's own choice reappears untouched. Idempotent — a
     * person with no override is already showing their own.
     */
    public function destroyOverride($masjid_id, $contact_id)
    {
        $contact = Contact::findOrFail($contact_id);

        $contact->fill([
            'staff_avatar_character' => null,
            'staff_avatar_tone' => null,
            'staff_avatar_color' => null,
        ])->save();

        return response()->json([
            'status' => 'success',
            'message' => $contact->studentAvatarParts() !== null
                ? 'Showing the student’s own avatar again.'
                : 'Override removed. This person has not chosen an avatar yet.',
            'data' => $contact->fresh(),
        ], Response::HTTP_OK);
    }
}
