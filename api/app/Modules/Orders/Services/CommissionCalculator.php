<?php

namespace App\Modules\Orders\Services;

/** Comissão em INTEIROS (centavos e pontos-base). Sem float: arredonda para cima só na metade exata (half-up). */
class CommissionCalculator
{
    /** @return array{platform_fee: int, seller_earning: int} */
    public function split(int $priceCents, int $commissionBps): array
    {
        $commissionBps = max(0, min(10000, $commissionBps));
        $fee = intdiv($priceCents * $commissionBps + 5000, 10000);
        $fee = min($fee, $priceCents);

        return ['platform_fee' => $fee, 'seller_earning' => $priceCents - $fee];
    }
}
