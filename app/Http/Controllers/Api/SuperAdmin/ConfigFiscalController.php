<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\SuperAdminConfig;
use App\Services\Pac\PacManager;
use Illuminate\Http\Request;

class ConfigFiscalController extends Controller
{
    private function ctx(): array
    {
        $ambiente = SuperAdminConfig::get('fiscal_ambiente', 'test');

        return [
            'rfc'            => strtoupper(trim(SuperAdminConfig::get('fiscal_rfc', ''))),
            'nombre'         => SuperAdminConfig::get('fiscal_razon_social', ''),
            'nombre_sat'     => SuperAdminConfig::get('fiscal_razon_social', ''),
            'regimen_fiscal' => SuperAdminConfig::get('fiscal_regimen_fiscal', '601'),
            'codigo_postal'  => SuperAdminConfig::get('fiscal_codigo_postal', ''),
            'ambiente'       => $ambiente,
            'org_id'         => SuperAdminConfig::get('fiscal_facturapi_org_id'),
            'org_key'        => $ambiente === 'live'
                ? SuperAdminConfig::get('fiscal_facturapi_live_key')
                : SuperAdminConfig::get('fiscal_facturapi_test_key'),
        ];
    }

    private function pac()
    {
        return PacManager::make(SuperAdminConfig::get('fiscal_pac_provider', 'facturama'));
    }

    /** GET /api/superadmin/config-fiscal */
    public function show()
    {
        return response()->json([
            'data' => [
                'rfc'              => SuperAdminConfig::get('fiscal_rfc'),
                'razon_social'     => SuperAdminConfig::get('fiscal_razon_social'),
                'regimen_fiscal'   => SuperAdminConfig::get('fiscal_regimen_fiscal'),
                'codigo_postal'    => SuperAdminConfig::get('fiscal_codigo_postal'),
                'serie'            => SuperAdminConfig::get('fiscal_serie', 'SS'),
                'facturapi_org_id' => SuperAdminConfig::get('fiscal_facturapi_org_id'),
                'csd_subido'       => (bool) SuperAdminConfig::get('fiscal_csd_subido'),
                'csd_vigencia'     => SuperAdminConfig::get('fiscal_csd_vigencia'),
                'pac_provider'     => SuperAdminConfig::get('fiscal_pac_provider', 'facturama'),
            ],
        ]);
    }

    /** POST /api/superadmin/config-fiscal */
    public function update(Request $request)
    {
        $data = $request->validate([
            'rfc'            => 'nullable|string|max:13',
            'razon_social'   => 'nullable|string|max:255',
            'regimen_fiscal' => 'nullable|string|max:10',
            'codigo_postal'  => 'nullable|string|max:10',
            'serie'          => 'nullable|string|max:5',
            'pac_provider'   => 'nullable|in:facturapi,facturama,sw_sapiens',
        ]);

        foreach ($data as $key => $value) {
            SuperAdminConfig::set("fiscal_{$key}", $value);
        }

        // Si cambió a Facturama/SW Sapiens, marcar csd_subido=false para forzar re-subida
        if (isset($data['pac_provider']) && $data['pac_provider'] !== 'facturapi') {
            $csdSubido = SuperAdminConfig::get('fiscal_csd_subido');
            // Solo resetear si venía de Facturapi (tiene org_id pero no es multi-emisor)
            if (SuperAdminConfig::get('fiscal_facturapi_org_id') && !$csdSubido) {
                SuperAdminConfig::set('fiscal_csd_subido', false);
            }
        }

        return response()->json(['message' => 'Configuración guardada']);
    }

