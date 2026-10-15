<?php

namespace App\Modules\Marketplace\Services;

use App\Enums\MediaStatus;
use App\Models\Listing;
use Illuminate\Validation\ValidationException;

/** Requisitos para enviar uma publicação à moderação / ao catálogo. */
class ListingRules
{
    /** @throws ValidationException */
    public function assertReadyToSubmit(Listing $listing): void
    {
        $listing->loadMissing(['media.variants', 'prices']);
        $errors = [];
        $media = $listing->media;

        if (mb_strlen(trim($listing->title)) < 3) {
            $errors['title'] = ['Informe um título com ao menos 3 caracteres.'];
        }
        if (mb_strlen(trim((string) $listing->description)) < 10) {
            $errors['description'] = ['Descreva a mídia (ao menos 10 caracteres).'];
        }
        if (! $listing->category || ! array_key_exists($listing->category, config('mediahub.marketplace.categories'))) {
            $errors['category'] = ['Escolha uma categoria.'];
        }
        if ($listing->prices->isEmpty()) {
            $errors['prices'] = ['Defina o preço de ao menos uma licença.'];
        }
        if (! $listing->rights_declared_at) {
            $errors['rights_declared'] = ['É necessário declarar que você é o autor ou tem os direitos para vender esta mídia.'];
        }
        if ($listing->has_people && ! $listing->model_release_confirmed) {
            $errors['model_release_confirmed'] = ['Mídia com pessoas reconhecíveis exige a confirmação de autorização de uso de imagem.'];
        }
        if ($media->status !== MediaStatus::Ready || ! $media->variants->contains(fn ($v) => $v->kind->value === 'preview')) {
            $errors['media'] = ['A mídia ainda está sendo processada ou falhou. Aguarde a miniatura ficar pronta.'];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
