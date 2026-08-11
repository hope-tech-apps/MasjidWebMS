<?php

namespace App\Http\Requests\Admin\Masjids;

use App\Http\Requests\BaseFormRequest;
use App\Models\City;
use App\Models\User;
use Illuminate\Validation\Rule;

class UpdateMasjidRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string',
            'email' => 'required|email',
            'phone' => 'required|string|regex:/^\+?[0-9 ]+$/',
            'logo' => 'image|mimes:jpeg,png,jpg,gif,webp|max:25600',
            'footer_logo' => 'image|mimes:jpeg,png,jpg,gif,webp|max:25600',
            'longitude' => 'required|numeric|min:-180|max:180',
            'latitude' => 'required|numeric|min:-90|max:90',
            'address' => 'required|string',
            'timezone' => 'nullable|string|timezone',
            'user_id' => [
                'nullable',
                'exists:users,id',
                // S0 — ownership determinism (docs/multi-tenant-admin-design.md).
                // StoreMasjidRequest and ProvisionMasjidRequest have carried
                // `unique:masjids,user_id` all along; this request did not, which is
                // how one user could end up owning two masjids and `User::masjid()`
                // (a hasOne) could start returning an arbitrary one. The DB index
                // added in add_owner_uniqueness_to_masjids_table is the real
                // guarantee; this rule is what turns the resulting constraint
                // violation into the 422 the admin SPA already knows how to render.
                //
                // Two qualifiers keep it EXACTLY as permissive as that index, so no
                // update that succeeds today starts failing:
                //   - ignore(this masjid): re-submitting the masjid's own current
                //     owner is the normal case on every save, and must pass.
                //   - whereNull(deleted_at): a trashed masjid does not pin its
                //     former owner, matching the index's partial predicate.
                Rule::unique('masjids', 'user_id')
                    ->ignore($this->route('masjid_id'))
                    ->whereNull('deleted_at'),
                function ($attribute, $value, $fail) {
                    $user = User::where('id', $value)->where('type', 'MasjidAdmin')->first();
                    if (!$user) {
                        $fail('The selected user is not of a Masjid Admin type.');
                    }
                },
            ],
            'country_id' => 'required|exists:countries,id',
            'city_id' => [
                'required',
                'exists:cities,id',
                function ($attribute, $value, $fail) {
                    $city = City::where('id', $value)
                        ->where('country_id', $this->input('country_id'))
                        ->exists();
                    if (!$city) {
                        $fail('The selected city does not belong to the given country.');
                    }
                },
            ],
        ];
    }
}
