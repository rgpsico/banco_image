<?php

namespace App\Modules\Marketplace\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class JoinSellerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'min:2', 'max:80'],
            'bio' => ['nullable', 'string', 'max:500'],
            'accept_terms' => ['accepted'], // responsabilidade por direitos autorais e autorização de imagem
        ];
    }

    public function messages(): array
    {
        return ['accept_terms.accepted' => 'É necessário aceitar os termos do vendedor.'];
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
