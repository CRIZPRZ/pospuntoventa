<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Cotización {{ $cotizacion->folio }}</title>
@php $headerColor = $documentos['color_header'] ?? '#6d28d9'; @endphp
<style>
  @page { margin: 0; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1a1a2e; background: #fff; margin: 0; padding: 0; }
  .page { max-width: 100%; margin: 0; background: #fff; }

  .header { background: {{ $headerColor }}; color: #fff; padding: 28px 24px 22px; text-align: center; }
  .header .brand { font-size: 9px; letter-spacing: 3px; text-transform: uppercase; opacity: 0.75; margin-bottom: 6px; }
  .header .company { font-size: 22px; font-weight: bold; margin-bottom: 4px; }
  .header .address { font-size: 11px; opacity: 0.8; }
  .header img { max-height: 52px; margin-bottom: 10px; }

  .hero { background: #f5f3ff; border-bottom: 2px solid #c4b5fd; padding: 16px 24px; display: table; width: 100%; }
  .hero-left { display: table-cell; vertical-align: middle; }
  .hero-right { display: table-cell; text-align: right; vertical-align: middle; }
  .folio { font-size: 18px; font-weight: bold; color: {{ $headerColor }}; }
  .badge { display: inline-block; font-size: 10px; font-weight: bold; padding: 3px 10px; border-radius: 20px; background: #ede9fe; color: {{ $headerColor }}; }
  .total-big   { font-size: 26px; font-weight: bold; color: {{ $headerColor }}; }
  .total-label { font-size: 10px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; }

  .meta-grid { padding: 14px 24px; border-bottom: 1px solid #e2e8f0; display: table; width: 100%; }
  .meta-cell { display: table-cell; width: 50%; vertical-align: top; }
  .meta-row  { font-size: 11px; color: #64748b; margin-bottom: 5px; }
  .meta-row strong { color: #1e293b; font-weight: 600; }

  .validity-box { margin: 14px 24px; background: #faf5ff; border: 1.5px solid #e9d5ff; border-radius: 8px; padding: 10px 14px; display: table; width: calc(100% - 48px); }
  .validity-left  { display: table-cell; font-size: 11px; color: #7c3aed; }
  .validity-right { display: table-cell; text-align: right; font-size: 11px; color: #7c3aed; font-weight: 600; }

  .section { padding: 14px 24px 0; }
  .section-title { font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 2px; color: #94a3b8; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px; margin-bottom: 10px; }

  .items { width: 100%; border-collapse: collapse; margin: 0 24px; width: calc(100% - 48px); }
  .items th { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: #94a3b8; padding: 0 0 8px 0; text-align: left; }
  .items th.right { text-align: right; }
  .items td { font-size: 12px; color: #334155; padding: 8px 0; border-top: 1px solid #f1f5f9; vertical-align: top; }
  .items td.right { text-align: right; font-weight: 600; color: #1e293b; }
  .items .qty  { color: #64748b; font-size: 11px; }

  .totals { margin: 12px 24px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px; }
  .trow { display: table; width: 100%; font-size: 12px; margin-bottom: 6px; }
  .trow-label { display: table-cell; color: #64748b; }
  .trow-val   { display: table-cell; text-align: right; color: #334155; }
  .trow.grand { border-top: 1.5px solid #cbd5e1; padding-top: 8px; margin-top: 4px; }
  .trow.grand .trow-label { font-size: 14px; font-weight: bold; color: #1e293b; }
  .trow.grand .trow-val   { font-size: 16px; font-weight: bold; color: {{ $headerColor }}; }

  .footer { text-align: center; padding: 18px 24px; border-top: 1px dashed #cbd5e1; margin-top: 12px; }
  .footer .thanks { font-size: 13px; font-weight: bold; color: #334155; margin-bottom: 4px; }
  .footer .sub    { font-size: 10px; color: #94a3b8; }
  .notes { margin: 0 24px 12px; font-size: 11px; color: #64748b; font-style: italic; }
  .disclaimer { font-size: 10px; color: #94a3b8; }
</style>
</head>
<body>
<div class="page">

  <div class="header">
    @if($logoUrl)<div><img src="{{ $logoUrl }}" alt="Logo"></div>@endif
    <div class="brand">Cotización</div>
    <div class="company">{{ $config['nombre_comercial'] ?? $empresa['nombre'] ?? 'Mi Negocio' }}</div>
    @if(!empty($empresa['direccion']))<div class="address">{{ $empresa['direccion'] }}</div>@endif
    @if(!empty($empresa['telefono']))<div class="address">Tel: {{ $empresa['telefono'] }}</div>@endif
  </div>

  <div class="hero">
    <div class="hero-left">
      <div class="folio">{{ $cotizacion->folio }}</div>
      <div style="margin-top:6px"><span class="badge">{{ ucfirst($cotizacion->status) }}</span></div>
    </div>
    <div class="hero-right">
      <div class="total-label">Total cotizado</div>
      <div class="total-big">${{ number_format((float)$cotizacion->total,2) }}</div>
    </div>
  </div>

  <div class="meta-grid">
    <div class="meta-cell">
      <div class="meta-row">Fecha: <strong>{{ $cotizacion->fecha->format('d/m/Y') }}</strong></div>
      @if($cotizacion->vendedor)<div class="meta-row">Vendedor: <strong>{{ $cotizacion->vendedor->name }}</strong></div>@endif
    </div>
    <div class="meta-cell">
      @if($cotizacion->cliente)<div class="meta-row">Cliente: <strong>{{ $cotizacion->cliente->nombre }}</strong></div>
      @elseif($cotizacion->nombre_cliente)<div class="meta-row">Cliente: <strong>{{ $cotizacion->nombre_cliente }}</strong></div>@endif
    </div>
  </div>

  @if($cotizacion->fecha_vencimiento)
  <div class="validity-box">
    <span class="validity-left">Vigencia de la cotización</span>
    <span class="validity-right">{{ $cotizacion->fecha_vencimiento->format('d/m/Y') }}</span>
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
          $desc = $item->descripcion ?? $item->nombre_producto ?? $item->producto?->nombre ?? '—';
          $subtotal = (float)($item->subtotal ?? ($item->precio_unitario * $item->cantidad));
        @endphp
        <tr>
          <td>
            {{ $desc }}<br>
            <span class="qty">{{ number_format((float)$item->cantidad,2) }} × ${{ number_format((float)$item->precio_unitario,2) }}
            @if((float)($item->descuento??0)>0) · desc. {{ $item->descuento }}%@endif
            </span>
          </td>
          <td class="right">${{ number_format($subtotal,2) }}</td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  <div class="totals" style="margin-top:14px">
    <div class="trow"><span class="trow-label">Subtotal</span><span class="trow-val">${{ number_format((float)$cotizacion->subtotal,2) }}</span></div>
    @if((float)$cotizacion->descuento > 0)
      <div class="trow"><span class="trow-label">Descuento {{ $cotizacion->descuento }}%</span><span class="trow-val" style="color:#dc2626">-${{ number_format($cotizacion->subtotal*$cotizacion->descuento/100,2) }}</span></div>
    @endif
    @if((float)$cotizacion->impuesto_pct > 0)
      <div class="trow"><span class="trow-label">IVA {{ $cotizacion->impuesto_pct }}%</span><span class="trow-val">${{ number_format($cotizacion->subtotal*(1-$cotizacion->descuento/100)*$cotizacion->impuesto_pct/100,2) }}</span></div>
    @endif
    <div class="trow grand"><span class="trow-label">Total</span><span class="trow-val">${{ number_format((float)$cotizacion->total,2) }}</span></div>
  </div>

  @if($cotizacion->notas)
  <div class="notes">Notas: {{ $cotizacion->notas }}</div>
  @endif

  <div class="footer">
    <div class="thanks">Esperamos poder servirle.</div>
    <div class="sub disclaimer">Esta cotización no es una factura fiscal. Precios sujetos a cambio sin previo aviso.</div>
    @if($cotizacion->fecha_vencimiento)
      <div class="sub" style="margin-top:4px">Válida hasta: {{ $cotizacion->fecha_vencimiento->format('d/m/Y') }}</div>
    @endif
    @if(!empty($documentos['pie_pagina']))<div class="sub" style="margin-top:6px">{{ $documentos['pie_pagina'] }}</div>@endif
  </div>

</div>
</body>
</html>
