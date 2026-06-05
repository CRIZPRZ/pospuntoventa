<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ScopesBySucursal;
use App\Models\Camara;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CamaraController extends Controller
{
    use ScopesBySucursal;

    private function empresaId(): int
    {
        return auth()->user()->empresa_id;
    }

    public function index(Request $request)
    {
        $query = Camara::with('sucursal:id,nombre')
            ->where('empresa_id', $this->empresaId())
            ->orderBy('orden')
            ->orderBy('nombre');

        $query = $this->applySucursalScope($query);

        if ($request->has('activo')) {
            $query->where('activo', filter_var($request->activo, FILTER_VALIDATE_BOOLEAN));
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'          => 'required|string|max:100',
            'tipo'            => 'required|in:ip,usb,mjpeg,aws_kinesis',
            'url_rtsp'        => 'nullable|string|max:500',
            'usuario'         => 'nullable|string|max:100',
            'password'        => 'nullable|string|max:200',
            'url_stream'      => 'nullable|string|max:500',
            'aws_stream_name' => 'nullable|string|max:200',
            'aws_region'      => 'nullable|string|max:50',
            'activo'          => 'boolean',
            'orden'           => 'integer|min:0',
        ]);

        $data['empresa_id']  = $this->empresaId();
        $data['sucursal_id'] = $this->sucursalId();

        $camara = Camara::create($data);
        $this->syncMediaMtx($camara);

        return response()->json($camara, 201);
    }

    public function update(Request $request, Camara $camara)
    {
        abort_if($camara->empresa_id !== $this->empresaId(), 403);

        $data = $request->validate([
            'nombre'          => 'sometimes|string|max:100',
            'tipo'            => 'sometimes|in:ip,usb,mjpeg,aws_kinesis',
            'url_rtsp'        => 'nullable|string|max:500',
            'usuario'         => 'nullable|string|max:100',
            'password'        => 'nullable|string|max:200',
            'url_stream'      => 'nullable|string|max:500',
            'aws_stream_name' => 'nullable|string|max:200',
            'aws_region'      => 'nullable|string|max:50',
            'activo'          => 'boolean',
            'orden'           => 'integer|min:0',
            'sucursal_id'     => 'nullable|exists:sucursales,id',
        ]);

        $camara->update($data);
        $this->syncMediaMtx($camara->fresh());

        return response()->json($camara->fresh());
    }

    public function destroy(Camara $camara)
    {
        abort_if($camara->empresa_id !== $this->empresaId(), 403);
        $this->syncMediaMtx($camara, delete: true);
        $camara->delete();
        return response()->json(null, 204);
    }

    /**
     * Prueba conexión sin guardar (desde modal crear/editar).
     */
    public function testConnection(Request $request)
    {
        $data = $request->validate([
            'tipo'       => 'required|in:ip,mjpeg',
            'url_rtsp'   => 'nullable|string',
            'url_stream' => 'nullable|string',
            'usuario'    => 'nullable|string',
            'password'   => 'nullable|string',
        ]);

        [$ok, $message] = $this->probarStream($data['tipo'], $data);

        return response()->json(['ok' => $ok, 'message' => $message]);
    }

    /**
     * Registra (o elimina) el path de una cámara IP en MediaMTX via API.
     */
    private function syncMediaMtx(Camara $camara, bool $delete = false): void
    {
        if ($camara->tipo !== 'ip' || !$camara->url_rtsp) return;

        $apiBase  = config('services.mediamtx.api_url', 'http://mediamtx:9997');
        $pathName = "empresa_{$camara->empresa_id}_cam_{$camara->id}";

        try {
            if ($delete) {
                \Illuminate\Support\Facades\Http::delete("{$apiBase}/v3/config/paths/delete/{$pathName}");
                return;
            }

            $payload = [
                'source'                       => $camara->rtsp_con_credenciales,
                'sourceOnDemand'               => true,
                'sourceOnDemandStartTimeout'   => '10s',
                'sourceOnDemandCloseAfter'     => '60s',
            ];

            // Primero intenta actualizar, si falla crea nuevo
            $res = \Illuminate\Support\Facades\Http::patch("{$apiBase}/v3/config/paths/patch/{$pathName}", $payload);
            if ($res->status() === 404) {
                \Illuminate\Support\Facades\Http::post("{$apiBase}/v3/config/paths/add/{$pathName}", $payload);
            }
        } catch (\Throwable) {
            // MediaMTX no disponible — no bloquear la operación principal
        }
    }

    /**
     * Prueba una cámara guardada y actualiza su status.
     */
    public function testSaved(Camara $camara)
    {
        abort_if($camara->empresa_id !== $this->empresaId(), 403);

        [$ok, $message] = $this->probarStream($camara->tipo, [
            'url_rtsp'   => $camara->url_rtsp,
            'url_stream' => $camara->url_stream,
            'usuario'    => $camara->usuario,
            'password'   => $camara->password,
        ]);

        $camara->update([
            'status'       => $ok ? 'connected' : 'error',
            'last_error'   => $ok ? null : $message,
            'last_checked' => now(),
        ]);

        return response()->json(['ok' => $ok, 'message' => $message]);
    }

    private function probarStream(string $tipo, array $data): array
    {
        try {
            if ($tipo === 'ip') {
                $url = $data['url_rtsp'] ?? '';
                if (!$url) return [false, 'URL RTSP requerida'];

                $parsed = parse_url($url);
                $host   = $parsed['host'] ?? '';
                $port   = $parsed['port'] ?? 554;

                if (!$host) return [false, 'URL RTSP inválida'];

                // IP privada → no testeable desde el servidor, pero el agente local sí puede conectar
                if (preg_match('/^(10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.)/', $host)) {
                    return [true, "IP local ({$host}) — prueba no disponible desde el servidor. El agente en tu red local se conectará automáticamente."];
                }

                // Preferir ffprobe si está instalado
                $ffprobe = trim(shell_exec('which ffprobe 2>/dev/null') ?? '');
                if ($ffprobe) {
                    if (!empty($data['usuario'])) {
                        $scheme = $parsed['scheme'] ?? 'rtsp';
                        $portStr = isset($parsed['port']) ? ":{$parsed['port']}" : '';
                        $path   = $parsed['path'] ?? '';
                        $query  = isset($parsed['query']) ? "?{$parsed['query']}" : '';
                        $creds  = urlencode($data['usuario']) . ':' . urlencode($data['password'] ?? '') . '@';
                        $url    = "{$scheme}://{$creds}{$host}{$portStr}{$path}{$query}";
                    }
                    $escaped = escapeshellarg($url);
                    exec("timeout 8 {$ffprobe} -v quiet -rtsp_transport tcp -i {$escaped} 2>&1", $out, $code);
                    return $code === 0
                        ? [true,  'Conexión exitosa (ffprobe)']
                        : [false, 'No se pudo conectar. Verifica IP, puerto y credenciales.'];
                }

                // Fallback: RTSP handshake con PHP puro (sin ffprobe)
                $socket = @fsockopen($host, (int) $port, $errno, $errstr, 5);
                if (!$socket) {
                    return [false, "No se pudo conectar a {$host}:{$port}. Verifica IP y puerto."];
                }

                // Enviar OPTIONS para verificar que responde como servidor RTSP
                $cseq   = 1;
                $url    = "rtsp://{$host}:{$port}/";
                $req    = "OPTIONS {$url} RTSP/1.0\r\nCSeq: {$cseq}\r\n\r\n";
                fwrite($socket, $req);
                stream_set_timeout($socket, 4);
                $resp = fread($socket, 512);
                fclose($socket);

                if (str_contains($resp, 'RTSP/1.0')) {
                    // 401 en OPTIONS es normal — la cámara exige auth incluso para OPTIONS.
                    // No implica credenciales incorrectas; se verifican al reproducir con MediaMTX.
                    return [true, "Servidor RTSP accesible en {$host}:{$port}. Credenciales se verificarán al reproducir."];
                }

                return [false, "El servidor en {$host}:{$port} no responde como cámara RTSP."];
            }

            if ($tipo === 'mjpeg') {
                $url = $data['url_stream'] ?? '';
                if (!$url) return [false, 'URL requerida'];

                $ctx = stream_context_create(['http' => ['timeout' => 6, 'method' => 'HEAD']]);
                $headers = @get_headers($url, true, $ctx);

                if ($headers && (str_contains($headers[0], '200') || str_contains($headers[0], '401'))) {
                    return [true, 'Stream accesible'];
                }
                return [false, 'No se pudo acceder al stream HTTP'];
            }

            return [false, 'Tipo de prueba no soportado para este tipo de cámara'];
        } catch (\Throwable $e) {
            return [false, 'Error al probar: ' . $e->getMessage()];
        }
    }

    /**
     * Retorna la URL del stream HLS via MediaMTX.
     * MediaMTX corre en el mismo servidor, expone HLS en puerto 8888.
     * Path del stream = "empresa_{id}_camara_{id}"
     */
    public function streamUrl(Camara $camara)
    {
        abort_if($camara->empresa_id !== $this->empresaId(), 403);
        abort_unless($camara->activo, 422, 'Cámara inactiva.');

        // Prioridad: mediamtx_url de la sucursal → config global → fallback localhost
        $sucursal     = $camara->sucursal;
        $mediaMtxBase = $sucursal?->mediamtx_url
            ?? config('services.mediamtx.hls_url', 'http://localhost:8888');

        $data = match ($camara->tipo) {
            'ip' => [
                'type'         => 'hls',
                'stream_url'   => "{$mediaMtxBase}/empresa_{$camara->empresa_id}_cam_{$camara->id}/index.m3u8",
                'rtsp_url'     => $camara->rtsp_con_credenciales,
                'mediamtx_url' => $mediaMtxBase,
            ],
            'mjpeg' => [
                'type'       => 'mjpeg',
                'stream_url' => $camara->url_stream,
            ],
            'usb' => [
                'type' => 'usb',
            ],
            'aws_kinesis' => [
                'type'            => 'aws_kinesis',
                'stream_name'     => $camara->aws_stream_name,
                'region'          => $camara->aws_region,
            ],
            default => abort(422, 'Tipo de cámara no soportado.')
        };

        return response()->json($data);
    }

    /**
     * Captura un frame via FFmpeg y lo guarda en storage.
     * Requiere FFmpeg instalado en el servidor.
     */
    public function snapshot(Camara $camara)
    {
        abort_if($camara->empresa_id !== $this->empresaId(), 403);
        abort_unless(in_array($camara->tipo, ['ip', 'mjpeg']), 422, 'Solo cámaras IP/MJPEG soportan snapshot remoto.');
        abort_unless($camara->activo, 422, 'Cámara inactiva.');

        $dir      = "capturas/{$camara->empresa_id}/{$camara->id}";
        $filename = now()->format('Ymd_His') . '_' . uniqid() . '.jpg';
        $fullPath = storage_path("app/public/{$dir}/{$filename}");

        Storage::disk('public')->makeDirectory($dir);

        if ($camara->tipo === 'ip') {
            $rtsp = escapeshellarg($camara->rtsp_con_credenciales);
            $out  = escapeshellarg($fullPath);
            $cmd  = "ffmpeg -y -rtsp_transport tcp -i {$rtsp} -frames:v 1 -q:v 2 {$out} 2>/dev/null";
        } else {
            $url = escapeshellarg($camara->url_stream);
            $out = escapeshellarg($fullPath);
            $cmd = "ffmpeg -y -i {$url} -frames:v 1 -q:v 2 {$out} 2>/dev/null";
        }

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || !file_exists($fullPath)) {
            return response()->json(['message' => 'No se pudo capturar el frame. Verifica conexión y credenciales.'], 422);
        }

        return response()->json([
            'url'      => Storage::disk('public')->url("{$dir}/{$filename}"),
            'path'     => "{$dir}/{$filename}",
            'captured' => now()->toIso8601String(),
        ]);
    }

    /**
     * Captura frame y lo asocia a una venta (llamado desde VentaController post-venta).
     */
    public function snapshotVenta(Request $request)
    {
        $request->validate([
            'venta_id'   => 'required|integer',
            'camara_ids' => 'nullable|array',
            'camara_ids.*' => 'integer',
        ]);

        $empresaId = $this->empresaId();
        $ventaId   = $request->integer('venta_id');

        $query = Camara::where('empresa_id', $empresaId)->where('activo', true);

        if ($request->filled('camara_ids')) {
            $query->whereIn('id', $request->camara_ids);
        }

        $camaras   = $this->applySucursalScope($query)->get();
        $resultados = [];

        foreach ($camaras as $camara) {
            if (!in_array($camara->tipo, ['ip', 'mjpeg'])) continue;

            $dir      = "capturas/{$empresaId}/ventas/{$ventaId}";
            $filename = "cam_{$camara->id}_" . now()->format('Ymd_His') . '.jpg';
            $fullPath = storage_path("app/public/{$dir}/{$filename}");

            Storage::disk('public')->makeDirectory($dir);

            if ($camara->tipo === 'ip') {
                $rtsp = escapeshellarg($camara->rtsp_con_credenciales);
                $out  = escapeshellarg($fullPath);
                $cmd  = "ffmpeg -y -rtsp_transport tcp -i {$rtsp} -frames:v 1 -q:v 2 {$out} 2>/dev/null";
            } else {
                $url = escapeshellarg($camara->url_stream);
                $out = escapeshellarg($fullPath);
                $cmd = "ffmpeg -y -i {$url} -frames:v 1 -q:v 2 {$out} 2>/dev/null";
            }

            exec($cmd, $cmdOutput, $exitCode);

            $resultados[] = [
                'camara_id' => $camara->id,
                'nombre'    => $camara->nombre,
                'ok'        => $exitCode === 0 && file_exists($fullPath),
                'url'       => $exitCode === 0 ? Storage::disk('public')->url("{$dir}/{$filename}") : null,
            ];
        }

        return response()->json(['capturas' => $resultados]);
    }
}
