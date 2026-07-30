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
- **Registro de un paso (2026-07-30)**: `POST /api/register` solo recibe datos de empresa/usuario y siempre inicia trial; no acepta `plan_id`. Sus mensajes de validación deben ser explícitos en español para no devolver claves internas como `validation.unique`.
- **Alta default trial (2026-07-30)**: nuevos tenants ya no deben nacer con `facturacion`, `whatsapp`, `mercado_libre`, `sucursales` ni `ubicaciones`. La fuente de verdad queda en `RegisterController::MODULOS_TRIAL` y `EmpresaController::modulosDefault()`.
- **Catálogo de planes vigente (2026-07-30)**: `Trial` sigue siendo un estado temporal del tenant, no un registro en `planes`. Los planes públicos sembrados por `PlanesSeeder` son `Básico`, `Pro` e `Ilimitado`. Los planes `manual`/personalizados se crean desde superadmin, no salen en `GET /api/planes` y no deben poder contratarse por checkout self-service.
- **Trial desacoplado del plan comercial (2026-07-30)**: `RegisterController` no recibe `plan_id`; la contratación ocurre después. Mientras `plan_estado='trial'`, los límites operativos son propios: `1` sucursal, `1` usuario, `0` timbres CFDI incluidos.
- **Paquetes one-time de timbres (2026-07-30)**: `BillingController::comprarTimbres()` cobra `50 → $149 MXN`, `200 → $549 MXN`, `500 → $1,199 MXN`. Si cambia el precio, actualizar también el selector visible del frontend en `MiPlan.jsx`.
- **Hardening planes/trial (2026-07-30)**: los límites de `1` sucursal / `1` usuario del trial ya no son solo UX; `SucursalController::store()` y `UsuarioController::store()` deben validarlos server-side usando `Empresa::limiteSucursales()` y `Empresa::limiteUsuarios()`.
- **`sin_plan` vencido por default (2026-07-30)**: en producción, `CheckTrial` ya no debe dejar pasar `trial`/`sin_plan` con `plan_vigente_hasta = null`; ese bypass queda solo para `local`/`testing`. Los tenants creados por superadmin nacen con `plan_estado='sin_plan'` y `plan_vigente_hasta=now()` hasta que se les asigne plan.
- **Eliminar plan deja tenant bloqueado (2026-07-30)**: `SuperAdmin\\PlanController::destroy()` debe poner `plan_id=null`, `plan_estado='sin_plan'`, `plan_vigente_hasta=now()`, limpiar `plan_precio_pactado` y desactivar módulos para evitar que una empresa siga operando con acceso comercial huérfano.
- **Registro público con rate limit (2026-07-30)**: `POST /api/register` debe usar `throttle:register` y `RateLimiter::for('register')` con cupos por IP. Si cambia la ruta de registro o se mueve de archivo, preservar este límite para evitar farming de trials.
- **Anti-bot liviano en registro (2026-07-30)**: `RegisterController` valida honeypot `website` y `flow_started_at` para frenar automatizaciones básicas además del rate limit. No venderlo como seguridad fuerte; es una capa complementaria.
- **Fuente de verdad de timbres en billing (2026-07-30)**: `BillingController::miPlan()` debe contar uso mensual desde `timbres_consumo`, no desde `ventas.cfdi_uuid`, para que la UI coincida con la lógica real de bloqueo en `FacturacionController`.
- **Catálogo público self-service (2026-07-30)**: `BillingController::planesPublicos()` debe devolver solo planes `tipo='stripe'`. Aunque exista un plan legacy `gratis` o uno `manual`, no debe aparecer en la landing; el registro ya no muestra planes.
- **Sin tipo gratis nuevo (2026-07-30)**: `SuperAdmin\\PlanController` y el frontend de superadmin ya no deben permitir crear/editar planes `tipo='gratis'`. El modelo comercial vigente es `trial` como estado temporal + planes `stripe` y `manual`.
- **Webhook Stripe idempotente (2026-07-30)**: `WebhookController` registra `event_id` en `stripe_webhook_events` y debe ignorar duplicados. Esto evita dobles recargas de timbres o activaciones repetidas cuando Stripe reintenta eventos.
- **Comando de saneamiento producción (2026-07-30)**: `php artisan billing:audit-production` reporta planes legacy `gratis`, empresas `sin_plan/trial` con módulos activos o sin vigencia y planes manuales con Stripe IDs. Con `--apply` corrige solo inconsistencias seguras.
- **Permisos sensibles cerrados (2026-07-30)**: setup/upload/test de facturación CFDI ahora requieren `can:gestionar facturacion`. Las mutaciones de Mercado Libre (publicar, sync, pausar, unlink, kits, importar) requieren `can:gestionar mercado libre`; `can:ver mercado libre` queda para lecturas.
- **Seeder crítico**: `database/seeders/DatabaseSeeder.php` debe llamar `PlanesSeeder::class`. Si se corre `php artisan migrate:fresh --seed` sin esa línea, el catálogo público queda vacío aunque `PlanesSeeder.php` esté correcto.
- **Login/Me**: response incluye `empresa: { id, nombre, slug }`.
- **Configuración**: archivo por tenant `storage/app/configuracion_{empresa_id}.json`, cache key `ventas_configuracion_{empresa_id}`, logo en `storage/app/public/config/{empresa_id}/`.
- **WhatsApp Business**: la UX pública vive en `config.whatsapp`, pero las credenciales técnicas de Meta NO deben guardarse ni devolverse en ese JSON; van en `whatsapp_configs`.
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

