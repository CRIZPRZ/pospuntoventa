@extends('emails.layout')

@section('title', '¡Bienvenido a Ventas POS!')

@section('content')

{{-- Icono hero --}}
<div style="text-align:center;margin-bottom:28px;">
  <div style="display:inline-block;background:#eff6ff;border-radius:50%;width:64px;height:64px;line-height:64px;font-size:32px;text-align:center;">
    🎉
  </div>
</div>

{{-- Título --}}
<h1 style="margin:0 0 8px;font-size:24px;font-weight:800;color:#111827;text-align:center;letter-spacing:-0.5px;">
  ¡Bienvenido, {{ $user->name }}!
</h1>
<p style="margin:0 0 28px;font-size:16px;color:#6b7280;text-align:center;line-height:1.6;">
  Tu empresa <strong style="color:#111827;">{{ $empresaNombre }}</strong> ya está lista.<br/>
  Tienes <strong style="color:#2563eb;">{{ $diasTrial }} días gratis</strong> para explorar todo el sistema.
</p>

{{-- CTA principal --}}
<div style="text-align:center;margin-bottom:36px;">
  <a href="{{ env('FRONTEND_URL', 'http://localhost:5173') }}/dashboard"
     style="display:inline-block;background:#2563eb;color:white;text-decoration:none;padding:14px 32px;border-radius:10px;font-weight:700;font-size:16px;letter-spacing:-0.2px;">
    Entrar a mi cuenta →
  </a>
</div>

{{-- Qué puedes hacer --}}
<div style="background:#f8fafc;border-radius:12px;padding:24px;margin-bottom:28px;">
  <p style="margin:0 0 16px;font-size:14px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:0.05em;">
    ¿Qué puedes hacer hoy?
  </p>
  @php
    $pasos = [
      ['🛒', 'Registra tu primera venta',    'Abre el Punto de Venta y cobra en segundos', null],
      ['📦', 'Agrega tus productos',          'Importa desde Excel o crea uno a uno',       'productos'],
      ['🧾', 'Configura tu facturación CFDI', 'Conecta tu CSD y timbra facturas',           'facturacion'],
      ['🏪', 'Configura tu empresa',          'Logo, datos fiscales y diseño del ticket',   'configuracion'],
    ];
  @endphp
  @foreach($pasos as [$icon, $title, $desc, $requiere])
    @if(!$requiere || in_array($requiere, $modulos))
    <div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:12px;">
      <span style="font-size:20px;width:28px;flex-shrink:0;">{{ $icon }}</span>
      <div>
        <p style="margin:0;font-size:14px;font-weight:600;color:#111827;">{{ $title }}</p>
        <p style="margin:2px 0 0;font-size:13px;color:#6b7280;">{{ $desc }}</p>
      </div>
    </div>
    @endif
  @endforeach
</div>

{{-- Trial info --}}
<div style="border:1.5px solid #bfdbfe;background:#eff6ff;border-radius:10px;padding:16px 20px;">
  <p style="margin:0;font-size:13px;color:#1e40af;line-height:1.6;">
    <strong>⏱ Tu prueba gratuita termina en {{ $diasTrial }} días.</strong><br/>
    Sin tarjeta de crédito por ahora. Cuando estés listo, puedes elegir el plan que mejor se adapte a tu negocio desde
    <a href="{{ env('FRONTEND_URL', 'http://localhost:5173') }}/mi-plan" style="color:#2563eb;font-weight:700;">Mi Plan</a>.
  </p>
</div>

@endsection
