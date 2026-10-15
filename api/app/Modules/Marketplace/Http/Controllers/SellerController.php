<?php

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Actions\BecomeSeller;
use App\Modules\Marketplace\Http\Requests\JoinSellerRequest;
use App\Modules\Marketplace\Http\Requests\UpdateSellerProfileRequest;
use App\Modules\Marketplace\Http\Resources\SellerProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SellerController extends Controller
{
    public function join(JoinSellerRequest $request, BecomeSeller $action): JsonResponse
    {
        $profile = $action->handle($request->user(), $request->validated('display_name'), $request->validated('bio'));

        return (new SellerProfileResource($profile))->response()->setStatusCode(201);
    }

    /** Qualquer usuário logado: informa se já é vendedor (para o front decidir o que mostrar). */
    public function status(Request $request): JsonResponse
    {
        $profile = $request->user()->sellerProfile;

        return response()->json(['data' => $profile ? new SellerProfileResource($profile) : null]);
    }

    public function show(Request $request): SellerProfileResource
    {
        return new SellerProfileResource($request->user()->sellerProfile);
    }

    public function update(UpdateSellerProfileRequest $request): SellerProfileResource
    {
        $profile = $request->user()->sellerProfile;
        $profile->update($request->validated());

        return new SellerProfileResource($profile->fresh());
    }
}
