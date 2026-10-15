<?php

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Gestão de quem é administrador da plataforma, pela interface (sem comando no servidor). */
class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $v = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'admins' => ['nullable', 'boolean']]);
        $q = trim((string) ($v['q'] ?? ''));

        $page = User::query()
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('name', 'like', '%' . addcslashes($q, '%_\\') . '%')->orWhere('email', 'like', '%' . addcslashes($q, '%_\\') . '%')))
            ->when($request->boolean('admins'), fn ($query) => $query->where('is_platform_admin', true))
            ->orderByDesc('is_platform_admin')->latest('id')->paginate(25);

        return response()->json([
            'data' => $page->getCollection()->map(fn (User $u) => $this->row($u, $request->user()))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function update(Request $request, string $user): JsonResponse
    {
        $v = $request->validate(['is_admin' => ['required', 'boolean']]);
        $target = User::where('ulid', $user)->firstOrFail();

        if ($target->is($request->user())) {
            return response()->json(['message' => 'Você não pode alterar o seu próprio acesso de administrador.', 'errors' => ['user' => ['Você não pode alterar o seu próprio acesso de administrador.']]], 422);
        }

        if (! $v['is_admin'] && $target->isConfiguredAdmin()) {
            return response()->json(['message' => 'Este administrador é fixo (definido na configuração do servidor).', 'errors' => ['user' => ['Este administrador é fixo (definido na configuração do servidor).']]], 422);
        }

        $target->forceFill(['is_platform_admin' => $v['is_admin']])->save();
        Audit::record($v['is_admin'] ? 'admin.granted' : 'admin.revoked', ['target' => $target->ulid, 'by' => $request->user()->ulid]);

        return response()->json(['data' => $this->row($target->fresh(), $request->user())]);
    }

    private function row(User $u, User $me): array
    {
        return [
            'id' => $u->ulid, 'name' => $u->name, 'email' => $u->email,
            'is_admin' => $u->isPlatformAdmin(), 'fixed_admin' => $u->isConfiguredAdmin(), 'is_me' => $u->is($me),
            'created_at' => $u->created_at?->toIso8601String(),
        ];
    }
}
