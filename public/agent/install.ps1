# ============================================================
# Ventas POS — Camera Agent Installer (Windows)
# PowerShell 5.1+ / Windows 10/11
# Ejecutar como Administrador:
#   Set-ExecutionPolicy Bypass -Scope Process -Force
#   irm https://api.eventpos.online/agent/install.ps1 | iex
# O con token directo:
#   .\install.ps1 -Token TU_TOKEN_AQUI
# ============================================================
param([string]$Token = "")

$API_BASE   = if ($env:VENTAS_API) { $env:VENTAS_API } else { "https://api.eventpos.online/api" }
$AgentDir   = "$env:USERPROFILE\.ventas-agent"
$MTX_VER    = "v1.12.2"
$TaskName   = "VentasPOS-CameraAgent"
$ErrorActionPreference = "Stop"

function Write-Ok($msg)   { Write-Host "  [OK] $msg" -ForegroundColor Green }
function Write-Warn($msg) { Write-Host "  [!]  $msg" -ForegroundColor Yellow }
function Write-Err($msg)  { Write-Host "  [X]  $msg" -ForegroundColor Red; exit 1 }

Clear-Host
Write-Host ""
Write-Host "  +==========================================+" -ForegroundColor Cyan
Write-Host "  |   Ventas POS - Camera Agent  (Windows)  |" -ForegroundColor Cyan
Write-Host "  +==========================================+" -ForegroundColor Cyan
Write-Host ""

# ── Token ────────────────────────────────────────────────────
if (-not $Token) {
    $Token = Read-Host "  Ingresa tu token de agente"
}
if (-not $Token) { Write-Err "Token requerido" }

# ── Verificar token ───────────────────────────────────────────
Write-Host "  Verificando token..." -NoNewline
try {
    $headers = @{ "X-Agent-Token" = $Token }
    $config  = Invoke-RestMethod -Uri "$API_BASE/agent/config" -Headers $headers
    Write-Host " OK" -ForegroundColor Green
    Write-Ok "Sucursal: $($config.sucursal)"
} catch {
    Write-Err "Token invalido o sin conexion a $API_BASE"
}

# ── Crear directorio ─────────────────────────────────────────
New-Item -ItemType Directory -Force -Path $AgentDir | Out-Null
$Token    | Out-File "$AgentDir\token.txt"    -Encoding utf8 -NoNewline
$API_BASE | Out-File "$AgentDir\api_base.txt" -Encoding utf8 -NoNewline

# ── Detectar arquitectura ─────────────────────────────────────
$arch = if ([System.Environment]::Is64BitOperatingSystem) { "amd64" } else { "386" }

# ── Descargar MediaMTX ────────────────────────────────────────
$mtxBin = "$AgentDir\mediamtx.exe"
if (-not (Test-Path $mtxBin)) {
    Write-Host "  Descargando MediaMTX $MTX_VER..." -NoNewline
    $mtxUrl = "https://github.com/bluenviron/mediamtx/releases/download/$MTX_VER/mediamtx_${MTX_VER}_windows_$arch.zip"
    $mtxZip = "$AgentDir\mediamtx.zip"
    Invoke-WebRequest -Uri $mtxUrl -OutFile $mtxZip -UseBasicParsing
    Expand-Archive -Path $mtxZip -DestinationPath $AgentDir -Force
    Remove-Item $mtxZip
    Write-Host " OK" -ForegroundColor Green
} else {
    Write-Ok "MediaMTX ya instalado"
}

# ── Descargar cloudflared ─────────────────────────────────────
$cfBin = "$AgentDir\cloudflared.exe"
if (-not (Test-Path $cfBin)) {
    Write-Host "  Descargando cloudflared..." -NoNewline
    $cfUrl = "https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-$arch.exe"
    Invoke-WebRequest -Uri $cfUrl -OutFile $cfBin -UseBasicParsing
    Write-Host " OK" -ForegroundColor Green
} else {
    Write-Ok "cloudflared ya instalado"
}

