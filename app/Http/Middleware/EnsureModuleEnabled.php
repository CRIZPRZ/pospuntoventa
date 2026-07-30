<?php

namespace App\Http\Middleware;

use App\Models\EmpresaModulo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $empresaId = app()->bound('tenant_id') ? (int) app('tenant_id') : 0;

        $enabled = $empresaId > 0
            && EmpresaModulo::query()
                ->where('empresa_id', $empresaId)
                ->where('modulo_key', $module)
                ->where('activo', true)
                ->exists();

        if (! $enabled) {
            return response()->json([
                'message' => 'Este módulo no está habilitado para tu empresa.',
            ], 403);
        }

        return $next($request);
    }
}
