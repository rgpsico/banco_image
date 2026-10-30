<?php

namespace App\Models;

use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id', 'uploaded_by', 'type', 'original_name', 'display_name', 'tags', 'disk', 'path', 'mime_type',
    'extension', 'size_bytes', 'checksum_sha256', 'width', 'height', 'duration_seconds', 'metadata', 'status', 'taken_at',
])]
// Caminho interno, disco e ids numéricos nunca saem pela API.
#[Hidden(['id', 'tenant_id', 'uploaded_by', 'disk', 'path', 'checksum_sha256'])]
class Media extends Model
{
    use BelongsToTenant, HasFactory, HasUlid, SoftDeletes;

    protected $table = 'media';

    protected function casts(): array
    {
        return [
            'type' => MediaType::class,
            'status' => MediaStatus::class,
            'metadata' => 'array',
            'size_bytes' => 'integer',
            'taken_at' => 'datetime',
        ];
    }

    public function variants(): HasMany
    {
        return $this->hasMany(MediaVariant::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class, 'collection_media')
            ->withoutGlobalScope('tenant')
            ->withPivot('position')
            ->withTimestamps();
    }

    public function listing(): HasOne
    {
        return $this->hasOne(Listing::class);
    }

    /** @return list<string> palavras-chave limpas: minúsculas, sem repetição, até 20 de 30 letras. */
    public static function normalizeTags(array|string|null $tags): array
    {
        $list = is_array($tags) ? $tags : preg_split('/[,;\n]+/', (string) $tags);

        return collect($list)
            ->map(fn ($t) => mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $t))), 0, 30))
            ->map(fn ($t) => mb_strtolower($t))
            ->filter()->unique()->take(20)->values()->all();
    }

    /** @return list<string> */
    public function tagList(): array
    {
        return $this->tags ? array_values(array_filter(explode(',', $this->tags))) : [];
    }

    /** Cada palavra digitada precisa aparecer no nome OU nas palavras-chave (até 5 palavras). */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        foreach (array_slice(preg_split('/\s+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY), 0, 5) as $word) {
            $like = '%' . addcslashes($word, '%_\\') . '%';
            $query->where(fn (Builder $q) => $q->where('display_name', 'like', $like)->orWhere('tags', 'like', $like));
        }

        return $query;
    }

    /**
     * Mídias de uma ou mais pastas (ULIDs) e de TODAS as subpastas delas.
     * @param  list<string>  $ulids
     */
    public function scopeInCollections(Builder $query, array $ulids): Builder
    {
        $all = Collection::query()->get(['id', 'parent_id', 'ulid']);
        $ids = $all->whereIn('ulid', $ulids)->pluck('id')->all();

        for ($frontier = $ids; $frontier; ) {
            $frontier = $all->whereIn('parent_id', $frontier)->pluck('id')->diff($ids)->values()->all();
            $ids = array_merge($ids, $frontier);
        }

        return $query->whereHas('collections', fn ($q) => $q->whereIn('collections.id', $ids ?: [0]));
    }
}
