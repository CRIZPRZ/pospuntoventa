<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\CotizacionController;
use App\Models\Cotizacion;
use App\Models\Empresa;
use App\Models\Pedido;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class CotizacionDecisionFinalTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejected_quote_cannot_be_accepted_or_rejected_again(): void
    {
        $cotizacion = $this->cotizacion('rechazada', 'COT-TEST-1');

        $this->post(route('ticket.cotizacion.aceptar', $cotizacion->ticket_token))
            ->assertRedirect()
            ->assertSessionHas('action_msg', 'Esta cotización ya fue rechazada.');

        $this->post(route('ticket.cotizacion.rechazar', $cotizacion->ticket_token))
            ->assertRedirect()
            ->assertSessionHas('action_msg', 'Esta cotización ya fue rechazada.');

        $this->assertSame('rechazada', $cotizacion->fresh()->status);
        $this->assertSame(0, Pedido::query()->where('cotizacion_id', $cotizacion->id)->count());

        $this->app['session']->flush();

        $this->get(route('ticket.cotizacion', $cotizacion->ticket_token))
            ->assertOk()
            ->assertSee('La decisión ya no puede modificarse.')
            ->assertDontSee('Aceptar cotización')
            ->assertDontSee('✕ Rechazar');
    }

    public function test_admin_reopening_rejected_quote_rotates_public_token(): void
    {
        $cotizacion = $this->cotizacion('rechazada', 'COT-TEST-2');
        $oldToken = $cotizacion->ticket_token;

        $response = app(CotizacionController::class)->update(
            Request::create('/api/cotizaciones/' . $cotizacion->id, 'PUT', ['status' => 'borrador']),
            $cotizacion,
        );

        $this->assertSame(200, $response->getStatusCode());
        $cotizacion->refresh();
        $this->assertSame('borrador', $cotizacion->status);
        $this->assertNotSame($oldToken, $cotizacion->ticket_token);
        $this->get(route('ticket.cotizacion', $oldToken))->assertNotFound();
        $this->get(route('ticket.cotizacion', $cotizacion->ticket_token))
            ->assertOk()
            ->assertSee('Aceptar cotización');
    }

    public function test_admin_cannot_reopen_accepted_quote(): void
    {
        $cotizacion = $this->cotizacion('aceptada', 'COT-TEST-3');
        $oldToken = $cotizacion->ticket_token;

        $response = app(CotizacionController::class)->update(
            Request::create('/api/cotizaciones/' . $cotizacion->id, 'PUT', ['status' => 'borrador']),
            $cotizacion,
        );

        $this->assertSame(422, $response->getStatusCode());
        $cotizacion->refresh();
        $this->assertSame('aceptada', $cotizacion->status);
        $this->assertSame($oldToken, $cotizacion->ticket_token);
    }

    public function test_actionable_quote_renders_full_page_decision_loading(): void
    {
        $cotizacion = $this->cotizacion('enviada', 'COT-TEST-4');

        $this->get(route('ticket.cotizacion', $cotizacion->ticket_token))
            ->assertOk()
            ->assertSee('id="decision-loading"', false)
            ->assertSee('class="decision-form"', false)
            ->assertSee('Aceptando cotización...')
            ->assertSee('Registrando rechazo...');
    }

    private function cotizacion(string $status, string $folio): Cotizacion
    {
        $empresa = Empresa::query()->create([
            'nombre' => 'Empresa de prueba',
            'slug' => 'empresa-' . strtolower($folio),
            'email' => strtolower($folio) . '@example.test',
        ]);

        return Cotizacion::query()->create([
            'empresa_id' => $empresa->id,
            'folio' => $folio,
            'fecha' => now()->toDateString(),
            'status' => $status,
            'subtotal' => 100,
            'total' => 100,
        ]);
    }
}
