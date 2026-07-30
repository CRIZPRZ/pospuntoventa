<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cotización {{ $cotizacion->folio }}</title>
@php
  $headerColor = $documentos['color_header'] ?? '#2563eb';
  $canAct = in_array($cotizacion->status, ['borrador', 'enviada']);
@endphp
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; font-size: 14px; color: #1a1a2e; background: #f1f5f9; min-height: 100vh; }

  .page-wrap { max-width: 680px; margin: 0 auto; padding: 20px 16px 40px; }

  /* Action bar */
  .action-bar { background: white; border-radius: 16px; padding: 16px 20px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); flex-wrap: wrap; }
  .action-bar-left { font-size: 13px; color: #64748b; }
  .action-bar-left strong { color: #1e293b; }
  .action-bar-right { display: flex; gap: 10px; flex-wrap: wrap; }

  .btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px; border-radius: 10px; font-size: 13px; font-weight: 600; cursor: pointer; border: none; text-decoration: none; transition: opacity .15s; }
  .btn:hover { opacity: .88; }
  .btn-accept { background: #059669; color: white; }
  .btn-reject { background: #fef2f2; color: #dc2626; border: 1.5px solid #fecaca; }
  .btn-pdf    { background: #f1f5f9; color: #475569; border: 1.5px solid #e2e8f0; }

  /* Status message */
  .msg-box { border-radius: 12px; padding: 14px 20px; margin-bottom: 16px; font-size: 14px; font-weight: 500; }
  .msg-accept { background: #f0fdf4; border: 1.5px solid #86efac; color: #166534; }
  .msg-reject { background: #fef2f2; border: 1.5px solid #fecaca; color: #991b1b; }
  .msg-vencida { background: #fffbeb; border: 1.5px solid #fde68a; color: #92400e; }

  /* Full-screen feedback while a decision is being processed */
  .decision-loading { position: fixed; inset: 0; z-index: 1000; display: none; align-items: center; justify-content: center; padding: 24px; background: rgba(15, 23, 42, .72); backdrop-filter: blur(3px); }
  .decision-loading.active { display: flex; }
  .decision-loading-card { width: min(100%, 340px); padding: 28px 24px; border-radius: 18px; background: white; text-align: center; box-shadow: 0 24px 60px rgba(15, 23, 42, .3); }
  .decision-spinner { width: 42px; height: 42px; margin: 0 auto 16px; border: 4px solid #dbeafe; border-top-color: {{ $headerColor }}; border-radius: 50%; animation: decision-spin .75s linear infinite; }
  .decision-loading-title { color: #0f172a; font-size: 17px; font-weight: 700; }
  .decision-loading-copy { margin-top: 7px; color: #64748b; font-size: 13px; line-height: 1.45; }
  @keyframes decision-spin { to { transform: rotate(360deg); } }

  /* Card */
  .card { background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }

  .header { background: {{ $headerColor }}; color: #fff; padding: 28px 24px 22px; text-align: center; }
  .header .brand { font-size: 10px; letter-spacing: 3px; text-transform: uppercase; opacity: 0.75; margin-bottom: 6px; }
  .header .company { font-size: 22px; font-weight: bold; margin-bottom: 4px; }
  .header .address { font-size: 12px; opacity: 0.8; margin-top: 2px; }
  .header img { max-height: 52px; margin-bottom: 10px; border-radius: 6px; }

  .hero { background: #f8faff; border-bottom: 2px solid #e0e7ff; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
  .folio { font-size: 20px; font-weight: bold; color: {{ $headerColor }}; }
  .badge { display: inline-block; font-size: 11px; font-weight: bold; padding: 3px 12px; border-radius: 20px; margin-top: 6px; }
  .badge-borrador  { background: #f3f4f6; color: #6b7280; }
  .badge-enviada   { background: #eff6ff; color: #2563eb; }
  .badge-aceptada  { background: #f0fdf4; color: #059669; }
  .badge-rechazada { background: #fef2f2; color: #dc2626; }
  .badge-vencida   { background: #fffbeb; color: #d97706; }
  .total-big   { font-size: 28px; font-weight: bold; color: {{ $headerColor }}; }
  .total-label { font-size: 10px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; text-align: right; }

  .meta-grid { padding: 14px 24px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px; }
  .meta-row { font-size: 12px; color: #64748b; margin-bottom: 4px; }
  .meta-row strong { color: #1e293b; font-weight: 600; }

  .validity-box { margin: 14px 24px; background: #faf5ff; border: 1.5px solid #e9d5ff; border-radius: 8px; padding: 10px 14px; display: flex; justify-content: space-between; }
  .validity-label { font-size: 12px; color: #7c3aed; }
  .validity-val   { font-size: 12px; color: #7c3aed; font-weight: 600; }

  .section { padding: 14px 24px 0; }
  .section-title { font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: 2px; color: #94a3b8; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px; margin-bottom: 10px; }

  .items { width: 100%; border-collapse: collapse; }
  .items th { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: #94a3b8; padding: 0 0 8px 0; text-align: left; }
  .items th.right { text-align: right; }
  .items td { font-size: 13px; color: #334155; padding: 8px 0; border-top: 1px solid #f1f5f9; vertical-align: top; }
  .items td.right { text-align: right; font-weight: 600; color: #1e293b; }
  .items .qty  { color: #64748b; font-size: 12px; }

  .totals { margin: 12px 24px 0; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px; }
  .trow { display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 6px; }
  .trow-label { color: #64748b; }
  .trow-val   { color: #334155; }
  .trow.grand { border-top: 1.5px solid #cbd5e1; padding-top: 8px; margin-top: 4px; }
  .trow.grand .trow-label { font-size: 15px; font-weight: bold; color: #1e293b; }
  .trow.grand .trow-val   { font-size: 17px; font-weight: bold; color: {{ $headerColor }}; }

  .notes { margin: 12px 24px; font-size: 12px; color: #64748b; font-style: italic; }

  .footer { text-align: center; padding: 18px 24px; border-top: 1px dashed #cbd5e1; margin-top: 12px; }
  .footer .thanks { font-size: 14px; font-weight: bold; color: #334155; margin-bottom: 4px; }
  .footer .sub    { font-size: 11px; color: #94a3b8; margin-top: 4px; }
</style>
</head>
<body>
<div class="page-wrap">

  {{-- Action bar --}}
  <div class="action-bar">
    <div class="action-bar-left">
      Cotización <strong>{{ $cotizacion->folio }}</strong> de
      <strong>{{ $config['nombre_comercial'] ?? $empresa['nombre'] ?? 'Mi Negocio' }}</strong>
    </div>
    <div class="action-bar-right">
      <a href="{{ route('ticket.cotizacion.pdf', $token) }}" class="btn btn-pdf" target="_blank">
        ↓ Descargar PDF
      </a>
      @if($canAct)
        <form method="POST" action="{{ route('ticket.cotizacion.rechazar', $token) }}" class="decision-form" data-loading-title="Registrando rechazo..." style="display:inline">
          @csrf
          <button type="submit" class="btn btn-reject"
            onclick="return confirm('¿Confirmas que deseas rechazar esta cotización?')">
            ✕ Rechazar
          </button>
        </form>
        <form method="POST" action="{{ route('ticket.cotizacion.aceptar', $token) }}" class="decision-form" data-loading-title="Aceptando cotización..." style="display:inline">
          @csrf
          <button type="submit" class="btn btn-accept"
            onclick="return confirm('¿Confirmas que deseas aceptar esta cotización?')">
            ✓ Aceptar cotización
          </button>
        </form>
      @endif
    </div>
  </div>

  {{-- Status/result message --}}
  @if(session('action_msg'))
    @php
      $isAccept = str_contains(session('action_msg'), 'aceptada') || str_contains(session('action_msg'), 'pedido');
      $isReject = str_contains(session('action_msg'), 'rechazada');
    @endphp
    <div class="msg-box {{ $isAccept ? 'msg-accept' : ($isReject ? 'msg-reject' : 'msg-vencida') }}">
      {{ session('action_msg') }}
    </div>
  @elseif(!$canAct)
    @php
      $finalMessage = match($cotizacion->status) {
        'aceptada' => 'Esta cotización ya fue aceptada y se generó el pedido.',
        'rechazada' => 'Esta cotización fue rechazada. La decisión ya no puede modificarse.',
        'vencida' => 'Esta cotización venció y ya no admite una decisión.',
        default => 'Esta cotización ya no admite cambios.',
      };
    @endphp
    <div class="msg-box {{ $cotizacion->status === 'aceptada' ? 'msg-accept' : ($cotizacion->status === 'rechazada' ? 'msg-reject' : 'msg-vencida') }}">
      {{ $finalMessage }}
    </div>
  @endif

  {{-- Cotizacion card --}}
  <div class="card">

    <div class="header">
      @if($logoUrl)<div><img src="{{ $logoUrl }}" alt="Logo"></div>@endif
      <div class="brand">Cotización</div>
      <div class="company">{{ $config['nombre_comercial'] ?? $empresa['nombre'] ?? 'Mi Negocio' }}</div>
      @if(!empty($empresa['direccion']))<div class="address">{{ $empresa['direccion'] }}</div>@endif
      @if(!empty($empresa['telefono']))<div class="address">Tel: {{ $empresa['telefono'] }}</div>@endif
    </div>

    <div class="hero">
      <div>
        <div class="folio">{{ $cotizacion->folio }}</div>
        <div>
          <span class="badge badge-{{ $cotizacion->status }}">{{ ucfirst($cotizacion->status) }}</span>
        </div>
      </div>
      <div style="text-align:right">
        <div class="total-label">Total cotizado</div>
        <div class="total-big">${{ number_format((float)$cotizacion->total, 2) }}</div>
      </div>
    </div>

    <div class="meta-grid">
      <div>
        <div class="meta-row">Fecha: <strong>{{ $cotizacion->fecha->format('d/m/Y') }}</strong></div>
        @if($cotizacion->vendedor)<div class="meta-row">Vendedor: <strong>{{ $cotizacion->vendedor->name }}</strong></div>@endif
      </div>
      <div>
        @if($cotizacion->cliente)<div class="meta-row">Cliente: <strong>{{ $cotizacion->cliente->nombre }}</strong></div>
        @elseif($cotizacion->nombre_cliente)<div class="meta-row">Cliente: <strong>{{ $cotizacion->nombre_cliente }}</strong></div>@endif
      </div>
    </div>

    @if($cotizacion->fecha_vencimiento)
    <div class="validity-box">
      <span class="validity-label">Vigencia de la cotización</span>
      <span class="validity-val">{{ $cotizacion->fecha_vencimiento->format('d/m/Y') }}</span>
    </div>
    @endif

    <div class="section">
      <div class="section-title">Productos y servicios</div>
      <table class="items">
        <thead>
          <tr>
            <th>Descripción</th>
            <th class="right">Importe</th>
          </tr>
        </thead>
        <tbody>
          @foreach($cotizacion->items as $item)
          @php
            $desc     = $item->descripcion ?? $item->nombre_producto ?? $item->producto?->nombre ?? '—';
            $subtotal = (float)($item->subtotal ?? ($item->precio_unitario * $item->cantidad));
          @endphp
          <tr>
            <td>
              {{ $desc }}<br>
              <span class="qty">{{ number_format((float)$item->cantidad, 2) }} × ${{ number_format((float)$item->precio_unitario, 2) }}
              @if((float)($item->descuento??0)>0) · desc. {{ $item->descuento }}%@endif
              </span>
            </td>
            <td class="right">${{ number_format($subtotal, 2) }}</td>
          </tr>
          @endforeach
        </tbody>
      </table>
    </div>

    <div class="totals" style="margin-top:14px">
      <div class="trow"><span class="trow-label">Subtotal</span><span class="trow-val">${{ number_format((float)$cotizacion->subtotal, 2) }}</span></div>
      @if((float)$cotizacion->descuento > 0)
        <div class="trow"><span class="trow-label">Descuento {{ $cotizacion->descuento }}%</span><span class="trow-val" style="color:#dc2626">-${{ number_format($cotizacion->subtotal*$cotizacion->descuento/100, 2) }}</span></div>
      @endif
      @if((float)$cotizacion->impuesto_pct > 0)
        <div class="trow"><span class="trow-label">IVA {{ $cotizacion->impuesto_pct }}%</span><span class="trow-val">${{ number_format($cotizacion->subtotal*(1-$cotizacion->descuento/100)*$cotizacion->impuesto_pct/100, 2) }}</span></div>
      @endif
      <div class="trow grand"><span class="trow-label">Total</span><span class="trow-val">${{ number_format((float)$cotizacion->total, 2) }}</span></div>
    </div>

    @if($cotizacion->notas && ($documentos['mostrar_notas'] ?? true))
    <div class="notes">Notas: {{ $cotizacion->notas }}</div>
    @endif

    <div class="footer">
      <div class="thanks">Esperamos poder servirle.</div>
      <div class="sub">Esta cotización no es una factura fiscal. Precios sujetos a cambio sin previo aviso.</div>
      @if($cotizacion->fecha_vencimiento)
        <div class="sub">Válida hasta: {{ $cotizacion->fecha_vencimiento->format('d/m/Y') }}</div>
      @endif
      @if(!empty($documentos['pie_pagina']))<div class="sub" style="margin-top:6px">{{ $documentos['pie_pagina'] }}</div>@endif
    </div>

  </div>
</div>

<div id="decision-loading" class="decision-loading" role="status" aria-live="polite" aria-hidden="true">
  <div class="decision-loading-card">
    <div class="decision-spinner"></div>
    <div id="decision-loading-title" class="decision-loading-title">Procesando decisión...</div>
    <div class="decision-loading-copy">Espera un momento. No cierres esta página.</div>
  </div>
</div>

<script>
  (() => {
    const overlay = document.getElementById('decision-loading')
    const title = document.getElementById('decision-loading-title')
    const forms = document.querySelectorAll('.decision-form')

    const hideLoading = () => {
      overlay?.classList.remove('active')
      overlay?.setAttribute('aria-hidden', 'true')
      document.body.style.overflow = ''
      forms.forEach((form) => {
        form.querySelectorAll('button').forEach((button) => { button.disabled = false })
      })
    }

    forms.forEach((form) => {
      form.addEventListener('submit', () => {
        title.textContent = form.dataset.loadingTitle || 'Procesando decisión...'
        forms.forEach((item) => {
          item.querySelectorAll('button').forEach((button) => { button.disabled = true })
        })
        overlay.classList.add('active')
        overlay.setAttribute('aria-hidden', 'false')
        document.body.style.overflow = 'hidden'
      })
    })

    window.addEventListener('pageshow', hideLoading)
  })()
</script>
</body>
</html>
