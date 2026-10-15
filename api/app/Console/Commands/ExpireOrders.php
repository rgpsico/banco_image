<?php

namespace App\Console\Commands;

use App\Modules\Orders\Services\OrderService;
use Illuminate\Console\Command;

class ExpireOrders extends Command
{
    protected $signature = 'mediahub:expire-orders';
    protected $description = 'Marca como expirados os pedidos pendentes vencidos.';

    public function handle(OrderService $orders): int
    {
        $this->info('Pedidos expirados: ' . $orders->expireStale());

        return self::SUCCESS;
    }
}
