<?php

namespace App\Modules\Marketplace\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // posse da publicação verificada no controller
    }

    public function rules(): array
    {
        return [
            'media_id' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'string', 'size:26'],
            'title' => ['sometimes', 'string', 'min:3', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'category' => ['sometimes', 'nullable', Rule::in(array_keys(config('mediahub.marketplace.categories')))],
            'tags' => ['sometimes', 'array', 'max:' . config('mediahub.marketplace.max_tags')],
            'tags.*' => ['string', 'max:30'],
            'prices' => ['sometimes', 'array', 'max:10'],
            'prices.*.license' => ['required', 'string', 'max:30'],
            'prices.*.price_cents' => ['required', 'integer', 'min:1'],
            'has_people' => ['sometimes', 'boolean'],
            'model_release_confirmed' => ['sometimes', 'boolean'],
            'rights_declared' => ['sometimes', 'boolean'],
        ];
    }

    /** O preço chega SEMPRE em centavos inteiros; "1500" (texto) é aceito e convertido. */
    protected function prepareForValidation(): void
    {
        if (is_array($this->prices)) {
            $this->merge(['prices' => collect($this->prices)->map(function ($p) {
                if (is_array($p) && isset($p['price_cents']) && is_numeric($p['price_cents']) && floor((float) $p['price_cents']) == (float) $p['price_cents']) {
                    $p['price_cents'] = (int) $p['price_cents'];
                }

                return $p;
            })->all()]);
        }
    }
}
