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

## Alta default gratis/trial (2026-07-30)
- Nuevos tenants ya no deben nacer con `facturacion`, `whatsapp`, `mercado_libre`, `sucursales` ni `ubicaciones`.
- `Trial` sigue siendo un estado temporal del tenant y no un registro en la tabla `planes`.
- Catálogo público vigente sembrado por `PlanesSeeder`: `Básico`, `Pro`, `Ilimitado`.
- Los planes `manual`/personalizados se crean desde superadmin, no salen en `GET /api/planes` y checkout/portal Stripe deben rechazarlos.
- `RegisterController` no recibe `plan_id`; el registro siempre inicia trial y la contratación se hace después. Límites del trial: `1` sucursal, `1` usuario, `0` timbres CFDI incluidos.
- Los mensajes de validación de `POST /api/register` deben ser explícitos en español para no exponer claves internas como `validation.unique`.
- Paquetes one-time de timbres vigentes: `50 → $149 MXN`, `200 → $549 MXN`, `500 → $1,199 MXN`. La fuente de verdad del cobro es `BillingController::comprarTimbres()`.
- `DatabaseSeeder.php` debe llamar `PlanesSeeder::class`; de lo contrario, `php artisan migrate:fresh --seed` deja `/api/planes` vacío y el frontend cae al fallback de “Prueba gratis”.
- Los límites de trial ya no pueden vivir solo en frontend: `SucursalController::store()` y `UsuarioController::store()` deben frenar creación cuando `Empresa::limiteSucursales()` / `limiteUsuarios()` reportan cupo agotado.
- `CheckTrial` solo permite `trial`/`sin_plan` con fecha nula en `local` y `testing`; en producción, `plan_vigente_hasta = null` se considera no vigente.
- Los tenants creados manualmente desde superadmin nacen en `plan_estado='sin_plan'` con `plan_vigente_hasta=now()` para obligar asignación explícita de plan antes de usar el sistema.
- Si se elimina un plan, las empresas afectadas deben quedar con `plan_id=null`, `plan_estado='sin_plan'`, `plan_vigente_hasta=now()` y módulos desactivados para no conservar acceso comercial huérfano.
- `POST /api/register` debe mantenerse con `throttle:register` y un rate limiter por IP definido en `AppServiceProvider`; esto mitiga abuso del trial público.
- `RegisterController` también valida un honeypot (`website`) y un tiempo mínimo de llenado (`flow_started_at`) como capa anti-bot complementaria al rate limit.
- `BillingController::miPlan()` debe contar timbres usados desde `timbres_consumo`, igual que `FacturacionController`, para que el panel de billing y el bloqueo real no se contradigan.
- `BillingController::planesPublicos()` solo debe exponer planes `tipo='stripe'`. Los personalizados `manual` y cualquier legacy `gratis` deben quedar fuera del catálogo público.
- `SuperAdmin\PlanController` y la UI de superadmin ya no deben permitir `tipo='gratis'`; el modelo vigente es trial temporal + planes `stripe`/`manual`.
- `WebhookController` debe ser idempotente usando la tabla `stripe_webhook_events`, ignorar `event_id` repetidos y sincronizar `plan_vigente_hasta` desde `current_period_end` real de Stripe, no desde `now()`.
- Desde el 4 de agosto de 2026 existe una primera capa de licencias desktop: tablas `licenses` y `license_devices`, modelos `License`/`LicenseDevice` y servicio `DesktopLicenseService`.
- La licencia desktop no reemplaza billing; resuelve estado desde `Empresa::accesoSistemaVigente()` y solo agrega overrides manuales (`suspended`, `cancelled`) y una ventana `grace_until` para tolerancia offline.
- Endpoints backend desktop vigentes:
  - `GET /api/desktop/license` (auth:sanctum)
  - `POST /api/desktop/license/activate` (auth:sanctum)
  - `POST /api/desktop/license/deactivate-device` (auth:sanctum)
  - `POST /api/desktop/license/validate` (público)
  - `POST /api/desktop/license/activate-device` (público, `throttle:desktop-activate`) — activación PRE-LOGIN, ver sección siguiente.
