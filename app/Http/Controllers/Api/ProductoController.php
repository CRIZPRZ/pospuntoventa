<?php

namespace App\Http\Controllers\Api;

use App\Models\Categoria;
use App\Models\Producto;
use App\Services\MercadoLibreService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ProductoController extends Controller
{
    public function __construct(private MercadoLibreService $meliService)
    {
    }

    public function index(Request $request)
    {
        $query = Producto::with(['categoria', 'proveedor', 'mercadoLibre'])->latest();

        if ($request->filled('q')) {
            $search = $request->string('q');
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'like', "%{$search}%")
                    ->orWhere('codigo', 'like', "%{$search}%")
                    ->orWhere('codigo_barras', 'like', "%{$search}%")
                    ->orWhereHas('categoria', fn ($categoria) => $categoria->where('nombre', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('categoria')) {
            $query->whereHas('categoria', fn ($q) => $q->where('nombre', $request->categoria));
        }

        if ($request->has('activo')) {
            $query->where('activo', filter_var($request->activo, FILTER_VALIDATE_BOOLEAN));
        }

        return response()->json($query->paginate($request->integer('per_page', 50)));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $producto = Producto::create($data)->load(['categoria', 'proveedor', 'mercadoLibre']);

        return response()->json($producto, 201);
    }

    public function show(Producto $producto)
    {
        return response()->json($producto->load(['categoria', 'proveedor', 'mercadoLibre']));
    }

    public function update(Request $request, Producto $producto)
    {
        $data = $this->validated($request, $producto);
        $producto->update($data);
        $producto = $producto->fresh(['categoria', 'proveedor', 'mercadoLibre']);

        if ($producto->mercadoLibre->isNotEmpty()) {
            try {
                $this->meliService->syncProductData($producto);
            } catch (\Throwable $e) {
                Log::warning('No se pudo sincronizar el producto con Mercado Libre al editarlo', [
                    'producto_id' => $producto->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return response()->json($producto->fresh(['categoria', 'proveedor', 'mercadoLibre']));
    }

    public function destroy(Producto $producto)
    {
        $producto->delete();

        return response()->json(['message' => 'Producto eliminado']);
    }

    public function buscar(Request $request)
    {
        $request->validate(['q' => ['nullable', 'string', 'max:255']]);

        return $this->index($request);
    }

    private function validated(Request $request, ?Producto $producto = null): array
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'codigo' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('productos', 'codigo')->ignore($producto),
            ],
            'codigo_barras' => ['nullable', 'string', 'max:255'],
            'categoria_id' => ['nullable', 'exists:categorias,id'],
            'categoria' => ['nullable', 'string', 'max:255'],
            'proveedor_id' => ['nullable', 'exists:proveedores,id'],
            'precio' => ['required', 'numeric', 'min:0'],
            'costo' => ['nullable', 'numeric', 'min:0'],
            'stock' => ['required', 'integer', 'min:0'],
            'stock_minimo' => ['nullable', 'integer', 'min:0'],
            'unidad' => ['nullable', 'string', 'max:50'],
            'imagen' => ['nullable', 'string', 'max:255'],
            'imagenes' => ['nullable', 'array', 'max:8'],
            'imagenes.*' => ['image', 'max:4096', 'dimensions:min_width=500,min_height=500'],
            'activo' => ['nullable', 'boolean'],
            'disponible_ml' => ['nullable', 'boolean'],
            'control_stock' => ['nullable', 'boolean'],
        ]);

        if ($request->hasFile('imagenes')) {
            $imagenes = collect($producto?->imagenes ?? [])
                ->filter()
                ->values()
                ->all();

            foreach ($request->file('imagenes') as $imagen) {
                $imagenes[] = $imagen->store('productos', 'public');
            }

            $data['imagenes'] = array_values(array_slice($imagenes, 0, 8));
            $data['imagen'] = $data['imagen'] ?? ($data['imagenes'][0] ?? null);
        }

        if (! empty($data['categoria']) && empty($data['categoria_id'])) {
            $categoria = Categoria::firstOrCreate(
                ['nombre' => $data['categoria']],
                ['descripcion' => null, 'color' => '#2563eb', 'activo' => true]
            );
            $data['categoria_id'] = $categoria->id;
        }

        unset($data['categoria']);

        return $data;
    }
}
