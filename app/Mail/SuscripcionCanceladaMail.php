<?php

namespace App\Mail;

use App\Models\Empresa;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SuscripcionCanceladaMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Empresa $empresa,
        public string  $planNombre,
        public ?string $vigenciaHasta = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Tu suscripción a Ventas POS ha sido cancelada",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.suscripcion-cancelada',
        );
    }
}
