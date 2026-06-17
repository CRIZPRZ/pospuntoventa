<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Ticket {{ $venta->folio }}</title>
<style>
  @page { margin: 0; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1a1a2e; background: #fff; margin: 0; padding: 0; }

  .page { max-width: 100%; margin: 0; background: #fff; }

  /* Header */
  .header { background: {{ $documentos['color_header'] ?? '#1e40af' }}; color: #fff; padding: 28px 24px 22px; text-align: center; }
  .header .brand { font-size: 9px; letter-spacing: 3px; text-transform: uppercase; opacity: 0.75; margin-bottom: 6px; }
  .header .company { font-size: 22px; font-weight: bold; margin-bottom: 4px; }
  .header .address { font-size: 11px; opacity: 0.8; }
  .header img { max-height: 52px; margin-bottom: 10px; }

  /* Hero strip */
  .hero { background: #eff6ff; border-bottom: 2px solid #bfdbfe; padding: 16px 24px; display: table; width: 100%; }
  .hero-left { display: table-cell; vertical-align: middle; }
  .hero-right { display: table-cell; text-align: right; vertical-align: middle; }
  .folio { font-size: 18px; font-weight: bold; color: #1e40af; }
  .badge { display: inline-block; font-size: 10px; font-weight: bold; padding: 3px 10px; border-radius: 20px; }
  .badge-green { background: #dcfce7; color: #15803d; }
  .badge-red   { background: #fee2e2; color: #b91c1c; }
  .total-big   { font-size: 26px; font-weight: bold; color: #1e40af; }
  .total-label { font-size: 10px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; }

  /* Meta */
  .meta-grid { padding: 14px 24px; border-bottom: 1px solid #e2e8f0; display: table; width: 100%; }
  .meta-cell { display: table-cell; width: 50%; vertical-align: top; }
  .meta-row  { font-size: 11px; color: #64748b; margin-bottom: 5px; }
  .meta-row strong { color: #1e293b; font-weight: 600; }

  /* Section title */
  .section { padding: 14px 24px 0; }
  .section-title { font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 2px; color: #94a3b8; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px; margin-bottom: 10px; }

  /* Items table */
  .items { width: 100%; border-collapse: collapse; margin: 0 24px; width: calc(100% - 48px); }
  .items th { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: #94a3b8; padding: 0 0 8px 0; text-align: left; }
  .items th.right { text-align: right; }
  .items td { font-size: 12px; color: #334155; padding: 8px 0; border-top: 1px solid #f1f5f9; vertical-align: top; }
  .items td.right { text-align: right; font-weight: 600; color: #1e293b; }
  .items .qty  { color: #64748b; font-size: 11px; }

  /* Totals */
  .totals { margin: 12px 24px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px; }
  .trow { display: table; width: 100%; font-size: 12px; margin-bottom: 6px; }
  .trow-label { display: table-cell; color: #64748b; }
  .trow-val   { display: table-cell; text-align: right; color: #334155; }
  .trow.grand { border-top: 1.5px solid #cbd5e1; padding-top: 8px; margin-top: 4px; }
  .trow.grand .trow-label { font-size: 14px; font-weight: bold; color: #1e293b; }
  .trow.grand .trow-val   { font-size: 16px; font-weight: bold; color: #1e40af; }

  /* Payment badge */
  .pay-row { padding: 10px 24px; }
  .pay-badge { display: inline-block; font-size: 11px; font-weight: 600; padding: 5px 14px; border-radius: 20px; background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }

  /* Footer */
  .footer { text-align: center; padding: 18px 24px; border-top: 1px dashed #cbd5e1; margin-top: 12px; }
  .footer .thanks { font-size: 13px; font-weight: bold; color: #334155; margin-bottom: 4px; }
  .footer .sub    { font-size: 10px; color: #94a3b8; }
</style>
</head>
<body>
<div class="page">

  {{-- HEADER --}}
  <div class="header">
    @if($logoUrl)
      <div><img src="{{ $logoUrl }}" alt="Logo"></div>
    @endif
    <div class="brand">Ticket de compra</div>
    <div class="company">{{ $config['nombre_comercial'] ?? $empresa['nombre'] ?? 'Mi Negocio' }}</div>
    @if(!empty($empresa['direccion']))<div class="address">{{ $empresa['direccion'] }}</div>@endif
    @if(!empty($empresa['telefono']))<div class="address">Tel: {{ $empresa['telefono'] }}</div>@endif
  </div>

  {{-- HERO --}}
  <div class="hero">
    <div class="hero-left">
      <div class="folio">{{ $venta->folio }}</div>
      <div style="margin-top:6px">
        <span class="badge {{ $venta->estado === 'cancelada' ? 'badge-red' : 'badge-green' }}">
          {{ $venta->estado === 'cancelada' ? '✕ Cancelada' : '✓ Pagada' }}
        </span>
      </div>
    </div>
    <div class="hero-right">
      <div class="total-label">Total pagado</div>
      <div class="total-big">${{ number_format((float)$venta->total, 2) }}</div>
    </div>
  </div>

  {{-- META --}}
  <div class="meta-grid">
    <div class="meta-cell">
      <div class="meta-row">Fecha: <strong>{{ $venta->created_at->setTimezone('America/Mexico_City')->format('d/m/Y H:i') }}</strong></div>
      @if($venta->user)<div class="meta-row">Cajero: <strong>{{ $venta->user->name }}</strong></div>@endif
    </div>
    <div class="meta-cell">
      @if($venta->cliente)<div class="meta-row">Cliente: <strong>{{ $venta->cliente->nombre }}</strong></div>@endif
      <div class="meta-row">Pago:
        <strong>{{ match($venta->tipo_pago) { 'efectivo'=>'Efectivo','tarjeta'=>'Tarjeta','credito'=>'Crédito',default=>ucfirst($venta->tipo_pago)} }}</strong>
      </div>
    </div>
  </div>

  {{-- PRODUCTS --}}
  <div class="section">
    <div class="section-title">Productos</div>
    <table class="items">
      <thead>
        <tr>
          <th>Descripción</th>
          <th class="right">Importe</th>
        </tr>
      </thead>
      <tbody>
        @foreach($venta->items as $item)
        <tr>
          <td>
            {{ $item->nombre_producto }}<br>
            <span class="qty">{{ (int)$item->cantidad }} × ${{ number_format((float)$item->precio_unitario,2) }}</span>
          </td>
          <td class="right">${{ number_format((float)$item->subtotal,2) }}</td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  {{-- TOTALS --}}
  <div class="totals" style="margin-top:14px">
    @if((float)$venta->descuento > 0)
      <div class="trow"><span class="trow-label">Subtotal</span><span class="trow-val">${{ number_format((float)$venta->subtotal,2) }}</span></div>
      <div class="trow"><span class="trow-label">Descuento</span><span class="trow-val" style="color:#dc2626">-${{ number_format((float)$venta->descuento,2) }}</span></div>
    @endif
    @if((float)$venta->impuesto > 0)
      <div class="trow"><span class="trow-label">Impuesto</span><span class="trow-val">${{ number_format((float)$venta->impuesto,2) }}</span></div>
    @endif
    <div class="trow grand"><span class="trow-label">Total</span><span class="trow-val">${{ number_format((float)$venta->total,2) }}</span></div>
  </div>

  {{-- FOOTER --}}
  <div class="footer">
    <div class="thanks">¡Gracias por tu compra!</div>
    <div class="sub">Conserva este comprobante · {{ $venta->created_at->setTimezone('America/Mexico_City')->format('d/m/Y H:i') }}</div>
    @if(!empty($documentos['pie_pagina']))<div class="sub" style="margin-top:6px">{{ $documentos['pie_pagina'] }}</div>@endif
  </div>

</div>
</body>
</html>
