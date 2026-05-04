<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Producto;
use App\Models\ProductoMeli;
use App\Services\MercadoLibreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MercadoLibreController extends Controller
{
    protected MercadoLibreService $meliService;

    public function __construct(MercadoLibreService $meliService)
    {
        $this->meliService = $meliService;
    }

    // ==================== Config ====================

    public function config()
    {
        $config = \App\Models\MercadoLibreConfig::first();

        if (!$config) {
            return response()->json([
                'connected' => false,
                'client_id' => config('services.mercadolibre.client_id', ''),
                'site_id' => config('services.mercadolibre.site_id', 'MLM'),
            ]);
        }

        return response()->json([
            'connected' => $config->active,
            'client_id' => $config->client_id,
            'site_id' => $config->site_id,
            'seller_id' => $config->seller_id,
            'seller_name' => $config->seller_name,
            'token_expires_at' => $config->token_expires_at,
            'token_valid' => $config->hasValidToken(),
            'auto_sync_stock' => $config->auto_sync_stock,
            'auto_publish' => $config->auto_publish,
            'callback_url' => $config->callback_url,
            'sandbox_mode' => $config->sandbox_mode ?? false,
            'test_user' => $config->test_user,
            'total_published' => ProductoMeli::where('status', 'active')->count(),
        ]);
    }

    public function saveConfig(Request $request)
    {
        $data = $request->validate([
            'client_id' => 'required|string',
            'client_secret' => 'required|string',
            'site_id' => 'required|string|in:MLM,MLB,MLC,MCO,MLA,MLU',
            'callback_url' => 'nullable|url',
            'auto_sync_stock' => 'boolean',
            'auto_publish' => 'boolean',
            'sandbox_mode' => 'boolean',
        ]);

        $config = \App\Models\MercadoLibreConfig::first();

        if ($config) {
            $config->update($data);
        } else {
            $config = \App\Models\MercadoLibreConfig::create(array_merge($data, [
                'auto_sync_stock' => $data['auto_sync_stock'] ?? true,
                'auto_publish' => $data['auto_publish'] ?? false,
            ]));
        }

        return response()->json(['message' => 'Configuración guardada']);
    }

    // ==================== OAuth ====================

    public function authUrl(Request $request)
    {
        $config = \App\Models\MercadoLibreConfig::first();
        $redirectUri = $config?->callback_url ?? $request->input('redirect_uri', config('app.url') . '/api/mercado-libre/callback');
        $url = $this->meliService->getAuthUrl($redirectUri);

        return response()->json(['auth_url' => $url]);
    }

    public function callback(Request $request)
    {
        $code = $request->input('code');
        $config = \App\Models\MercadoLibreConfig::first();
        $redirectUri = $config?->callback_url ?? config('app.url') . '/api/mercado-libre/callback';

        if (!$code) {
            $error = $request->input('error') ?? 'Código de autorización no recibido';
            // Redirigir al frontend con error
            $frontendUrl = str_replace('/api/mercado-libre/callback', '', $redirectUri);
            return redirect($frontendUrl . '/mercadolibre?error=' . urlencode($error));
        }

        try {
            $result = $this->meliService->exchangeCode($code, $redirectUri);

            // Redirigir al frontend con éxito
            $frontendUrl = str_replace('/api/mercado-libre/callback', '', $redirectUri);
            return redirect($frontendUrl . '/mercadolibre?connected=1');
        } catch (\Exception $e) {
            $frontendUrl = str_replace('/api/mercado-libre/callback', '', $redirectUri);
            return redirect($frontendUrl . '/mercadolibre?error=' . urlencode($e->getMessage()));
        }
    }

    public function disconnect()
    {
        $config = \App\Models\MercadoLibreConfig::getActive();
        if ($config) {
            $config->update(['active' => false, 'access_token' => null, 'refresh_token' => null]);
        }

        return response()->json(['message' => 'Desconectado de Mercado Libre']);
    }

    public function refresh()
    {
        $result = $this->meliService->refreshToken();

        if (!$result) {
            return response()->json(['message' => 'No se pudo refrescar el token. Reconecta la cuenta.'], 400);
        }

        return response()->json(['message' => 'Token actualizado']);
    }

    // ==================== Products ====================

    public function publish(Producto $producto, Request $request)
    {
        if (!$this->meliService->isConnected()) {
            return response()->json(['message' => 'Mercado Libre no está conectado'], 400);
        }

        $existing = ProductoMeli::where('producto_id', $producto->id)->first();
        if ($existing) {
            return response()->json(['message' => 'Este producto ya está publicado en ML', 'item_id' => $existing->meli_item_id], 422);
        }

        $data = $request->validate([
            'title' => 'nullable|string|min:10|max:60',
            'description' => 'nullable|string',
            'category_id' => 'required|string',
            'price_usd' => 'nullable|numeric|min:0',
            'price' => 'nullable|numeric|min:0',
            'currency_id' => 'nullable|string|in:MXN,BRL,ARS,CLP,COP,UYU,PEN',
            'available_quantity' => 'nullable|integer|min:0',
            'listing_type_id' => 'nullable|string|in:gold_special,gold_pro,gold',
            'condition' => 'nullable|string|in:new,used',
            'free_shipping' => 'boolean',
            'brand' => 'nullable|string|min:2',
            'model' => 'nullable|string|min:1|max:255',
            'gtin' => 'nullable|regex:/^[0-9]{8,14}$/',
            'attributes' => 'nullable|array',
            'attributes.*.id' => 'required_with:attributes|string',
            'attributes.*.value_id' => 'nullable|string',
            'attributes.*.value_name' => 'nullable|string',
        ]);

        if (empty($producto->imagenes) && empty($producto->imagen)) {
            return response()->json([
                'message' => 'Revisa los campos marcados.',
                'errors' => [
                    'images' => ['Agrega al menos una imagen al producto antes de publicarlo en Mercado Libre.'],
                ],
            ], 422);
        }

        $submittedAttributes = collect($data['attributes'] ?? [])
            ->filter(fn ($attribute) => !empty($attribute['id']))
            ->keyBy(fn ($attribute) => strtoupper((string) $attribute['id']));

        $requiredAttributeErrors = [];
        foreach ($this->meliService->getCategoryAttributes($data['category_id']) as $attribute) {
            $tags = $attribute['tags'] ?? [];
            $isRequired = ($tags['required'] ?? false) || ($tags['catalog_required'] ?? false);
            $isHidden = ($tags['hidden'] ?? false) || ($tags['read_only'] ?? false);
            if (!$isRequired || $isHidden || empty($attribute['id'])) {
                continue;
            }

            $attributeId = strtoupper((string) $attribute['id']);
            $submitted = $submittedAttributes->get($attributeId);
            $hasValue = !empty($submitted['value_id']) || trim((string) ($submitted['value_name'] ?? '')) !== '';
            if (!$hasValue) {
                $requiredAttributeErrors["attributes.{$attributeId}"] = [($attribute['name'] ?? $attributeId) . ' es obligatorio para esta categoría.'];
            }
        }

        if (!empty($requiredAttributeErrors)) {
            return response()->json([
                'message' => 'Revisa los atributos requeridos de Mercado Libre.',
                'errors' => $requiredAttributeErrors,
            ], 422);
        }

        // Title must be at least 10 chars and include brand/model/category keywords
        $title = $data['title'] ?? '';
        if (strlen($title) < 10) {
            return response()->json([
                'message' => 'Revisa los campos marcados.',
                'errors' => [
                    'title' => ['El título debe tener al menos 10 caracteres. Incluye marca, modelo o categoría.'],
                ],
            ], 422);
        }

        // Title should not be too generic - check for brand inclusion
        $brandLower = strtolower($data['brand'] ?? '');
        $titleLower = strtolower($title);
        
        // If title is short (10-25 chars), it must contain the brand or specific keywords
        if (strlen($title) < 25 && $brandLower && !str_contains($titleLower, $brandLower)) {
            return response()->json([
                'message' => 'Revisa los campos marcados.',
                'errors' => [
                    'title' => ['El título debe incluir la marca "' . $data['brand'] . '"'],
                ],
            ], 422);
        }

        // Validate GTIN format if provided
        if (!empty($data['gtin']) && !preg_match('/^[0-9]{8,14}$/', $data['gtin'])) {
            return response()->json([
                'message' => 'Revisa los campos marcados.',
                'errors' => [
                    'gtin' => ['El código de barras (GTIN) debe tener entre 8 y 14 dígitos numéricos.'],
                ],
            ], 422);
        }

        // Check for generic single words as title
        $genericTitles = ['producto', 'articulo', 'cosa', 'item', 'objeto', 'nuevo'];
        if (in_array($titleLower, $genericTitles)) {
            return response()->json([
                'message' => 'Revisa los campos marcados.',
                'errors' => [
                    'title' => ['El título es muy genérico. Incluye el nombre del producto, marca y modelo.'],
                ],
            ], 422);
        }

        try {
            // Accept 'price' as alternative to 'price_usd'
        if (!isset($data['price_usd']) && isset($data['price'])) {
            $data['price_usd'] = $data['price'];
        }
        $result = $this->meliService->publishItem($producto, $data);

            return response()->json([
                'message' => 'Producto publicado',
                'item_id' => $result['id'],
                'permalink' => $result['permalink'] ?? '',
            ], 201);
        } catch (\Throwable $e) {
            if (str_contains(strtolower($e->getMessage()), 'imagen')) {
                return response()->json([
                    'message' => 'Revisa los campos marcados.',
                    'errors' => [
                        'images' => [$e->getMessage()],
                    ],
                ], 422);
            }

            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function syncStock(Producto $producto)
    {
        if (!$this->meliService->isConnected()) {
            return response()->json(['message' => 'Mercado Libre no está conectado'], 400);
        }

        $success = $this->meliService->updateStock($producto);

        if (!$success) {
            return response()->json(['message' => 'No se encontró publicación de ML para este producto'], 404);
        }

        return response()->json(['message' => 'Stock sincronizado']);
    }

    public function syncPrice(Producto $producto, Request $request)
    {
        if (!$this->meliService->isConnected()) {
            return response()->json(['message' => 'Mercado Libre no está conectado'], 400);
        }

        $data = $request->validate([
            'price_usd' => 'nullable|numeric|min:0',
            'price' => 'nullable|numeric|min:0',
        ]);

        $success = $this->meliService->updatePrice($producto, $data['price_usd'] ?? null);

        if (!$success) {
            return response()->json(['message' => 'No se encontró publicación de ML para este producto'], 404);
        }

        return response()->json(['message' => 'Precio sincronizado']);
    }

    public function pause(Producto $producto)
    {
        if (!$this->meliService->isConnected()) {
            return response()->json(['message' => 'Mercado Libre no está conectado'], 400);
        }

        $this->meliService->pauseItem($producto);
        return response()->json(['message' => 'Publicación pausada']);
    }

    public function reactivate(Producto $producto)
    {
        if (!$this->meliService->isConnected()) {
            return response()->json(['message' => 'Mercado Libre no está conectado'], 400);
        }

        $this->meliService->reactivateItem($producto);
        return response()->json(['message' => 'Publicación reactivada']);
    }

    public function unlink(Producto $producto)
    {
        ProductoMeli::where('producto_id', $producto->id)->delete();
        return response()->json(['message' => 'Desvinculado de ML']);
    }

    // ==================== Bulk ====================

    public function syncAllStock()
    {
        if (!$this->meliService->isConnected()) {
            return response()->json(['message' => 'Mercado Libre no está conectado'], 400);
        }

        $result = $this->meliService->syncAllStock();
        return response()->json($result);
    }

    // ==================== Categories ====================

    public function searchCategories(Request $request)
    {
        $query = $request->input('q');
        if (!$query) {
            return response()->json(['message' => 'Query requerida'], 400);
        }

        $results = $this->meliService->searchCategories($query);
        return response()->json($results);
    }

    public function getSiteCategories()
    {
        if (request()->boolean('debug')) {
            return response()->json($this->meliService->getSiteCategoriesDebug());
        }

        $results = $this->meliService->getSiteCategories();
        return response()->json($results);
    }

    public function getCategoryChildren($categoryId)
    {
        $results = $this->meliService->getCategoryChildren($categoryId);
        return response()->json($results);
    }

    public function getCategoryInfo($categoryId)
    {
        $result = $this->meliService->getCategoryInfo($categoryId);
        return response()->json($result);
    }

    public function getCategoryAttributes($categoryId)
    {
        $result = $this->meliService->getCategoryAttributes($categoryId);
        return response()->json($result);
    }

    // ==================== Webhook ====================

    public function webhook(Request $request)
    {
        $notification = $request->all();

        Log::info('ML Webhook received', $notification);

        try {
            $result = $this->meliService->handleItemNotification($notification);
            return response()->json($result, 200);
        } catch (\Exception $e) {
            Log::error('ML Webhook error: ' . $e->getMessage());
            // Still return 200 to prevent ML from retrying
            return response()->json(['error' => $e->getMessage()], 200);
        }
    }

    // ==================== ML Product info ====================

    public function productInfo(Producto $producto)
    {
        $meli = ProductoMeli::with('producto')->where('producto_id', $producto->id)->first();

        if (!$meli) {
            return response()->json(['published' => false]);
        }

        // Fetch live data from ML
        try {
            $itemData = $this->meliService->getItem($meli->meli_item_id);
            $liveStatus = $itemData['status'] ?? $meli->status;

            if ($liveStatus !== $meli->status) {
                $meli->update([
                    'status' => $liveStatus,
                    'last_sync_at' => now(),
                ]);
            }

            return response()->json([
                'published' => true,
                'item_id' => $meli->meli_item_id,
                'status' => $liveStatus,
                'sub_status' => $itemData['sub_status'] ?? [],
                'permalink' => $itemData['permalink'] ?? '',
                'price_usd' => $itemData['price'] ?? $meli->price_usd,
                'available_quantity' => $itemData['available_quantity'] ?? 0,
                'sold_quantity' => $itemData['sold_quantity'] ?? 0,
                'listing_type' => $itemData['listing_type_id'] ?? '',
                'last_sync' => $meli->fresh()->last_sync_at,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'published' => true,
                'item_id' => $meli->meli_item_id,
                'status' => $meli->status,
                'last_sync' => $meli->last_sync_at,
                'error' => 'No se pudo obtener info en vivo',
            ]);
        }
    }

    public function productsMeli(Request $request)
    {
        $query = ProductoMeli::with('producto')
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->paginate($request->integer('per_page', 50)));
    }

    // ==================== Sandbox ====================

    public function createTestUser()
    {
        if (!$this->meliService->isConnected()) {
            return response()->json(['message' => 'Debes estar conectado para crear usuarios de prueba'], 400);
        }

        $config = \App\Models\MercadoLibreConfig::first();

        // If we already have a test user saved, return it
        if ($config && $config->test_user && isset($config->test_user['nickname'], $config->test_user['password'])) {
            return response()->json([
                'message' => 'Ya tienes un usuario de prueba creado',
                'test_user' => $config->test_user,
            ]);
        }

        try {
            $testUser = $this->meliService->createTestUser();

            $config->update(['sandbox_mode' => true, 'test_user' => $testUser]);

            return response()->json([
                'message' => 'Usuario de prueba creado',
                'test_user' => $testUser,
            ]);
        } catch (\Exception $e) {
            $msg = $e->getMessage();

            // If max users reached, try to return existing test user from API
            if (str_contains($msg, 'maximum quantity')) {
                if ($config && $config->test_user) {
                    return response()->json([
                        'message' => 'Ya tienes un usuario de prueba creado',
                        'test_user' => $config->test_user,
                    ]);
                }

                return response()->json([
                    'message' => 'Ya alcanzaste el límite de 10 usuarios de prueba. Usa un usuario de prueba existente o elimina uno desde tu cuenta de Mercado Libre.',
                ], 400);
            }

            return response()->json(['message' => $msg], 400);
        }
    }

    public function toggleSandbox(Request $request)
    {
        $config = \App\Models\MercadoLibreConfig::first();
        if (!$config) {
            return response()->json(['message' => 'No hay configuración'], 404);
        }

        $config->update(['sandbox_mode' => !$config->sandbox_mode]);

        return response()->json([
            'message' => $config->sandbox_mode ? 'Modo sandbox activado' : 'Modo producción activado',
            'sandbox_mode' => $config->sandbox_mode,
        ]);
    }

    public function clearTestUser()
    {
        $config = \App\Models\MercadoLibreConfig::first();
        if (!$config || !$config->test_user) {
            return response()->json(['message' => 'No hay usuario de prueba guardado'], 404);
        }

        $config->update(['test_user' => null]);

        return response()->json(['message' => 'Usuario de prueba eliminado del registro local']);
    }
}
