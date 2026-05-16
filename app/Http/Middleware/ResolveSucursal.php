<?php

namespace App\Http\Middleware;

use App\Models\Sucursal;
use Closure;
use Illuminate\Http\Request;

class ResolveSucursal
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        if (!$user || !$user->empresa_id) {
            return $next($request);
        }

        // Prioridad: header > sucursal_id del usuario > primera sucursal de la empresa
        $sucursalId = $request->header('X-Sucursal-ID')
            ?? $user->sucursal_id
            ?? Sucursal::withoutGlobalScopes()
                ->where('empresa_id', $user->empresa_id)
                ->where('activo', true)
                ->value('id');

        if ($sucursalId) {
            // Verificar que la sucursal pertenece a la empresa del usuario
            $valida = Sucursal::withoutGlobalScopes()
                ->where('id', $sucursalId)
                ->where('empresa_id', $user->empresa_id)
                ->exists();

            if ($valida) {
                app()->instance('sucursal_id', (int) $sucursalId);
            }
        }

        return $next($request);
    }
}