## WhatsApp Business (2026-06-05)
- Tabla nueva: `whatsapp_configs`
- Modelo: `app/Models/WhatsAppConfig.php`
- Servicio: `app/Services/WhatsAppService.php`
- Controlador: `app/Http/Controllers/Api/WhatsAppController.php`
- Modelo vigente: `empresa + sucursal con fallback`
- Endpoints:
  - `POST /api/whatsapp/connect`
  - `POST /api/whatsapp/complete`
  - `POST /api/whatsapp/test`
  - `POST /api/whatsapp/disconnect`
- `connect` puede devolver `embedded_signup` con `app_id`, `config_id`, `redirect_uri` y `api_version` para lanzar `Facebook Login for Business` desde frontend.
- `complete` cierra la conexión técnica cuando el onboarding embebido devuelve `code` o `access_token`, más `phone_number_id` y opcionalmente `waba_id`.
- `ConfiguracionController::show()` hidrata `config.whatsapp` con estado público desde `whatsapp_configs`, pero nunca expone `access_token`, `phone_number_id` ni `whatsapp_business_account_id`.
- `config.whatsapp` solo guarda UX y automatizaciones; si la sucursal tiene override se mezcla sobre la empresa y si no, hereda.
- `whatsapp_configs` guarda la conexión técnica real por empresa o por sucursal: `empresa_id`, `sucursal_id nullable`, `phone_number_id`, `whatsapp_business_account_id`, `access_token`, `status`, `last_error`.
- `disconnect` en scope sucursal elimina el override y hace que la sucursal vuelva a heredar el número general.
- Config adicional requerida en `.env`: `WHATSAPP_APP_SECRET` y `WHATSAPP_LOGIN_CONFIGURATION_ID`.
- Aunque `connect` soporta Embedded Signup, también acepta conexión manual enviando `phone_number_id`, `whatsapp_business_account_id` y `access_token` en el mismo endpoint para apps que aún no son Tech Provider/BSP.
- **Doble proveedor WhatsApp (2026-07-02)**: `empresas.whatsapp_provider` decide el motor por tenant (`cloud_api`, `baileys`, `disabled`). El módulo `whatsapp` solo controla acceso/visibilidad. Superadmin lo cambia desde el frontend en Empresas.
- **Baileys MVP**: `app/Services/BaileysWhatsAppService.php` llama al microservicio Node `whatsapp-baileys/` con `WHATSAPP_BAILEYS_URL` y `WHATSAPP_BAILEYS_TOKEN`. Nuevas rutas: `GET /api/whatsapp/qr` y `GET /api/whatsapp/status`. Laravel sigue siendo la única fachada pública; el frontend no habla directo con Node.
- **Tickets de venta manuales (2026-07-30)**: se eliminó `SendVentaTicketToWhatsApp`; `VentaCompletada` ya no envía WhatsApp. `auto_send_ticket` funciona como permiso/preferencia para mostrar la opción y `VentaController@enviarWhatsApp` realiza el único envío explícito.
- **Bug resuelto provider visible**: `ConfiguracionController::attachWhatsAppConfig()` usa `empresas.whatsapp_provider` como fuente de verdad y solo hidrata datos técnicos cuando `whatsapp_configs.provider` coincide. No reintroducir el override desde la fila técnica.
- **Bug resuelto QR inicial**: `WhatsAppController@qr` arranca/crea sesión Baileys si no existe fila `whatsapp_configs` compatible y el provider activo es `baileys`. No volver al error de sesión Baileys inexistente para tenants ya configurados en superadmin.
- **Bug resuelto estado Baileys post-QR (2026-07-03)**: `GET /api/whatsapp/status` actualiza `config.whatsapp` y debe limpiar campos derivados (`empresa_default`, `inherits_from_empresa`, `scope_mode`) antes de persistir. `ConfiguracionController` también debe descartarlos al guardar, porque son solo datos resueltos para la UI.
- **Bug resuelto QR conectado (2026-07-03)**: `WhatsAppController@qr` no debe confiar solo en `whatsapp_configs.status === connected`; debe consultar Node y persistir el estado real antes de decidir si hay QR o sesión conectada.
- **Bug resuelto sesión Baileys rota (2026-07-03)**: `whatsapp-baileys/src/server.js` limpia la sesión local y genera QR nuevo cuando credenciales guardadas fallan con `Connection Failure`. `WhatsAppController@qr` persiste el status devuelto por Node (`qr_pending`/`connected`) en `config.whatsapp`.
- **Bug resuelto QR con varios clics (2026-07-30)**: una solicitud explícita de QR recupera en la misma llamada sesiones guardadas que cierren como `disconnected` o `reconnecting`, borrando solo las credenciales inválidas y esperando el QR nuevo. `connectBaileys` responde con el estado real y nunca anuncia `qr_pending` si no recibió QR.
- **Bug resuelto timeout envío Baileys (2026-07-03)**: `whatsapp-baileys/src/server.js` usa `startingSessions` para no abrir sockets paralelos, captura `unhandledRejection`/`uncaughtException` y envuelve `sendMessage` con timeout controlado. `BaileysWhatsAppService` sube timeout HTTP a 60s.
- **Bug resuelto número México Baileys (2026-07-03)**: `BaileysWhatsAppService::normalizePhone()` usa `521` para números MX de 10 dígitos. `whatsapp-baileys/src/server.js` resuelve el destinatario con `socket.onWhatsApp()` probando candidatos `521...` y `52...` antes de enviar.
- **Fix JID México Baileys (2026-07-03)**: para evitar chats duplicados, Baileys debe priorizar `52 + 10 dígitos` sobre `521 + 10 dígitos`. `521` queda solo como fallback si `onWhatsApp()` no encuentra la variante `52`.
- **Prioridad entrega MX Baileys (2026-07-03)**: no forzar `52...@s.whatsapp.net` si `onWhatsApp()` reconoce `521...`; forzar `52` puede dejar mensajes en `PENDING` sin entrega. Priorizar el JID confirmado por WhatsApp aunque visualmente pueda abrir/usar otro chat.
- **Confirmación real Baileys (2026-07-03)**: Node no debe reportar éxito con `SERVER_ACK`; eso solo confirma servidor, no entrega al teléfono. Esperar `DELIVERY_ACK`, `READ` o `PLAYED`; si se queda en `PENDING/SERVER_ACK`, Laravel debe devolver error al usuario. Si `onWhatsApp()` devuelve `lid`, usar ese `lid` como destino preferente.
- **Enlaces Baileys compatibles (2026-07-30)**: tickets, cotizaciones, pedidos y facturas se envían como texto normal con URL visible y `linkPreview` manual (`canonical-url`/`matched-text`) para ofrecer una tarjeta tocable aunque el cliente no autodetecte una IP local. No usar `viewOnceMessage` ni `interactiveMessage.nativeFlowMessage`: algunos clientes los muestran como “visualización única”.
- **URL pública de tickets WhatsApp (2026-07-30)**: configurar `WHATSAPP_PUBLIC_URL` con el origen HTTPS público del backend, sin `/api`. Los mensajes de texto por QR no admiten enlaces ocultos; la URL debe permanecer visible para ser clicable. `localhost` no funciona desde el teléfono.
- **Teléfono explícito en envíos (2026-07-30)**: si una petición WhatsApp incluye `telefono`, ese valor tiene prioridad sobre el guardado y debe actualizar al cliente relacionado. Cotizaciones también persisten `telefono_cliente`. Aplica a tickets, cotizaciones, pedidos, CFDI y recordatorios.
- **Decisión final de cotizaciones (2026-07-30)**: `aceptada`, `rechazada` y `vencida` son estados terminales desde el enlace público. Reenviar por WhatsApp no cambia el estado ni reactiva botones. Aceptar/rechazar usa `DB::transaction()` + `lockForUpdate()` para impedir decisiones concurrentes y pedidos duplicados.
- **Reapertura por administrador (2026-07-30)**: `CotizacionController@update` permite pasar `rechazada`/`vencida` a `borrador` o `enviada` y rota `ticket_token` con `Str::random(48)`. El enlace anterior queda inválido y la nueva decisión requiere reenviar el enlace. `aceptada` no puede cambiar de estado.
- **Loading de decisión pública (2026-07-30)**: al enviar Aceptar/Rechazar, `cotizacion_publica.blade.php` muestra un overlay de carga de pantalla completa, bloquea ambos botones y restaura el estado en `pageshow`. Mantener el `confirm()` antes del submit.
- **Teléfono en pedidos ligados (2026-07-30)**: `PedidoController@index` debe cargar `cliente:id,nombre,email,telefono`. Si solo selecciona ID/nombre, el modal WhatsApp reconoce al cliente pero no puede precargar su número, especialmente en pedidos creados al aceptar cotizaciones.
- **Flujo igual por tipo de pago (2026-07-30)**: efectivo, tarjeta y crédito requieren confirmación manual del teléfono antes de enviar. El contenido se construye con `VentaTicketWhatsAppMessage`; no existe un camino automático para ningún tipo de venta.
- **Saludo de ticket sin nombre (2026-07-30)**: `buildTicketContent()` recorta el nombre comercial. Si queda vacío usa “¡Gracias por tu compra!” y nunca genera “en !”.
- **UX cliente WhatsApp QR (2026-07-03)**: el backend mantiene `provider=baileys` internamente, pero la UI del cliente no debe exponer ese proveedor ni términos de infraestructura. La compatibilidad con Cloud API debe mantenerse separada y sin regresiones.
- **POS ticket WhatsApp (2026-07-03)**: `VentaController@enviarWhatsApp` debe preferir `telefono` del payload aunque venga `cliente_id` y rechazar teléfonos con menos de 10 dígitos. Evita falsos enviados cuando el cliente tiene un teléfono incompleto guardado.
- **Estado real Baileys al enviar (2026-07-03)**: `WhatsAppService::isConnected()` no debe confiar solo en `whatsapp_configs.status` para Baileys. Siempre consulta `whatsapp-baileys /status`, actualiza `whatsapp_configs`, sincroniza `config.whatsapp` y limpia cache (`ventas_configuracion_*`, `ventas_config_sucursal_*`). Así el POS no muestra/enruta como conectado una sesión caída con `Connection Failure`.
- **Docker local Baileys (2026-07-30)**: el compose principal incluye `whatsapp-baileys` con Node 20, healthcheck, sesiones persistentes y `restart: unless-stopped`. Levantar con `docker compose up -d --build whatsapp-baileys` y configurar Laravel con `WHATSAPP_BAILEYS_URL=http://whatsapp-baileys:3025`; no mantener un `npm run dev` manual dentro de `ventas-app`.
- **Runbook de producción (2026-07-30)**: `deploy/PRODUCTION_RELEASE.md` documenta el despliegue completo. En el servidor sin Laravel en Docker, Baileys usa su compose independiente y Laravel apunta a `http://127.0.0.1:3025`. Nunca ejecutar `migrate:fresh`; respaldar DB y volumen de sesiones antes del release.
- `POST /api/whatsapp/test` primero intenta texto libre y, si Meta bloquea por la ventana de 24 horas, reintenta con la plantilla `hello_world` para que el número de prueba de Meta siga siendo útil durante desarrollo.
- Envío de tickets: `POST /api/ventas/{id}/enviar-whatsapp` valida `auto_send_ticket`, teléfono y conexión efectiva. Puede recibir `{ telefono }` o `{ cliente_id, telefono }`.
- **Nombre en recordatorios de pago (2026-07-30)**: `ClienteController@recordarPagoWhatsApp` usa solo `nombre_comercial` efectivo de sucursal/empresa mediante `resolveConfiguredBusinessName()`, nunca el `display_name` técnico de la sesión. `PaymentReminderWhatsAppMessage` omite por completo el separador y nombre cuando no hay nombre comercial.

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

