<?php

namespace App\Modules\Marketplace\Actions;

use App\Models\SellerProfile;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BecomeSeller
{
    public function handle(User $user, string $displayName, ?string $bio): SellerProfile
    {
        $already = ValidationException::withMessages(['seller' => ['Você já é vendedor.']]);

        // Consulta o banco (não a relação em cache) e trata a corrida de dois cliques simultâneos.
        if (SellerProfile::where('user_id', $user->id)->exists()) {
            throw $already;
        }

        try {
            $profile = SellerProfile::create([
                'user_id' => $user->id,
                'slug' => $this->uniqueSlug($displayName),
                'display_name' => $displayName,
                'bio' => $bio,
                'status' => 'active',
                'terms_accepted_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw $already;
        }

        $user->unsetRelation('sellerProfile');

        Audit::record('seller.joined', ['seller' => $profile->slug]);

        return $profile;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'vendedor';
        $slug = $base;
        $i = 2;

        while (SellerProfile::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }
}
