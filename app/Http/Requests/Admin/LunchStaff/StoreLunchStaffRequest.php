<?php

namespace App\Http\Requests\Admin\LunchStaff;

use App\Http\Requests\BaseFormRequest;
use App\Support\OrganisationAccess;
use App\Support\StaffArea;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Creating a Jummah-lunch staff login.
 *
 * NOTE WHAT IS ABSENT: `type`. The login is always created as
 * User::TYPE_LUNCH_STAFF by the controller, so no request body can ask for a
 * MasjidAdmin — which is what makes it safe for a MasjidAdmin to hold this
 * endpoint at all.
 */
class StoreLunchStaffRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:120',
            'email' => ['required', 'email', 'max:190', $this->oneAddressOneLogin()],
            'phone' => 'nullable|string|max:32',
        ];
    }

    /**
     * Unique across the whole users table, not per masjid: one address is one
     * login, and a duplicate would make the invite ambiguous.
     *
     * While one login may hold a role at each organisation (StaffArea::open()) there
     * is one kind of address with a login that this door does not turn away here:
     * a LIVE login of the two scoped kinds, a teacher's or a lunch-staff one,
     * because a person who teaches somewhere may be given lunch access with the
     * login they already have. Whether THIS one may is decided by the controller,
     * under a lock and from the login's rows (LunchStaffController::createOrAttach):
     * a login that already runs lunch anywhere is answered there, in this rule's
     * own words. The rule looks past those rows and nothing else, so every other
     * address with a login (an administrator, a member, a SuperAdmin, anybody
     * archived) is still refused by this rule, in the same body and alongside any
     * other field's error, exactly as before. With the switch off it is the rule it
     * always was.
     */
    private function oneAddressOneLogin(): Unique
    {
        $rule = Rule::unique('users', 'email');

        if (! StaffArea::open()) {
            return $rule;
        }

        return $rule->where(fn ($query) => $query->where(
            fn ($login) => $login->whereNotIn('type', OrganisationAccess::SCOPED_TYPES)->orWhereNotNull('deleted_at')
        ));
    }
}
