<?php

namespace App\Mail;

use App\Models\SuscripcionFactura;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FacturaSuscripcionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public SuscripcionFactura $factura,
        public string $xmlContent,
        public string $pdfContent,   // base64-encoded bytes (safe for queue JSON serialization)
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Factura CFDI — Suscripción Ventas POS ({$this->factura->cfdi_uuid})",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.factura-suscripcion');
    }

    public function attachments(): array
    {
        $uuid = $this->factura->cfdi_uuid ?? $this->factura->id;
        return [
            Attachment::fromData(fn() => $this->xmlContent, "cfdi_{$uuid}.xml")
                ->withMime('application/xml'),
            Attachment::fromData(fn() => base64_decode($this->pdfContent), "cfdi_{$uuid}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
