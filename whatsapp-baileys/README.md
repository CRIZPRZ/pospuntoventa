# EventPOS WhatsApp QR

Microservicio interno de WhatsApp Web. Laravel es la única fachada pública; este
servicio debe escuchar solamente en `127.0.0.1`.

## Producción

1. Copia `.env.example` a `.env`.
2. Genera un token largo y único para `BAILEYS_AUTH_TOKEN`.
3. Configura el mismo valor en Laravel como `WHATSAPP_BAILEYS_TOKEN`.
4. Configura `WHATSAPP_BAILEYS_URL=http://127.0.0.1:3025` en Laravel.
5. Levanta únicamente este servicio:

```bash
docker compose -f compose.production.yml up -d --build
```

Comprobación:

```bash
curl http://127.0.0.1:3025/health
docker compose -f compose.production.yml ps
docker compose -f compose.production.yml logs -f --tail=100
```

Las sesiones se guardan en el volumen `baileys-sessions`. Incluye ese volumen en
los respaldos del servidor. No publiques el puerto `3025` en Internet y no
guardes el directorio `sessions/` en Git.

## Actualización

```bash
git pull
docker compose -f whatsapp-baileys/compose.production.yml up -d --build
```

Después de actualizar, verifica `/health` y envía un mensaje de prueba desde
Configuración > WhatsApp.

El runbook completo, incluyendo el worker de Laravel, está en
`deploy/WHATSAPP_PRODUCTION.md`.
