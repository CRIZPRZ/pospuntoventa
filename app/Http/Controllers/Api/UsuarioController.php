<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UsuarioController extends Controller
{
    public function index(Request $request)
    {
        $query = User::query()->with('roles')->latest();

        if ($request->filled('q')) {
            $search = $request->string('q');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return response()->json(
            $query->paginate($request->integer('per_page', 50))->through(fn (User $user) => $this->formatUser($user))
        );
    }

    public function show(User $usuario)
    {
        return response()->json($this->formatUser($usuario->load('roles')));
    }

    public function store(Request $request)
    {
        $roles = Role::pluck('name')->toArray();

        $data = $request->validate([
            'nombre' => ['required_without:name', 'string', 'max:255'],
            'name' => ['required_without:nombre', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'rol' => ['required', Rule::in($roles)],
        ]);

        $user = User::create([
            'name' => $data['name'] ?? $data['nombre'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);
        $user->assignRole($data['rol']);

        return response()->json($this->formatUser($user->load('roles')), 201);
    }

    public function update(Request $request, User $usuario)
    {
        $roles = Role::pluck('name')->toArray();

        $data = $request->validate([
            'nombre' => ['required_without:name', 'string', 'max:255'],
            'name' => ['required_without:nombre', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($usuario)],
            'password' => ['nullable', 'string', 'min:6'],
            'rol' => ['required', Rule::in($roles)],
        ]);

        $usuario->update(array_filter([
            'name' => $data['name'] ?? $data['nombre'],
            'email' => $data['email'],
            'password' => $data['password'] ?? null,
        ], fn ($value) => $value !== null && $value !== ''));
        $usuario->syncRoles([$data['rol']]);

        return response()->json($this->formatUser($usuario->fresh('roles')));
    }

    public function destroy(User $usuario)
    {
        if (auth()->id() === $usuario->id) {
            return response()->json(['message' => 'No puedes eliminar tu propio usuario'], 422);
        }

        $usuario->delete();

        return response()->json(['message' => 'Usuario eliminado']);
    }

    public function toggle(User $usuario)
    {
        return response()->json([
            'message' => 'El backend actual no maneja estado activo/inactivo de usuarios',
            'user' => $this->formatUser($usuario->load('roles')),
        ]);
    }

    private function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'nombre' => $user->name,
            'email' => $user->email,
            'rol' => $user->roles->first()?->name ?? 'cajero',
            'roles' => $user->roles->pluck('name')->values(),
            'activo' => true,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];
    }
}
