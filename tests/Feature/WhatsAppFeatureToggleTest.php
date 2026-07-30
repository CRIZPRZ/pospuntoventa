<?php

namespace Tests\Feature;

use App\Models\Configuracion;
use App\Models\Empresa;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppFeatureToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_whatsapp_send_features_are_disabled_by_default(): void
    {
        $empresa = $this->empresa();

        $this->assertFalse(
            app(WhatsAppService::class)->isFeatureEnabled(
                $empresa->id,
                null,
                'auto_send_order_ready',
            ),
        );
    }

    public function test_only_checked_whatsapp_send_features_are_enabled(): void
    {
        $empresa = $this->empresa();

        Configuracion::query()->create([
            'empresa_id' => $empresa->id,
            'config' => [
                'whatsapp' => [
                    'auto_send_order_ready' => true,
                    'auto_send_invoice' => false,
                ],
            ],
        ]);

        $service = app(WhatsAppService::class);

        $this->assertTrue($service->isFeatureEnabled($empresa->id, null, 'auto_send_order_ready'));
        $this->assertFalse($service->isFeatureEnabled($empresa->id, null, 'auto_send_invoice'));
        $this->assertFalse($service->isFeatureEnabled($empresa->id, null, 'unknown_feature'));
    }

    private function empresa(): Empresa
    {
        return Empresa::query()->create([
            'nombre' => 'Empresa de prueba',
            'slug' => 'empresa-whatsapp-' . uniqid(),
            'email' => uniqid() . '@example.test',
        ]);
    }
}
