<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\SuperAdminConfig;
use App\Services\FacturapiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ConfigFiscalController extends Controller
{
    private FacturapiService $facturapi;

    public function __construct(FacturapiService $facturapi)
    {
        $this->facturapi = $facturapi;
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
                'ambiente'         => SuperAdminConfig::get('fiscal_ambiente', 'test'),
                'facturapi_org_id' => SuperAdminConfig::get('fiscal_facturapi_org_id'),
                'csd_subido'       => (bool) SuperAdminConfig::get('fiscal_csd_subido'),
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
            'ambiente'       => 'nullable|in:test,live',
        ]);

        foreach ($data as $key => $value) {
            SuperAdminConfig::set("fiscal_{$key}", $value);
        }

        // Si ya existe org en Facturapi, sincronizar datos legales
        $orgId = SuperAdminConfig::get('fiscal_facturapi_org_id');
        if ($orgId) {
            $rfc    = SuperAdminConfig::get('fiscal_rfc');
            $nombre = SuperAdminConfig::get('fiscal_razon_social');
            $regimen = SuperAdminConfig::get('fiscal_regimen_fiscal');
            $cp      = SuperAdminConfig::get('fiscal_codigo_postal');

            if ($rfc && $nombre && $regimen && $cp) {
                try {
                    $this->facturapi->actualizarLegalOrg($orgId, [
                        'legal_name' => $nombre,
                        'tax_id'     => strtoupper($rfc),
                        'tax_system' => $regimen,
                        'address'    => ['zip' => $cp],
                    ]);
                } catch (\Throwable $e) {
                    // No romper guardado si Facturapi falla — se puede reintentar
                    \Illuminate\Support\Facades\Log::warning("ConfigFiscal: error sync Facturapi org: {$e->getMessage()}");
                }
            }
        }

        return response()->json(['message' => 'Configuración guardada']);
    }

    /** POST /api/superadmin/config-fiscal/setup-facturapi
     *  Crea organización Facturapi para el superadmin (si no existe)
     */
    public function setupFacturapi(Request $request)
    {
        $orgId = SuperAdminConfig::get('fiscal_facturapi_org_id');

        if (! $orgId) {
            $nombre = SuperAdminConfig::get('fiscal_razon_social', 'Ventas POS SAAS');
            $orgId  = $this->facturapi->crearOrganizacion($nombre);
            SuperAdminConfig::set('fiscal_facturapi_org_id', $orgId);
        }

        $ambiente = SuperAdminConfig::get('fiscal_ambiente', 'test');

        // Obtener la API key correcta
        if ($ambiente === 'live') {
            $apiKey = $this->facturapi->getLiveApiKey($orgId);
            SuperAdminConfig::set('fiscal_facturapi_live_key', $apiKey);
        } else {
            $apiKey = $this->facturapi->getTestApiKey($orgId);
            SuperAdminConfig::set('fiscal_facturapi_test_key', $apiKey);
        }

        // Sincronizar datos legales si ya están guardados
        $rfc    = SuperAdminConfig::get('fiscal_rfc');
        $nombre = SuperAdminConfig::get('fiscal_razon_social');
        $regimen = SuperAdminConfig::get('fiscal_regimen_fiscal');
        $cp      = SuperAdminConfig::get('fiscal_codigo_postal');

        if ($rfc && $nombre && $regimen && $cp) {
            try {
                $this->facturapi->actualizarLegalOrg($orgId, [
                    'legal_name' => $nombre,
                    'tax_id'     => strtoupper($rfc),
                    'tax_system' => $regimen,
                    'address'    => ['zip' => $cp],
                ]);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("setupFacturapi: sync legal error: {$e->getMessage()}");
            }
        }

        return response()->json([
            'message'  => 'Organización Facturapi configurada',
            'org_id'   => $orgId,
            'ambiente' => $ambiente,
        ]);
    }

    /** POST /api/superadmin/config-fiscal/upload-csd
     *  Sube certificados CSD a la organización Facturapi del superadmin
     */
    public function uploadCsd(Request $request)
    {
        $request->validate([
            'cer'      => 'required|string',
            'key'      => 'required|string',
            'password' => 'required|string',
        ]);

        $orgId = SuperAdminConfig::get('fiscal_facturapi_org_id');

        if (! $orgId) {
            return response()->json(['message' => 'Primero configura la organización Facturapi'], 422);
        }

        // subirCsd usa el userKey() internamente — solo necesita orgId
        $this->facturapi->subirCsd($orgId, $request->cer, $request->key, $request->password);
        SuperAdminConfig::set('fiscal_csd_subido', true);

        return response()->json(['message' => 'CSD subido correctamente']);
    }

    /** DELETE /api/superadmin/config-fiscal/reset
     *  Borra toda la config fiscal local para empezar de cero.
     *  La org en Facturapi queda huérfana (sandbox — no importa).
     */
    public function reset()
    {
        \App\Models\SuperAdminConfig::where('key', 'like', 'fiscal_%')->delete();
        return response()->json(['message' => 'Configuración fiscal reiniciada']);
    }

    /** POST /api/superadmin/config-fiscal/test */
    public function test()
    {
        $ambiente = SuperAdminConfig::get('fiscal_ambiente', 'test');
        $apiKey   = $ambiente === 'live'
            ? SuperAdminConfig::get('fiscal_facturapi_live_key')
            : SuperAdminConfig::get('fiscal_facturapi_test_key');

        if (! $apiKey) {
            return response()->json(['message' => 'No hay API key configurada'], 422);
        }

        $ok = $this->facturapi->testOrg($apiKey);

        return response()->json([
            'ok'      => $ok,
            'message' => $ok ? 'Conexión exitosa con Facturapi' : 'Error de conexión',
        ]);
    }
}
