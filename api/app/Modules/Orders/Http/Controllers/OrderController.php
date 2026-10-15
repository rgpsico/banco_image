<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Modules\Orders\Http\Requests\CreateOrderRequest;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Payments\Support\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    private const WITH = ['items.license', 'items.listing', 'payments'];

    public function store(CreateOrderRequest $request, OrderService $orders): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        $order = $orders->create($request->user(), $request->validated('items'), $key ? mb_substr($key, 0, 60) : null);

        return (new OrderResource($order->load(self::WITH)))->response()->setStatusCode($order->wasRecentlyCreated ? 201 : 200);
    }

    public function index(Request $request)
    {
        return OrderResource::collection(Order::where('buyer_id', $request->user()->id)->with(self::WITH)->latest('id')->paginate(20));
    }

    public function show(Request $request, string $order): OrderResource
    {
        return new OrderResource($this->mine($request, $order)->load(self::WITH));
    }

    /** Inicia o pagamento. Resposta traz o status; no gateway simulado não há redirecionamento. */
    public function pay(Request $request, string $order, PaymentService $payments): JsonResponse
    {
        $model = $this->mine($request, $order);
        $payment = $payments->start($model);

        return response()->json(['data' => [
            'payment' => ['id' => $payment->ulid, 'status' => $payment->status->value, 'gateway' => $payment->gateway],
            'simulated' => $payment->gateway === 'simulated',
        ]]);
    }

    /** Confirma o pagamento SIMULADO (mesmo caminho do webhook real). Indisponível fora do gateway simulado. */
    public function simulatePayment(Request $request, string $order, PaymentService $payments): OrderResource
    {
        abort_unless(config('mediahub.payments.gateway') === 'simulated' && app()->environment(['local', 'testing']), 404);

        $model = $this->mine($request, $order);
        $payment = $payments->start($model);
        $payments->handle('simulated', new WebhookEvent('evt_' . strtolower((string) Str::ulid()), 'payment.confirmed', $payment->gateway_reference));

        return new OrderResource($model->fresh()->load(self::WITH));
    }

    private function mine(Request $request, string $ulid): Order
    {
        return Order::where('ulid', $ulid)->where('buyer_id', $request->user()->id)->firstOrFail();
    }
}
