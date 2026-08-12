@extends('emails.layout')

@section('title', 'Suscripción cancelada — Ventas POS')

@section('content')
<h2 style="margin:0 0 8px;font-size:22px;font-weight:800;color:#111827;">Tu suscripción fue cancelada</h2>
<p style="margin:0 0 24px;font-size:15px;color:#6b7280;line-height:1.6;">
  Hola, <strong style="color:#111827;">{{ $empresa->nombre }}</strong>.
</p>

<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:20px 24px;margin-bottom:24px;">
  <p style="margin:0 0 6px;font-size:13px;font-weight:700;color:#dc2626;text-transform:uppercase;letter-spacing:0.05em;">Plan cancelado</p>
  <p style="margin:0;font-size:20px;font-weight:800;color:#111827;">{{ $planNombre }}</p>
  @if($vigenciaHasta)
  <p style="margin:8px 0 0;font-size:13px;color:#6b7280;">
    Tu acceso se mantiene activo hasta el <strong>{{ $vigenciaHasta }}</strong>.
  </p>
  @endif
</div>

<p style="margin:0 0 16px;font-size:14px;color:#374151;line-height:1.7;">
  A partir de esa fecha tu sistema quedará en modo restringido. Tus datos están seguros y puedes reactivar tu plan en cualquier momento.
</p>

<table cellpadding="0" cellspacing="0" style="margin:24px 0;">
  <tr>
    <td style="background:#2563eb;border-radius:10px;">
      <a href="https://app.eventpos.online/mi-plan"
         style="display:inline-block;padding:13px 28px;color:white;font-size:14px;font-weight:700;text-decoration:none;">
        Reactivar mi plan
      </a>
    </td>
  </tr>
</table>

<p style="margin:0;font-size:13px;color:#9ca3af;line-height:1.6;">
  Si cancelaste por error o tienes dudas, contáctanos en
  <a href="mailto:c.lira.prz@gmail.com" style="color:#2563eb;text-decoration:none;">c.lira.prz@gmail.com</a>
  y lo resolvemos.
</p>
@endsection
