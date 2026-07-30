<?php

namespace Tests\Unit;

use App\Models\WhatsAppConfig;
use App\Services\BaileysWhatsAppService;
use RuntimeException;
use Tests\TestCase;

class BaileysWhatsAppServiceTest extends TestCase
{
    public function test_internal_token_is_required(): void
    {
        config()->set('services.whatsapp.baileys_url', 'http://127.0.0.1:3025');
        config()->set('services.whatsapp.baileys_token', '');

        $config = new WhatsAppConfig([
            'empresa_id' => 1,
            'session_key' => 'empresa-1',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('WHATSAPP_BAILEYS_TOKEN');

        app(BaileysWhatsAppService::class)->getStatus($config);
    }
}