## Módulo Facturación CFDI 4.0 — Multi-PAC (2026-06-17)

### Arquitectura
- **`app/Services/Pac/PacContract`** (interface): `key, setup, subirCsd, crearFactura, descargarXml, descargarPdf, cancelarFactura, test`
- **`app/Services/Pac/FacturapiPac`**: envuelve `FacturapiService`, `tax_included:true`
- **`app/Services/Pac/FacturamaPac`**: Basic Auth global (`FACTURAMA_USER/PASSWORD`), una cuenta, cada tenant = emisor por RFC. Des-IVAr por ítem (`precio / (1+tasa)`), `TaxObject:'02'`, desglose explícito de IVA. Facturama NO acepta `tax_included`.
- **`app/Services/Pac/PacManager::for(?Empresa)`**: resuelve PAC por `empresa.pac_provider` (default `'facturama'`)
- **`app/Models/TimbreConsumo`**: ledger atómico de timbres — fila por timbre exitoso
- **`FacturacionController`**: helpers `pac()`, `emisorCtx()`, `buildNormalizedInvoice()`. Créditos con `DB::transaction` + `lockForUpdate` + conteo desde `timbres_consumo`

### Columnas nuevas / renombradas (migraciones `2026_06_17_*`)
- `empresas.pac_provider` string default `'facturama'` — controlado por superadmin
- `ventas.cfdi_pac_id` (renombrado de `cfdi_facturapi_id`) + `ventas.cfdi_pac` (qué PAC timbró)
- Tabla `timbres_consumo`: `empresa_id, sucursal_id, venta_id, pac, uuid, created_at`

