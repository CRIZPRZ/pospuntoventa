<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckTrial
{
    // Rutas que siempre pasan (billing, auth, webhook, landing)
    private const SKIP_PREFIXES = [
        'api/auth',
        'api/checkout',
        'api/billing',
        'api/webhook',
        'api/planes',
        'api/register',
        'api/superadmin',
    ];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        // Sin usuario o superadmin: pasar
        if (! $user || $user->is_superadmin) {
            return $next($request);
        }

        // Rutas exentas
        $path = $request->path();
        foreach (self::SKIP_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $next($request);
            }
        }

        $empresa = $user->empresa;
        if (! $empresa) {
            return $next($request);
        }

        $estado = $empresa->plan_estado ?? 'sin_plan';

        // Plan activo: siempre pasa
        if ($estado === 'activo') {
            return $next($request);
        }

        // Trial o sin_plan: verificar vigencia
        if (in_array($estado, ['trial', 'sin_plan'])) {
            if (! $empresa->plan_vigente_hasta) {
                if (app()->environment('local', 'testing')) {
                    return $next($request);
                }
            } elseif ($empresa->plan_vigente_hasta->isFuture()) {
                return $next($request);
            }
        }

        // Vencido
        $dias = $empresa->plan_vigente_hasta
            ? (int) now()->diffInDays($empresa->plan_vigente_hasta, false)
            : null;

        return response()->json([
            'message'  => 'Tu período de prueba ha vencido. Actualiza tu plan para continuar.',
            'code'     => 'TRIAL_EXPIRED',
            'dias'     => $dias,
            'redirect' => '/mi-plan',
        ], 402);
    }
}