- `POST /api/desktop/license/validate` usa `license_key`, `device_uuid` y opcionalmente `fingerprint`; si el dispositivo no está vinculado responde `422 {message, code: 'device_not_registered'}`, si está revocado o la huella no coincide responde `422 {message, code: 'device_revoked'}`, y si la key no existe responde `422 {message, code: 'invalid_license_key'}`. Estos `code` los usa `DesktopBootstrap.jsx` del frontend para decidir si debe forzar reactivación.

### Activación de licencia PRE-LOGIN (2026-08-05)
- `POST /api/desktop/license/activate-device` (`DesktopLicenseController::activateByEmail`) es **público** (fuera de `auth:sanctum`), rate-limited con `throttle:desktop-activate` (`RateLimiter::for('desktop-activate', ...)` en `AppServiceProvider`, 5/min y 20/hora por IP, mismo patrón que `register`).
- Body: `{license_key, email, device_uuid, device_name, fingerprint, platform, app_version}`. Resuelve la empresa 100% por `license_key` (no requiere sesión). Valida que `email` pertenezca a un usuario con rol `admin` **de la empresa dueña de esa `license_key`** (`User::where('email',...)->where('empresa_id', $license->empresa_id)->first()` + `hasRole('admin')`, mismo patrón que `CajaController` ya usa para "es admin").
- Lógica vive en `DesktopLicenseService::activateDeviceByEmail()`, reutiliza `activateDevice()` existente (mismo conteo de `max_devices`).
- Errores devuelven `{message, code}` vía `App\Exceptions\DesktopLicenseException` (tiene `render()` propio, no pasa por el manejador genérico de `ValidationException`): `invalid_license_key`, `license_suspended`, `license_cancelled`, `license_expired`, `email_mismatch`, `max_devices_reached`. Siempre 422, nunca 401 (el frontend excluye esta URL del interceptor de 401).
- El frontend persiste la activación en `desktop-device.json` (proceso main de Electron), no en el token de sesión — por eso este endpoint no depende de `auth:sanctum`. Una vez activado el equipo, `DesktopBootstrap.jsx` solo usa `validate` en logins/revalidaciones posteriores, ya no vuelve a llamar `/activate` automáticamente.
- Tabla `licenses` ahora tiene `owner_user_id` (nullable, FK a `users`, `nullOnDelete`) — ver sección superadmin.
- La gracia offline se controla con `DESKTOP_LICENSE_GRACE_HOURS` y se renueva en cada activación/validación correcta. El frontend/instalable debe bloquear cuando `resolved_status` sea `expired`, `suspended` o `cancelled`.
- Toda licencia desktop nueva se crea `suspended` y requiere activación manual desde superadmin. `POST /api/desktop/license/activate` no debe registrar un dispositivo mientras `access.allowed` sea `false`.
- La app revalida cada 60 segundos y al recuperar foco/visibilidad/conexión; suspender desde superadmin bloquea sin cerrar y abrir EventPOS.
- Una licencia suspendida nueva no crea dispositivo: `activate-device`/`activate` devuelven `access.allowed=false` sin registrar el equipo hasta que superadmin la habilite. El frontend (`DesktopActivationGate`) reintenta manualmente (botón, sin polling) mientras no esté activada; una vez que el dispositivo queda vinculado, pasa a usar solo `validate`.
- Desde el 4 de agosto de 2026, superadmin ya tiene un panel backend para licencias desktop por empresa:
  - `GET /api/superadmin/empresas/{empresa}/license`
  - `PUT /api/superadmin/empresas/{empresa}/license`
  - `POST /api/superadmin/empresas/{empresa}/license/devices/{deviceUuid}/revoke`
