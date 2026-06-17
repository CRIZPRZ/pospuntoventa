<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Pedido {{ $pedido->folio }}</title>
@php $headerColor = $documentos['color_header'] ?? '#0f766e'; @endphp
<style>
  @page { margin: 0; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1a1a2e; background: #fff; margin: 0; padding: 0; }
  .page { max-width: 100%; margin: 0; background: #fff; }

  .header { background: {{ $headerColor }}; color: #fff; padding: 28px 24px 22px; text-align: center; }
  .header .brand { font-size: 9px; letter-spacing: 3px; text-transform: uppercase; opacity: 0.75; margin-bottom: 6px; }
  .header .company { font-size: 22px; font-weight: bold; margin-bottom: 4px; }
  .header .address { font-size: 11px; opacity: 0.8; }
  .header img { max-height: 52px; margin-bottom: 10px; }

  .hero { background: #f0fdf4; border-bottom: 2px solid #86efac; padding: 16px 24px; display: table; width: 100%; }
  .hero-left { display: table-cell; vertical-align: middle; }
  .hero-right { display: table-cell; text-align: right; vertical-align: middle; }
  .folio { font-size: 18px; font-weight: bold; color: {{ $headerColor }}; }
  .badge { display: inline-block; font-size: 10px; font-weight: bold; padding: 3px 10px; border-radius: 20px; }
  .badge-teal   { background: #ccfbf1; color: #0f766e; }
  .badge-orange { background: #fef3c7; color: #b45309; }
  .badge-green  { background: #dcfce7; color: #15803d; }
  .badge-gray   { background: #f1f5f9; color: #64748b; }
  .total-big   { font-size: 26px; font-weight: bold; color: #0f766e; }
  .total-label { font-size: 10px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; }

  .meta-grid { padding: 14px 24px; border-bottom: 1px solid #e2e8f0; display: table; width: 100%; }
  .meta-cell { display: table-cell; width: 50%; vertical-align: top; }
  .meta-row  { font-size: 11px; color: #64748b; margin-bottom: 5px; }
  .meta-row strong { color: #1e293b; font-weight: 600; }

  .delivery-box { margin: 14px 24px; background: #f0fdf4; border: 1.5px solid #86efac; border-radius: 8px; padding: 10px 14px; }
  .delivery-box .label { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: #15803d; font-weight: bold; margin-bottom: 2px; }
  .delivery-box .date  { font-size: 16px; font-weight: bold; color: #166534; }

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
</style>
</head>
<body>
<div class="page">

  <div class="header">
    @if($logoUrl)<div><img src="{{ $logoUrl }}" alt="Logo"></div>@endif
    <div class="brand">Pedido</div>
    <div class="company">{{ $config['nombre_comercial'] ?? $empresa['nombre'] ?? 'Mi Negocio' }}</div>
    @if(!empty($empresa['direccion']))<div class="address">{{ $empresa['direccion'] }}</div>@endif
    @if(!empty($empresa['telefono']))<div class="address">Tel: {{ $empresa['telefono'] }}</div>@endif
  </div>

  <div class="hero">
    <div class="hero-left">
      <div class="folio">{{ $pedido->folio }}</div>
      <div style="margin-top:6px">
        @php
          $statusMap = [
            'pendiente'  => ['label'=>'Pendiente',  'class'=>'badge-orange'],
            'en_proceso' => ['label'=>'En proceso', 'class'=>'badge-teal'],
            'listo'      => ['label'=>'Listo',      'class'=>'badge-green'],
            'entregado'  => ['label'=>'Entregado',  'class'=>'badge-green'],
            'cancelado'  => ['label'=>'Cancelado',  'class'=>'badge-gray'],
          ];
          $sm = $statusMap[$pedido->status] ?? ['label'=>ucfirst($pedido->status),'class'=>'badge-gray'];
        @endphp
        <span class="badge {{ $sm['class'] }}">{{ $sm['label'] }}</span>
      </div>
    </div>
    <div class="hero-right">
      <div class="total-label">Total del pedido</div>
      <div class="total-big">${{ number_format((float)$pedido->total,2) }}</div>
    </div>
  </div>

  <div class="meta-grid">
    <div class="meta-cell">
      <div class="meta-row">Fecha pedido: <strong>{{ is_string($pedido->fecha) ? $pedido->fecha : $pedido->fecha->format('d/m/Y') }}</strong></div>
      @if($pedido->vendedor)<div class="meta-row">Vendedor: <strong>{{ $pedido->vendedor->name }}</strong></div>@endif
    </div>
    <div class="meta-cell">
      @if($pedido->cliente)<div class="meta-row">Cliente: <strong>{{ $pedido->cliente->nombre }}</strong></div>
      @elseif($pedido->nombre_cliente)<div class="meta-row">Cliente: <strong>{{ $pedido->nombre_cliente }}</strong></div>@endif
    </div>
  </div>

  @if($pedido->fecha_entrega)
  <div class="delivery-box">
    <div class="label">Fecha de entrega estimada</div>
    <div class="date">{{ is_string($pedido->fecha_entrega) ? $pedido->fecha_entrega : $pedido->fecha_entrega->format('d/m/Y') }}</div>
  </div>
  @endif

  <div class="section">
    <div class="section-title">Productos del pedido</div>
    <table class="items">
      <thead>
        <tr>
          <th>Descripción</th>
          <th class="right">Importe</th>
        </tr>
      </thead>
      <tbody>
        @foreach($pedido->items as $item)
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
    <div class="trow"><span class="trow-label">Subtotal</span><span class="trow-val">${{ number_format((float)$pedido->subtotal,2) }}</span></div>
    @if((float)$pedido->descuento > 0)
      <div class="trow"><span class="trow-label">Descuento {{ $pedido->descuento }}%</span><span class="trow-val" style="color:#dc2626">-${{ number_format($pedido->subtotal*$pedido->descuento/100,2) }}</span></div>
    @endif
    @if((float)$pedido->impuesto_pct > 0)
      <div class="trow"><span class="trow-label">IVA {{ $pedido->impuesto_pct }}%</span><span class="trow-val">${{ number_format($pedido->subtotal*(1-$pedido->descuento/100)*$pedido->impuesto_pct/100,2) }}</span></div>
    @endif
    <div class="trow grand"><span class="trow-label">Total</span><span class="trow-val">${{ number_format((float)$pedido->total,2) }}</span></div>
  </div>

  @if($pedido->notas)
  <div class="notes">Notas: {{ $pedido->notas }}</div>
  @endif

  <div class="footer">
    <div class="thanks">¡Gracias por tu pedido!</div>
    <div class="sub">Te avisaremos cuando esté listo para recoger.</div>
    @if(!empty($empresa['telefono']))<div class="sub" style="margin-top:4px">{{ $empresa['telefono'] }}</div>@endif
    @if(!empty($documentos['pie_pagina']))<div class="sub" style="margin-top:6px">{{ $documentos['pie_pagina'] }}</div>@endif
  </div>

</div>
</body>
</html>
