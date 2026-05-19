<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        if (!auth()->attempt($credentials)) {
            return response()->json(['message' => 'Credenciales incorrectas'], 401);
        }

        $user = auth()->user()->load('roles', 'permissions', 'empresa', 'sucursalDefault');
        $token = $user->createToken('ventas-pos')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => $this->formatUser($user),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Sesión cerrada']);
    }

    public function me(Request $request)
    {
        $user = $request->user()->load('roles', 'permissions', 'empresa', 'sucursalDefault');
        return response()->json(['user' => $this->formatUser($user)]);
    }

    private function formatUser($user): array
    {
        if ($user->is_superadmin) {
            return array_merge($user->toArray(), [
                'is_superadmin' => true,
                'roles'         => [],
                'permissions'   => [],
                'empresa'       => null,
            ]);
        }

        // Sucursales del usuario con su rol en cada una
        $sucursales = \Illuminate\Support\Facades\DB::table('usuario_sucursal as us')
            ->join('sucursales', 'sucursales.id', '=', 'us.sucursal_id')
            ->join('roles', 'roles.id', '=', 'us.role_id')
            ->where('us.user_id', $user->id)
            ->where('sucursales.activo', true)
            ->select('sucursales.id', 'sucursales.nombre', 'sucursales.es_principal', 'roles.name as rol')
            ->get();

        $modulos = $user->empresa
            ? $user->empresa->modulos()->where('activo', true)->pluck('modulo_key')
            : [];

        return array_merge($user->toArray(), [
            'roles'              => $user->getRoleNames(),
            'permissions'        => $user->getAllPermissions()->pluck('name'),
            'empresa'            => $user->empresa?->only(['id', 'nombre', 'slug']),
            'sucursal_activa'    => $user->sucursalDefault?->only(['id', 'nombre', 'es_principal']),
            'sucursales'         => $sucursales,
            'modulos_habilitados'=> $modulos,
        ]);
    }
}
