<?php

namespace App\Modules\Collections\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttachMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'media' => ['required', 'array', 'min:1', 'max:200'],
            'media.*' => ['string', 'size:26', 'distinct'],
        ];
    }
}
