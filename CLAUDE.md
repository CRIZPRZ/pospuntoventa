# Ventas POS — Contexto del Proyecto (Backend Laravel)

## Descripción
Sistema de Punto de Venta (POS) completo para gestión de ventas de cualquier tipo de productos.

## Stack
- **Framework**: Laravel 13 (PHP 8.3)
- **Base de datos**: MySQL 8.0 (via Docker)
- **Auth**: Laravel Sanctum (API tokens)
- **Roles/Permisos**: Spatie Laravel Permission v7
- **Cache/Queue/Session**: Redis
- **Correo dev**: Mailpit
- **Contenedor**: Docker (PHP-FPM + Nginx)

## Infraestructura Docker
- **API**: http://localhost:8080
- **MySQL**: localhost:3307 (usuario: root, contraseña: 12345678, DB: ventas)
- **Redis**: localhost:6380
- **Mailpit UI**: http://localhost:8026

## Comandos clave
```bash
make up          # Levantar contenedores
make down        # Bajar contenedores
make setup       # up + composer install + migrate + seed
make sh          # Shell dentro del contenedor app
make artisan     # Correr artisan dentro del contenedor
make migrate     # Correr migraciones
make seed        # Correr seeders
make fresh       # migrate:fresh --seed
```

## Regla de mantenimiento de contexto (OBLIGATORIA)
- **Todo cambio relevante DEBE documentarse en `CLAUDE.md` y `AGENTS.md` antes de cerrar la tarea.** Sin excepción.
- Si el cambio afecta backend y frontend, actualizar también los archivos equivalentes en `../ventas-frontend/`.
- Documentar: bug fixes con causa raíz, nuevos endpoints, cambios de arquitectura, decisiones que evitan regresiones, integraciones externas, auth, rutas, permisos, storage, imágenes, validaciones.
- No documentar: cambios triviales de estilos, refactors internos sin impacto en otros módulos.

## Estructura de Base de Datos
- **users**: Usuarios del sistema (auth)
- **roles / permissions**: Spatie — control de acceso
- **categorias**: Categorías de productos
- **productos**: Catálogo de productos (con softDeletes, stock, código de barras)
- **cajas**: Sesiones de caja (apertura/cierre con totales por método de pago)
- **ventas**: Transacciones de venta (folio auto-generado V-XXXXXX)
- **venta_items**: Líneas de detalle de venta (snapshot de precio/nombre)
- **pagos**: Pagos individuales por venta (efectivo, tarjeta, crédito)

## Roles del sistema
- **admin**: Acceso total
- **supervisor**: Gestión de productos, ventas, reportes, caja
- **cajero**: Realizar ventas, gestionar caja

## Rutas API principales (prefijo /api)
```
POST   /auth/login
POST   /auth/logout
GET    /auth/me
GET    /productos
POST   /productos
GET/PUT/DELETE /productos/{id}
GET    /productos/buscar?q=...
GET/POST /categorias
GET    /caja/actual
POST   /caja/abrir
POST   /caja/cerrar
GET    /caja/cortes
GET/POST /ventas
POST   /ventas/{id}/cancelar
GET    /ventas/{id}/ticket
POST   /ventas/{id}/imprimir-termico
GET    /cortes/hoy?fecha&cajero_id
POST   /cortes/generar
GET    /cortes/{id}/ticket
POST   /cortes/{id}/imprimir-termico   ← PENDIENTE IMPLEMENTAR (frontend ya lo llama)
```

## Módulo Cortes — endpoint pendiente
- Frontend llama `POST /api/cortes/{id}/imprimir-termico` para impresión térmica por red/USB (mismo patrón que `POST /ventas/{id}/imprimir-termico`).
- Body esperado: `{ ancho_papel, impresora_ip, impresora_puerto, conexion_tipo, dispositivo_usb, impresora_nombre }`.
- Controlador sugerido: `CortesController@imprimirTermico` — recuperar el corte guardado, construir ESC/POS con mike42/escpos-php, enviar a impresora según `conexion_tipo`.
- Si `conexion_tipo === 'webusb'` el frontend maneja el envío directamente (no llega al backend).
- `GET /api/cortes/{id}/ticket` ya existe y retorna `{ ticket_html: '...' }` — no modificar.

## Integración Mercado Libre
- Controlador: `app/Http/Controllers/Api/MercadoLibreController.php`.
- Servicio: `app/Services/MercadoLibreService.php`.
- Modelos/tablas: `mercado_libre_config`, `producto_meli`, `App\Models\MercadoLibreConfig`, `App\Models\ProductoMeli`.
- Rutas principales bajo `/api/mercado-libre/*` están dentro de `auth:sanctum` y permisos; al probar por ngrok se requiere `Authorization: Bearer ...`.
- Categorías: `GET /site-categories`, `GET /categories?q=...`, `GET /categories/{categoryId}/children`, `GET /categories/{categoryId}`, `GET /categories/{categoryId}/attributes`.
- Algunas respuestas públicas de ML pueden fallar sin token; el servicio usa token cuando está disponible.
- Para hijos de categoría, si `/categories/{id}/children` viene vacío, usar fallback a `/categories/{id}` y `children_categories`.
- Atributos requeridos: antes de publicar, validar contra `/categories/{categoryId}/attributes` los tags `required` y `catalog_required`. Ejemplo: `MLM189045` exige `BRAND` y `MODEL`.
- Publicación: no aceptar URLs públicas para fotos. Las imágenes deben estar en `storage/app/public/productos` y subirse directo a Mercado Libre con `POST /pictures/items/upload`; usar el `id` retornado en `pictures`.
- La subida de imágenes a ML puede responder `201 Created`; considerar cualquier respuesta `2xx` con `id` como éxito.
- Validar imágenes de producto en Laravel con mínimo 500x500 px; ML recomienda 1200x1200 px y máximo 10 MB.
- Al editar un producto ya publicado, `ProductoController@update` intenta sincronizar datos editables con Mercado Libre mediante `MercadoLibreService::syncProductData`: precio, stock y fotos.
- `productInfo()` consulta el item vivo en ML, actualiza status local y devuelve `sub_status` para explicar pausas/revisión en frontend.