- `GET /api/superadmin/empresas/{empresa}/license` retorna la misma resolución de estado que consume el instalable más la lista de dispositivos registrados, activos y revocados.
- `PUT /api/superadmin/empresas/{empresa}/license` no altera billing/Stripe; solo aplica override administrativo de licencia (`active`, `suspended`, `cancelled`), cambia `max_devices` y desde 2026-08-05 acepta `owner_user_id` (nullable, debe pertenecer a esa empresa) para dejar constancia de qué usuario es responsable de la licencia. La respuesta de `license` (tanto en este endpoint como en `GET`, `/desktop/license`, `activate`, `validate`, `activate-device`) incluye `license.owner: {id, name, email} | null`.
- `GET /api/superadmin/empresas/{empresa}/usuarios` (nuevo 2026-08-05, `EmpresaController::usuarios`) — lista `{id, name, email, roles}` de los usuarios de esa empresa, usada por el frontend para alimentar el selector de "usuario responsable" en la tarjeta de licencia.
- Desde el 4 de agosto de 2026, el shell inicial de Electron existe en el frontend. Desde el 5 de agosto de 2026, el handshake principal ya NO es `POST /api/desktop/license/activate` (requiere sesión) sino `POST /api/desktop/license/activate-device` (público, pre-login) — `activate` queda solo como fallback legado si algún equipo antiguo aún no migró su estado local.
- El instalable revalida con `POST /api/desktop/license/validate`; backend debe mantener alineado el shape de `license/access/modules/limits/empresa/owner` entre `activate`, `activate-device` y `validate`.
- Existe el comando `php artisan billing:audit-production` para auditar legacy de billing; con `--apply` corrige inconsistencias seguras como planes `gratis` activos, `sin_plan/trial` sin vigencia o con módulos activos y planes manuales con Stripe IDs cargados.
- Los endpoints mutativos de setup CFDI (`/api/facturacion/*`) ahora requieren `can:gestionar facturacion`. Las mutaciones de Mercado Libre también deben vivir bajo `can:gestionar mercado libre`; `can:ver mercado libre` queda solo para lecturas.
- Mantener sincronizados estos tres puntos para evitar regresiones:
  - `app/Http/Controllers/Api/RegisterController.php` → `MODULOS_TRIAL`
  - `app/Http/Controllers/Api/SuperAdmin/EmpresaController.php` → `modulosDefault()`
  - `database/seeders/PlanesSeeder.php` → catálogo público `Básico`, `Pro`, `Ilimitado`

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

## WhatsApp Business
- `config.whatsapp` es solo configuración pública para frontend: número del negocio, estado visible, prueba y automatizaciones.
- Secretos de Meta NO van en `config.whatsapp`; se guardan en tabla `whatsapp_configs`.
- El modelo vigente es `empresa + sucursal con fallback`: si una sucursal no tiene override propio, usa la configuración técnica y pública de la empresa.
- Backend nuevo:
  - `app/Http/Controllers/Api/WhatsAppController.php`
  - `app/Services/WhatsAppService.php`
  - `app/Models/WhatsAppConfig.php`
  - migration `2026_06_05_110324_create_whatsapp_configs_table.php`
- Rutas:
  - `POST /api/whatsapp/connect`
  - `POST /api/whatsapp/complete`
  - `POST /api/whatsapp/test`
  - `POST /api/whatsapp/disconnect`
