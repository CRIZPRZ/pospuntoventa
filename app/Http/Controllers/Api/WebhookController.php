<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\SuscripcionCanceladaMail;
use App\Models\Empresa;
use App\Models\Plan;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Stripe\Stripe;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;

class WebhookController extends Controller
{
    public function stripe(Request $request)
    {
        $stripeSecret = config('services.stripe.secret');
        $webhookSecret = config('services.stripe.webhook_secret');

        if (! $stripeSecret || ! $webhookSecret) {
            Log::error('Stripe webhook recibido sin configuración completa');
            return response()->json(['error' => 'Stripe webhook not configured'], 503);
        }

        Stripe::setApiKey($stripeSecret);

        $payload   = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (SignatureVerificationException|\UnexpectedValueException $e) {
            Log::warning('Stripe webhook signature inválida', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        $eventId = (string) ($event->id ?? '');
        if ($eventId !== '' && $this->wasProcessed($eventId)) {
            Log::info('Stripe webhook duplicado ignorado', ['event_id' => $eventId, 'type' => $event->type]);
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        if ($eventId !== '') {
            $this->storePendingEvent($eventId, (string) $event->type, $payload);
        }

        try {
            match ($event->type) {
                'checkout.session.completed'      => $this->handleCheckoutCompleted($event->data->object),
                'invoice.payment_succeeded'       => $this->handlePaymentSucceeded($event->data->object),
                'invoice.payment_failed'          => $this->handlePaymentFailed($event->data->object),
                'customer.subscription.deleted'   => $this->handleSubscriptionDeleted($event->data->object),
                'customer.subscription.updated'   => $this->handleSubscriptionUpdated($event->data->object),
                default => null,
            };

            if ($eventId !== '') {
                $this->markProcessed($eventId);
            }
        } catch (\Throwable $e) {
            Log::error('Error procesando Stripe webhook', [
                'event_id' => $eventId,
                'type' => $event->type,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }

        return response()->json(['received' => true]);
    }

    private function handleCheckoutCompleted($session): void
    {
        $metadata  = $session->metadata ?? null;
        $empresaId = $metadata?->empresa_id ?? null;

        if (! $empresaId) return;

        // Paquete de timbres — one-time payment
        if (($metadata?->type ?? '') === 'timbres') {
            $empresa  = Empresa::find($empresaId);
            $cantidad = (int) ($metadata?->cantidad ?? 0);
            if ($empresa && $cantidad > 0) {
                $empresa->increment('timbres_extra', $cantidad);
                Log::info("Timbres +{$cantidad} acreditados a empresa {$empresa->nombre}");
            }
            return;
        }

        // Activar plan tras checkout de suscripción
        $customerId     = $session->customer;
        $subscriptionId = $session->subscription;

        $planId = $metadata?->plan_id ?? null;

        if (! $planId || ! $subscriptionId) {
            Log::warning('Checkout Stripe sin plan o suscripción asociada', [
                'empresa_id' => $empresaId,
                'session_id' => $session->id ?? null,
            ]);
            return;
        }

        $empresa = Empresa::find($empresaId);
        $plan    = Plan::find($planId);
        if (! $empresa || ! $plan) return;

        $subscription = \Stripe\Subscription::retrieve($subscriptionId);

        $this->syncSubscriptionState($empresa, $subscription, $plan, $customerId);

        Log::info("Plan {$plan->nombre} activado para empresa {$empresa->nombre}");
    }

    private function handlePaymentSucceeded($invoice): void
    {
        $customerId = $invoice->customer;
        $empresa    = Empresa::where('stripe_customer_id', $customerId)->first();
        if (! $empresa) return;

        $subscriptionId = $invoice->subscription ?? $empresa->stripe_subscription_id;
        if ($subscriptionId) {
            $subscription = \Stripe\Subscription::retrieve($subscriptionId);
            $planId = $subscription->metadata->plan_id ?? $empresa->plan_id;
            $plan = $planId ? Plan::find($planId) : null;
            $this->syncSubscriptionState($empresa, $subscription, $plan, $customerId);
        }

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

        $planNombre    = $empresa->plan?->nombre ?? 'tu plan';
        $vigencia = $subscription->current_period_end
            ? Carbon::createFromTimestamp($subscription->current_period_end)
            : null;
        $vigenciaHasta = $vigencia
            ? $vigencia->locale('es')->isoFormat('D [de] MMMM [de] YYYY')
            : null;

        $empresa->update([
            'plan_estado'            => $vigencia?->isFuture() ? 'activo' : 'vencido',
            'plan_vigente_hasta'     => $vigencia,
            'stripe_subscription_id' => null,
        ]);

        // Email al admin de la empresa
        try {
            $adminUser = $empresa->usuarios()->first();
            if ($adminUser) {
                Mail::to($adminUser->email)
                    ->queue(new SuscripcionCanceladaMail($empresa, $planNombre, $vigenciaHasta));
            }
        } catch (\Throwable $e) {
            Log::error("Error enviando email cancelación — empresa {$empresa->id}: {$e->getMessage()}");
        }

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

        $data = ['plan_estado' => $estado];

        if (! empty($subscription->current_period_end)) {
            $data['plan_vigente_hasta'] = Carbon::createFromTimestamp($subscription->current_period_end);
        }

        $planId = $subscription->metadata->plan_id ?? $empresa->plan_id;
        if ($planId && $plan = Plan::find($planId)) {
            $data['plan_id'] = $plan->id;
            $this->sincronizarModulos($empresa, $plan);
        }

        $empresa->update($data);
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

    private function syncSubscriptionState(Empresa $empresa, $subscription, ?Plan $plan = null, ?string $customerId = null): void
    {
        $estado = match ($subscription->status ?? null) {
            'active', 'trialing', 'past_due' => 'activo',
            'canceled', 'unpaid', 'incomplete_expired' => 'vencido',
            default => $empresa->plan_estado,
        };

        $data = [
            'stripe_customer_id' => $customerId ?: $empresa->stripe_customer_id,
            'stripe_subscription_id' => $subscription->id ?? $empresa->stripe_subscription_id,
            'plan_estado' => $estado,
            'plan_vigente_hasta' => ! empty($subscription->current_period_end)
                ? Carbon::createFromTimestamp($subscription->current_period_end)
                : $empresa->plan_vigente_hasta,
        ];

        if ($plan) {
            $data['plan_id'] = $plan->id;
        }

        $empresa->update($data);

        if ($plan) {
            $this->sincronizarModulos($empresa, $plan);
        }
    }

    private function wasProcessed(string $eventId): bool
    {
        if (! Schema::hasTable('stripe_webhook_events')) {
            return false;
        }

        return DB::table('stripe_webhook_events')
            ->where('event_id', $eventId)
            ->whereNotNull('processed_at')
            ->exists();
    }

    private function storePendingEvent(string $eventId, string $type, string $payload): void
    {
        if (! Schema::hasTable('stripe_webhook_events')) {
            return;
        }

        DB::table('stripe_webhook_events')->updateOrInsert(
            ['event_id' => $eventId],
            [
                'type' => $type,
                'payload' => $payload,
                'processed_at' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    private function markProcessed(string $eventId): void
    {
        if (! Schema::hasTable('stripe_webhook_events')) {
            return;
        }

        DB::table('stripe_webhook_events')
            ->where('event_id', $eventId)
            ->update([
                'processed_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