# ── Script principal del agente ───────────────────────────────
$runScript = @'
# Ventas POS Camera Agent — run.ps1
$AgentDir  = "$env:USERPROFILE\.ventas-agent"
$Token     = Get-Content "$AgentDir\token.txt"    -Raw
$ApiBase   = Get-Content "$AgentDir\api_base.txt" -Raw
$MtxBin    = "$AgentDir\mediamtx.exe"
$CfBin     = "$AgentDir\cloudflared.exe"
$MtxCfg    = "$AgentDir\mediamtx.yml"
$CfLog     = "$AgentDir\cloudflare.log"
$LogFile   = "$AgentDir\agent.log"

function Log($msg) {
    $ts = Get-Date -Format "HH:mm:ss"
    $line = "[$ts] $msg"
    Add-Content $LogFile $line
    Write-Host $line
}

function Get-AgentConfig {
    $headers = @{ "X-Agent-Token" = $Token.Trim() }
    return Invoke-RestMethod -Uri "$($ApiBase.Trim())/agent/config" -Headers $headers
}

function Write-MtxConfig($cfg) {
    $paths = ""
    foreach ($cam in $cfg.cameras) {
        $paths += "  $($cam.path):`n"
        $paths += "    source: $($cam.source)`n"
        $paths += "    sourceOnDemand: yes`n"
        $paths += "    sourceOnDemandStartTimeout: 10s`n"
        $paths += "    sourceOnDemandCloseAfter: 60s`n"
    }
    $yml = @"
hlsAddress: :8888
hlsAlwaysRemux: yes
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
$paths
"@
    $yml | Out-File $MtxCfg -Encoding utf8
    Log "Config generada con $($cfg.cameras.Count) camara(s)"
}

$mtxProc = $null
$cfProc  = $null

function Start-Mediamtx {
    if ($mtxProc -and -not $mtxProc.HasExited) { $mtxProc.Kill() }
    Start-Sleep 1
    $script:mtxProc = Start-Process -FilePath $MtxBin -ArgumentList $MtxCfg -PassThru -WindowStyle Hidden
    Log "MediaMTX iniciado (PID $($mtxProc.Id))"
}

function Start-Tunnel {
    if ($cfProc -and -not $cfProc.HasExited) { $cfProc.Kill() }
    Remove-Item $CfLog -ErrorAction SilentlyContinue
    Start-Sleep 1
    $script:cfProc = Start-Process -FilePath $CfBin -ArgumentList "tunnel --url http://localhost:8888" -RedirectStandardOutput $CfLog -RedirectStandardError $CfLog -PassThru -WindowStyle Hidden

    $tunnelUrl = ""
    for ($i = 0; $i -lt 20; $i++) {
        Start-Sleep 2
        if (Test-Path $CfLog) {
            $content = Get-Content $CfLog -Raw -ErrorAction SilentlyContinue
            if ($content -match 'https://[a-z0-9\-]+\.trycloudflare\.com') {
                $tunnelUrl = $Matches[0]
                break
            }
        }
    }

    if (-not $tunnelUrl) {
        Log "WARN: No se obtuvo URL del tunnel"
        return
    }

    Log "Tunnel activo: $tunnelUrl"

    try {
        $headers = @{ "X-Agent-Token" = $Token.Trim(); "Content-Type" = "application/json" }
        $body    = @{ mediamtx_url = $tunnelUrl; version = "1.0.0-win" } | ConvertTo-Json
        Invoke-RestMethod -Method POST -Uri "$($ApiBase.Trim())/agent/register" -Headers $headers -Body $body
        Log "URL registrada en el sistema"
    } catch {
        Log "WARN: No se pudo registrar URL: $_"
    }
}

Log "=== Ventas POS Camera Agent iniciando ==="
$cfg = Get-AgentConfig
Write-MtxConfig $cfg
Start-Mediamtx
Start-Tunnel

