<?php

namespace Database\Factories;

use App\Models\MediaVariant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Enums\VariantKind;

/** @extends Factory<MediaVariant> */
class MediaVariantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'media_id' => \App\Models\Media::factory(),
            'kind' => VariantKind::Thumb,
            'disk' => 'media',
            'path' => 'variants/' . Str::ulid() . '.jpg',
            'width' => 320,
            'height' => 213,
            'size_bytes' => 12_000,
        ];
    }
}
