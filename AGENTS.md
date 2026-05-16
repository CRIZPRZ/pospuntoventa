# Ventas POS — Contexto del Proyecto (Backend Laravel)

## Descripción
Sistema de Punto de Venta (POS) completo **multi-tenant**. Cada negocio es una `empresa`; todos los datos están aislados por `empresa_id`.

## Multi-tenant Architecture
- **Tabla**: `empresas` (`id, nombre, slug, email, status`)
- **Columna**: `empresa_id` en todas las tablas de datos: `users, productos, categorias, clientes, ventas, cajas, abonos, proveedores, pagos_proveedores, mercado_libre_config, cotizaciones`
- **Roles**: `empresa_id` en tabla `roles`. Unique por `(name, guard_name, empresa_id)`. Cada tenant tiene sus propios roles.
- **Rol admin**: sagrado — no se puede eliminar. Se crea automáticamente al registrar empresa.
- **Permissions**: globales (sin empresa_id). Seeder los crea una vez.
- **Middleware**: `ResolveTenant` (appended a grupo `api`) — lee `user.empresa_id` y hace `app()->instance('tenant_id', id)`.
- **Global Scopes**: en todos los modelos de datos. Filtra por `empresa_id` cuando `app()->bound('tenant_id')`.
- **Auto-set empresa_id**: modelos tienen evento `creating` que pone `empresa_id = app('tenant_id')` automáticamente.
- **Registro**: `POST /api/register` (público) — crea empresa + admin role (todos los permisos) + user admin → devuelve token.
- **Login/Me**: response incluye `empresa: { id, nombre, slug }`.
- **Configuración**: archivo por tenant `storage/app/configuracion_{empresa_id}.json`, cache key `ventas_configuracion_{empresa_id}`, logo en `storage/app/public/config/{empresa_id}/`.
- **MercadoLibreService**: constructor tiene try/catch para no fallar si tabla no existe (ej. durante migrate:fresh).
- **Folio ventas**: unique por `(empresa_id, folio)`. Se genera con `withoutGlobalScope('tenant')` filtrando por empresa.

### Archivos clave del tenant
- `app/Models/Empresa.php` — modelo tenant
- `app/Http/Middleware/ResolveTenant.php` — middleware
- `app/Http/Controllers/Api/RegisterController.php` — registro público
- `database/migrations/2026_05_11_000001_create_empresas_table.php`
- `database/migrations/2026_05_11_000002_add_empresa_id_to_tables.php`
- `database/migrations/2026_05_11_000003_add_empresa_id_to_roles_table.php`

### Reglas críticas para código nuevo
- Todo modelo nuevo que sea datos de un negocio: agregar `empresa_id` en fillable + global scope + creating event (copiar patrón de Producto.php).
- Al crear usuarios en UsuarioController: el rol se busca por `empresa_id` y `name` (no solo por name).
- No usar `Role::pluck('name')` sin filtrar por empresa_id.
- Ruta de registro NO debe estar dentro del grupo `auth:sanctum`.

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

## Módulo Pedidos
- Tabla `pedidos`: `empresa_id, folio (PED-XXXX), cotizacion_id, cliente_id, nombre_cliente, email_cliente, vendedor_id, fecha, fecha_entrega, status, subtotal, descuento, impuesto_pct, total, notas, softDeletes`
- Tabla `pedido_items`: `pedido_id, producto_id, descripcion, cantidad, precio_unitario, descuento, subtotal`
- Tabla `cotizaciones` tiene `pedido_id` para rastrear la conversión
- Status: `pendiente | confirmado | en_proceso | enviado | entregado | cancelado`
- No se puede eliminar en status `enviado` o `entregado`
- No se puede editar en status `entregado`
- Permisos: `ver pedidos`, `gestionar pedidos`
- `POST /cotizaciones/{id}/convertir-pedido` → crea Pedido desde Cotización, marca `cotizacion.pedido_id`

## Rutas API principales (prefijo /api)
```text
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
```

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

## Credenciales de prueba
- No hay usuarios pre-seeded. Registrar empresa en `POST /api/register` o desde `/register` en el frontend.
- El seeder solo crea los permisos globales (no roles ni usuarios).

## Módulo Proveedores
- `app/Models/Proveedor.php`: `$table = 'proveedores'` OBLIGATORIO (sin esto Laravel busca "proveedors")
- `app/Http/Controllers/Api/ProveedorController.php`: CRUD estándar, destroy bloquea si tiene productos
- Permisos: `ver proveedores`, `gestionar proveedores`
- Relación: `Producto belongsTo Proveedor` via `proveedor_id nullable`
- `ProductoController` incluye `proveedor` en todos los `with([...])`

## Regla crítica: nombres de tabla en español
Siempre declarar `protected $table = 'nombre_plural_correcto'` en modelos con nombre en español.
Ejemplos donde Laravel falla: Proveedor→proveedors, Almacen→almacens, Imagen→imagenes-falla.
Siempre verificar que la tabla en migration y `$table` del model coincidan.

## Patrón: nuevo módulo backend
1. Migration + softDeletes para entidades principales
2. Model: `$table` explícito, `$fillable`, `$casts`, relaciones
3. Controller: index(busqueda q), store, show, update, destroy(guard), toggle
4. Rutas agrupadas por permiso en `api.php`
5. RolesSeeder: permisos al array global + asignar a roles

## Bug resuelto: precio_compra vs costo en productos
- Causa: migration renombró columna `costo` → `precio_compra` pero modelo/controllers seguían usando `costo`.
- Fix modelo: `$fillable`/`$casts` usan `precio_compra`; `getCostoAttribute()` como alias.
- Fix `ProductoController`: eliminar bloque `precio_compra→costo` mapping.
- Fix `VentaController`: `costo_unitario => $producto->precio_compra ?? 0`.
- Síntomas: "Unknown column 'costo'" al editar producto + "costo_unitario cannot be null" al hacer venta.

## Módulo Cortes — endpoint pendiente
- Frontend llama `POST /api/cortes/{id}/imprimir-termico` para impresión térmica por red/USB.
- Body: `{ ancho_papel, impresora_ip, impresora_puerto, conexion_tipo, dispositivo_usb, impresora_nombre }`.
- Implementar en `CortesController@imprimirTermico` — mismo patrón que `VentaController@imprimirTermico`.
- `conexion_tipo === 'webusb'` nunca llega al backend (el frontend lo maneja directamente vía WebUSB API).

## Multi-sucursal: tablas con empresa_id + sucursal_id
- `cotizaciones`: empresa_id + sucursal_id → migration `2026_05_15_100001`. Unique anterior `folio` cambiado a `(empresa_id, folio)`.
- `pagos_proveedores`: empresa_id + sucursal_id → misma migration.
- `pedidos`: ya tenía empresa_id, se agrega sucursal_id → misma migration.
- **IMPORTANTE**: Sin correr esta migration, cotizaciones/pedidos/pagos-proveedores dan 500 porque `applySucursalScope` usa `where('sucursal_id', ...)` que no existe.
- `CortesController`: todas las queries raw ahora filtran por `empresa_id` (tenant) y `sucursal_id`.

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
- [x] CortesController filtra por empresa_id + sucursal_id
- [ ] Reportes (pendiente)
- [ ] `POST /api/cortes/{id}/imprimir-termico` (pendiente — frontend ya lo llama)
