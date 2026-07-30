# WhatsApp en producción

Laravel, Nginx, PHP, MySQL y Redis pueden seguir instalados directamente en el
servidor. Solo el proceso de WhatsApp por QR se ejecuta en Docker.

## Variables

Laravel:

```env
QUEUE_CONNECTION=redis
WHATSAPP_BAILEYS_URL=http://127.0.0.1:3025
WHATSAPP_BAILEYS_TOKEN=un-token-largo-y-unico
```

`whatsapp-baileys/.env`:

```env
BAILEYS_AUTH_TOKEN=el-mismo-token-de-laravel
LOG_LEVEL=info
```

No publiques el puerto `3025` en Nginx ni en el firewall.

## Baileys

```bash
cd /var/www/eventpos/whatsapp-baileys
docker compose -f compose.production.yml up -d --build
docker compose -f compose.production.yml ps
curl http://127.0.0.1:3025/health
```

## Cola Laravel

El sistema usa un worker permanente para sus trabajos generales. Los tickets de
venta por WhatsApp son manuales y no se encolan automáticamente. Copia
`deploy/supervisor-eventpos-worker.conf.example` a la configuración de
Supervisor, ajusta la ruta `/var/www/eventpos` y ejecuta:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart eventpos-worker:*
```

Después de cada deploy de Laravel:

```bash
php artisan migrate --force
php artisan optimize
php artisan queue:restart
```

## Respaldo

El volumen `whatsapp-baileys_baileys-sessions` contiene las vinculaciones.
Inclúyelo en el respaldo del servidor. Si se pierde, cada negocio tendrá que
escanear un QR nuevo; no se pierden ventas ni datos del POS.

## Prueba de humo

1. Activa el módulo WhatsApp para una empresa desde superadmin.
2. Selecciona conexión por QR y escanea el código.
3. Confirma que `/api/whatsapp/status` muestre `connected`.
4. Envía una prueba.
5. Envía manualmente ticket, cotización, pedido y CFDI.
6. Activa el check de tickets, realiza ventas de contado y crédito, y confirma
   que ambas pidan teléfono antes de enviar.
7. Reinicia el contenedor y confirma que la sesión continúa conectada.

El procedimiento completo de release está en `deploy/PRODUCTION_RELEASE.md`.
