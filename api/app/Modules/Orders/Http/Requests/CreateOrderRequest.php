<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Só ids (ULID) e a licença: valores e comissão nunca vêm do cliente. */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.listing' => ['required', 'string', 'size:26'],
            'items.*.license' => ['required', 'string', 'max:30'],
        ];
    }
}
