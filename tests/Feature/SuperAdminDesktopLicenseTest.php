<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Plan;
use App\Models\User;
use App\Services\DesktopLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuperAdminDesktopLicenseTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_view_company_license_with_devices(): void
    {
        [$empresa, $superadmin] = $this->createEmpresaAndSuperadmin();
        $this->activateSampleDevice($empresa);

        Sanctum::actingAs($superadmin);

        $response = $this->getJson("/api/superadmin/empresas/{$empresa->id}/license");

        $response->assertOk()
            ->assertJsonPath('data.empresa.id', $empresa->id)
            ->assertJsonPath('data.license.max_devices', 1)
            ->assertJsonPath('data.devices.0.device_uuid', 'desktop-001');
    }

    public function test_superadmin_can_update_license_status_and_device_limit(): void
    {
        [$empresa, $superadmin] = $this->createEmpresaAndSuperadmin();
        $this->activateSampleDevice($empresa);

        Sanctum::actingAs($superadmin);

        $response = $this->putJson("/api/superadmin/empresas/{$empresa->id}/license", [
            'status' => 'suspended',
            'max_devices' => 3,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.license.status', 'suspended')
            ->assertJsonPath('data.license.resolved_status', 'suspended')
            ->assertJsonPath('data.license.max_devices', 3)
            ->assertJsonPath('data.access.allowed', false);
    }

    public function test_superadmin_can_revoke_device(): void
    {
        [$empresa, $superadmin] = $this->createEmpresaAndSuperadmin();
        $this->activateSampleDevice($empresa);

        Sanctum::actingAs($superadmin);

        $response = $this->postJson("/api/superadmin/empresas/{$empresa->id}/license/devices/desktop-001/revoke");

        $response->assertOk()
            ->assertJsonPath('data.devices.0.device_uuid', 'desktop-001')
            ->assertJsonPath('data.devices.0.is_active', false);

        $this->assertDatabaseHas('license_devices', [
            'device_uuid' => 'desktop-001',
            'is_active' => false,
        ]);
    }

    private function activateSampleDevice(Empresa $empresa): void
    {
        $service = app(DesktopLicenseService::class);
        $license = $service->getOrCreateForEmpresa($empresa);

        $service->activateDevice($license, [
            'device_uuid' => 'desktop-001',
            'device_name' => 'Caja Principal',
            'fingerprint' => 'fp-001',
            'platform' => 'windows',
            'app_version' => '1.0.0',
        ]);
    }

    private function createEmpresaAndSuperadmin(): array
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
            'nombre' => 'Empresa Licencia',
            'slug' => 'empresa-licencia',
            'email' => 'empresa-licencia@test.local',
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

        $superadmin = User::factory()->create([
            'email' => 'superadmin-license@test.local',
            'is_superadmin' => true,
        ]);

        return [$empresa->fresh(), $superadmin];
    }
}
