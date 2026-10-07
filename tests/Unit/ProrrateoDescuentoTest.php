<?php

namespace Tests\Unit;

use App\Support\ProrrateoDescuento;
use PHPUnit\Framework\TestCase;

class ProrrateoDescuentoTest extends TestCase
{
    public function test_sin_descuento_global_regresa_importes_igual(): void
    {
        $this->assertSame([100.0, 50.5], ProrrateoDescuento::repartir([100, 50.5], 150.5));
    }

    public function test_reparte_proporcional_y_suma_exacto_el_total(): void
    {
        $r = ProrrateoDescuento::repartir([100, 50], 140);

        $this->assertEqualsWithDelta(93.33, $r[0], 0.001);
        $this->assertEqualsWithDelta(46.67, $r[1], 0.001);
        $this->assertEqualsWithDelta(140.0, array_sum($r), 0.0001);
    }

    public function test_residuo_de_redondeo_lo_absorbe_la_partida_mayor(): void
    {
        $r = ProrrateoDescuento::repartir([33.33, 33.33, 33.34], 90);

        $this->assertEqualsWithDelta(90.0, array_sum($r), 0.0001);
        foreach ($r as $importe) {
            $this->assertGreaterThan(0, $importe);
        }
    }

    public function test_total_cero_deja_todo_en_cero(): void
    {
        $this->assertEqualsWithDelta(0.0, array_sum(ProrrateoDescuento::repartir([20, 30], 0)), 0.0001);
    }
}
