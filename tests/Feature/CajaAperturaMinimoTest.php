<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\CajaController;
use App\Models\Caja;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/** El fondo mínimo de apertura se validaba solo en el frontend. */
class CajaAperturaMinimoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $empresa = Empresa::query()->create(['nombre' => 'Tienda', 'slug' => 'tienda-caja', 'email' => 'caja@example.test']);
        app()->instance('tenant_id', $empresa->id);

        $this->user = User::query()->create([
            'name' => 'Cajero', 'email' => 'cajero-caja@example.test',
            'password' => 'secret123', 'empresa_id' => $empresa->id,
        ]);
    }

    private function abrir(float $fondo): \Illuminate\Http\JsonResponse
    {
        $request = Request::create('/api/caja/abrir', 'POST', ['fondo_inicial' => $fondo]);
        $request->setUserResolver(fn () => $this->user);

        return app(CajaController::class)->abrir($request);
    }

    public function test_rechaza_fondo_menor_al_minimo(): void
    {
        $response = $this->abrir(500);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('1,000.00', $response->getData()->message);
        $this->assertSame(0, Caja::query()->count());
    }

    public function test_permite_fondo_igual_o_mayor_al_minimo(): void
    {
        $this->assertSame(201, $this->abrir(1000)->getStatusCode());
    }
}
