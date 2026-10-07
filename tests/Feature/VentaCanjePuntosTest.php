<?php

namespace Tests\Feature;

use App\Events\VentaCompletada;
use App\Http\Controllers\Api\VentaController;
use App\Models\Caja;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Regresión: el canje de puntos se restaba dos veces (el POS mandaba `total`
 * ya descontado y el backend volvía a restar), descuadrando venta y caja.
 */
class VentaCanjePuntosTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Caja $caja;
    private Cliente $cliente;
    private Producto $producto;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([VentaCompletada::class]); // fake() global apagaría también los eventos de Eloquent

        $empresa = Empresa::query()->create([
            'nombre' => 'Tienda puntos',
            'slug'   => 'tienda-puntos',
            'email'  => 'puntos@example.test',
        ]);
        app()->instance('tenant_id', $empresa->id);

        Configuracion::query()->create([
            'empresa_id' => $empresa->id,
            'config'     => ['loyalty' => [
                'activo' => true, 'modo' => 'total',
                'monto_por_punto' => 100, 'puntos_otorgados' => 10, 'valor_punto' => 1,
            ]],
        ]);

        $this->user = User::query()->create([
            'name' => 'Cajero', 'email' => 'cajero@example.test',
            'password' => 'secret123', 'empresa_id' => $empresa->id,
        ]);

        $this->caja = Caja::query()->create([
            'empresa_id' => $empresa->id, 'user_id' => $this->user->id,
            'fondo_inicial' => 0, 'abierta_at' => now(), 'estado' => 'abierta',
        ]);

        $this->cliente = Cliente::query()->create([
            'empresa_id' => $empresa->id, 'nombre' => 'Cliente frecuente',
            'points_balance' => 50, 'lifetime_points' => 50,
        ]);

        $this->producto = Producto::query()->create([
            'empresa_id' => $empresa->id, 'nombre' => 'Producto',
            'precio' => 100, 'stock' => 10, 'control_stock' => true,
        ]);
    }

    private function vender(array $payload): Venta
    {
        $request = Request::create('/api/ventas', 'POST', $payload);
        $request->setUserResolver(fn () => $this->user);

        $response = app(VentaController::class)->store($request);
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        return Venta::query()->findOrFail($response->getData()->id);
    }

    private function payload(array $override): array
    {
        return array_merge([
            'tipo_pago'        => 'efectivo',
            'cliente_id'       => $this->cliente->id,
            'subtotal'         => 100,
            'impuesto'         => 0,
            'puntos_canjeados' => 10,
            'items'            => [[
                'producto_id' => $this->producto->id, 'cantidad' => 1,
                'precio_unitario' => 100, 'descuento' => 0, 'subtotal' => 100,
            ]],
            'pagos' => [['metodo' => 'efectivo', 'monto' => 90, 'cambio' => 0]],
        ], $override);
    }

    public function test_pos_nuevo_descuenta_puntos_una_sola_vez(): void
    {
        // POS actual: manda total/descuento SIN puntos.
        $venta = $this->vender($this->payload(['descuento' => 0, 'total' => 100]));

        $this->assertEquals(90.0, (float) $venta->total);
        $this->assertEquals(10.0, (float) $venta->descuento);
        $this->assertEquals(90.0, (float) $this->caja->fresh()->total_efectivo);
        $this->assertSame(40 + 9, $this->cliente->fresh()->points_balance); // 50 - 10 canjeados + 9 ganados ($90)
    }

    public function test_pos_viejo_con_total_ya_descontado_no_resta_dos_veces(): void
    {
        // Desktop / ventas offline con el POS anterior: total y descuento ya traían los puntos.
        $venta = $this->vender($this->payload(['descuento' => 10, 'total' => 90]));

        $this->assertEquals(90.0, (float) $venta->total);
        $this->assertEquals(10.0, (float) $venta->descuento);
        $this->assertEquals(90.0, (float) $this->caja->fresh()->total_efectivo);
    }
}
