<?php

namespace Tests\Concerns;

use App\Models\Listing;
use App\Models\Order;
use App\Models\User;
use App\Modules\Payments\Gateways\SimulatedGateway;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

trait MakesOrders
{
    /** Cria um pedido pela API. @param list<array{0: Listing, 1: string}> $lines [listing, licença] */
    protected function placeOrder(User $buyer, array $lines, ?string $key = null): TestResponse
    {
        Sanctum::actingAs($buyer);

        return $this->postJson('/api/v1/orders', ['items' => array_map(fn ($l) => ['listing' => $l[0]->ulid, 'license' => $l[1]], $lines)],
            $key ? ['Idempotency-Key' => $key] : []);
    }

    /** Pedido PAGO (pelo mesmo caminho do webhook). */
    protected function paidOrder(User $buyer, array $lines): Order
    {
        $id = $this->placeOrder($buyer, $lines)->assertSuccessful()->json('data.id');
        $this->postJson("/api/v1/orders/{$id}/simulate-payment")->assertOk()->assertJsonPath('data.status', 'paid');

        return Order::where('ulid', $id)->firstOrFail();
    }

    /** Envia um webhook do gateway simulado (assinado, a menos que $signature seja informada). */
    protected function webhook(array $body, ?string $signature = null, string $gateway = 'simulated'): TestResponse
    {
        $json = json_encode($body);

        return $this->call('POST', "/api/v1/webhooks/{$gateway}", [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => $signature ?? SimulatedGateway::sign($json),
        ], $json);
    }
}