### Reglas críticas
- Descarga PDF / cancelación: usa `venta->cfdi_pac` para resolver PAC aunque el tenant cambie de PAC después.
- Timbre extra: decrementar `timbres_extra` SOLO tras timbrado exitoso (dentro del transaction, fuera del PAC call).
- `SuperAdmin\EmpresaController::update` acepta `pac_provider` (`in:facturapi,facturama`).

### `.env` necesario
```
FACTURAMA_USER=...
FACTURAMA_PASSWORD=...
FACTURAMA_SANDBOX=true
```

### Migraciones a correr en producción
```
php artisan migrate --force
```
(3 migraciones de fecha `2026_06_17_000001/2/3`)

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

## WhatsApp producción — hardening (2026-07-30)
- Todas las rutas de conexión y envío requieren `module:whatsapp` y `throttle:whatsapp`; ocultar la UI no es suficiente.
- Los `cliente_id` usados para envíos se resuelven siempre con el global scope del tenant. No usar `Cliente::withoutGlobalScopes()` en estos flujos.
- Laravel puede correr nativo y únicamente Baileys en Docker con `whatsapp-baileys/compose.production.yml`. El puerto host queda en `127.0.0.1:3025`, el token interno es obligatorio y las sesiones viven en el volumen `baileys-sessions`.
- `whatsapp-baileys/sessions/` y `whatsapp-baileys/node_modules/` están fuera de Git. Si una sesión llegó al repositorio, se debe desvincular desde el teléfono y sanear también el historial remoto.
- Node valida `session_key`, ofrece `/health`, reconecta con backoff, limpia credenciales inválidas/logout y atiende `SIGTERM/SIGINT`.
- `auto_send_ticket` es opt-in (`false` por defecto). El listener en cola tiene tres intentos y deduplicación por empresa/venta; requiere un worker permanente `php artisan queue:work`.
- Los cinco flags `auto_send_*` también actúan como habilitación del tipo de envío. Si el flag está apagado, frontend oculta la acción y backend rechaza el endpoint con `422`; ocultar el botón no es suficiente.
- `WhatsAppController::connect()` no acepta ni persiste `auto_send_*`. Cada check se actualiza de forma individual mediante `PUT /api/configuracion`; conectar o generar QR no debe producir guardados laterales de preferencias.
