@extends('emails.layout')

@section('title', 'Tu instalador de EventPOS')

@section('content')

<div style="text-align:center;margin-bottom:28px;">
  <div style="display:inline-block;background:#eff6ff;border-radius:50%;width:64px;height:64px;line-height:64px;font-size:32px;text-align:center;">
    💻
  </div>
</div>

<h1 style="margin:0 0 8px;font-size:24px;font-weight:800;color:#111827;text-align:center;letter-spacing:-0.5px;">
  Instala EventPOS en tu equipo
</h1>
<p style="margin:0 0 28px;font-size:16px;color:#6b7280;text-align:center;line-height:1.6;">
  <strong style="color:#111827;">{{ $empresa->nombre }}</strong> ya puede activar EventPOS en este equipo.
</p>

<div style="text-align:center;margin-bottom:32px;">
  <a href="{{ $downloadUrl }}"
     style="display:inline-block;background:#2563eb;color:white;text-decoration:none;padding:14px 32px;border-radius:10px;font-weight:700;font-size:16px;letter-spacing:-0.2px;">
    Descargar instalador →
  </a>
</div>

<div style="background:#f8fafc;border-radius:12px;padding:24px;margin-bottom:28px;">
  <p style="margin:0 0 16px;font-size:14px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:0.05em;">
    Datos para activar tu equipo
  </p>

  <p style="margin:0 0 4px;font-size:12px;color:#9ca3af;">Llave de licencia</p>
  <p style="margin:0 0 16px;font-size:15px;font-weight:700;color:#111827;font-family:monospace;letter-spacing:0.5px;">
    {{ $license->license_key }}
  </p>

  <p style="margin:0 0 4px;font-size:12px;color:#9ca3af;">Correo de activación</p>
  <p style="margin:0;font-size:15px;font-weight:700;color:#111827;">
    {{ $activationEmail }}
  </p>
</div>

<div style="border:1.5px solid #bfdbfe;background:#eff6ff;border-radius:10px;padding:16px 20px;">
  <p style="margin:0;font-size:13px;color:#1e40af;line-height:1.6;">
    <strong>Pasos para instalar:</strong><br/>
    1. Descarga e instala EventPOS con el botón de arriba.<br/>
    2. Al abrirlo por primera vez, ingresa tu llave de licencia y el correo de activación.<br/>
    3. Inicia sesión normalmente con tu usuario y contraseña.
  </p>
</div>

@endsection