- `POST /api/whatsapp/connect` puede devolver `embedded_signup` (`app_id`, `config_id`, `redirect_uri`, `api_version`) para iniciar `Facebook Login for Business` desde frontend.
- `POST /api/whatsapp/complete` se usa cuando el popup/callback ya trae `code` o `access_token` más los datos técnicos del número y hay que persistirlos sin captura manual.
- `POST /api/whatsapp/test` intenta primero enviar texto libre; si Meta responde que ya expiró la ventana de 24 horas, el backend cae automáticamente a la plantilla `hello_world`.
- `ConfiguracionController` hidrata `config.whatsapp` desde la tabla técnica para que el frontend vea `status`, `display_name`, `connected_phone_number`, `last_test_at`, `last_error`, `scope_mode`, `inherits_from_empresa` sin exponer tokens.
- `.env` debe incluir `WHATSAPP_APP_SECRET` y `WHATSAPP_LOGIN_CONFIGURATION_ID` además del `APP_ID`, `REDIRECT_URI` y valores heredados.
- El mismo `POST /api/whatsapp/connect` también debe aceptar onboarding manual con `phone_number_id`, `whatsapp_business_account_id` y `access_token` para escenarios donde Meta restringe Embedded Signup a BSP/Tech Provider.
- Los tickets de venta se envían únicamente bajo confirmación del cajero mediante `POST /api/ventas/{id}/enviar-whatsapp`. `auto_send_ticket` habilita esa opción, pero `VentaCompletada` no envía mensajes.
- **Doble proveedor WhatsApp (2026-07-02)**: `empresas.whatsapp_provider` decide el motor por tenant (`cloud_api`, `baileys`, `disabled`). El módulo `whatsapp` sigue siendo solo acceso/visibilidad. `cloud_api` usa Meta como antes; `baileys` usa `app/Services/BaileysWhatsAppService.php` contra el microservicio Node en `whatsapp-baileys/`.
- **Endpoints nuevos Baileys**: `GET /api/whatsapp/qr` y `GET /api/whatsapp/status`; `POST /api/whatsapp/connect` genera/actualiza una sesión `baileys` y devuelve QR cuando el provider activo es `baileys`.
- **Config necesaria**: `WHATSAPP_BAILEYS_URL` y `WHATSAPP_BAILEYS_TOKEN`. Laravel conserva la fachada `/api/whatsapp/*`; frontend y módulos de ventas no hablan directo con Node.
- **Contenido de ticket**: `App\Services\VentaTicketWhatsAppMessage` construye el texto y URL usados por `VentaController`; no hay listener ni trabajo en cola para autoenviar ventas.
- **Nombre en recordatorios de pago (2026-07-30)**: usar `WhatsAppService::resolveConfiguredBusinessName()` y `PaymentReminderWhatsAppMessage`. Si existe nombre comercial mostrar `Recordatorio de pago — Nombre`; si está vacío mostrar solo `Recordatorio de pago`. No usar `display_name` técnico como EventPOS.
- **Bug resuelto provider visible**: `ConfiguracionController::attachWhatsAppConfig()` debe tomar `empresas.whatsapp_provider` como fuente de verdad. Solo hidrata estado desde `whatsapp_configs` si la fila técnica tiene el mismo provider activo; esto evita que una conexión vieja de Cloud API siga apareciendo después de cambiar el tenant a Baileys.
- **Bug resuelto QR inicial**: `WhatsAppController@qr` puede crear/iniciar la sesión Baileys cuando el provider activo es `baileys` y no existe fila técnica compatible. No depender de que el usuario haya llamado antes `connect`.
- **Bug resuelto estado Baileys post-QR (2026-07-03)**: cuando Baileys reporta `connected`, `WhatsAppController@status` debe persistir el estado público limpio en `config.whatsapp`. `ConfiguracionController` debe ignorar campos derivados (`empresa_default`, `inherits_from_empresa`, `scope_mode`) para evitar nesting recursivo y estados viejos en la UI.
- **Bug resuelto QR conectado (2026-07-03)**: aunque `whatsapp_configs.status` diga `connected`, `WhatsAppController@qr` debe consultar Node y guardar el estado real. No usar la DB como única fuente para saltarse el QR.
- **Bug resuelto sesión Baileys rota (2026-07-03)**: si Baileys falla reabriendo credenciales guardadas con `Connection Failure`, `whatsapp-baileys` borra solo esa sesión local y genera QR nuevo. `WhatsAppController@qr` debe guardar el estado real devuelto por Node para no dejar la UI en conectado falso.
- **Bug resuelto QR con varios clics (2026-07-30)**: `POST /sessions/start` y `GET /sessions/{key}/qr` recuperan sesiones cerradas o en reconexión dentro de la misma petición y esperan el QR nuevo. Laravel persiste y devuelve el estado real; si no hay QR ni conexión responde `422` en vez de un éxito falso.
- **Bug resuelto timeout envío Baileys (2026-07-03)**: para evitar `cURL error 28` al enviar prueba, el microservicio no debe abrir múltiples sockets simultáneos para el mismo `session_key`; usar `startingSessions`, manejar rechazos internos de Baileys y aplicar timeout propio a `sendMessage`. Laravel espera hasta 60s en `BaileysWhatsAppService`.
- **Bug resuelto número México Baileys (2026-07-03)**: números MX de 10 dígitos se normalizan a `521...`; Node valida con `socket.onWhatsApp()` y envía al JID existente. Esto evita respuestas `sent` a una variante que no llega al teléfono.
- **Fix JID México Baileys (2026-07-03)**: para evitar chats duplicados, Baileys debe priorizar `52 + 10 dígitos` sobre `521 + 10 dígitos`. `521` queda solo como fallback si `onWhatsApp()` no encuentra la variante `52`.
- **Prioridad entrega MX Baileys (2026-07-03)**: no forzar `52...@s.whatsapp.net` si `onWhatsApp()` reconoce `521...`; forzar `52` puede dejar mensajes en `PENDING` sin entrega. Priorizar el JID confirmado por WhatsApp aunque visualmente pueda abrir/usar otro chat.
- **Confirmación real Baileys (2026-07-03)**: Node no debe reportar éxito con `SERVER_ACK`; eso solo confirma servidor, no entrega al teléfono. Esperar `DELIVERY_ACK`, `READ` o `PLAYED`; si se queda en `PENDING/SERVER_ACK`, Laravel debe devolver error al usuario. Si `onWhatsApp()` devuelve `lid`, usar ese `lid` como destino preferente.
- **Enlaces Baileys compatibles (2026-07-30)**: `sendTicketMessage()` usa `BaileysWhatsAppService::sendUrlMessage()` para mandar texto normal con URL visible y una vista previa manual tocable. No restaurar `interactiveMessage` envuelto en `viewOnceMessage`: WhatsApp puede mostrarlo como “visualización única”.
- **URL pública de tickets WhatsApp (2026-07-30)**: `WHATSAPP_PUBLIC_URL` define el origen público del backend para tickets, pedidos, cotizaciones y descargas CFDI. Debe ser HTTPS en producción y no incluir `/api`. Si falta, usa `APP_URL`. La conexión QR requiere URL visible; no soporta de forma estable texto con hipervínculo oculto.
- **Teléfono explícito en envíos (2026-07-30)**: cuando llega `telefono`, usarlo como destino aunque también llegue `cliente_id` y persistirlo en el cliente relacionado. En cotizaciones actualizar además `telefono_cliente`, para que reenvíos posteriores muestren el dato corregido.
- **Decisión final de cotizaciones (2026-07-30)**: una cotización aceptada, rechazada o vencida puede consultarse y reenviarse, pero no volver a decidirse. La página pública oculta acciones y muestra el resultado final; los endpoints bloquean la fila durante la transición para evitar carreras y pedidos duplicados.
- **Reapertura por administrador (2026-07-30)**: la decisión es final para el token público existente, pero un administrador puede reabrir una rechazada/vencida desde edición. La transición a `borrador/enviada` rota el token; el enlace viejo deja de existir y debe enviarse el nuevo. Las aceptadas permanecen inmutables.
- **Feedback al decidir (2026-07-30)**: la página pública de cotización muestra un loading bloqueante con texto contextual al aceptar/rechazar. Deshabilita las dos acciones durante el POST y se limpia en `pageshow` para soportar navegación atrás/BFCache.
- **Pedidos desde cotización y WhatsApp (2026-07-30)**: el índice de pedidos incluye teléfono/email en la relación `cliente`; no reducirla a `id,nombre`, porque `WhatsAppSendModal` necesita precargar el número del cliente ligado.
- **Ventas a crédito y ticket WA (2026-07-30)**: el listener automático retorna antes de resolver configuración para `tipo_pago=credito`. El usuario confirma teléfono y envío desde el POS; nunca mandar silenciosamente un ticket de crédito.
- **Saludo sin nombre comercial (2026-07-30)**: si el nombre resuelto está vacío o solo contiene espacios, el ticket dice “¡Gracias por tu compra!” sin agregar “en”.
- **UX cliente WhatsApp QR (2026-07-03)**: la distinción `cloud_api`/`baileys` es interna y de superadmin. En pantalla del negocio usar copy simple de WhatsApp/QR; no mostrar palabras técnicas ni datos que no apliquen al proveedor QR.
- **Número conectado QR (2026-07-03)**: `WhatsAppController@status` debe persistir `connected_phone_number` y `display_name` cuando Baileys reporta `connected`. La UI debe mostrar ese número real, no el `phone_number` manual que pudo quedar viejo.
- **Errores técnicos QR (2026-07-03)**: no exponer `last_error` técnico de Baileys en la UI del cliente. Mantenerlo internamente para soporte/logs, pero la pantalla debe guiar con acciones simples como generar QR nuevo.
- **POS ticket WhatsApp (2026-07-03)**: `VentaController@enviarWhatsApp` debe preferir `telefono` del payload aunque venga `cliente_id` y rechazar teléfonos con menos de 10 dígitos. Evita falsos enviados cuando el cliente tiene un teléfono incompleto guardado.
- **Estado real Baileys al enviar (2026-07-03)**: `WhatsAppService::isConnected()` no debe confiar solo en `whatsapp_configs.status` para Baileys. Siempre consulta `whatsapp-baileys /status`, actualiza `whatsapp_configs`, sincroniza `config.whatsapp` y limpia cache (`ventas_configuracion_*`, `ventas_config_sucursal_*`). Así el POS no muestra/enruta como conectado una sesión caída con `Connection Failure`.
- **Docker local Baileys (2026-07-30)**: usar `docker compose up -d --build whatsapp-baileys`. El servicio dedicado del compose principal usa Node 20, monta `whatsapp-baileys/sessions`, incluye healthcheck y `restart: unless-stopped`. Laravel debe usar `WHATSAPP_BAILEYS_URL=http://whatsapp-baileys:3025`.
- **Runbook de producción (2026-07-30)**: seguir `deploy/PRODUCTION_RELEASE.md`. Producción mantiene Laravel/Nginx/MySQL/Redis nativos y ejecuta solo Baileys con `whatsapp-baileys/compose.production.yml`; allí la URL de Laravel es `http://127.0.0.1:3025`. Respaldar DB y volumen antes de desplegar.
- **Módulos fijos por plan (2026-07-30)**: Trial/Básico no incluyen WhatsApp; Pro e Ilimitado sí. Cámaras queda desactivado para todos y fuera de los defaults. Ejecutar `PlanesSeeder --force` aplica la definición a planes y tenants Stripe activos sin alterar módulos de planes manuales.

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

