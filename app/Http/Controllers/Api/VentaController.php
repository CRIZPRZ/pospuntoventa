<?php

namespace App\Http\Controllers\Api;

use App\Models\Cliente;
use App\Models\Caja;
use App\Models\Producto;
use App\Models\Venta;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ScopesBySucursal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Events\VentaCompletada;
use Illuminate\Validation\Rule;

class VentaController extends Controller
{
    use ScopesBySucursal;
    public function index(Request $request)
    {
        $query = $this->applySucursalScope(
            Venta::with(['user', 'cliente', 'canceladoPor'])->withCount('items')->latest()
        );

        if ($request->filled('q')) {
            $search = $request->string('q');
            $query->where(function ($q) use ($search) {
                $q->where('folio', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($user) => $user->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('fecha_inicio')) {
            $query->whereDate('created_at', '>=', $request->fecha_inicio);
        }

        if ($request->filled('fecha_fin')) {
            $query->whereDate('created_at', '<=', $request->fecha_fin);
        }

        if ($request->filled('tipo_pago')) {
            $query->where('tipo_pago', $request->tipo_pago);
        }

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        if ($request->boolean('tiene_cfdi')) {
            $query->whereNotNull('cfdi_uuid');
        }

        if ($request->filled('cfdi_status')) {
            $query->where('cfdi_status', $request->cfdi_status);
        }

        return response()->json($query->paginate($request->integer('per_page', 50)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'tipo_pago' => ['required', Rule::in(['efectivo', 'tarjeta', 'credito', 'mixto'])],
            'cliente_id' => ['nullable', 'exists:clientes,id'],
            'subtotal' => ['nullable', 'numeric', 'min:0'],
            'descuento' => ['nullable', 'numeric', 'min:0'],
            'impuesto' => ['nullable', 'numeric', 'min:0'],
            'total' => ['nullable', 'numeric', 'min:0'],
            'notas' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.producto_id' => ['required', 'exists:productos,id'],
            'items.*.cantidad' => ['required', 'integer', 'min:1'],
            'items.*.precio_unitario' => ['nullable', 'numeric', 'min:0'],
            'items.*.descuento' => ['nullable', 'numeric', 'min:0'],
            'pagos' => ['nullable', 'array'],
            'pagos.*.metodo' => ['required_with:pagos', Rule::in(['efectivo', 'tarjeta', 'credito'])],
            'pagos.*.monto' => ['required_with:pagos', 'numeric', 'min:0'],
            'pagos.*.cambio' => ['nullable', 'numeric', 'min:0'],
            'pagos.*.referencia' => ['nullable', 'string', 'max:255'],
        ]);

        if ($data['tipo_pago'] === 'credito' && empty($data['cliente_id'])) {
            return response()->json(['message' => 'Selecciona un cliente para ventas a crédito'], 422);
        }

        $caja = Caja::where('user_id', $request->user()->id)
            ->where('estado', 'abierta')
            ->latest('abierta_at')
            ->first();

        if (! $caja) {
            return response()->json(['message' => 'Debes abrir caja antes de vender'], 422);
        }

        $venta = DB::transaction(function () use ($request, $data, $caja) {
            $items = collect($data['items'])->map(function ($item) {
                $producto = Producto::lockForUpdate()->findOrFail($item['producto_id']);
                $cantidad = (int) $item['cantidad'];

                if ($producto->control_stock && $producto->stock < $cantidad) {
                    abort(422, "Stock insuficiente para {$producto->nombre}");
                }

                $precio = (float) ($item['precio_unitario'] ?? $producto->precio);
                $descuento = (float) ($item['descuento'] ?? 0);
                $subtotal = max(0, ($precio * $cantidad) - $descuento);

                return compact('producto', 'cantidad', 'precio', 'descuento', 'subtotal');
            });

            $subtotal = $items->sum('subtotal');
            $descuento = (float) ($data['descuento'] ?? 0);
            $impuesto = (float) ($data['impuesto'] ?? 0);
            $total = (float) ($data['total'] ?? max(0, $subtotal - $descuento + $impuesto));

            if ($data['tipo_pago'] === 'credito' && $data['cliente_id']) {
                $cliente = Cliente::lockForUpdate()->findOrFail($data['cliente_id']);
                $disponible = (float) $cliente->limite_credito - (float) $cliente->saldo_credito;
                if ($total > $disponible) {
                    abort(422, "Crédito insuficiente. Disponible: $" . number_format($disponible, 2) . ", Requerido: $" . number_format($total, 2));
                }
            }

            $venta = Venta::create([
                'user_id'     => $request->user()->id,
                'caja_id'     => $caja->id,
                'sucursal_id' => $this->sucursalId(),
                'cliente_id'  => $data['cliente_id'] ?? null,
                'subtotal'    => $subtotal,
                'descuento'   => $descuento,
                'impuesto'    => $impuesto,
                'total'       => $total,
                'tipo_pago'   => $data['tipo_pago'],
                'estado'      => 'completada',
                'notas'       => $data['notas'] ?? null,
            ]);

            foreach ($items as $item) {
                $venta->items()->create([
                    'producto_id' => $item['producto']->id,
                    'nombre_producto' => $item['producto']->nombre,
                    'precio_unitario' => $item['precio'],
                    'costo_unitario' => $item['producto']->precio_compra ?? 0,
                    'cantidad' => $item['cantidad'],
                    'descuento' => $item['descuento'],
                    'subtotal' => $item['subtotal'],
                ]);

                if ($item['producto']->control_stock) {
                    $item['producto']->decrement('stock', $item['cantidad']);
                }
            }

            $pagos = $data['pagos'] ?? [[
                'metodo' => $data['tipo_pago'] === 'mixto' ? 'efectivo' : $data['tipo_pago'],
                'monto' => $total,
                'cambio' => 0,
            ]];

            foreach ($pagos as $pago) {
                $venta->pagos()->create([
                    'metodo' => $pago['metodo'],
                    'monto' => $pago['monto'],
                    'cambio' => $pago['cambio'] ?? 0,
                    'referencia' => $pago['referencia'] ?? null,
                ]);
            }

            if ($data['tipo_pago'] === 'credito' && $data['cliente_id']) {
                Cliente::where('id', $data['cliente_id'])->increment('saldo_credito', $total);
            }

            $this->actualizarCaja($caja, $data['tipo_pago'], $total);

            return $venta->load(['user', 'cliente', 'items.producto', 'pagos']);
        });

        VentaCompletada::dispatch($venta);

        return response()->json($venta, 201);
    }

    public function show(Venta $venta)
    {
        return response()->json($venta->load(['user', 'cliente', 'items.producto', 'pagos', 'abonos', 'canceladoPor']));
    }

    public function cancelar(Venta $venta, Request $request)
    {
        if ($venta->estado === 'cancelada') {
            return response()->json(['message' => 'La venta ya está cancelada'], 422);
        }

        $venta = DB::transaction(function () use ($venta, $request) {
            $venta->load(['items.producto', 'caja']);

            foreach ($venta->items as $item) {
                if ($item->producto?->control_stock) {
                    $item->producto->increment('stock', $item->cantidad);
                }
            }

            if ($venta->caja) {
                $this->actualizarCaja($venta->caja, $venta->tipo_pago, -1 * (float) $venta->total);
            }

            $venta->update(['estado' => 'cancelada', 'cancelada_por' => $request->user()->id]);

            return $venta->fresh(['user', 'canceladoPor', 'items.producto', 'pagos']);
        });

        return response()->json($venta);
    }

    public function ticket(Venta $venta)
    {
        return response()->json($venta->load(['user', 'cliente', 'items.producto', 'pagos', 'canceladoPor']));
    }

    public function imprimirTermico(Request $request, Venta $venta)
    {
        $config = $request->validate([
            'tipo_impresora' => ['nullable', 'string'],
            'ancho_papel' => ['nullable', 'string'],
            'mostrar_logo' => ['nullable', 'boolean'],
            'copias' => ['nullable', 'integer', 'min:1'],
        ]);

        $configuracion = $this->getConfiguracion();
        $venta->load(['user', 'cliente', 'items.producto', 'pagos']);

        try {
            $this->imprimirTicketTermico($venta, $configuracion, $config);
            return response()->json(['message' => 'Ticket impreso correctamente']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al imprimir: ' . $e->getMessage()], 500);
        }
    }

    private function getConfiguracion(): array
    {
        $ctrl = new ConfiguracionController();
        return $ctrl->mergedConfig();
    }

    private function imprimirTicketTermico(Venta $venta, array $configuracion, array $requestConfig): void
    {
        $impresion = $configuracion['impresion'] ?? [];
        $ticket = $configuracion['ticket'] ?? [];

        $connector = $this->getConnector($impresion);
        $printer = new \Mike42\Escpos\Printer($connector);

        $ancho = (int) ($requestConfig['ancho_papel'] ?? $impresion['ancho_papel'] ?? 80);

        // Logo
        if (($ticket['mostrar_logo'] ?? false) && ($requestConfig['mostrar_logo'] ?? false)) {
            $logoPath = $this->getLogoPath();
            if ($logoPath) {
                $logo = \Mike42\Escpos\EscposImage::load($logoPath, false);
                $printer->graphics($logo);
            }
        }

        // Encabezado
        $printer->setJustification(\Mike42\Escpos\Printer::JUSTIFY_CENTER);
        if ($ticket['mostrar_datos_negocio'] ?? true) {
            $empresa = $configuracion['empresa'] ?? [];
            $nombreComercial = !empty($configuracion['nombre_comercial'])
                ? $configuracion['nombre_comercial']
                : ($empresa['nombre'] ?? 'Mi Empresa');
            $printer->text($nombreComercial . "\n");
            if (!empty($empresa['rfc'])) $printer->text("RFC: {$empresa['rfc']}\n");
            if (!empty($empresa['direccion'])) $printer->text($empresa['direccion'] . "\n");
            if (!empty($empresa['telefono'])) $printer->text("Tel: {$empresa['telefono']}\n");
        }

        $printer->text(str_repeat('-', $ancho) . "\n");

        // Folio y fecha
        if ($ticket['mostrar_folio'] ?? true) {
            $printer->text("Folio: {$venta->folio}\n");
        }
        if ($ticket['mostrar_fecha'] ?? true) {
            $printer->text("Fecha: " . $venta->created_at->format('d/m/Y H:i:s') . "\n");
        }
        if ($ticket['mostrar_cajero'] ?? true) {
            $printer->text("Cajero: " . ($venta->user->name ?? 'N/A') . "\n");
        }

        $printer->text(str_repeat('-', $ancho) . "\n");

        // Items
        foreach ($venta->items as $item) {
            $nombre = $item->nombre_producto;
            $cantidad = $item->cantidad;
            $precio = number_format($item->precio_unitario, 2);
            $subtotal = number_format($item->subtotal, 2);

            $printer->text("{$cantidad}x {$nombre}\n");
            if ($ticket['mostrar_precio_unitario'] ?? true) {
                $printer->text("  Precio: $" . $precio . "\n");
            }
            if ($ticket['mostrar_subtotal_linea'] ?? true) {
                $printer->text("  Subtotal: $" . $subtotal . "\n");
            }
        }

        $printer->text(str_repeat('-', $ancho) . "\n");

        // Totales
        if ($ticket['mostrar_subtotal'] ?? true) {
            $printer->text("Subtotal: $" . number_format($venta->subtotal, 2) . "\n");
        }
        if ($ticket['mostrar_descuento'] ?? true && $venta->descuento > 0) {
            $printer->text("Descuento: $" . number_format($venta->descuento, 2) . "\n");
        }
        if ($ticket['mostrar_iva'] ?? true && $venta->impuesto > 0) {
            $printer->text("IVA: $" . number_format($venta->impuesto, 2) . "\n");
        }
        $printer->text("TOTAL: $" . number_format($venta->total, 2) . "\n");

        // Pagos
        if ($ticket['mostrar_metodo_pago'] ?? true) {
            foreach ($venta->pagos as $pago) {
                $printer->text("Pago ({$pago->metodo}): $" . number_format($pago->monto, 2) . "\n");
                if ($ticket['mostrar_cambio'] ?? true && $pago->cambio > 0) {
                    $printer->text("Cambio: $" . number_format($pago->cambio, 2) . "\n");
                }
            }
        }

        // Cliente
        if ($venta->cliente) {
            $printer->text("Cliente: {$venta->cliente->nombre}\n");
        }

        $printer->text(str_repeat('-', $ancho) . "\n");
        $printer->setJustification(\Mike42\Escpos\Printer::JUSTIFY_CENTER);
        $printer->text($ticket['pie_ticket'] ?? ($impresion['pie_ticket'] ?? 'Gracias por su compra') . "\n");

        $printer->cut();
        $printer->close();
    }

    private function getConnector(array $impresion): \Mike42\Escpos\PrintConnectors\PrintConnector
    {
        $conexion = $impresion['conexion_tipo'] ?? 'red';
        $ip = $impresion['impresora_ip'] ?? '192.168.1.100';
        $puerto = $impresion['impresora_puerto'] ?? '9100';
        $nombre = $impresion['impresora_nombre'] ?? 'Impresora';
        $dispositivo = $impresion['dispositivo_usb'] ?? '/dev/usb/lp0';

        return match ($conexion) {
            'red' => new \Mike42\Escpos\PrintConnectors\NetworkPrintConnector($ip, (int) $puerto),
            'usb' => new \Mike42\Escpos\PrintConnectors\FilePrintConnector($dispositivo),
            'windows' => new \Mike42\Escpos\PrintConnectors\WindowsPrintConnector($nombre),
            default => new \Mike42\Escpos\PrintConnectors\NetworkPrintConnector($ip, (int) $puerto),
        };
    }

    private function getLogoPath(): ?string
    {
        $files = \Illuminate\Support\Facades\Storage::disk('public')->files('config');
        foreach ($files as $file) {
            if (str_starts_with(basename($file), 'logo_')) {
                return storage_path('app/public/' . $file);
            }
        }
        return null;
    }

    private function actualizarCaja(Caja $caja, string $tipoPago, float $total): void
    {
        $campo = match ($tipoPago) {
            'tarjeta' => 'total_tarjeta',
            'credito' => 'total_credito',
            default => 'total_efectivo',
        };

        $caja->increment($campo, $total);
        $caja->increment('total_ventas', $total);
        $caja->increment('num_transacciones', $total >= 0 ? 1 : -1);
    }
}
