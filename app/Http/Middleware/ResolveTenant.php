<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        if ($user && $user->empresa_id) {
            app()->instance('tenant_id', $user->empresa_id);
            app()->instance('tenant', $user->empresa);
        }

        return $next($request);
    }
}
