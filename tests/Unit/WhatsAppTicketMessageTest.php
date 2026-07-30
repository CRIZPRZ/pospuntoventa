<?php

namespace Tests\Unit;

use App\Services\VentaTicketWhatsAppMessage;
use App\Models\Venta;
use App\Models\VentaItem;
use Tests\TestCase;

class WhatsAppTicketMessageTest extends TestCase
{
    public function test_ticket_omits_business_preposition_when_name_is_blank(): void
    {
        [$body, $url] = VentaTicketWhatsAppMessage::build(
            $this->venta(),
            '   ',
        );

        $this->assertStringContainsString('¡Gracias por tu compra!', $body);
        $this->assertStringNotContainsString('compra en', $body);
        $this->assertSame('https://tickets.example.test/t/ticket-token', $url);
    }

    public function test_ticket_includes_trimmed_business_name(): void
    {
        [$body] = VentaTicketWhatsAppMessage::build(
            $this->venta(),
            '  Autofussion  ',
        );

        $this->assertStringContainsString('¡Gracias por tu compra en Autofussion!', $body);
    }

    private function venta(): Venta
    {
        config()->set('services.whatsapp.public_url', 'https://tickets.example.test/');

        $venta = new Venta([
            'folio' => 'V-000001',
            'total' => 48.93,
            'ticket_token' => 'ticket-token',
        ]);

        $venta->setRelation('items', collect([
            new VentaItem([
                'nombre_producto' => 'Producto de prueba',
                'precio_unitario' => 48.93,
                'cantidad' => 1,
            ]),
        ]));

        return $venta;
    }
}
