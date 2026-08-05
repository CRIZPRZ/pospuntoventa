<?php

namespace App\Services;

use App\Models\Empresa;
use App\Models\License;
use App\Models\LicenseDevice;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DesktopLicenseService
{
    public function getOrCreateForEmpresa(Empresa $empresa): License
    {
        $license = License::firstOrCreate(
            ['empresa_id' => $empresa->id],
            [
                'license_key' => $this->generateLicenseKey(),
                'status' => 'suspended',
                'issued_at' => now(),
                'max_devices' => (int) config('desktop.default_max_devices', 1),
                'plan_snapshot' => $this->buildPlanSnapshot($empresa),
            ]
        );

        $license->forceFill([
            'valid_until' => $empresa->plan_vigente_hasta,
        ])->save();

        return $license->fresh();
    }

    public function activateDevice(License $license, array $attributes): LicenseDevice
    {
        $device = $license->devices()->firstWhere('device_uuid', $attributes['device_uuid']);

        if (! $device) {
            $activeDevices = $license->devices()
                ->where('is_active', true)
                ->whereNull('revoked_at')
                ->count();

            if ($activeDevices >= (int) $license->max_devices) {
                throw ValidationException::withMessages([
                    'device_uuid' => 'Esta licencia ya alcanzó el máximo de dispositivos permitidos.',
                ]);
            }

            $device = new LicenseDevice([
                'device_uuid' => $attributes['device_uuid'],
            ]);
            $device->license()->associate($license);
        }

        $device->fill([
            'device_name' => $attributes['device_name'],
            'fingerprint' => $attributes['fingerprint'],
            'platform' => $attributes['platform'],
            'app_version' => $attributes['app_version'] ?? null,
            'last_seen_at' => now(),
            'last_token_issued_at' => now(),
            'revoked_at' => null,
            'is_active' => true,
        ]);
        $device->save();

        $license->forceFill([
            'valid_until' => $license->empresa->plan_vigente_hasta,
            'grace_until' => now()->addHours((int) config('desktop.license_offline_grace_hours', 48)),
            'last_validated_at' => now(),
            'plan_snapshot' => $this->buildPlanSnapshot($license->empresa),
        ])->save();

        return $device->fresh();
    }

    public function deactivateDevice(LicenseDevice $device): void
    {
        $device->forceFill([
            'is_active' => false,
            'revoked_at' => now(),
        ])->save();
    }

    public function validateDevice(License $license, string $deviceUuid, ?string $fingerprint = null): LicenseDevice
    {
        $device = $license->devices()
            ->where('device_uuid', $deviceUuid)
            ->where('is_active', true)
            ->whereNull('revoked_at')
            ->first();

        if (! $device) {
            throw ValidationException::withMessages([
                'device_uuid' => 'Este dispositivo no está vinculado a la licencia.',
            ]);
        }

        if ($fingerprint && $device->fingerprint !== $fingerprint) {
            throw ValidationException::withMessages([
                'fingerprint' => 'La huella del dispositivo no coincide con la licencia registrada.',
            ]);
        }

        $device->forceFill([
            'last_seen_at' => now(),
        ])->save();

        $license->forceFill([
            'valid_until' => $license->empresa->plan_vigente_hasta,
            'grace_until' => now()->addHours((int) config('desktop.license_offline_grace_hours', 48)),
            'last_validated_at' => now(),
            'plan_snapshot' => $this->buildPlanSnapshot($license->empresa),
        ])->save();

        return $device->fresh();
    }

    public function buildResponse(License $license, ?LicenseDevice $device = null): array
    {
        $license->loadMissing('empresa.plan', 'empresa.modulos', 'devices');
        $empresa = $license->empresa;

        [$resolvedStatus, $allowed, $reason] = $this->resolveStatus($license, $empresa);

        return [
            'license' => [
                'key' => $license->license_key,
                'status' => $license->status,
                'resolved_status' => $resolvedStatus,
                'issued_at' => $license->issued_at,
                'valid_until' => $license->valid_until,
                'grace_until' => $license->grace_until,
                'last_validated_at' => $license->last_validated_at,
                'max_devices' => (int) $license->max_devices,
                'offline_grace_hours' => (int) config('desktop.license_offline_grace_hours', 48),
                'activation_token' => $device ? $this->issueActivationToken($license, $device) : null,
            ],
            'device' => $device ? [
                'device_uuid' => $device->device_uuid,
                'device_name' => $device->device_name,
                'platform' => $device->platform,
                'app_version' => $device->app_version,
                'last_seen_at' => $device->last_seen_at,
            ] : null,
            'access' => [
                'allowed' => $allowed,
                'reason' => $reason,
                'plan_estado' => $empresa->plan_estado,
                'server_time' => now()->toIso8601String(),
            ],
            'modules' => $empresa->modulosActivos(),
            'limits' => [
                'max_sucursales' => $empresa->limiteSucursales(),
                'max_usuarios' => $empresa->limiteUsuarios(),
            ],
            'empresa' => [
                'id' => $empresa->id,
                'nombre' => $empresa->nombre,
                'slug' => $empresa->slug,
            ],
        ];
    }

    private function resolveStatus(License $license, Empresa $empresa): array
    {
        if (in_array($license->status, ['suspended', 'cancelled'], true)) {
            return [$license->status, false, 'La licencia fue desactivada manualmente.'];
        }

        if ($empresa->accesoSistemaVigente()) {
            $status = $empresa->plan_estado === 'activo' ? 'active' : 'trial';

            return [$status, true, null];
        }

        if ($license->grace_until?->isFuture()) {
            return ['grace', true, 'La app está operando en período de gracia sin conexión o con pago pendiente.'];
        }

        return ['expired', false, 'La licencia venció o el plan ya no está vigente.'];
    }

    private function buildPlanSnapshot(Empresa $empresa): array
    {
        $empresa->loadMissing('plan');

        return [
            'plan_id' => $empresa->plan_id,
            'plan_nombre' => $empresa->plan?->nombre,
            'plan_estado' => $empresa->plan_estado,
            'plan_vigente_hasta' => optional($empresa->plan_vigente_hasta)?->toIso8601String(),
            'max_sucursales' => $empresa->limiteSucursales(),
            'max_usuarios' => $empresa->limiteUsuarios(),
        ];
    }

    private function generateLicenseKey(): string
    {
        return sprintf(
            'EVP-%s-%s-%s',
            Str::upper(Str::random(4)),
            Str::upper(Str::random(4)),
            Str::upper(Str::random(8))
        );
    }

    private function issueActivationToken(License $license, LicenseDevice $device): string
    {
        $payload = json_encode([
            'license_key' => $license->license_key,
            'device_uuid' => $device->device_uuid,
            'empresa_id' => $license->empresa_id,
            'issued_at' => now()->toIso8601String(),
            'grace_until' => optional($license->grace_until)->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES);

        $encodedPayload = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $payload, (string) config('app.key'));

        return $encodedPayload . '.' . $signature;
    }
}
