<?php

namespace Tests\Unit;

use App\Modules\Orders\Services\CommissionCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CommissionCalculatorTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_split_uses_integer_math_and_always_sums_to_the_price(int $price, int $bps, int $fee, int $earning): void
    {
        $split = (new CommissionCalculator())->split($price, $bps);

        $this->assertSame($fee, $split['platform_fee']);
        $this->assertSame($earning, $split['seller_earning']);
        $this->assertSame($price, $split['platform_fee'] + $split['seller_earning']); // nunca perde nem cria centavo
    }

    public static function cases(): array
    {
        return [
            'padrão 20%' => [1500, 2000, 300, 1200],
            'arredonda metade para cima' => [999, 2000, 200, 799],      // 199,8 -> 200
            'abaixo da metade' => [333, 2500, 83, 250],                // 83,25 -> 83
            'metade exata' => [1250, 1000, 125, 1125],
            'centavo único' => [1, 2000, 0, 1],                        // 0,2 -> 0
            'sem comissão' => [5000, 0, 0, 5000],
            '100%' => [5000, 10000, 5000, 0],
            'acima de 100% é limitado' => [5000, 99999, 5000, 0],
            'negativa é zerada' => [5000, -50, 0, 5000],
            'valor grande' => [10_000_000, 2000, 2_000_000, 8_000_000],
        ];
    }
}
