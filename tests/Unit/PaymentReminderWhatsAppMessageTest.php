<?php

namespace Tests\Unit;

use App\Models\Cliente;
use App\Services\PaymentReminderWhatsAppMessage;
use PHPUnit\Framework\TestCase;

class PaymentReminderWhatsAppMessageTest extends TestCase
{
    public function test_reminder_omits_business_separator_when_name_is_blank(): void
    {
        $body = PaymentReminderWhatsAppMessage::build(
            new Cliente(['nombre' => 'ABIGAIL', 'saldo_credito' => 343]),
            '  ',
        );

        $this->assertStringContainsString('*Recordatorio de pago*', $body);
        $this->assertStringNotContainsString('Recordatorio de pago —', $body);
    }

    public function test_reminder_includes_trimmed_business_name(): void
    {
        $body = PaymentReminderWhatsAppMessage::build(
            new Cliente(['nombre' => 'ABIGAIL', 'saldo_credito' => 343]),
            ' AutoFussion ',
        );

        $this->assertStringContainsString('*Recordatorio de pago — AutoFussion*', $body);
    }
}
