<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BillingController extends Controller
{
    // No inicializar Stripe en constructor — miPlan() y planesPublicos() no usan Stripe
    // y fallarían con 500 si STRIPE_SECRET_KEY no está configurado.
    private function initStripe(): void
    {
        $key = config('services.stripe.secret');
        if (! $key) {
            abort(422, 'Stripe no está configurado. Agrega STRIPE_SECRET_KEY en .env.');
        }
        \Stripe\Stripe::setApiKey($key);
    }

    /**
     * GET /api/planes  (público — sin auth)
     * Retorna solo planes activos para mostrar en landing.
     */
    public function planesPublicos()
    {
        $planes = Plan::where('activo', true)
            ->where('tipo', '!=', 'manual')
            ->orderBy('precio_mensual')
            ->get(['id', 'nombre', 'descripcion', 'precio_mensual', 'max_sucursales', 'max_usuarios', 'modulos', 'color', 'tipo']);

        return response()->json(['data' => $planes]);
    }

    /**
     * GET /api/planes/mi-plan
     * Retorna el plan actual del tenant con límites y uso.
     */
    public function miPlan(Request $request)
    {
        $empresa = $request->user()->empresa;

        if (! $empresa) {
            return response()->json(['data' => null]);
        }

        $empresa->load('plan');

        $sucursalesCount = DB::table('sucursales')
            ->where('empresa_id', $empresa->id)
            ->where('activo', true)
            ->count();

        $usuariosCount = $empresa->usuarios()->count();

        $diasRestantes = null;
        if ($empresa->plan_vigente_hasta) {
            $diasRestantes = max(0, (int) now()->diffInDays($empresa->plan_vigente_hasta, false));
        }

        $timbresIncluidos = $empresa->plan?->timbres_incluidos ?? 0;
        $timbresUsados    = DB::table('ventas')
            ->where('empresa_id', $empresa->id)
            ->whereNotNull('cfdi_uuid')
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->count();

        return response()->json([
            'data' => [
                'plan'               => $empresa->plan,
                'plan_estado'        => $empresa->plan_estado,
                'plan_vigente_hasta' => $empresa->plan_vigente_hasta,
                'dias_restantes'     => $diasRestantes,
                'vigente'            => $empresa->planVigente(),
                'max_sucursales'     => $empresa->plan?->max_sucursales ?? -1,
                'max_usuarios'       => $empresa->plan?->max_usuarios ?? -1,
                'uso_sucursales'     => $sucursalesCount,
                'uso_usuarios'       => $usuariosCount,
                'timbres_incluidos'  => $timbresIncluidos,
                'timbres_usados'     => $timbresUsados,
                'timbres_extra'      => (int) ($empresa->timbres_extra ?? 0),
                'stripe_customer_id'  => $empresa->stripe_customer_id,
                'tiene_suscripcion'   => (bool) $empresa->stripe_subscription_id,
                'datos_facturacion'   => $empresa->datos_facturacion,
            ],
        ]);
    }

    /**
     * POST /api/checkout/crear-sesion
     * Crea Stripe Checkout Session y retorna la URL.
     */
    public function crearSesion(Request $request)
    {
        $this->initStripe();

        $data = $request->validate([
            'plan_id'        => 'required|exists:planes,id',
            'success_url'    => 'required|url',
            'cancel_url'     => 'required|url',
            'billing_period' => 'nullable|in:mensual,anual',
        ]);

        $plan   = Plan::findOrFail($data['plan_id']);

        if ($plan->tipo !== 'stripe') {
            return response()->json([
                'message' => $plan->tipo === 'manual'
                    ? 'Este es un plan de pago manual. Contacta a soporte para activarlo.'
                    : 'Este plan no requiere pago.',
            ], 422);
        }

        $anual  = ($data['billing_period'] ?? 'mensual') === 'anual';
        $priceId = $anual ? $plan->stripe_price_id_anual : $plan->stripe_price_id;

        if (! $priceId) {
            $campo = $anual ? 'Price ID anual' : 'Price ID mensual';
            return response()->json([
                'message' => "Este plan no tiene {$campo} de Stripe configurado. Configúralo en el panel de superadmin.",
            ], 422);
        }

        $empresa = $request->user()->empresa;
        $user    = $request->user();

        // Obtener o crear Customer en Stripe
        $customerId = $empresa->stripe_customer_id;

        if (! $customerId) {
            $customer   = \Stripe\Customer::create([
                'email'    => $user->email,
                'name'     => $empresa->nombre,
                'metadata' => ['empresa_id' => $empresa->id],
            ]);
            $customerId = $customer->id;
            $empresa->update(['stripe_customer_id' => $customerId]);
        }

        $meta = [
            'empresa_id'     => $empresa->id,
            'plan_id'        => $plan->id,
            'billing_period' => $anual ? 'anual' : 'mensual',
        ];

        $session = \Stripe\Checkout\Session::create([
            'customer'             => $customerId,
            'mode'                 => 'subscription',
            'line_items'           => [[
                'price'    => $priceId,
                'quantity' => 1,
            ]],
            'success_url'          => $data['success_url'] . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'           => $data['cancel_url'],
            // metadata en sesión Y en suscripción — webhook lee de ambos lugares
            'metadata'             => $meta,
            'subscription_data'    => ['metadata' => $meta],
            'allow_promotion_codes' => true,
        ]);

        return response()->json(['url' => $session->url, 'session_id' => $session->id]);
    }

    /**
     * POST /api/billing/portal
     * Crea Stripe Customer Portal Session para gestionar suscripción.
     */
    public function crearPortal(Request $request)
    {
        $this->initStripe();

        $data = $request->validate(['return_url' => 'required|url']);

        $empresa = $request->user()->empresa;

        if (! $empresa->stripe_customer_id) {
            return response()->json(['message' => 'Sin suscripción activa de Stripe'], 422);
        }

        $session = \Stripe\BillingPortal\Session::create([
            'customer'   => $empresa->stripe_customer_id,
            'return_url' => $data['return_url'],
        ]);

        return response()->json(['url' => $session->url]);
    }

    /**
     * POST /api/billing/cambiar-plan
     * Cambia plan en suscripción Stripe existente con prorrateo automático.
     */
    public function cambiarPlan(Request $request)
    {
        $this->initStripe();

        $data = $request->validate([
            'plan_id'        => 'required|exists:planes,id',
            'billing_period' => 'nullable|in:mensual,anual',
        ]);

        $plan = Plan::findOrFail($data['plan_id']);

        if ($plan->tipo !== 'stripe') {
            return response()->json([
                'message' => $plan->tipo === 'manual'
                    ? 'Este es un plan de pago manual. Contacta a soporte para activarlo.'
                    : 'Este plan no requiere pago.',
            ], 422);
        }

        $anual   = ($data['billing_period'] ?? 'mensual') === 'anual';
        $priceId = $anual ? $plan->stripe_price_id_anual : $plan->stripe_price_id;

        if (! $priceId) {
            $campo = $anual ? 'Price ID anual' : 'Price ID mensual';
            return response()->json([
                'message' => "Este plan no tiene {$campo} de Stripe configurado.",
            ], 422);
        }

        $empresa = $request->user()->empresa;

        if (! $empresa->stripe_subscription_id) {
            return response()->json([
                'message' => 'No tienes suscripción activa. Usa el checkout para suscribirte.',
            ], 422);
        }

        $subscription = \Stripe\Subscription::retrieve([
            'id'     => $empresa->stripe_subscription_id,
            'expand' => ['items'],
        ]);

        $itemId = $subscription->items->data[0]->id ?? null;
        if (! $itemId) {
            return response()->json(['message' => 'No se encontró el ítem de suscripción en Stripe.'], 422);
        }

        \Stripe\Subscription::update($empresa->stripe_subscription_id, [
            'items' => [[
                'id'    => $itemId,
                'price' => $priceId,
            ]],
            'proration_behavior' => 'create_prorations',
            'metadata' => [
                'empresa_id'     => $empresa->id,
                'plan_id'        => $plan->id,
                'billing_period' => $anual ? 'anual' : 'mensual',
            ],
        ]);

        // Actualizar plan y sincronizar módulos inmediatamente
        $empresa->update(['plan_id' => $plan->id]);

        if (! empty($plan->modulos)) {
            $empresa->modulos()->update(['activo' => false]);
            foreach ($plan->modulos as $key) {
                $empresa->modulos()->updateOrCreate(
                    ['modulo_key' => $key],
                    ['activo' => true]
                );
            }
        }

        return response()->json([
            'message' => "Plan cambiado a {$plan->nombre} correctamente.",
            'plan'    => $plan,
        ]);
    }

    /**
     * POST /api/billing/comprar-timbres
     * Crea Stripe Checkout one-time para paquete de timbres CFDI.
     * Paquetes disponibles: 50 → $100, 200 → $350, 500 → $750 MXN
     */
    public function comprarTimbres(Request $request)
    {
        $this->initStripe();

        $data = $request->validate([
            'cantidad'    => 'required|integer|in:50,200,500',
            'success_url' => 'required|url',
            'cancel_url'  => 'required|url',
        ]);

        $empresa = $request->user()->empresa;

        $precios = [
            50  => ['monto' => 10000, 'label' => '50 timbres CFDI'],  // $100 MXN en centavos
            200 => ['monto' => 35000, 'label' => '200 timbres CFDI'], // $350 MXN
            500 => ['monto' => 75000, 'label' => '500 timbres CFDI'], // $750 MXN
        ];

        $paquete = $precios[$data['cantidad']];

        // Obtener o crear Customer
        $customerId = $empresa->stripe_customer_id;
        if (! $customerId) {
            $customer   = \Stripe\Customer::create([
                'email'    => $request->user()->email,
                'name'     => $empresa->nombre,
                'metadata' => ['empresa_id' => $empresa->id],
            ]);
            $customerId = $customer->id;
            $empresa->update(['stripe_customer_id' => $customerId]);
        }

        $session = \Stripe\Checkout\Session::create([
            'customer'   => $customerId,
            'mode'       => 'payment',
            'line_items' => [[
                'price_data' => [
                    'currency'     => 'mxn',
                    'unit_amount'  => $paquete['monto'],
                    'product_data' => ['name' => $paquete['label']],
                ],
                'quantity' => 1,
            ]],
            'success_url'          => $data['success_url'] . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'           => $data['cancel_url'],
            'metadata'             => [
                'type'       => 'timbres',
                'empresa_id' => $empresa->id,
                'cantidad'   => $data['cantidad'],
            ],
        ]);

        return response()->json(['url' => $session->url, 'session_id' => $session->id]);
    }

    /**
     * GET /api/billing/invoices
     * Historial de facturas/pagos del tenant desde Stripe.
     */
    public function invoices(Request $request)
    {
        $this->initStripe();

        $empresa = $request->user()->empresa;

        if (! $empresa?->stripe_customer_id) {
            return response()->json(['data' => []]);
        }

        $invoices = \Stripe\Invoice::all([
            'customer' => $empresa->stripe_customer_id,
            'limit'    => 24,
            'expand'   => ['data.subscription'],
        ]);

        $data = collect($invoices->data)->map(fn($inv) => [
            'id'          => $inv->id,
            'numero'      => $inv->number,
            'fecha'       => $inv->created,
            'monto'       => $inv->amount_paid / 100,
            'moneda'      => strtoupper($inv->currency),
            'status'      => $inv->status,          // paid, open, void, uncollectible
            'pdf_url'     => $inv->invoice_pdf,
            'hosted_url'  => $inv->hosted_invoice_url,
            'descripcion' => $inv->lines->data[0]->description ?? null,
            'periodo_inicio' => $inv->period_start,
            'periodo_fin'    => $inv->period_end,
        ]);

        return response()->json(['data' => $data]);
    }
}
