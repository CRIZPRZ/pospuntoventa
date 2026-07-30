# Despliegue completo a producción

Este procedimiento asume:

- Backend Laravel en `/var/www/eventpos`.
- Frontend publicado en `https://eventpos.online`.
- API publicada en `https://api.eventpos.online`.
- Laravel, Nginx, PHP, MySQL y Redis instalados directamente en el servidor.
- Solo WhatsApp QR se ejecuta con Docker.

Nunca ejecutar `php artisan migrate:fresh` en producción.

## 0. Limpieza única antes del primer push

El repositorio no debe versionar dependencias Node ni credenciales de WhatsApp.
Ejecutar una sola vez en el equipo de desarrollo:

```bash
cd /ruta/al/backend
git rm -r --cached whatsapp-baileys/node_modules whatsapp-baileys/sessions
git add .gitignore whatsapp-baileys/package.json whatsapp-baileys/package-lock.json
git commit -m "Remove Baileys dependencies and sessions from Git"
```

`--cached` los elimina del repositorio, no del disco local. Antes del push,
confirmar:

```bash
git ls-files 'whatsapp-baileys/node_modules/**'
git ls-files 'whatsapp-baileys/sessions/**'
```

Ambos comandos deben quedar sin salida. Si alguna sesión ya se publicó en un
repositorio remoto, desvincularla desde WhatsApp, generar una sesión nueva y
rotar `BAILEYS_AUTH_TOKEN`.

## 1. Respaldo

Antes de actualizar:

```bash
mysqldump -u USUARIO -p BASE_DATOS | gzip > ~/eventpos-$(date +%F-%H%M).sql.gz
cd /var/www/eventpos/whatsapp-baileys
docker compose -f compose.production.yml ps
docker run --rm \
  -v whatsapp-baileys_baileys-sessions:/data \
  -v "$HOME":/backup \
  alpine tar czf /backup/eventpos-whatsapp-$(date +%F-%H%M).tar.gz -C /data .
```

Si Baileys todavía no se ha instalado, el segundo respaldo se omite.

## 2. Backend Laravel

```bash
cd /var/www/eventpos
php artisan down --retry=60
git pull --ff-only
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan optimize:clear
php artisan migrate --force
php artisan db:seed --class=PlanesSeeder --force
php artisan storage:link
php artisan optimize
php artisan queue:restart
php artisan up
```

`PlanesSeeder` usa `updateOrCreate`: sincroniza Básico, Pro e Ilimitado sin
eliminar planes manuales ni empresas.

## 3. Variables Laravel

En `/var/www/eventpos/.env`:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.eventpos.online
FRONTEND_URL=https://eventpos.online

QUEUE_CONNECTION=redis

WHATSAPP_BAILEYS_URL=http://127.0.0.1:3025
WHATSAPP_BAILEYS_TOKEN=TOKEN_LARGO_COMPARTIDO
WHATSAPP_PUBLIC_URL=https://api.eventpos.online
```

Después de modificar `.env`:

```bash
cd /var/www/eventpos
php artisan optimize:clear
php artisan optimize
php artisan queue:restart
```

`WHATSAPP_PUBLIC_URL` no lleva `/api`; se usa para generar enlaces HTTPS
clicables de tickets, cotizaciones y pedidos.

## 4. WhatsApp QR con Docker

Generar el token una sola vez:

```bash
openssl rand -hex 32
```

Guardar exactamente el mismo token de Laravel en
`/var/www/eventpos/whatsapp-baileys/.env`:

```env
BAILEYS_AUTH_TOKEN=TOKEN_LARGO_COMPARTIDO
LOG_LEVEL=info
```

Levantar el servicio:

```bash
cd /var/www/eventpos/whatsapp-baileys
docker compose -f compose.production.yml up -d --build
docker compose -f compose.production.yml ps
curl http://127.0.0.1:3025/health
```

El puerto `3025` queda enlazado únicamente a `127.0.0.1`. No agregarlo a Nginx
ni abrirlo en el firewall. El contenedor tiene `restart: unless-stopped` y las
sesiones viven en el volumen `baileys-sessions`.

La primera conexión de cada empresa se realiza desde Superadmin:

1. Activar el módulo WhatsApp.
2. Seleccionar proveedor de WhatsApp por QR.
3. Entrar al tenant en Configuración > WhatsApp.
4. Generar y escanear el QR.

No se recomienda copiar la sesión del equipo local a producción; es más seguro
vincular un QR nuevo en el servidor definitivo.

## 5. Worker Laravel

Instalar Supervisor si aún no existe y copiar
`deploy/supervisor-eventpos-worker.conf.example` a
`/etc/supervisor/conf.d/eventpos-worker.conf`.

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart eventpos-worker:*
sudo supervisorctl status
```

El worker atiende trabajos generales del sistema. Los tickets de venta por
WhatsApp se envían únicamente cuando el cajero los confirma.

## 6. Frontend

Si Vercel está conectado al repositorio, el push de la rama de producción
dispara el build. Verificar que tenga:

```env
VITE_API_URL=https://api.eventpos.online/api
VITE_BACKEND_URL=https://api.eventpos.online
```

Para una publicación manual:

```bash
npm ci
VITE_API_URL=https://api.eventpos.online/api \
VITE_BACKEND_URL=https://api.eventpos.online \
npm run build
```

Publicar el contenido de `dist/`, no el directorio completo del repositorio.

## 7. Prueba de humo

1. `GET https://api.eventpos.online/up` responde correctamente.
2. Registro crea un tenant en trial sin datos de prueba.
3. Landing muestra Básico, Pro e Ilimitado.
4. Superadmin puede asignar plan, módulos y timbres.
5. Login, POS, venta normal y venta a crédito funcionan.
6. El POS confirma manualmente el teléfono antes de enviar un ticket.
7. Cotización se puede enviar, aceptar y convertir en pedido una sola vez.
8. Recordatorio de deuda muestra el nombre comercial solo cuando existe.
9. `curl http://127.0.0.1:3025/health` responde `status: ok`.
10. Configuración > WhatsApp muestra `connected` y el mensaje de prueba llega.
11. Reiniciar Baileys conserva la sesión:

```bash
cd /var/www/eventpos/whatsapp-baileys
docker compose -f compose.production.yml restart
curl http://127.0.0.1:3025/health
```

## 8. Rollback

Si el release falla:

1. Volver al commit anterior con un despliegue normal de Git.
2. Ejecutar `composer install --no-dev --prefer-dist --optimize-autoloader`.
3. Ejecutar `php artisan optimize:clear && php artisan optimize`.
4. Restaurar la base solo si una migración destructiva lo requiere.
5. No borrar el volumen `baileys-sessions`.