$lastHash = ($cfg | ConvertTo-Json | Get-FileHash -Algorithm MD5).Hash
$lastCheck = [DateTime]::Now

while ($true) {
    Start-Sleep 30

    # Heartbeat
    try {
        $headers = @{ "X-Agent-Token" = $Token.Trim() }
        Invoke-RestMethod -Method POST -Uri "$($ApiBase.Trim())/agent/heartbeat" -Headers $headers | Out-Null
    } catch {}

    # Revisar cambios cada 2 min
    if (([DateTime]::Now - $lastCheck).TotalSeconds -gt 120) {
        try {
            $newCfg  = Get-AgentConfig
            $newHash = ($newCfg | ConvertTo-Json | Get-FileHash -Algorithm MD5).Hash
            if ($newHash -ne $lastHash) {
                Log "Cambio en camaras detectado — recargando..."
                Write-MtxConfig $newCfg
                Start-Mediamtx
                $lastHash = $newHash
            }
        } catch {}
        $lastCheck = [DateTime]::Now
    }

    # Revisar que procesos siguen vivos
    if ($mtxProc -and $mtxProc.HasExited) {
        Log "MediaMTX se detuvo — reiniciando..."
        $cfg = Get-AgentConfig; Write-MtxConfig $cfg; Start-Mediamtx
    }
    if ($cfProc -and $cfProc.HasExited) {
        Log "Tunnel caido — reiniciando..."
        Start-Tunnel
    }
}
'@

$runScript | Out-File "$AgentDir\run.ps1" -Encoding utf8

# ── Registrar en Task Scheduler (autostart) ───────────────────
Write-Host "  Instalando servicio de inicio automatico..." -NoNewline
try {
    # Eliminar tarea existente si hay
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue

    $action  = New-ScheduledTaskAction `
        -Execute "powershell.exe" `
        -Argument "-NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File `"$AgentDir\run.ps1`""

    $trigger = @(
        $(New-ScheduledTaskTrigger -AtLogOn),
        $(New-ScheduledTaskTrigger -AtStartup)
    )

    $settings = New-ScheduledTaskSettingsSet `
        -ExecutionTimeLimit (New-TimeSpan -Hours 0) `
        -RestartCount 10 `
        -RestartInterval (New-TimeSpan -Minutes 1) `
        -MultipleInstances IgnoreNew

    $principal = New-ScheduledTaskPrincipal `
        -UserId $env:USERNAME `
        -LogonType Interactive `
        -RunLevel Highest

    Register-ScheduledTask `
        -TaskName $TaskName `
        -Action $action `
        -Trigger $trigger `
        -Settings $settings `
        -Principal $principal `
        -Description "Ventas POS Camera Agent" | Out-Null

    # Iniciar ahora
    Start-ScheduledTask -TaskName $TaskName
    Write-Host " OK" -ForegroundColor Green
} catch {
    Write-Warn "No se pudo registrar tarea automatica: $_"
    Write-Warn "Ejecuta manualmente: powershell -File `"$AgentDir\run.ps1`""
}

Write-Host ""
Write-Host "  +==========================================+" -ForegroundColor Green
Write-Host "  |   Agente instalado correctamente        |" -ForegroundColor Green
Write-Host "  +==========================================+" -ForegroundColor Green
Write-Host ""
Write-Host "  Sucursal : $($config.sucursal)" -ForegroundColor White
Write-Host "  Logs     : $AgentDir\agent.log" -ForegroundColor Gray
Write-Host ""
Write-Host "  El agente inicia automaticamente con Windows." -ForegroundColor White
Write-Host "  Las camaras apareceran en tu cuenta de Ventas POS" -ForegroundColor White
Write-Host "  desde cualquier dispositivo en 1-2 minutos." -ForegroundColor White
Write-Host ""
Read-Host "  Presiona Enter para cerrar"
