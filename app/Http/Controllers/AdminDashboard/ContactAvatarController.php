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

    public function update(SetAvatarRequest $request, $masjid_id, $contact_id)
    {
        $contact = Contact::findOrFail($contact_id);

        $contact->fill([
            'avatar_character' => $request->input('character') ?: null,
            'avatar_tone' => $request->input('tone') ?: null,
            'avatar_color' => $request->input('color') ?: null,
        ])->save();

        return response()->json([
            'status' => 'success',
            'message' => $contact->avatar === null
                ? 'Avatar cleared.'
                : 'Avatar updated.',
            'data' => $contact->fresh(),
        ], Response::HTTP_OK);
    }
}
