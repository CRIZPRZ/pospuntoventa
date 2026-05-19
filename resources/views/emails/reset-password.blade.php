@extends('emails.layout')

@section('title', 'Restablecer contraseña — Ventas POS')

@section('content')

{{-- Icono --}}
<div style="text-align:center;margin-bottom:28px;">
  <div style="display:inline-block;background:#fef3c7;border-radius:50%;width:64px;height:64px;line-height:64px;font-size:32px;text-align:center;">
    🔐
  </div>
</div>

{{-- Título --}}
<h1 style="margin:0 0 8px;font-size:22px;font-weight:800;color:#111827;text-align:center;">
  Restablecer contraseña
</h1>
<p style="margin:0 0 28px;font-size:15px;color:#6b7280;text-align:center;line-height:1.6;">
  Recibimos una solicitud para cambiar la contraseña de tu cuenta.<br/>
  Si no fuiste tú, ignora este correo.
</p>

{{-- CTA --}}
<div style="text-align:center;margin-bottom:32px;">
  <a href="{{ $url }}"
     style="display:inline-block;background:#2563eb;color:white;text-decoration:none;padding:14px 32px;border-radius:10px;font-weight:700;font-size:15px;">
    Cambiar mi contraseña
  </a>
</div>

{{-- URL manual --}}
<div style="background:#f8fafc;border-radius:10px;padding:16px;margin-bottom:24px;">
  <p style="margin:0 0 6px;font-size:12px;color:#9ca3af;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">
    O copia este enlace en tu navegador:
  </p>
  <p style="margin:0;font-size:12px;color:#2563eb;word-break:break-all;">{{ $url }}</p>
</div>

{{-- Aviso expira --}}
<div style="border:1px solid #fee2e2;background:#fff5f5;border-radius:10px;padding:14px 18px;">
  <p style="margin:0;font-size:13px;color:#991b1b;line-height:1.6;">
    ⚠️ Este enlace <strong>expira en 60 minutos</strong>.<br/>
    Si ya pasó ese tiempo, solicita uno nuevo desde la pantalla de inicio de sesión.
  </p>
</div>

@endsection
