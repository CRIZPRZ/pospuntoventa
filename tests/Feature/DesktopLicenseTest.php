<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DesktopLicenseTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_activate_desktop_license_device(): void
    {
        [$empresa, $user] = $this->createEmpresaConUsuarioActivo();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/desktop/license/activate', [
            'device_uuid' => 'desktop-001',
            'device_name' => 'Caja Principal',
            'fingerprint' => 'fp-001',
            'platform' => 'windows',
            'app_version' => '1.0.0',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.access.allowed', true)
            ->assertJsonPath('data.device.device_uuid', 'desktop-001')
            ->assertJsonPath('data.license.resolved_status', 'active');

        $this->assertDatabaseHas('licenses', [
            'empresa_id' => $empresa->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('license_devices', [
            'device_uuid' => 'desktop-001',
            'device_name' => 'Caja Principal',
            'is_active' => true,
        ]);
    }

    public function test_license_validation_respects_grace_period_for_expired_company_access(): void
    {
        [$empresa, $user] = $this->createEmpresaConUsuarioActivo();

        Sanctum::actingAs($user);

        $activation = $this->postJson('/api/desktop/license/activate', [
            'device_uuid' => 'desktop-001',
            'device_name' => 'Caja Principal',
            'fingerprint' => 'fp-001',
            'platform' => 'windows',
        ])->assertOk()->json('data');

        $empresa->update([
            'plan_estado' => 'vencido',
            'plan_vigente_hasta' => now()->subDay(),
        ]);

        $empresa->license()->update([
            'grace_until' => now()->addHours(48),
        ]);

        $response = $this->postJson('/api/desktop/license/validate', [
            'license_key' => $activation['license']['key'],
            'device_uuid' => 'desktop-001',
            'fingerprint' => 'fp-001',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.access.allowed', true)
            ->assertJsonPath('data.license.resolved_status', 'grace');
    }

    public function test_license_cannot_activate_more_devices_than_allowed(): void
    {
        [$empresa, $user] = $this->createEmpresaConUsuarioActivo();

        Sanctum::actingAs($user);

        $this->postJson('/api/desktop/license/activate', [
            'device_uuid' => 'desktop-001',
            'device_name' => 'Caja Principal',
            'fingerprint' => 'fp-001',
            'platform' => 'windows',
        ])->assertOk();

        $response = $this->postJson('/api/desktop/license/activate', [
            'device_uuid' => 'desktop-002',
            'device_name' => 'Caja Secundaria',
            'fingerprint' => 'fp-002',
            'platform' => 'windows',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['device_uuid']);
    }

    private function createEmpresaConUsuarioActivo(): array
    {
        $plan = Plan::create([
            'nombre' => 'Pro',
            'descripcion' => 'Plan Pro',
            'precio_mensual' => 999,
            'max_sucursales' => 3,
            'max_usuarios' => 10,
            'timbres_incluidos' => 200,
            'modulos' => ['dashboard', 'pos', 'ventas'],
            'color' => '#2563eb',
            'tipo' => 'stripe',
            'activo' => true,
        ]);

        $empresa = Empresa::create([
            'nombre' => 'Desktop Test',
            'slug' => 'desktop-test',
            'email' => 'desktop@test.local',
            'plan_id' => $plan->id,
            'plan_estado' => 'activo',
            'plan_vigente_hasta' => now()->addMonth(),
        ]);

        foreach (['dashboard', 'pos', 'ventas'] as $module) {
            $empresa->modulos()->create([
                'modulo_key' => $module,
                'activo' => true,
            ]);
        }

        $user = User::factory()->create([
            'empresa_id' => $empresa->id,
            'email' => 'desktop-user@test.local',
        ]);

        return [$empresa->fresh(), $user];
    }
}
