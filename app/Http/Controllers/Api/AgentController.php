<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sucursal;
use App\Models\Camara;
use Illuminate\Http\Request;

class AgentController extends Controller
{
    /**
     * El agente llama este endpoint para obtener su configuración de cámaras.
     * Público — autenticado solo con agent_token.
     */
    public function config(Request $request)
    {
        $token    = $request->header('X-Agent-Token') ?? $request->query('token');
        $sucursal = Sucursal::where('agent_token', $token)->firstOrFail();

        // Actualizar heartbeat
        $sucursal->update(['agent_last_seen' => now()]);

        $camaras = Camara::where('sucursal_id', $sucursal->id)
            ->where('empresa_id', $sucursal->empresa_id)
            ->where('activo', true)
            ->where('tipo', 'ip')
            ->whereNotNull('url_rtsp')
            ->get(['id', 'empresa_id', 'nombre', 'url_rtsp', 'usuario', 'password']);

        $cameras = $camaras->map(fn ($c) => [
            'path'   => "empresa_{$c->empresa_id}_cam_{$c->id}",
            'source' => $c->rtsp_con_credenciales,
            'nombre' => $c->nombre,
        ]);

        return response()->json([
            'sucursal_id'   => $sucursal->id,
            'sucursal'      => $sucursal->nombre,
            'empresa_id'    => $sucursal->empresa_id,
            'vps_rtsp_push' => config('services.mediamtx.rtsp_url', 'rtsp://api.eventpos.online:8554'),
            'cameras'       => $cameras,
        ]);
    }

    /**
     * El agente registra su URL pública (tunnel) para que el frontend la use.
     */
    public function register(Request $request)
    {
        $token    = $request->header('X-Agent-Token') ?? $request->query('token');
        $sucursal = Sucursal::where('agent_token', $token)->firstOrFail();

        $data = $request->validate([
            'mediamtx_url' => 'required|url|max:255',
            'version'      => 'nullable|string|max:30',
        ]);

        $sucursal->update([
            'mediamtx_url'    => $data['mediamtx_url'],
            'agent_version'   => $data['version'] ?? null,
            'agent_last_seen' => now(),
        ]);

        return response()->json(['ok' => true, 'sucursal' => $sucursal->nombre]);
    }

    /**
     * Heartbeat — el agente llama cada 60s para indicar que sigue vivo.
     */
    public function heartbeat(Request $request)
    {
        $token    = $request->header('X-Agent-Token') ?? $request->query('token');
        $sucursal = Sucursal::where('agent_token', $token)->firstOrFail();
        $sucursal->update(['agent_last_seen' => now()]);
        return response()->json(['ok' => true]);
    }

    /** Sirve el script de instalación para Mac/Linux */
    public function installSh()
    {
        $path = public_path('agent/install.sh');
        if (!file_exists($path)) {
            abort(404, 'Script no encontrado. Despliega el código primero.');
        }
        return response()->file($path, ['Content-Type' => 'text/plain']);
    }

    /** Sirve el script de instalación para Windows (PowerShell) */
    public function installPs1()
    {
        $path = public_path('agent/install.ps1');
        if (!file_exists($path)) {
            abort(404, 'Script no encontrado. Despliega el código primero.');
        }
        return response()->file($path, ['Content-Type' => 'text/plain']);
    }
}
