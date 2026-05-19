<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stripe\Stripe;
use Stripe\Customer;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\BillingPortal\Session as PortalSession;

class BillingController extends Controller
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * GET /api/planes  (público — sin auth)
     * Retorna solo planes activos para mostrar en landing.
     */
    public function planesPublicos()
    {
        $planes = Plan::where('activo', true)
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
                'stripe_customer_id' => $empresa->stripe_customer_id,
                'tiene_suscripcion'  => (bool) $empresa->stripe_subscription_id,
            ],
        ]);
    }

    /**
     * POST /api/checkout/crear-sesion
     * Crea Stripe Checkout Session y retorna la URL.
     */
    public function crearSesion(Request $request)
    {
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
            $customer   = Customer::create([
                'email'    => $user->email,
                'name'     => $empresa->nombre,
                'metadata' => ['empresa_id' => $empresa->id],
            ]);
            $customerId = $customer->id;
            $empresa->update(['stripe_customer_id' => $customerId]);
        }

        $session = CheckoutSession::create([
            'customer'             => $customerId,
            'mode'                 => 'subscription',
            'line_items'           => [[
                'price'    => $priceId,
                'quantity' => 1,
            ]],
            'success_url'          => $data['success_url'] . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'           => $data['cancel_url'],
            'subscription_data'    => [
                'metadata' => [
                    'empresa_id'     => $empresa->id,
                    'plan_id'        => $plan->id,
                    'billing_period' => $anual ? 'anual' : 'mensual',
                ],
            ],
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
        $data = $request->validate(['return_url' => 'required|url']);

        $empresa = $request->user()->empresa;

        if (! $empresa->stripe_customer_id) {
            return response()->json(['message' => 'Sin suscripción activa de Stripe'], 422);
        }

        $session = PortalSession::create([
            'customer'   => $empresa->stripe_customer_id,
            'return_url' => $data['return_url'],
        ]);

        return response()->json(['url' => $session->url]);
    }
}
