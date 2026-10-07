<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResetTenantsCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;
    private int $planId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->superadmin = User::withoutGlobalScopes()->create([
            'name' => 'Super', 'email' => 'super@example.test',
            'password' => 'secret123', 'is_superadmin' => true,
        ]);

        $this->planId = DB::table('planes')->insertGetId([
            'nombre' => 'Pro', 'precio_mensual' => 999, 'stripe_price_id' => 'price_123',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([1, 2] as $n) {
            $empresa = Empresa::query()->create([
                'nombre' => "Tenant {$n}", 'slug' => "tenant-{$n}", 'email' => "t{$n}@example.test",
            ]);
            User::withoutGlobalScopes()->create([
                'name' => "Admin {$n}", 'email' => "admin{$n}@example.test",
                'password' => 'secret123', 'empresa_id' => $empresa->id,
            ]);
            Producto::withoutGlobalScopes()->create(['empresa_id' => $empresa->id, 'nombre' => 'P', 'precio' => 10, 'stock' => 1]);
            Cliente::withoutGlobalScopes()->create(['empresa_id' => $empresa->id, 'nombre' => 'C']);
            Configuracion::query()->create(['empresa_id' => $empresa->id, 'config' => []]);
            Storage::disk('public')->put("config/{$empresa->id}/logo_1.png", 'x');
        }

        Storage::disk('public')->put('productos/foto.jpg', 'x');
        Storage::disk('public')->put('desktop/EventPOS-Installer.exe', 'x');
    }

    public function test_simulacion_no_borra_nada(): void
    {
        $this->artisan('tenants:reset')->assertSuccessful();

        $this->assertSame(2, Empresa::withoutGlobalScopes()->count());
        $this->assertSame(2, Producto::withoutGlobalScopes()->count());
    }

    public function test_borra_tenants_y_conserva_superadmin_planes_e_instalador(): void
    {
        $this->artisan('tenants:reset', ['--execute' => true, '--confirm' => 'BORRAR TODO'])
            ->assertSuccessful();

        $this->assertSame(0, Empresa::withoutGlobalScopes()->count());
        $this->assertSame(0, Producto::withoutGlobalScopes()->count());
        $this->assertSame(0, Cliente::withoutGlobalScopes()->count());
        $this->assertSame(0, DB::table('configuraciones')->count());

        $this->assertSame(['super@example.test'], User::withoutGlobalScopes()->pluck('email')->all());
        $this->assertSame('price_123', DB::table('planes')->where('id', $this->planId)->value('stripe_price_id'));

        Storage::disk('public')->assertMissing('config/1/logo_1.png');
        Storage::disk('public')->assertMissing('productos/foto.jpg');
        Storage::disk('public')->assertExists('desktop/EventPOS-Installer.exe');
    }

    public function test_confirmacion_incorrecta_no_borra(): void
    {
        $this->artisan('tenants:reset', ['--execute' => true, '--confirm' => 'si'])->assertFailed();

        $this->assertSame(2, Empresa::withoutGlobalScopes()->count());
    }

    public function test_no_borra_si_hay_suscripciones_stripe_sin_ignore(): void
    {
        Empresa::withoutGlobalScopes()->first()->update(['stripe_subscription_id' => 'sub_123']);

        $this->artisan('tenants:reset', ['--execute' => true, '--confirm' => 'BORRAR TODO'])->assertFailed();
        $this->assertSame(2, Empresa::withoutGlobalScopes()->count());

        $this->artisan('tenants:reset', ['--execute' => true, '--confirm' => 'BORRAR TODO', '--ignore-stripe' => true])
            ->assertSuccessful();
        $this->assertSame(0, Empresa::withoutGlobalScopes()->count());
    }
}
