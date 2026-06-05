#!/bin/bash
# ============================================================
# Ventas POS — Camera Agent Installer
# Compatible: macOS, Ubuntu/Debian, RHEL/CentOS
# ============================================================
set -e

API_BASE="${VENTAS_API:-https://api.eventpos.online/api}"
AGENT_DIR="$HOME/.ventas-agent"
MTX_VERSION="v1.12.2"
LOG_FILE="$AGENT_DIR/agent.log"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
info()  { echo -e "${GREEN}[✓]${NC} $1"; }
warn()  { echo -e "${YELLOW}[!]${NC} $1"; }
error() { echo -e "${RED}[✗]${NC} $1"; exit 1; }

echo ""
echo "  ╔══════════════════════════════════════╗"
echo "  ║   Ventas POS — Camera Agent Setup    ║"
echo "  ╚══════════════════════════════════════╝"
echo ""

# ── Token ────────────────────────────────────────────────────
if [ -z "$1" ]; then
  echo -n "  Ingresa tu token de agente: "
  read -r AGENT_TOKEN
else
  AGENT_TOKEN="$1"
fi

[ -z "$AGENT_TOKEN" ] && error "Token requerido"

# ── Verificar token ───────────────────────────────────────────
info "Verificando token..."
VERIFY=$(curl -sf -H "X-Agent-Token: $AGENT_TOKEN" "$API_BASE/agent/config" 2>/dev/null) || \
  error "Token inválido o sin conexión a $API_BASE"

SUCURSAL=$(echo "$VERIFY" | python3 -c "import sys,json;d=json.load(sys.stdin);print(d.get('sucursal','?'))" 2>/dev/null)
info "Sucursal: $SUCURSAL"

# ── Crear directorio ─────────────────────────────────────────
mkdir -p "$AGENT_DIR"
echo "$AGENT_TOKEN" > "$AGENT_DIR/token"
echo "$API_BASE"    > "$AGENT_DIR/api_base"

# ── Detectar OS y arquitectura ────────────────────────────────
OS=$(uname -s | tr '[:upper:]' '[:lower:]')
ARCH=$(uname -m)
case "$OS" in
  darwin) OS_SLUG="darwin" ;;
  linux)  OS_SLUG="linux"  ;;
  *) error "OS no soportado: $OS" ;;
esac
# MediaMTX usa "arm64v8" en Linux pero "arm64" en macOS
case "$ARCH" in
  x86_64|amd64) ARCH_SLUG="amd64" ;;
  arm64|aarch64)
    if [ "$OS_SLUG" = "darwin" ]; then
      ARCH_SLUG="arm64"
    else
      ARCH_SLUG="arm64v8"
    fi ;;
  *) error "Arquitectura no soportada: $ARCH" ;;
esac

# ── Descargar MediaMTX ────────────────────────────────────────
MTX_BIN="$AGENT_DIR/mediamtx"
if [ ! -f "$MTX_BIN" ]; then
  info "Descargando MediaMTX $MTX_VERSION ($OS_SLUG/$ARCH_SLUG)..."
  MTX_URL="https://github.com/bluenviron/mediamtx/releases/download/${MTX_VERSION}/mediamtx_${MTX_VERSION}_${OS_SLUG}_${ARCH_SLUG}.tar.gz"
  curl -sL "$MTX_URL" | tar xz -C "$AGENT_DIR" mediamtx
  chmod +x "$MTX_BIN"
  # macOS Gatekeeper
  [ "$OS" = "darwin" ] && xattr -d com.apple.quarantine "$MTX_BIN" 2>/dev/null || true
  info "MediaMTX instalado"
else
  info "MediaMTX ya instalado"
fi

# ── Descargar cloudflared ─────────────────────────────────────
CF_BIN="$AGENT_DIR/cloudflared"
if [ ! -f "$CF_BIN" ]; then
  info "Descargando cloudflared..."
  CF_VER="2025.5.0"
  case "${OS_SLUG}_${ARCH_SLUG}" in
    darwin_amd64)   CF_URL="https://github.com/cloudflare/cloudflared/releases/download/${CF_VER}/cloudflared-darwin-amd64.tgz" ; CF_TGZ=1 ;;
    darwin_arm64)   CF_URL="https://github.com/cloudflare/cloudflared/releases/download/${CF_VER}/cloudflared-darwin-arm64.tgz" ; CF_TGZ=1 ;;
    linux_amd64)    CF_URL="https://github.com/cloudflare/cloudflared/releases/download/${CF_VER}/cloudflared-linux-amd64"       ; CF_TGZ=0 ;;
    linux_arm64v8)  CF_URL="https://github.com/cloudflare/cloudflared/releases/download/${CF_VER}/cloudflared-linux-arm64"       ; CF_TGZ=0 ;;
    *) error "No hay cloudflared para $OS_SLUG/$ARCH_SLUG" ;;
  esac
  if [ "$CF_TGZ" = "1" ]; then
    curl -sL "$CF_URL" | tar xz -C "$AGENT_DIR" cloudflared
  else
    curl -sL "$CF_URL" -o "$CF_BIN"
  fi
  chmod +x "$CF_BIN"
  [ "$OS" = "darwin" ] && xattr -d com.apple.quarantine "$CF_BIN" 2>/dev/null || true
  info "cloudflared instalado"
