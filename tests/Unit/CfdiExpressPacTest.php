<?php

namespace Tests\Unit;

use App\Services\Pac\CfdiExpressPac;
use App\Services\Pac\PacManager;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CfdiExpressPacTest extends TestCase
{
    private const API = 'https://api.cfdi.express/v1';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests(); // ningún test debe pegarle a la API real

        config([
            'services.cfdi_express.url'      => 'https://api.cfdi.express',
            'services.cfdi_express.mode'     => 'test',
            'services.cfdi_express.test_key' => 'sk_test_fake',
            'services.cfdi_express.live_key' => 'sk_live_fake',
        ]);
    }

    private function ctx(array $extra = []): array
    {
        return array_merge([
            'rfc'            => 'EKU9003173C9',
            'nombre'         => 'Escuela Kemper Urgate',
            'nombre_sat'     => null,
            'regimen_fiscal' => '601',
            'codigo_postal'  => '26015',
            'serie'          => 'A',
        ], $extra);
    }

    private function invoice(array $receptor = []): array
    {
        return [
            'tipo'        => 'I',
            'serie'       => 'A',
            'folio'       => 7,
            'referencia'  => 'V-000123',
            'forma_pago'  => '01',
            'metodo_pago' => 'PUE',
            'tasa_iva'    => 0.16,
            'emisor'      => ['codigo_postal' => '26015'],
            'receptor'    => $receptor ?: [
                'es_publico'     => false,
                'rfc'            => 'XIA190128J61',
                'nombre'         => 'Xenon Industrial Articles',
                'codigo_postal'  => '76343',
                'regimen_fiscal' => '601',
                'uso_cfdi'       => 'G03',
            ],
            'items' => [[
                'descripcion'    => 'Coca cola',
                'clave_sat'      => '50202306',
                'clave_unidad'   => 'H87',
                'unidad'         => 'Pieza',
                'precio_con_iva' => 23.2,
                'cantidad'       => 2,
                'codigo'         => '7501|055',
            ]],
        ];
    }

    private function merchantsList(array $merchants = []): array
    {
        return ['object' => 'list', 'data' => $merchants, 'has_more' => false];
    }

    public function test_es_el_pac_por_defecto(): void
    {
        $this->assertInstanceOf(CfdiExpressPac::class, PacManager::make(null));
        $this->assertInstanceOf(CfdiExpressPac::class, PacManager::for(null));
    }

    public function test_setup_crea_merchant_si_no_existe(): void
    {
        Http::fake([
            self::API . '/merchants?*' => Http::response($this->merchantsList()),
            self::API . '/merchants'   => Http::response(['id' => 'mer_123'], 201),
        ]);

        $result = (new CfdiExpressPac())->setup($this->ctx());

        $this->assertSame('mer_123', $result['merchant_id']);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === self::API . '/merchants'
            && $r['rfc'] === 'EKU9003173C9'
            && $r['legalName'] === 'ESCUELA KEMPER URGATE'
            && $r['zip'] === '26015'
            && $r->hasHeader('Authorization', 'Bearer sk_test_fake'));
    }

    public function test_setup_reutiliza_merchant_existente_por_rfc(): void
    {
        Http::fake([
            self::API . '/merchants?*' => Http::response($this->merchantsList([
                ['id' => 'mer_old', 'rfc' => 'EKU9003173C9', 'archivedAt' => '2026-01-01'],
                ['id' => 'mer_ok', 'rfc' => 'EKU9003173C9', 'archivedAt' => null],
            ])),
            self::API . '/merchants/mer_ok' => Http::response(['id' => 'mer_ok', 'zip' => '26015', 'regimenFiscal' => '601', 'serie' => 'A']),
        ]);

        $result = (new CfdiExpressPac())->setup($this->ctx());

        $this->assertSame('mer_ok', $result['merchant_id']);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_setup_valida_codigo_postal(): void
    {
        Http::fake([self::API . '/merchants?*' => Http::response($this->merchantsList())]);

        $this->expectExceptionMessage('5 dígitos');
        (new CfdiExpressPac())->setup($this->ctx(['codigo_postal' => '123']));
    }

    public function test_crear_factura_arma_payload_e_idempotencia(): void
    {
        Http::fake([
            self::API . '/merchants?*'  => Http::response($this->merchantsList([['id' => 'mer_1', 'rfc' => 'EKU9003173C9']])),
            self::API . '/invoices'     => Http::response(['id' => 'inv_1', 'status' => 'stamped', 'uuid' => 'UUID-1'], 201),
            self::API . '/invoices/inv_1' => Http::response(['id' => 'inv_1', 'files' => ['status' => 'ready', 'xmlUrl' => 'https://files.cfdi.express/x.xml']]),
            'https://files.cfdi.express/x.xml' => Http::response('<cfdi/>'),
        ]);

        $result = (new CfdiExpressPac())->crearFactura($this->ctx(), $this->invoice());

        $this->assertSame('UUID-1', $result['uuid']);
        $this->assertSame('test:inv_1', $result['pac_id']);
        $this->assertSame('<cfdi/>', $result['xml']);

        Http::assertSent(function (Request $r) {
            if ($r->url() !== self::API . '/invoices') return false;
            $item = $r['items'][0];

            return $r['merchantId'] === 'mer_1'
                && $r['pricesIncludeTax'] === true
                && $r['folio'] === 'V-000123'
                && $r['receiver']['rfc'] === 'XIA190128J61'
                && $r['usoCfdi'] === 'G03'
                && $item['unitPrice'] == 23.2
                && $item['ivaRate'] == 0.16
                && $item['noIdentificacion'] === '7501055'
                && !isset($r['informacionGlobal'])
                && str_starts_with($r->header('Idempotency-Key')[0] ?? '', 'cfdi-');
        });
    }

    public function test_misma_factura_usa_misma_idempotency_key(): void
    {
        Http::fake([
            self::API . '/merchants?*'    => Http::response($this->merchantsList([['id' => 'mer_1', 'rfc' => 'EKU9003173C9']])),
            self::API . '/invoices'       => Http::response(['id' => 'inv_1', 'status' => 'stamped', 'uuid' => 'U'], 201),
            self::API . '/invoices/inv_1' => Http::response(['files' => ['status' => 'ready', 'xmlUrl' => 'https://f/x']]),
            'https://f/x'                 => Http::response('<x/>'),
        ]);

        $pac = new CfdiExpressPac();
        $pac->crearFactura($this->ctx(), $this->invoice());
        $pac->crearFactura($this->ctx(), $this->invoice());

        $keys = collect(Http::recorded())
            ->filter(fn ($pair) => $pair[0]->url() === self::API . '/invoices')
            ->map(fn ($pair) => $pair[0]->header('Idempotency-Key')[0])
            ->unique();

        $this->assertCount(1, $keys);
    }

    public function test_publico_en_general_manda_informacion_global(): void
    {
        Http::fake([
            self::API . '/merchants?*'    => Http::response($this->merchantsList([['id' => 'mer_1', 'rfc' => 'EKU9003173C9']])),
            self::API . '/invoices'       => Http::response(['id' => 'inv_2', 'status' => 'stamped', 'uuid' => 'U2'], 201),
            self::API . '/invoices/inv_2' => Http::response(['files' => ['status' => 'ready', 'xmlUrl' => 'https://f/x']]),
            'https://f/x'                 => Http::response('<x/>'),
        ]);

        (new CfdiExpressPac())->crearFactura($this->ctx(), $this->invoice(['es_publico' => true]));

        Http::assertSent(fn (Request $r) => $r->url() === self::API . '/invoices'
            && $r['receiver']['rfc'] === 'XAXX010101000'
            && $r['receiver']['zip'] === '26015'
            && $r['usoCfdi'] === 'S01'
            && $r['informacionGlobal']['periodicidad'] === '01');
    }

    public function test_saldo_insuficiente_no_expone_detalle_al_tenant(): void
    {
        Http::fake([
            self::API . '/merchants?*' => Http::response($this->merchantsList([['id' => 'mer_1', 'rfc' => 'EKU9003173C9']])),
            self::API . '/invoices'    => Http::response(['code' => 'insufficient_credits', 'detail' => 'Saldo insuficiente'], 402),
        ]);

        $this->expectExceptionMessage('Contacta a soporte');
        (new CfdiExpressPac())->crearFactura($this->ctx(), $this->invoice());
    }

    public function test_rechazo_sat_muestra_detalle(): void
    {
        Http::fake([
            self::API . '/merchants?*' => Http::response($this->merchantsList([['id' => 'mer_1', 'rfc' => 'EKU9003173C9']])),
            self::API . '/invoices'    => Http::response(['code' => 'sat_rejected', 'detail' => 'CFDI40147: RFC del receptor no existe'], 422),
        ]);

        $this->expectExceptionMessage('CFDI40147');
        (new CfdiExpressPac())->crearFactura($this->ctx(), $this->invoice());
    }

    public function test_descargas_y_cancelacion_usan_el_modo_del_pac_id(): void
    {
        Http::fake([
            self::API . '/invoices/inv_9'        => Http::response(['files' => ['status' => 'ready', 'pdfUrl' => 'https://f/p.pdf']]),
            'https://f/p.pdf'                    => Http::response('%PDF'),
            self::API . '/invoices/inv_9/cancel' => Http::response(['status' => 'cancelled']),
        ]);

        $pac = new CfdiExpressPac();
        $this->assertSame('%PDF', $pac->descargarPdf([], 'live:inv_9'));
        $this->assertSame('cancelled', $pac->cancelarFactura([], 'live:inv_9', '02', null)['status']);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/invoices/inv_9')
            && $r->hasHeader('Authorization', 'Bearer sk_live_fake'));
    }

    public function test_cancelar_motivo_01_exige_uuid_sustitucion(): void
    {
        $this->expectExceptionMessage('motivo 01');
        (new CfdiExpressPac())->cancelarFactura([], 'test:inv_1', '01', null);
    }

    public function test_publico_en_general_usa_cp_registrado_en_el_merchant(): void
    {
        Http::fake([
            self::API . '/merchants?*'    => Http::response($this->merchantsList([['id' => 'mer_1', 'rfc' => 'EKU9003173C9', 'zip' => '36257']])),
            self::API . '/invoices'       => Http::response(['id' => 'inv_3', 'status' => 'stamped', 'uuid' => 'U3'], 201),
            self::API . '/invoices/inv_3' => Http::response(['files' => ['status' => 'ready', 'xmlUrl' => 'https://f/x']]),
            'https://f/x'                 => Http::response('<x/>'),
        ]);

        (new CfdiExpressPac())->crearFactura($this->ctx(), $this->invoice(['es_publico' => true]));

        Http::assertSent(fn (Request $r) => $r->url() === self::API . '/invoices' && $r['receiver']['zip'] === '36257');
    }

    public function test_setup_sincroniza_cp_y_regimen_del_merchant_existente(): void
    {
        Http::fake([
            self::API . '/merchants?*'    => Http::response($this->merchantsList([
                ['id' => 'mer_1', 'rfc' => 'EKU9003173C9', 'zip' => '11111', 'regimenFiscal' => '601', 'serie' => 'A'],
            ])),
            self::API . '/merchants/mer_1' => Http::response(['id' => 'mer_1', 'zip' => '26015', 'regimenFiscal' => '601', 'serie' => 'A']),
        ]);

        (new CfdiExpressPac())->setup($this->ctx());

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r->url() === self::API . '/merchants/mer_1'
            && $r->data() === ['zip' => '26015']);
    }

    public function test_tras_error_del_pac_el_reintento_usa_otra_idempotency_key(): void
    {
        Http::fake([
            self::API . '/merchants?*'    => Http::response($this->merchantsList([['id' => 'mer_1', 'rfc' => 'EKU9003173C9']])),
            self::API . '/invoices'       => Http::sequence()
                ->push(['code' => 'sat_rejected', 'detail' => 'CSD no encontrado en LCO'], 422)
                ->push(['id' => 'inv_4', 'status' => 'stamped', 'uuid' => 'U4'], 201),
            self::API . '/invoices/inv_4' => Http::response(['files' => ['status' => 'ready', 'xmlUrl' => 'https://f/x']]),
            'https://f/x'                 => Http::response('<x/>'),
        ]);

        $pac = new CfdiExpressPac();
        try {
            $pac->crearFactura($this->ctx(), $this->invoice());
            $this->fail('Debió lanzar el rechazo del SAT');
        } catch (\Exception $e) {
            $this->assertStringContainsString('LCO', $e->getMessage());
        }
        $this->assertSame('U4', $pac->crearFactura($this->ctx(), $this->invoice())['uuid']);

        $keys = collect(Http::recorded())
            ->filter(fn ($pair) => $pair[0]->url() === self::API . '/invoices')
            ->map(fn ($pair) => $pair[0]->header('Idempotency-Key')[0])
            ->values();

        $this->assertCount(2, $keys);
        $this->assertNotSame($keys[0], $keys[1]);
    }

    public function test_razon_social_sin_regimen_societario_solo_en_morales(): void
    {
        $pac = new CfdiExpressPac();

        $this->assertSame('ESCUELA KEMPER URGATE', $pac->razonSocialCfdi('ESCUELA KEMPER URGATE SA DE CV', 'EKU9003173C9'));
        $this->assertSame('MI EMPRESA SAAS', $pac->razonSocialCfdi('Mi Empresa SaaS, S.A.P.I. de C.V.', 'MES010101AB1'));
        $this->assertSame('TACOS EL GÜERO', $pac->razonSocialCfdi('Tacos el Güero S. de R.L. de C.V.', 'TEG010101AB1'));
        $this->assertSame('CONSULTORES UNIDOS', $pac->razonSocialCfdi('CONSULTORES UNIDOS SC', 'CUN010101AB1'));
        // Persona física: intacto aunque termine parecido.
        $this->assertSame('XOCHILT CASAS CHAVEZ', $pac->razonSocialCfdi('Xochilt Casas Chavez', 'CACX7605101P8'));
        $this->assertSame('JUAN SA', $pac->razonSocialCfdi('Juan SA', 'JUSA800101AB1'));
    }
}
