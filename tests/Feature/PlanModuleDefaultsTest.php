<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Plan;
use Database\Seeders\PlanesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PlanModuleDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixed_plans_exclude_cameras_and_enable_whatsapp_from_pro(): void
    {
        $this->seed(PlanesSeeder::class);

        $basico = Plan::where('nombre', 'Básico')->firstOrFail();
        $pro = Plan::where('nombre', 'Pro')->firstOrFail();
        $ilimitado = Plan::where('nombre', 'Ilimitado')->firstOrFail();

        $this->assertNotContains('camaras', $basico->modulos);
        $this->assertNotContains('camaras', $pro->modulos);
        $this->assertNotContains('camaras', $ilimitado->modulos);
        $this->assertNotContains('whatsapp', $basico->modulos);
        $this->assertContains('whatsapp', $pro->modulos);
        $this->assertContains('whatsapp', $ilimitado->modulos);
    }

    public function test_new_trial_has_neither_cameras_nor_whatsapp(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/register', [
            'empresa_nombre' => 'Tenant limpio',
            'email' => 'tenant-limpio@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'website' => '',
            'flow_started_at' => now()->subSeconds(5)->getTimestampMs(),
        ]);

        $response->assertSuccessful();

        $empresa = Empresa::where('email', 'tenant-limpio@example.test')->firstOrFail();
        $activos = $empresa->modulos()->where('activo', true)->pluck('modulo_key')->all();

        $this->assertNotContains('camaras', $activos);
        $this->assertNotContains('whatsapp', $activos);
    }
}
