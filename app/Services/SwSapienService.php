<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class SwSapienService
{
    private string $baseUrl;
    private string $user;
    private string $password;
    private string $cacheKey;

    public function __construct(array $config)
    {
        $this->baseUrl = ($config['ambiente'] ?? 'sandbox') === 'production'
            ? 'https://services.sw.com.mx'
            : 'https://services.test.sw.com.mx';

        $this->user     = $config['sw_usuario'] ?? '';
        $this->password = $config['sw_password'] ?? '';
        $this->cacheKey = 'sw_token_' . md5($this->user);
    }

    public function authenticate(): string
    {
        $cached = Cache::get($this->cacheKey);
        if ($cached) return $cached;

        $response = Http::post("{$this->baseUrl}/security/authenticate", [
            'user'     => $this->user,
            'password' => $this->password,
        ]);

        if (!$response->successful() || $response->json('status') !== 'success') {
            $msg = $response->json('message') ?? 'Error autenticando con SW Sapien';
            throw new \Exception($msg);
        }

        $token = $response->json('data.token');
        Cache::put($this->cacheKey, $token, now()->addHours(23));

        return $token;
    }

    /**
     * Emite CFDI 4.0 desde estructura JSON.
     * Requiere CSD configurado en la plataforma SW Sapien.
     * Endpoint: POST /v4/cfdi40/issue/json/ondemand
     */
    public function issue(array $cfdiJson): array
    {
        $token = $this->authenticate();

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type'  => 'application/json',
        ])->post("{$this->baseUrl}/v4/cfdi40/issue/json/ondemand", $cfdiJson);

        if (!$response->successful()) {
            Cache::forget($this->cacheKey);
            $msg = $response->json('message') ?? $response->json('messageDetail') ?? 'Error al timbrar CFDI';
            throw new \Exception($msg);
        }

        $status = $response->json('status');
        if ($status !== 'success') {
            $msg = $response->json('message') ?? $response->json('messageDetail') ?? 'Error al timbrar CFDI';
            throw new \Exception($msg);
        }

        $data = $response->json('data');

        return [
            'uuid' => $data['uuid'] ?? null,
            'xml'  => base64_decode($data['cfdi'] ?? ''),
        ];
    }

    public function testConexion(): bool
    {
        Cache::forget($this->cacheKey);
        $this->authenticate();
        return true;
    }
}
