<?php

namespace App\Services;

use App\Models\Cliente;

class PaymentReminderWhatsAppMessage
{
    public static function build(Cliente $cliente, string $businessName): string
    {
        $businessName = trim($businessName);
        $header = $businessName !== ''
            ? "💳 *Recordatorio de pago — {$businessName}*"
            : '💳 *Recordatorio de pago*';
        $saldo = number_format((float) ($cliente->saldo_credito ?? 0), 2);

        return implode("\n", [
            $header,
            '',
            "Hola {$cliente->nombre},",
            '',
            "Te recordamos que tienes un saldo pendiente de *\${$saldo}*.",
            '',
            'Por favor comunícate con nosotros para ponerte al corriente. ¡Gracias! 🙏',
        ]);
    }
}