    /** POST /api/superadmin/config-fiscal/setup-facturapi */
    public function setupFacturapi(Request $request)
    {
        $provider = SuperAdminConfig::get('fiscal_pac_provider', 'facturama');
        $ctx      = $this->ctx();

        if ($provider === 'facturapi') {
            $facturapi = app(\App\Services\FacturapiService::class);
            $orgId     = SuperAdminConfig::get('fiscal_facturapi_org_id');

            if (!$orgId) {
                $nombre = SuperAdminConfig::get('fiscal_razon_social', 'Ventas POS SAAS');
                $orgId  = $facturapi->crearOrganizacion($nombre);
                SuperAdminConfig::set('fiscal_facturapi_org_id', $orgId);
            }

            $ambiente = SuperAdminConfig::get('fiscal_ambiente', 'test') === 'live' ? 'live' : 'test';
            if ($ambiente === 'live') {
                $apiKey = $facturapi->getLiveApiKey($orgId);
                SuperAdminConfig::set('fiscal_facturapi_live_key', $apiKey);
            } else {
                $apiKey = $facturapi->getTestApiKey($orgId);
                SuperAdminConfig::set('fiscal_facturapi_test_key', $apiKey);
            }

            return response()->json(['message' => 'Conexión verificada correctamente', 'org_id' => $orgId]);
        }

        // Facturama / SW Sapiens: setup es no-op, solo verificar conexión
        try {
            $this->pac()->test($ctx);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Conexión verificada correctamente']);
    }

    /** POST /api/superadmin/config-fiscal/upload-csd */
    public function uploadCsd(Request $request)
    {
        $request->validate([
            'cer'      => 'required|string',
            'key'      => 'required|string',
            'password' => 'required|string',
        ]);

        $provider = SuperAdminConfig::get('fiscal_pac_provider', 'facturama');
        $ctx      = $this->ctx();

        if ($provider === 'facturapi') {
            $orgId = SuperAdminConfig::get('fiscal_facturapi_org_id');
            if (!$orgId) {
                return response()->json(['message' => 'Primero configura la organización Facturapi'], 422);
            }
            $facturapi = app(\App\Services\FacturapiService::class);
            $facturapi->subirCsd($orgId, $request->cer, $request->key, $request->password);
        } else {
            $this->pac()->subirCsd($ctx, $request->cer, $request->key, $request->password);
        }

        $vigencia = $this->extractCsdVigencia($request->cer);
        SuperAdminConfig::set('fiscal_csd_subido', true);
        if ($vigencia) SuperAdminConfig::set('fiscal_csd_vigencia', $vigencia);

        return response()->json([
            'message'      => 'CSD subido correctamente',
            'csd_vigencia' => $vigencia,
        ]);
    }

    /** POST /api/superadmin/config-fiscal/test */
    public function test()
    {
        $provider = SuperAdminConfig::get('fiscal_pac_provider', 'facturama');
        $ctx      = $this->ctx();

        if ($provider === 'facturapi') {
            $apiKey = $ctx['org_key'];
            if (!$apiKey) {
                return response()->json(['message' => 'No hay API key configurada'], 422);
            }
            $facturapi = app(\App\Services\FacturapiService::class);
            $ok = $facturapi->testOrg($apiKey);
            return response()->json(['ok' => $ok, 'message' => $ok ? 'Conexión verificada correctamente' : 'Error de conexión']);
        }

        try {
            $this->pac()->test($ctx);
            return response()->json(['ok' => true, 'message' => 'Conexión verificada correctamente']);
        } catch (\Exception $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /** DELETE /api/superadmin/config-fiscal/reset */
    public function reset()
    {
        \App\Models\SuperAdminConfig::where('key', 'like', 'fiscal_%')->delete();
        return response()->json(['message' => 'Configuración fiscal reiniciada']);
    }

    private function extractCsdVigencia(string $cerBase64): ?string
    {
        try {
            $derBin = base64_decode($cerBase64);
            $tmpFile = tempnam(sys_get_temp_dir(), 'cer_');
            file_put_contents($tmpFile, $derBin);
            $output = shell_exec("openssl x509 -inform DER -noout -enddate -in " . escapeshellarg($tmpFile) . " 2>/dev/null");
            unlink($tmpFile);
            if ($output && preg_match('/notAfter=(.+)/', trim($output), $m)) {
                $dt = \DateTime::createFromFormat('M j H:i:s Y T', trim($m[1]));
                if (!$dt) $dt = new \DateTime(trim($m[1]));
                return $dt ? $dt->format('Y-m-d') : null;
            }
        } catch (\Throwable $e) {}
        return null;
    }
}
