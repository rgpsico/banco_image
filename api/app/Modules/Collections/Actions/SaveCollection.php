<?php

namespace App\Modules\Collections\Actions;

use App\Models\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Criar/renomear/mover pastas, com proteção contra ciclos e slug único entre irmãs. */
class SaveCollection
{
    /** @param array{name?: string, parent_id?: ?string, description?: ?string} $data */
    public function handle(Collection $collection, array $data, bool $isNew): Collection
    {
        if (array_key_exists('parent_id', $data)) {
            $parent = $data['parent_id'] ? $this->parent($data['parent_id']) : null;

            if ($parent && ! $isNew) {
                $this->assertNoCycle($collection, $parent);
            }

            $collection->parent_id = $parent?->id;
        }

        if (array_key_exists('name', $data)) {
            $collection->name = $data['name'];
        }

        if (array_key_exists('description', $data)) {
            $collection->description = $data['description'];
        }

        if ($isNew || $collection->isDirty(['name', 'parent_id'])) {
            $collection->slug = $this->uniqueSlug($collection);
        }

        $collection->save();

        return $collection;
    }

    /** O pai precisa ser do MESMO projeto (global scope): ulid de outro projeto vira "não encontrado". */
    private function parent(string $ulid): Collection
    {
        $parent = Collection::where('ulid', $ulid)->first();

        if (! $parent) {
            throw ValidationException::withMessages(['parent_id' => ['Pasta pai não encontrada.']]);
        }

        return $parent;
    }

    private function assertNoCycle(Collection $collection, Collection $newParent): void
    {
        for ($node = $newParent; $node; $node = $node->parent_id ? Collection::find($node->parent_id) : null) {
            if ($node->id === $collection->id) {
                throw ValidationException::withMessages(['parent_id' => ['Uma pasta não pode ser movida para dentro dela mesma.']]);
            }
        }
    }

    private function uniqueSlug(Collection $collection): string
    {
        $base = Str::slug($collection->name) ?: 'pasta';
        $slug = $base;
        $i = 2;

        while (Collection::where('parent_id', $collection->parent_id)->where('slug', $slug)->where('id', '!=', $collection->id ?? 0)->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }
}
