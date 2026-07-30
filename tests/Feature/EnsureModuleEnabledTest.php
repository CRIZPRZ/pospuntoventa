<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureModuleEnabled;
use App\Models\Empresa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class EnsureModuleEnabledTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_a_disabled_module(): void
    {
        $empresa = $this->createEmpresa();
        $empresa->modulos()->create(['modulo_key' => 'whatsapp', 'activo' => false]);
        app()->instance('tenant_id', $empresa->id);

        $response = $this->middlewareResponse();

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_it_allows_an_enabled_module(): void
    {
        $empresa = $this->createEmpresa();
        $empresa->modulos()->create(['modulo_key' => 'whatsapp', 'activo' => true]);
        app()->instance('tenant_id', $empresa->id);

        $response = $this->middlewareResponse();

        $this->assertSame(200, $response->getStatusCode());
    }

    private function createEmpresa(): Empresa
    {
        return Empresa::query()->create([
            'nombre' => 'Empresa de prueba',
            'slug' => 'empresa-prueba-' . uniqid(),
            'email' => uniqid() . '@example.test',
        ]);
    }

    private function middlewareResponse()
    {
        return app(EnsureModuleEnabled::class)->handle(
            Request::create('/api/whatsapp/status'),
            fn () => response()->json(['ok' => true]),
            'whatsapp',
        );
    }
}
