<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;

class WebhookController extends Controller
{
    public function stripe(Request $request)
    {
        Stripe::setApiKey(config('services.stripe.secret'));

        $payload   = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $secret    = config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $secret);
        } catch (SignatureVerificationException $e) {
            Log::warning('Stripe webhook signature inválida', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        match ($event->type) {
            'checkout.session.completed'      => $this->handleCheckoutCompleted($event->data->object),
            'invoice.payment_succeeded'       => $this->handlePaymentSucceeded($event->data->object),
            'invoice.payment_failed'          => $this->handlePaymentFailed($event->data->object),
            'customer.subscription.deleted'   => $this->handleSubscriptionDeleted($event->data->object),
            'customer.subscription.updated'   => $this->handleSubscriptionUpdated($event->data->object),
            default => null,
        };

        return response()->json(['received' => true]);
    }

    private function handleCheckoutCompleted($session): void
    {
        // Activar plan tras checkout exitoso
        $customerId    = $session->customer;
        $subscriptionId = $session->subscription;
        $metadata       = $session->subscription_data->metadata ?? null;

        $empresaId = $metadata?->empresa_id ?? null;
        $planId    = $metadata?->plan_id ?? null;

        if (! $empresaId || ! $planId) return;

        $empresa = Empresa::find($empresaId);
        $plan    = Plan::find($planId);
        if (! $empresa || ! $plan) return;

        $billingPeriod = $metadata?->billing_period ?? 'mensual';
        $vigencia = $billingPeriod === 'anual' ? now()->addYear() : now()->addMonth();

        $empresa->update([
            'stripe_customer_id'     => $customerId,
            'stripe_subscription_id' => $subscriptionId,
            'plan_id'                => $plan->id,
            'plan_estado'            => 'activo',
            'plan_vigente_hasta'     => $vigencia,
        ]);

        // Sincronizar módulos
        $this->sincronizarModulos($empresa, $plan);

        Log::info("Plan {$plan->nombre} activado para empresa {$empresa->nombre}");
    }

    private function handlePaymentSucceeded($invoice): void
    {
        $customerId = $invoice->customer;
        $empresa    = Empresa::where('stripe_customer_id', $customerId)->first();
        if (! $empresa) return;

        // Detectar si es suscripción anual por el intervalo de Stripe
        $interval = $invoice->lines->data[0]->plan->interval ?? 'month';
        $vigencia  = $interval === 'year' ? now()->addYear() : now()->addMonth();

        $empresa->update([
            'plan_estado'        => 'activo',
            'plan_vigente_hasta' => $vigencia,
        ]);

        Log::info("Pago exitoso — empresa {$empresa->nombre}, plan renovado");
    }

    private function handlePaymentFailed($invoice): void
    {
        $customerId = $invoice->customer;
        $empresa    = Empresa::where('stripe_customer_id', $customerId)->first();
        if (! $empresa) return;

        Log::warning("Pago fallido — empresa {$empresa->nombre}");
        // El estado queda activo hasta que expire — Stripe reintentará
    }

    private function handleSubscriptionDeleted($subscription): void
    {
        $customerId = $subscription->customer;
        $empresa    = Empresa::where('stripe_customer_id', $customerId)->first();
        if (! $empresa) return;

        $empresa->update([
            'plan_estado'            => 'vencido',
            'stripe_subscription_id' => null,
        ]);

        Log::info("Suscripción cancelada — empresa {$empresa->nombre}");
    }

    private function handleSubscriptionUpdated($subscription): void
    {
        $customerId = $subscription->customer;
        $empresa    = Empresa::where('stripe_customer_id', $customerId)->first();
        if (! $empresa) return;

        $estado = match ($subscription->status) {
            'active', 'trialing' => 'activo',
            'past_due'           => 'activo', // mantener activo durante gracia
            'canceled', 'unpaid' => 'vencido',
            default              => $empresa->plan_estado,
        };

        $empresa->update(['plan_estado' => $estado]);
    }

    private function sincronizarModulos(Empresa $empresa, Plan $plan): void
    {
        if (empty($plan->modulos)) return;

        $empresa->modulos()->update(['activo' => false]);

        foreach ($plan->modulos as $key) {
            $empresa->modulos()->updateOrCreate(
                ['modulo_key' => $key],
                ['activo' => true]
            );
        }
    }
}
