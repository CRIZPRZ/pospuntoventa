<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\License;
use App\Services\DesktopLicenseService;
use Illuminate\Http\Request;

class DesktopLicenseController extends Controller
{
    public function __construct(
        private readonly DesktopLicenseService $service
    ) {
    }

    public function me(Request $request)
    {
        $empresa = $request->user()->empresa;

        if (! $empresa) {
            return response()->json(['message' => 'El usuario no pertenece a ninguna empresa.'], 422);
        }

        $license = $this->service->getOrCreateForEmpresa($empresa);

        return response()->json([
            'data' => array_merge(
                $this->service->buildResponse($license),
                [
                    'devices' => $license->devices()
                        ->where('is_active', true)
                        ->whereNull('revoked_at')
                        ->get([
                            'device_uuid',
                            'device_name',
                            'platform',
                            'app_version',
                            'last_seen_at',
                        ]),
                ]
            ),
        ]);
    }

    public function activate(Request $request)
    {
        $data = $request->validate([
            'device_uuid' => ['required', 'string', 'max:120'],
            'device_name' => ['required', 'string', 'max:120'],
            'fingerprint' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'string', 'max:60'],
            'app_version' => ['nullable', 'string', 'max:40'],
        ]);

        $empresa = $request->user()->empresa;

        if (! $empresa) {
            return response()->json(['message' => 'El usuario no pertenece a ninguna empresa.'], 422);
        }

        $license = $this->service->getOrCreateForEmpresa($empresa);
        $device = $this->service->activateDevice($license, $data);

        return response()->json([
            'data' => $this->service->buildResponse($license->fresh(), $device),
        ]);
    }

    public function validateLicense(Request $request)
    {
        $data = $request->validate([
            'license_key' => ['required', 'string', 'max:80'],
            'device_uuid' => ['required', 'string', 'max:120'],
            'fingerprint' => ['nullable', 'string', 'max:255'],
        ]);

        $license = License::with('empresa.plan', 'empresa.modulos')
            ->where('license_key', $data['license_key'])
            ->firstOrFail();

        $device = $this->service->validateDevice($license, $data['device_uuid'], $data['fingerprint'] ?? null);

        return response()->json([
            'data' => $this->service->buildResponse($license->fresh(), $device),
        ]);
    }

    public function deactivateDevice(Request $request)
    {
        $data = $request->validate([
            'device_uuid' => ['required', 'string', 'max:120'],
        ]);

        $empresa = $request->user()->empresa;

        if (! $empresa) {
            return response()->json(['message' => 'El usuario no pertenece a ninguna empresa.'], 422);
        }

        $license = $this->service->getOrCreateForEmpresa($empresa);
        $device = $license->devices()
            ->where('device_uuid', $data['device_uuid'])
            ->where('is_active', true)
            ->whereNull('revoked_at')
            ->firstOrFail();

        $this->service->deactivateDevice($device);

        return response()->json([
            'message' => 'Dispositivo desvinculado correctamente.',
            'data' => $this->service->buildResponse($license->fresh()),
        ]);
    }
}
