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

## Regla de mantenimiento de contexto
- Mantener actualizados `CLAUDE.md` y `AGENTS.md` cuando se agregue, cambie o depure un flujo importante del proyecto.
- Si el cambio afecta backend y frontend, actualizar también los archivos equivalentes en `../ventas-frontend/`.
- Documentar decisiones operativas que evitan regresiones, especialmente integraciones externas, auth, rutas, permisos, storage, imágenes y validaciones.

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

## Credenciales de prueba (después del seed)
- Admin: admin@ventas.com / password
- Cajero: cajero@ventas.com / password

## Estado del proyecto
- [x] Estructura base Laravel 13
- [x] Docker configurado (puertos separados del proyecto salon)
- [x] Migraciones completas del esquema POS
- [x] Modelos con relaciones y fillable
- [x] Auth con Sanctum
- [x] Roles con Spatie (admin, supervisor, cajero)
- [x] Seeders con usuarios y permisos base
- [x] Rutas API definidas
- [ ] Controllers con lógica completa (pendiente por módulo)
- [ ] Generación e impresión de tickets (pendiente)
- [ ] Reportes (pendiente)
