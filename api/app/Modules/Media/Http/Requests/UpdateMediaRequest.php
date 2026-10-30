<?php

namespace App\Modules\Media\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Aceita as palavras-chave como lista ou como texto separado por vírgula. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('tags'))) {
            $this->merge(['tags' => array_values(array_filter(array_map('trim', explode(',', $this->input('tags')))))]);
        }
    }

    public function rules(): array
    {
        return [
            'display_name' => ['required_without:tags', 'string', 'min:1', 'max:150'],
            'tags' => ['sometimes', 'nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:30'],
        ];
    }
}