## WhatsApp producción — hardening (2026-07-30)
- Las rutas de conexión y envío usan `module:whatsapp` + `throttle:whatsapp`.
- Toda resolución de `cliente_id` conserva el scope tenant; queda prohibido `Cliente::withoutGlobalScopes()` en envíos WhatsApp.
- Laravel puede ejecutarse sin Docker. Para Baileys usar `whatsapp-baileys/compose.production.yml`, publicado solo en `127.0.0.1`, con token obligatorio y volumen persistente `baileys-sessions`.
- Las sesiones y `node_modules` no pertenecen a Git. Una sesión previamente versionada debe considerarse comprometida, desvincularse y eliminarse del historial remoto.
- El servicio tiene `/health`, validación de claves, reconexión con backoff, limpieza al logout y cierre controlado.
- `auto_send_ticket` se ejecuta automáticamente en cola. Los cinco flags `auto_send_*` son además gates de producto: con el flag apagado no se muestra la acción y el endpoint debe responder `422`. Cotizaciones, pedidos, CFDI y recordatorios conservan sus modales de confirmación.
- Los `auto_send_*` se persisten individualmente por `PUT /api/configuracion` al cambiar cada check. `POST /api/whatsapp/connect` ignora esos campos para evitar que QR/conectar arrastre otras preferencias.
