<?php

namespace App\Modules\Media\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(['image', 'video'])],
            'collection' => ['nullable', 'string', 'max:300', 'regex:/^[0-9a-z]{26}(,[0-9a-z]{26})*$/i'], // uma ou várias pastas (ULIDs separados por vírgula)
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
