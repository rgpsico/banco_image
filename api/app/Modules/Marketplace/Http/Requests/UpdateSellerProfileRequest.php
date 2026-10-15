<?php

namespace App\Modules\Marketplace\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSellerProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'display_name' => ['sometimes', 'required', 'string', 'min:2', 'max:80'],
            'bio' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['display_name', 'bio'] as $f) {
            if (is_string($this->$f)) {
                $this->merge([$f => trim(strip_tags($this->$f))]);
            }
        }
    }
}
