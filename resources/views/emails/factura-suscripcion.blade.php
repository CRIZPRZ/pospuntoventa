@extends('emails.layout')

@section('title', 'Factura CFDI — Ventas POS')

@section('content')
<h2 style="margin:0 0 8px;font-size:22px;font-weight:800;color:#111827;">Tu factura está lista</h2>
<p style="margin:0 0 24px;font-size:15px;color:#6b7280;line-height:1.6;">
  Adjuntamos el XML y PDF de tu CFDI por la suscripción a <strong style="color:#111827;">Ventas POS</strong>.
</p>

<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:20px 24px;margin-bottom:24px;">
  <p style="margin:0 0 6px;font-size:13px;font-weight:700;color:#1d4ed8;text-transform:uppercase;letter-spacing:0.05em;">Datos del comprobante</p>
  <table style="width:100%;border-collapse:collapse;">
    <tr>
      <td style="padding:4px 0;font-size:13px;color:#6b7280;width:40%;">UUID</td>
      <td style="padding:4px 0;font-size:13px;color:#111827;font-weight:600;">{{ $factura->cfdi_uuid }}</td>
    </tr>
    <tr>
      <td style="padding:4px 0;font-size:13px;color:#6b7280;">Concepto</td>
      <td style="padding:4px 0;font-size:13px;color:#111827;font-weight:600;">{{ $factura->concepto }}</td>
    </tr>
    <tr>
      <td style="padding:4px 0;font-size:13px;color:#6b7280;">Monto</td>
      <td style="padding:4px 0;font-size:13px;color:#111827;font-weight:600;">${{ number_format($factura->monto, 2) }} {{ $factura->moneda }}</td>
    </tr>
    <tr>
      <td style="padding:4px 0;font-size:13px;color:#6b7280;">Fecha</td>
      <td style="padding:4px 0;font-size:13px;color:#111827;font-weight:600;">{{ $factura->created_at->locale('es')->isoFormat('D [de] MMMM [de] YYYY') }}</td>
    </tr>
  </table>
</div>

<p style="margin:0 0 16px;font-size:14px;color:#374151;line-height:1.7;">
  Encuentra el XML y PDF adjuntos a este correo. También puedes descargarlos en cualquier momento desde <strong>Mi Plan</strong> dentro de tu sistema.
</p>

<table cellpadding="0" cellspacing="0" style="margin:24px 0;">
  <tr>
    <td style="background:#2563eb;border-radius:10px;">
      <a href="https://app.eventpos.online/mi-plan"
         style="display:inline-block;padding:13px 28px;color:white;font-size:14px;font-weight:700;text-decoration:none;">
        Ver en Mi Plan
      </a>
    </td>
  </tr>
</table>

<p style="margin:0;font-size:13px;color:#9ca3af;line-height:1.6;">
  ¿Tienes dudas? Contáctanos en
  <a href="mailto:c.lira.prz@gmail.com" style="color:#2563eb;text-decoration:none;">c.lira.prz@gmail.com</a>
</p>
@endsection
