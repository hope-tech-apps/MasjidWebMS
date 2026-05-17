<?php

namespace App\Http\Requests\Admin\DonationLink;

use App\Http\Requests\BaseFormRequest;

class SaveDonationLinkRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'link' => 'required|url',
        ];
    }
}