## Credenciales de prueba (después del seed)
- Admin: admin@ventas.com / password
- Cajero: cajero@ventas.com / password

## Módulo Proveedores
- Model: `app/Models/Proveedor.php` — tabla `proveedores` (declarada explícitamente en `$table` porque Laravel pluraliza en inglés → "proveedors")
- Controller: `app/Http/Controllers/Api/ProveedorController.php` — index(q search), store, show(+productos), update, destroy(bloquea si tiene productos), toggle
- Rutas: `GET/POST /api/proveedores`, `GET/PUT/DELETE/PATCH /api/proveedores/{proveedor}`
- FK en productos: `proveedor_id nullable` con `nullOnDelete` — relación `Producto belongsTo Proveedor`
- `ProductoController` carga `proveedor` en todos los with(['categoria', 'proveedor', 'mercadoLibre'])
- Permisos: `ver proveedores` (admin+supervisor), `gestionar proveedores` (admin only)
- **IMPORTANTE**: siempre declarar `protected $table = 'proveedores'` en modelos con nombre en español cuya pluralización inglesa difiere (proveedor→proveedors, almacen→almacens, etc.)

## Configuración — persistencia en archivo JSON
- `Cache::forever()` solo guarda en Redis. Redis se limpia al reiniciar contenedor → config se pierde.
- Fix: `ConfiguracionController::update()` escribe `storage/app/configuracion.json` además del cache.
- `configuracion()` lee el archivo primero; Redis y defaults son fallback.
- Archivo `storage/app/configuracion.json` es la fuente de verdad — NO eliminar en deploys.
- Logo sigue en `storage/app/public/config/` (Storage disk public).

## Cortes — ventas por proveedor, num_ventas, y totales por método de pago
- `ventas_departamento` en response agrupa por proveedor via JOIN: `venta_items → productos → proveedores`.
- Columna usada: `proveedores.nombre`. Items sin proveedor muestran "SIN PROVEEDOR".
- Response incluye `num_ventas` = count de ventas del día filtradas.
- El campo `codigo_departamento` en `ventas` NO existe y NO se usa — el agrupamiento es por proveedor.
- **Totales por método**: se suman desde la tabla `pagos` (JOIN con ventas), NO desde `ventas.tipo_pago`. Razón: `ventas.tipo_pago` en colección PHP puede tener conflictos; la tabla `pagos` es la fuente canónica y soporta pagos mixtos futuros.
- El `leftJoin cajas` fue eliminado del query de ventas para evitar columnas duplicadas (ambas tablas tienen `estado`, `created_at`, etc.).

## Columna precio_compra en productos (antes: costo)
- Migration `2026_05_05_152208_rename_costo_to_precio_compra_in_productos_table` renombró `costo` → `precio_compra`. La columna DB se llama `precio_compra`.
- Modelo `Producto`: `$fillable` y `$casts` usan `precio_compra`. Alias `getCostoAttribute()` devuelve `$this->precio_compra` para backwards-compat.
- `ProductoController`: ya NO mapear `precio_compra→costo`. El campo se guarda directo como `precio_compra`.
- `VentaController`: `costo_unitario` en venta_items → `$producto->precio_compra ?? 0`.
- **NUNCA** volver a poner `costo` en `$fillable` de Producto — columna DB no existe.

## Patrón: nuevo módulo backend
1. Migration con `$table->softDeletes()` si es entidad principal
2. Model: `$table` explícito si nombre en español, `$fillable`, `$casts`, relaciones
3. Controller: métodos index(search q), store(validate+create), show(load relations), update(validate+update), destroy(guard si hay dependencias), toggle(bool activo)
4. Rutas en `api.php`: agrupar por permiso `can:ver X` para GET y `can:gestionar X` para POST/PUT/PATCH/DELETE
5. RolesSeeder: agregar permisos al array y asignar a roles apropiados

## Estado del proyecto
- [x] Estructura base Laravel 13
- [x] Docker configurado (puertos separados del proyecto salon)
- [x] Migraciones completas del esquema POS
- [x] Modelos con relaciones y fillable
- [x] Auth con Sanctum
- [x] Roles con Spatie (admin, supervisor, cajero)
- [x] Seeders con usuarios y permisos base
- [x] Rutas API definidas
- [x] Controllers con lógica completa para ventas
- [x] Generación e impresión de tickets térmicos (mike42/escpos-php)
- [x] Módulo Proveedores (CRUD + relación con productos)
- [ ] Reportes (pendiente)