else
  info "cloudflared ya instalado"
fi

# ── Script principal del agente ───────────────────────────────
cat > "$AGENT_DIR/run.sh" << 'SCRIPT'
#!/bin/bash
AGENT_DIR="$HOME/.ventas-agent"
API_BASE=$(cat "$AGENT_DIR/api_base")
AGENT_TOKEN=$(cat "$AGENT_DIR/token")
MTX_BIN="$AGENT_DIR/mediamtx"
CF_BIN="$AGENT_DIR/cloudflared"
MTX_CFG="$AGENT_DIR/mediamtx.yml"
CF_LOG="$AGENT_DIR/cloudflare.log"
MTX_PID_FILE="$AGENT_DIR/mediamtx.pid"
CF_PID_FILE="$AGENT_DIR/cloudflare.pid"

log() { echo "[$(date '+%H:%M:%S')] $1"; }

stop_all() {
  [ -f "$MTX_PID_FILE" ] && kill "$(cat $MTX_PID_FILE)" 2>/dev/null && rm "$MTX_PID_FILE"
  [ -f "$CF_PID_FILE"  ] && kill "$(cat $CF_PID_FILE)"  2>/dev/null && rm "$CF_PID_FILE"
}
trap stop_all EXIT INT TERM

# ── Generar config MediaMTX desde API ────────────────────────
generate_config() {
  log "Obteniendo config de cámaras..."
  CONFIG=$(curl -sf -H "X-Agent-Token: $AGENT_TOKEN" "$API_BASE/agent/config" 2>/dev/null) || {
    log "ERROR: No se pudo obtener config. Reintentando en 30s..."
    return 1
  }

  VPS_RTSP=$(echo "$CONFIG" | python3 -c "import sys,json;d=json.load(sys.stdin);print(d.get('vps_rtsp_push','rtsp://localhost:8554'))" 2>/dev/null)

  # Construir paths
  PATHS=$(echo "$CONFIG" | python3 -c "
import sys, json
d = json.load(sys.stdin)
paths = ''
for cam in d.get('cameras', []):
    paths += '''  {path}:
    source: {source}
    sourceOnDemand: yes
    sourceOnDemandStartTimeout: 10s
    sourceOnDemandCloseAfter: 60s
    rpiCameraWidth: 0
'''.format(**cam)
print(paths)
" 2>/dev/null)

  cat > "$MTX_CFG" << CONF
hlsAddress: :8888
hlsAlwaysRemux: yes
hlsAllowOrigin: '*'
rtspAddress: :8554
webrtcAddress: :18889
api: yes
apiAddress: :9997
logLevel: warn

authMethod: internal
authInternalUsers:
  - user: any
    pass: ""
    ips: []
    permissions:
      - action: api
        path: ""
      - action: read
        path: ""
      - action: publish
        path: ""

paths:
$PATHS
CONF
  log "Config generada con $(echo "$CONFIG" | python3 -c "import sys,json;d=json.load(sys.stdin);print(len(d.get('cameras',[])))" 2>/dev/null) cámara(s)"
  return 0
}

# ── Start MediaMTX ────────────────────────────────────────────
start_mediamtx() {
  [ -f "$MTX_PID_FILE" ] && kill "$(cat $MTX_PID_FILE)" 2>/dev/null
  sleep 1
  "$MTX_BIN" "$MTX_CFG" >> "$AGENT_DIR/agent.log" 2>&1 &
  echo $! > "$MTX_PID_FILE"
  sleep 2
  log "MediaMTX iniciado (PID $(cat $MTX_PID_FILE))"
}

# ── Start Cloudflare Tunnel ───────────────────────────────────
start_tunnel() {
  [ -f "$CF_PID_FILE" ] && kill "$(cat $CF_PID_FILE)" 2>/dev/null
  rm -f "$CF_LOG"
  sleep 1
  "$CF_BIN" tunnel --url http://localhost:8888 > "$CF_LOG" 2>&1 &
  echo $! > "$CF_PID_FILE"
  log "Esperando URL del tunnel..."
  for i in $(seq 1 20); do
    TUNNEL_URL=$(grep -o 'https://[a-z0-9-]*\.trycloudflare\.com' "$CF_LOG" 2>/dev/null | head -1)
    [ -n "$TUNNEL_URL" ] && break
    sleep 2
  done

  if [ -z "$TUNNEL_URL" ]; then
    log "WARN: No se obtuvo URL del tunnel. Reintentando..."
    return 1
  fi

  log "Tunnel activo: $TUNNEL_URL"

  # Registrar URL en backend
  curl -sf -X POST "$API_BASE/agent/register" \
    -H "X-Agent-Token: $AGENT_TOKEN" \
    -H "Content-Type: application/json" \
    -d "{\"mediamtx_url\":\"$TUNNEL_URL\",\"version\":\"1.0.0\"}" > /dev/null 2>&1 && \
    log "URL registrada en el sistema" || \
    log "WARN: No se pudo registrar URL"

  return 0
}

# ── Esperar red antes de iniciar ─────────────────────────────
log "=== Ventas POS Camera Agent iniciando ==="
log "Esperando conexión a internet..."
WAIT=0
until curl -sf --max-time 5 "$API_BASE/agent/config" \
    -H "X-Agent-Token: $AGENT_TOKEN" > /dev/null 2>&1; do
  sleep 10
  WAIT=$((WAIT+10))
  [ $WAIT -ge 120 ] && log "WARN: Sin internet tras 2 min, reintentando..." && WAIT=0
done
log "Red disponible. Iniciando..."

# ── Main loop ─────────────────────────────────────────────────
generate_config && start_mediamtx && start_tunnel

LAST_CONFIG_CHECK=0
HEARTBEAT_INTERVAL=60

while true; do
  sleep 30
  NOW=$(date +%s)

  # Heartbeat al backend
  curl -sf -X POST "$API_BASE/agent/heartbeat" \
    -H "X-Agent-Token: $AGENT_TOKEN" > /dev/null 2>&1

  # Revisar si hay cambios en config cada 2 minutos
  if [ $((NOW - LAST_CONFIG_CHECK)) -gt 120 ]; then
    NEW_CONFIG=$(curl -sf -H "X-Agent-Token: $AGENT_TOKEN" "$API_BASE/agent/config" 2>/dev/null)
    NEW_HASH=$(echo "$NEW_CONFIG" | md5sum 2>/dev/null | cut -d' ' -f1)
    OLD_HASH=$(cat "$AGENT_DIR/config_hash" 2>/dev/null || echo "")
    if [ "$NEW_HASH" != "$OLD_HASH" ]; then
      log "Cambio en cámaras detectado — recargando..."
      echo "$NEW_HASH" > "$AGENT_DIR/config_hash"
      generate_config && start_mediamtx
    fi
    LAST_CONFIG_CHECK=$NOW
  fi

  # Verificar que MediaMTX sigue vivo
  if [ -f "$MTX_PID_FILE" ] && ! kill -0 "$(cat $MTX_PID_FILE)" 2>/dev/null; then
    log "MediaMTX se detuvo — reiniciando..."
    generate_config && start_mediamtx
  fi

  # Verificar que tunnel sigue vivo
  if [ -f "$CF_PID_FILE" ] && ! kill -0 "$(cat $CF_PID_FILE)" 2>/dev/null; then
    log "Tunnel caído — reiniciando..."
    start_tunnel
  fi
done
SCRIPT

chmod +x "$AGENT_DIR/run.sh"

# ── Instalar como servicio (autostart) ────────────────────────
install_service() {
  if [ "$OS" = "darwin" ]; then
    PLIST="$HOME/Library/LaunchAgents/com.ventaspos.agent.plist"
    cat > "$PLIST" << PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key>
  <string>com.ventaspos.agent</string>
  <key>ProgramArguments</key>
  <array>
    <string>/bin/bash</string>
    <string>$AGENT_DIR/run.sh</string>
  </array>
  <key>RunAtLoad</key>
  <true/>
  <key>KeepAlive</key>
  <true/>
  <key>StandardOutPath</key>
  <string>$AGENT_DIR/agent.log</string>
  <key>StandardErrorPath</key>
  <string>$AGENT_DIR/agent.log</string>
</dict>
</plist>
PLIST
    launchctl unload "$PLIST" 2>/dev/null || true
    launchctl load -w "$PLIST"
    info "Servicio instalado (macOS LaunchAgent)"

  elif [ "$OS" = "linux" ]; then
    SERVICE_FILE="/etc/systemd/system/ventas-agent.service"
    sudo tee "$SERVICE_FILE" > /dev/null << SERVICE
[Unit]
Description=Ventas POS Camera Agent
After=network.target

[Service]
Type=simple
User=$USER
ExecStart=/bin/bash $AGENT_DIR/run.sh
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
SERVICE
    sudo systemctl daemon-reload
    sudo systemctl enable --now ventas-agent
    info "Servicio instalado (systemd)"
  fi
}

install_service

echo ""
echo -e "  ${GREEN}╔══════════════════════════════════════╗${NC}"
echo -e "  ${GREEN}║   ✓ Agente instalado correctamente   ║${NC}"
echo -e "  ${GREEN}╚══════════════════════════════════════╝${NC}"
echo ""
echo "  Sucursal : $SUCURSAL"
echo "  Logs     : $LOG_FILE"
echo ""
echo "  El agente inicia automáticamente con el sistema."
echo "  Las cámaras estarán visibles en tu cuenta de Ventas POS"
echo "  desde cualquier dispositivo en 1-2 minutos."
echo ""
