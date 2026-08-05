<?php

namespace App\Mail;

use App\Models\Empresa;
use App\Models\License;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DesktopInstallerMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Empresa $empresa,
        public License $license,
        public string $downloadUrl,
        public string $activationEmail,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tu instalador de EventPOS',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.desktop-installer',
        );
    }
}
