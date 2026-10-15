<?php

namespace App\Modules\Marketplace\Actions;

use App\Enums\ListingStatus;
use App\Enums\TenantRole;
use App\Models\License;
use App\Models\Listing;
use App\Models\Media;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveListing
{
    /** Mídia que o usuário PODE vender: de um projeto dele em que seja editor ou mais. Outra coisa: "não encontrada". */
    public function findSellableMedia(User $user, string $ulid): Media
    {
        $media = Media::withoutGlobalScope('tenant')->where('ulid', $ulid)->first();

        if (! $media || ! ($user->roleIn($media->tenant)?->atLeast(TenantRole::Editor))) {
            throw ValidationException::withMessages(['media_id' => ['Mídia não encontrada nos seus projetos.']]);
        }

        if (Listing::withTrashed()->where('media_id', $media->id)->exists()) {
            throw ValidationException::withMessages(['media_id' => ['Esta mídia já tem uma publicação.']]);
        }

        return $media;
    }

    /** @param array<string, mixed> $data */
    public function create(User $user, Media $media, array $data): Listing
    {
        $listing = new Listing([
            'seller_id' => $user->id,
            'tenant_id' => $media->tenant_id,
            'media_id' => $media->id,
            'status' => ListingStatus::Draft,
            'title' => $data['title'] ?? $media->display_name,
        ]);

        return $this->persist($listing, $data, true);
    }

    /** @param array<string, mixed> $data */
    public function update(Listing $listing, array $data): Listing
    {
        $status = $listing->status;

        if (! in_array($status, [ListingStatus::Draft, ListingStatus::Rejected, ListingStatus::Archived, ListingStatus::Published], true)) {
            throw ValidationException::withMessages(['status' => ['Esta publicação não pode ser editada agora.']]);
        }

        // Publicada: só preços e tags. Mudar texto exige despublicar (e passar pela moderação de novo).
        if ($status === ListingStatus::Published) {
            foreach (['title', 'description', 'category', 'has_people', 'model_release_confirmed'] as $field) {
                if (array_key_exists($field, $data) && $data[$field] != $listing->{$field}) {
                    throw ValidationException::withMessages([$field => ['Despublique a mídia para alterar este campo.']]);
                }
            }
        }

        return $this->persist($listing, $data, false);
    }

    /** @param array<string, mixed> $data */
    private function persist(Listing $listing, array $data, bool $isNew): Listing
    {
        return DB::transaction(function () use ($listing, $data, $isNew) {
            foreach (['title', 'description', 'category', 'has_people', 'model_release_confirmed'] as $field) {
                if (array_key_exists($field, $data)) {
                    $listing->{$field} = $field === 'title' || $field === 'description' ? $this->clean($data[$field]) : $data[$field];
                }
            }

            if (array_key_exists('tags', $data)) {
                $tags = $this->normalizeTags($data['tags'] ?? []);
                $listing->tags = $tags;
                $listing->tags_text = implode(' ', $tags);
            }

            if (! empty($data['rights_declared']) && ! $listing->rights_declared_at) {
                $listing->rights_declared_at = now(); // declaração de direitos autorais (guardada com data)
            }

            $listing->save();

            if (array_key_exists('prices', $data)) {
                $this->syncPrices($listing, $data['prices'] ?? []);
            }

            Audit::record($isNew ? 'listing.created' : 'listing.updated', ['listing' => $listing->ulid]);

            return $listing->load(['prices.license', 'media.variants']);
        });
    }

    /** @param list<array{license: string, price_cents: int}> $prices */
    private function syncPrices(Listing $listing, array $prices): void
    {
        $licenses = License::where('is_active', true)->get()->keyBy('code');
        $min = (int) config('mediahub.marketplace.min_price_cents');
        $max = (int) config('mediahub.marketplace.max_price_cents');
        $keep = [];

        foreach ($prices as $i => $row) {
            $license = $licenses[$row['license'] ?? ''] ?? null;
            $cents = $row['price_cents'] ?? null;

            if (! $license) {
                throw ValidationException::withMessages(["prices.{$i}.license" => ['Licença inválida.']]);
            }
            if (! is_int($cents) || $cents < $min || $cents > $max) {
                throw ValidationException::withMessages(["prices.{$i}.price_cents" => ['Preço fora do permitido (de R$ ' . number_format($min / 100, 2, ',', '.') . ' a R$ ' . number_format($max / 100, 2, ',', '.') . ').']]);
            }

            $listing->prices()->updateOrCreate(['license_id' => $license->id], ['price_cents' => $cents, 'currency' => 'BRL']);
            $keep[] = $license->id;
        }

        $listing->prices()->whereNotIn('license_id', $keep)->delete();
    }

    /** @return list<string> */
    private function normalizeTags(array $tags): array
    {
        $max = (int) config('mediahub.marketplace.max_tags');

        return collect($tags)
            ->map(fn ($t) => mb_strtolower(trim(strip_tags((string) $t))))
            ->filter(fn ($t) => $t !== '' && mb_strlen($t) <= 30)
            ->unique()->take($max)->values()->all();
    }

    private function clean(?string $text): ?string
    {
        return $text === null ? null : trim(strip_tags($text));
    }
}
