<?php

namespace App\Http\Requests\Admin\Masjids;

use App\Http\Requests\BaseFormRequest;
use App\Models\City;
use App\Models\User;

class StoreMasjidRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string',
            'email' => 'required|email|unique:masjids,email',
            'phone' => 'required|string|regex:/^\+?[0-9 ]+$/',
            'logo' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:25600',
            'footer_logo' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:25600',
            'longitude' => 'required|numeric|min:-180|max:180',
            'latitude' => 'required|numeric|min:-90|max:90',
            'address' => 'required|string',
            'timezone' => 'required|string|timezone',
            'user_id' => [
                'nullable',
                'exists:users,id',
                'unique:masjids,user_id',
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
