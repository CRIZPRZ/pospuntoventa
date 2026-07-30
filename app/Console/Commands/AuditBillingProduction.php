<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Models\Plan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditBillingProduction extends Command
{
    protected $signature = 'billing:audit-production {--apply : Corrige inconsistencias seguras además de reportarlas}';

    protected $description = 'Audita y opcionalmente corrige inconsistencias de planes, trial y billing listas para producción.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $this->components->info('Auditando estado de billing y trial...');

        $gratisPlans = Plan::where('tipo', 'gratis')->get();
        $sinPlanConModulos = Empresa::with('modulos')
            ->whereIn('plan_estado', ['sin_plan', 'trial'])
            ->whereHas('modulos', fn ($q) => $q->where('activo', true))
            ->get();
        $sinPlanSinFecha = Empresa::whereIn('plan_estado', ['sin_plan', 'trial'])
            ->whereNull('plan_vigente_hasta')
            ->get();
        $activosSinPlan = Empresa::where('plan_estado', 'activo')
            ->whereNull('plan_id')
            ->get();
        $planesManualesConStripe = Plan::where('tipo', 'manual')
            ->where(function ($q) {
                $q->whereNotNull('stripe_price_id')
                    ->orWhereNotNull('stripe_price_id_anual');
            })
            ->get();

        $rows = [
            ['Planes legacy tipo gratis', (string) $gratisPlans->count()],
            ['Empresas sin_plan/trial con módulos activos', (string) $sinPlanConModulos->count()],
            ['Empresas sin_plan/trial sin vigencia', (string) $sinPlanSinFecha->count()],
            ['Empresas activas sin plan_id', (string) $activosSinPlan->count()],
            ['Planes manual con Stripe IDs cargados', (string) $planesManualesConStripe->count()],
        ];

        $this->table(['Chequeo', 'Cantidad'], $rows);

        if (! $apply) {
            $this->line('Ejecuta con `--apply` para corregir inconsistencias seguras.');
            return self::SUCCESS;
        }

        DB::transaction(function () use (
            $gratisPlans,
            $sinPlanConModulos,
            $sinPlanSinFecha,
            $activosSinPlan,
            $planesManualesConStripe
        ) {
            foreach ($gratisPlans as $plan) {
                $plan->update(['activo' => false]);
            }

            foreach ($sinPlanConModulos as $empresa) {
                $empresa->modulos()->update(['activo' => false]);
            }

            foreach ($sinPlanSinFecha as $empresa) {
                $empresa->update(['plan_vigente_hasta' => now()]);
            }

            foreach ($activosSinPlan as $empresa) {
                $empresa->update([
                    'plan_estado' => 'sin_plan',
                    'plan_vigente_hasta' => now(),
                    'plan_precio_pactado' => null,
                ]);
                $empresa->modulos()->update(['activo' => false]);
            }

            foreach ($planesManualesConStripe as $plan) {
                $plan->update([
                    'stripe_price_id' => null,
                    'stripe_price_id_anual' => null,
                ]);
            }
        });

        $this->components->info('Correcciones aplicadas.');

        return self::SUCCESS;
    }
}
