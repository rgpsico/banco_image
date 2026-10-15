<?php

namespace App\Modules\Media\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // MediaPolicy@create no controller
    }

    public function rules(): array
    {
        return [
            'original_name' => ['required', 'string', 'max:255'],
            'mime_type' => ['required', 'string', 'max:100'],
            'size_bytes' => ['required', 'integer', 'min:1'],
            'chunk_size' => ['required', 'integer', 'min:1', 'max:' . config('mediahub.max_chunk_size')],
        ];
    }
}
