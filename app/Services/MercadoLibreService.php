<?php

namespace App\Services;

use App\Models\MercadoLibreConfig;
use App\Models\Producto;
use App\Models\ProductoMeli;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MercadoLibreService
{
    const BASE_URL = 'https://api.mercadolibre.com';
    const AUTH_URL = 'https://auth.mercadolibre.com';
    const TOKEN_TTL_SECONDS = 21600; // 6 hours

    public function __construct(
        protected ?MercadoLibreConfig $config = null
    ) {
        $this->config ??= MercadoLibreConfig::first();
    }

    public function isConnected(): bool
    {
        return $this->config && $this->config->active && $this->config->access_token;
    }

    // ==================== OAuth ====================

    public function getAuthUrl(string $redirectUri): string
    {
        return sprintf(
            '%s/authorization?response_type=code&client_id=%s&redirect_uri=%s',
            self::AUTH_URL,
            $this->config->client_id,
            urlencode($redirectUri)
        );
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        $response = Http::asForm()->post(self::BASE_URL . '/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $this->config->client_id,
            'client_secret' => $this->config->client_secret,
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);

        if ($response->failed()) {
            throw new \Exception('Error al obtener token: ' . $response->body());
        }

        $data = $response->json();
        $this->saveTokens($data);

        // Get seller info
        $seller = $this->getSellerInfo($data['access_token']);
        $this->config->update([
            'seller_id' => $seller['id'],
            'seller_name' => $seller['nickname'] ?? $seller['first_name'] . ' ' . $seller['last_name'],
            'active' => true,
        ]);

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'expires_in' => $data['expires_in'],
            'seller_id' => $seller['id'],
            'seller_name' => $seller['nickname'],
            'site_id' => $seller['site_id'],
        ];
    }

    public function refreshToken(): ?array
    {
        if (!$this->config || !$this->config->refresh_token) {
            return null;
        }

        $response = Http::asForm()->post(self::BASE_URL . '/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $this->config->client_id,
            'client_secret' => $this->config->client_secret,
            'refresh_token' => $this->config->refresh_token,
        ]);

        if ($response->failed()) {
            Log::error('ML token refresh failed: ' . $response->body());
            $this->config->update(['active' => false]);
            return null;
        }

        $data = $response->json();
        $this->saveTokens($data);

        return $data;
    }

    protected function saveTokens(array $data): void
    {
        $this->config->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'token_expires_at' => now()->addSeconds($data['expires_in'] ?? self::TOKEN_TTL_SECONDS),
        ]);
    }

    public function getSellerInfo(?string $token = null): array
    {
        $response = Http::withToken($token ?? $this->getAccessToken())
            ->get(self::BASE_URL . '/users/me');

        return $response->json();
    }

    // ==================== Items ====================

    public function publishItem(Producto $producto, array $data = []): array
    {
        $this->ensureToken();

        $siteId = $this->config->site_id;
        $priceUsd = $data['price_usd'] ?? $this->mxnToUsd($producto->precio);
        $listingType = $data['listing_type_id'] ?? 'gold_special';
        $availableQuantity = $data['available_quantity'] ?? $producto->stock;

        $description = $data['description'] ?? $producto->descripcion ?? null;

        $payload = [
            'title' => $data['title'] ?? $producto->nombre,
            'category_id' => $data['category_id'] ?? $this->guessCategory($producto),
            'price' => round($priceUsd, 2),
            'currency_id' => $data['currency_id'] ?? 'MXN',
            'available_quantity' => max(0, (int) $availableQuantity),
            'buying_mode' => 'buy_it_now',
            'listing_type_id' => $listingType,
            'condition' => $data['condition'] ?? 'new',
            'site_id' => $siteId,
        ];

        // Build attributes: category-specific values from the form plus legacy fallbacks.
        $attributes = [];
        if (!empty($data['attributes']) && is_array($data['attributes'])) {
            foreach ($data['attributes'] as $attribute) {
                if (empty($attribute['id'])) {
                    continue;
                }

                $payloadAttribute = ['id' => (string) $attribute['id']];
                if (!empty($attribute['value_id'])) {
                    $payloadAttribute['value_id'] = (string) $attribute['value_id'];
                }
                if (!empty($attribute['value_name'])) {
                    $payloadAttribute['value_name'] = (string) $attribute['value_name'];
                }

                if (isset($payloadAttribute['value_id']) || isset($payloadAttribute['value_name'])) {
                    $attributes[] = $payloadAttribute;
                }
            }
        }

        $hasAttribute = fn (string $id) => collect($attributes)->contains(fn ($attribute) => ($attribute['id'] ?? null) === $id);

        if (!empty($data['brand'])) {
            if (!$hasAttribute('BRAND')) {
                $attributes[] = ['id' => 'BRAND', 'value_name' => $data['brand']];
            }
        }
        if (!empty($data['model'])) {
            if (!$hasAttribute('MODEL')) {
                $attributes[] = ['id' => 'MODEL', 'value_name' => $data['model']];
            }
        }
        if (!empty($data['gtin']) && !$hasAttribute('GTIN')) {
            $attributes[] = ['id' => 'GTIN', 'value_name' => $data['gtin']];
        }
        // Part number is required by ML
        $partNumber = $data['gtin'] ?? $producto->codigo_barras ?? $producto->sku ?? $producto->id;
        if ($partNumber && !$hasAttribute('PART_NUMBER')) {
            $attributes[] = ['id' => 'PART_NUMBER', 'value_name' => (string) $partNumber];
        }
        if (!empty($attributes)) {
            $payload['attributes'] = $attributes;
        }

        $pictures = $this->buildPicturesFromProduct($producto);
        if (!empty($pictures)) {
            $payload['pictures'] = $pictures;
        }

        // Shipping
        if ($data['free_shipping'] ?? false) {
            $payload['shipping'] = ['free_shipping' => true, 'mode' => 'me2'];
        }

        $response = Http::withToken($this->getAccessToken())
            ->asJson()
            ->post(self::BASE_URL . '/items', $payload);

        if ($response->failed()) {
            throw new \Exception('Error al publicar: ' . json_encode($response->json()));
        }

        $result = $response->json();

        // Enviar descripción a Mercado Libre (endpoint separado)
        if ($description) {
            $itemId = $result['id'];
            $descResponse = Http::withToken($this->getAccessToken())
                ->asJson()
                ->post(self::BASE_URL . "/items/{$itemId}/descriptions", [
                    'plain_text' => $description,
                ]);

            if ($descResponse->failed()) {
                Log::warning("No se pudo enviar la descripción para el ítem ML {$itemId}: " . $descResponse->body());
            }
        }

        ProductoMeli::create([
            'producto_id' => $producto->id,
            'meli_item_id' => $result['id'],
            'status' => $result['status'] ?? 'active',
            'listing_type_id' => $listingType,
            'price_usd' => $priceUsd,
            'published_at' => now(),
            'last_sync_at' => now(),
        ]);

        return $result;
    }

    public function updateStock(Producto $producto): bool
    {
        $meliItem = ProductoMeli::where('producto_id', $producto->id)->first();
        if (!$meliItem) return false;

        $this->ensureToken();

        $response = Http::withToken($this->getAccessToken())
            ->asJson()
            ->put(self::BASE_URL . "/items/{$meliItem->meli_item_id}", [
                'available_quantity' => max(0, $producto->stock),
            ]);

        if ($response->failed()) {
            Log::error("ML update stock failed for {$meliItem->meli_item_id}: " . $response->body());
            return false;
        }

        $meliItem->update([
            'status' => $producto->stock > 0 ? 'active' : 'paused',
            'last_sync_at' => now(),
        ]);

        return true;
    }

    public function updatePrice(Producto $producto, ?float $priceUsd = null): bool
    {
        $meliItem = ProductoMeli::where('producto_id', $producto->id)->first();
        if (!$meliItem) return false;

        $this->ensureToken();

        $price = $priceUsd ?? $this->mxnToUsd($producto->precio);

        $response = Http::withToken($this->getAccessToken())
            ->asJson()
            ->put(self::BASE_URL . "/items/{$meliItem->meli_item_id}", [
                'price' => round($price, 2),
            ]);

        if ($response->failed()) {
            Log::error("ML update price failed for {$meliItem->meli_item_id}: " . $response->body());
            return false;
        }

        $meliItem->update(['price_usd' => $price, 'last_sync_at' => now()]);
        return true;
    }

    public function syncProductData(Producto $producto): bool
    {
        $meliItem = ProductoMeli::where('producto_id', $producto->id)->first();
        if (!$meliItem) return false;

        $this->ensureToken();

        $payload = [
            'price' => round((float) $producto->precio, 2),
            'available_quantity' => max(0, (int) $producto->stock),
        ];

        $pictures = $this->buildPicturesFromProduct($producto);
        if (!empty($pictures)) {
            $payload['pictures'] = $pictures;
        }

        $response = Http::withToken($this->getAccessToken())
            ->asJson()
            ->put(self::BASE_URL . "/items/{$meliItem->meli_item_id}", $payload);

        if ($response->failed()) {
            Log::warning("ML product sync failed for {$meliItem->meli_item_id}: " . $response->body());
            return false;
        }

        // Sincronizar descripción
        if ($producto->descripcion) {
            $descResponse = Http::withToken($this->getAccessToken())
                ->asJson()
                ->put(self::BASE_URL . "/items/{$meliItem->meli_item_id}/descriptions", [
                    'plain_text' => $producto->descripcion,
                ]);

            if ($descResponse->failed()) {
                Log::warning("No se pudo actualizar la descripción para {$meliItem->meli_item_id}: " . $descResponse->body());
            }
        }

        $result = $response->json();
        $meliItem->update([
            'status' => $result['status'] ?? $meliItem->status,
            'price_usd' => $producto->precio,
            'last_sync_at' => now(),
        ]);

        return true;
    }

    public function pauseItem(Producto $producto): bool
    {
        $meliItem = ProductoMeli::where('producto_id', $producto->id)->first();
        if (!$meliItem) return false;

        $this->ensureToken();

        $response = Http::withToken($this->getAccessToken())
            ->asJson()
            ->put(self::BASE_URL . "/items/{$meliItem->meli_item_id}", [
                'status' => 'paused',
            ]);

        if ($response->failed()) return false;

        $meliItem->update(['status' => 'paused', 'last_sync_at' => now()]);
        return true;
    }

    public function reactivateItem(Producto $producto): bool
    {
        $meliItem = ProductoMeli::where('producto_id', $producto->id)->first();
        if (!$meliItem) return false;

        $this->ensureToken();

        $response = Http::withToken($this->getAccessToken())
            ->asJson()
            ->put(self::BASE_URL . "/items/{$meliItem->meli_item_id}", [
                'status' => 'active',
                'available_quantity' => max(1, $producto->stock),
            ]);

        if ($response->failed()) return false;

        $meliItem->update(['status' => 'active', 'last_sync_at' => now()]);
        return true;
    }

    public function deleteItem(Producto $producto): bool
    {
        $meliItem = ProductoMeli::where('producto_id', $producto->id)->first();
        if (!$meliItem) return false;

        $this->ensureToken();

        // ML doesn't allow delete, only cancel
        $response = Http::withToken($this->getAccessToken())
            ->asJson()
            ->put(self::BASE_URL . "/items/{$meliItem->meli_item_id}", [
                'status' => 'cancelled',
            ]);

        if ($response->ok()) {
            $meliItem->delete();
        }

        return $response->ok();
    }

    public function getItem(string $meliItemId): ?array
    {
        $this->ensureToken();

        $response = Http::withToken($this->getAccessToken())
            ->get(self::BASE_URL . "/items/{$meliItemId}");

        return $response->ok() ? $response->json() : null;
    }

    public function syncAllStock(): array
    {
        $this->ensureToken();

        $synced = 0;
        $errors = 0;

        ProductoMeli::with('producto')->chunk(50, function ($items) use (&$synced, &$errors) {
            foreach ($items as $pm) {
                try {
                    $this->updateStock($pm->producto);
                    $synced++;
                } catch (\Throwable $e) {
                    Log::error("ML sync stock error for producto {$pm->producto_id}: {$e->getMessage()}");
                    $errors++;
                }
            }
        });

        return ['synced' => $synced, 'errors' => $errors];
    }

    // ==================== Webhooks ====================

    public function handleItemNotification(array $notification): array
    {
        $resource = $notification['resource'] ?? '';
        $userId = $notification['user_id'] ?? null;

        // Verify it's our seller
        if ($userId && $this->config->seller_id && (int) $userId !== (int) $this->config->seller_id) {
            return ['ignored' => true, 'reason' => 'not our seller'];
        }

        // Extract item ID from resource: /items/MLM123456
        if (preg_match('/\/items\/(.+)$/', $resource, $matches)) {
            $meliItemId = $matches[1];
            return $this->handleItemUpdate($meliItemId);
        }

        if (preg_match('/\/orders\/(.+)$/', $resource, $matches)) {
            $orderId = $matches[1];
            return $this->handleOrderUpdate($orderId);
        }

        return ['ignored' => true, 'reason' => 'unknown resource'];
    }

    protected function handleItemUpdate(string $meliItemId): array
    {
        $item = $this->getItem($meliItemId);
        if (!$item) return ['error' => 'item not found'];

        $pm = ProductoMeli::where('meli_item_id', $meliItemId)->first();
        if (!$pm) return ['ignored' => true, 'reason' => 'not linked to any producto'];

        // If stock changed on ML, update local
        $mlStock = $item['available_quantity'] ?? 0;
        if ($pm->producto->control_stock && $pm->producto->stock !== $mlStock) {
            $pm->producto->update(['stock' => $mlStock]);
        }

        $pm->update([
            'status' => $item['status'] ?? 'active',
            'last_sync_at' => now(),
        ]);

        return ['synced' => true, 'producto_id' => $pm->producto_id];
    }

    protected function handleOrderUpdate(string $orderId): array
    {
        $this->ensureToken();

        $response = Http::withToken($this->getAccessToken())
            ->get(self::BASE_URL . "/orders/{$orderId}");

        if ($response->failed()) {
            return ['error' => 'failed to fetch order'];
        }

        $order = $response->json();

        foreach ($order['order_items'] ?? [] as $orderItem) {
            $meliItemId = $orderItem['item']['id'] ?? null;
            $quantity = $orderItem['quantity'] ?? 0;

            if (!$meliItemId || $quantity <= 0) continue;

            $pm = ProductoMeli::where('meli_item_id', $meliItemId)->first();
            if (!$pm || !$pm->producto->control_stock) continue;

            $pm->producto->decrement('stock', $quantity);

            // Update ML stock too (to prevent race conditions)
            $this->updateStock($pm->producto);
        }

        return ['synced' => true, 'order_id' => $orderId];
    }

    // ==================== Categories ====================

    public function getSiteCategories(?string $siteId = null): array
    {
        $resolvedSiteId = $siteId ?: ($this->config->site_id ?? 'MLM');

        $request = Http::acceptJson();
        if ($this->config?->access_token) {
            $request = $request->withToken($this->getAccessToken());
        }

        $response = $request->get(self::BASE_URL . '/sites/' . $resolvedSiteId . '/categories');

        if ($response->ok() && is_array($response->json())) {
            return $response->json();
        }

        Log::warning('Mercado Libre categories request failed', [
            'site_id' => $resolvedSiteId,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        return [];
    }

    public function getSiteCategoriesDebug(?string $siteId = null): array
    {
        $resolvedSiteId = $siteId ?: ($this->config->site_id ?? 'MLM');
        $request = Http::acceptJson();
        if ($this->config?->access_token) {
            $request = $request->withToken($this->getAccessToken());
        }

        $response = $request->get(self::BASE_URL . '/sites/' . $resolvedSiteId . '/categories');
        $json = $response->json();

        return [
            'site_id' => $resolvedSiteId,
            'ok' => $response->ok(),
            'status' => $response->status(),
            'is_array' => is_array($json),
            'count' => is_array($json) ? count($json) : null,
            'sample' => is_array($json) ? array_slice($json, 0, 3) : null,
            'body' => is_array($json) ? null : $response->body(),
        ];
    }

    public function getCategoryChildren(string $categoryId): array
    {
        $request = Http::acceptJson();
        if ($this->config?->access_token) {
            $request = $request->withToken($this->getAccessToken());
        }

        $response = $request->get(self::BASE_URL . '/categories/' . $categoryId . '/children');
        $children = $response->ok() && is_array($response->json()) ? $response->json() : [];

        if (!empty($children)) {
            return $children;
        }

        $categoryInfo = $this->getCategoryInfo($categoryId);
        return $categoryInfo['children_categories'] ?? [];
    }

    public function getCategoryInfo(string $categoryId): array
    {
        $request = Http::acceptJson();
        if ($this->config?->access_token) {
            $request = $request->withToken($this->getAccessToken());
        }

        $response = $request->get(self::BASE_URL . '/categories/' . $categoryId);

        return $response->ok() ? $response->json() : [];
    }

    public function getCategoryAttributes(string $categoryId): array
    {
        $request = Http::acceptJson();
        if ($this->config?->access_token) {
            $request = $request->withToken($this->getAccessToken());
        }

        $response = $request->get(self::BASE_URL . '/categories/' . $categoryId . '/attributes');

        return $response->ok() && is_array($response->json()) ? $response->json() : [];
    }

    public function searchCategories(string $query, ?string $siteId = null): array
    {
        $resolvedSiteId = $siteId ?: ($this->config->site_id ?? 'MLM');

        $request = Http::acceptJson();
        if ($this->config?->access_token) {
            $request = $request->withToken($this->getAccessToken());
        }

        $response = $request->get(self::BASE_URL . '/sites/' . $resolvedSiteId . '/domain_discovery/search', [
                'q' => $query,
                'limit' => 8,
            ]);

        return $response->ok() ? $response->json() : [];
    }

    public function getCategoryPredictors(string $title, string $description = ''): array
    {
        $resolvedSiteId = $this->config->site_id ?? 'MLM';

        $request = Http::acceptJson();
        if ($this->config?->access_token) {
            $request = $request->withToken($this->getAccessToken());
        }

        $response = $request->get(self::BASE_URL . '/sites/' . $resolvedSiteId . '/domain_discovery/search', [
                'q' => $title,
                'limit' => 5,
            ]);

        return $response->ok() ? $response->json() : [];
    }

    // ==================== Helpers ====================

    protected function getAccessToken(): string
    {
        if ($this->config->isTokenExpiringSoon()) {
            $this->refreshToken();
        }

        if (!$this->config->access_token) {
            throw new \Exception('No hay token de acceso');
        }

        return $this->config->access_token;
    }

    protected function ensureToken(): void
    {
        if (!$this->isConnected()) {
            throw new \Exception('Mercado Libre no está conectado');
        }

        $this->getAccessToken();
    }

    protected function mxnToUsd(float $mxn, ?float $rate = null): float
    {
        $rate ??= config('services.mercadolibre.usd_rate', 0.05);
        return $mxn * $rate;
    }

    protected function uploadItemPicture(string $path): array
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            throw new \RuntimeException('Las imágenes por URL ya no se envían a Mercado Libre. Carga la foto como archivo del producto.');
        }

        if (!Storage::disk('public')->exists($path)) {
            throw new \RuntimeException('No se encontró la imagen "' . basename($path) . '" en el storage del producto.');
        }

        $fullPath = Storage::disk('public')->path($path);
        $response = Http::withToken($this->getAccessToken())
            ->acceptJson()
            ->attach('file', file_get_contents($fullPath), basename($fullPath))
            ->post(self::BASE_URL . '/pictures/items/upload');

        if ($response->successful() && !empty($response->json('id'))) {
            return ['id' => $response->json('id')];
        }

        Log::warning('Mercado Libre picture upload failed', [
            'path' => $path,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        $message = $response->json('message') ?: $response->json('error') ?: 'La imagen no cumple con las reglas de Mercado Libre.';
        throw new \RuntimeException('Mercado Libre rechazó la imagen "' . basename($path) . '": ' . $message);
    }

    protected function buildPicturesFromProduct(Producto $producto): array
    {
        $imagePaths = [];
        if (!empty($producto->imagenes) && is_array($producto->imagenes)) {
            $imagePaths = array_merge($imagePaths, $producto->imagenes);
        }
        if ($producto->imagen) {
            $imagePaths[] = $producto->imagen;
        }

        $pictures = [];
        foreach (array_values(array_unique(array_filter($imagePaths))) as $imagePath) {
            if (filter_var($imagePath, FILTER_VALIDATE_URL)) {
                throw new \RuntimeException('Las imágenes por URL ya no se envían a Mercado Libre. Carga la foto como archivo del producto.');
            }

            $pictures[] = $this->uploadItemPicture($imagePath);
        }

        return $pictures;
    }

    protected function guessCategory(Producto $producto): string
    {
        // Default categories by site (Mexico MLM)
        if ($producto->categoria) {
            $catName = strtolower($producto->categoria->nombre);
            $categories = $this->getDefaultCategories();

            foreach ($categories as $keyword => $catId) {
                if (str_contains($catName, $keyword)) {
                    return $catId;
                }
            }
        }

        return $this->getDefaultCategories()['default'];
    }

    protected function getDefaultCategories(): array
    {
        return [
            'alimento' => 'MLM1430',
            'bebida' => 'MLM1431',
            'electronica' => 'MLM1648',
            'ropa' => 'MLM1442',
            'hogar' => 'MLM1923',
            'juguete' => 'MLM1268',
            'default' => 'MLM1923', // General
        ];
    }

    // ==================== Sandbox / Test Users ====================

    public function createTestUser(): array
    {
        if (!$this->config || !$this->config->access_token) {
            throw new \Exception('Debes estar conectado para crear usuarios de prueba');
        }

        $response = Http::withToken($this->getAccessToken())
            ->asJson()
            ->post(self::BASE_URL . '/users/test_user', [
                'site_id' => $this->config->site_id ?? 'MLM',
            ]);

        if ($response->failed()) {
            throw new \Exception('Error al crear usuario de prueba: ' . $response->body());
        }

        $data = $response->json();

        return [
            'id' => $data['id'],
            'nickname' => $data['nickname'],
            'password' => $data['password'],
            'site_status' => $data['site_status'] ?? 'active',
            'site_id' => $this->config->site_id ?? 'MLM',
        ];
    }
}
