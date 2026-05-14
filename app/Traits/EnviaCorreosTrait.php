<?php

namespace App\Traits;

use App\Models\Configuracion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

trait EnviaCorreosTrait
{
    protected function tenantId(): int|string
    {
        return app()->bound('tenant_id') ? (int) app('tenant_id') : 0;
    }

    protected function getConfig(): array
    {
        $cacheKey = 'ventas_configuracion_' . $this->tenantId();
        $cached = Cache::get($cacheKey);
        if ($cached) return $cached;

        $row = Configuracion::where('empresa_id', $this->tenantId())->first();
        return $row ? $row->config : [];
    }

    protected function getNotifConfig(): array
    {
        return $this->getConfig()['notificaciones'] ?? [];
    }

    protected function getCorreosCC(): array
    {
        return $this->getNotifConfig()['correos_cc'] ?? [];
    }

    protected function notifAlCrear(): bool
    {
        return (bool) ($this->getNotifConfig()['notif_al_crear'] ?? true);
    }

    protected function getLogoUrl(): ?string
    {
        $dir   = 'config/' . $this->tenantId();
        $files = Storage::disk('public')->files($dir);
        foreach ($files as $file) {
            if (str_starts_with(basename($file), 'logo_')) {
                return asset('storage/' . $dir . '/' . basename($file));
            }
        }
        return null;
    }

    protected function enviarConCC(string $to, string $subject, string $html): void
    {
        $cc = $this->getCorreosCC();

        Mail::html($html, function ($message) use ($to, $subject, $cc) {
            $message->to($to)->subject($subject);
            if (!empty($cc)) {
                $message->cc($cc);
            }
        });
    }

    protected function buildEmailWrapper(string $contentHtml, string $subtitulo = '', array $overrides = []): string
    {
        $notif   = $this->getNotifConfig();
        $empresa = $this->getConfig()['empresa'] ?? [];
        $config  = $this->getConfig();

        $color       = $overrides['color']      ?? $notif['color_primario'] ?? '#2563eb';
        $encabezado  = $overrides['encabezado'] ?? $notif['encabezado']    ?? '¡Gracias por su preferencia!';
        $intro       = $overrides['intro']      ?? $notif['intro']         ?? '';
        $pie         = $notif['pie']           ?? '';
        $mostrarLogo = (bool) ($notif['mostrar_logo'] ?? true);
        $logoUrl     = $mostrarLogo ? $this->getLogoUrl() : null;
        $nombreEmp   = htmlspecialchars($empresa['nombre'] ?? 'Mi Empresa');

        $logoHtml = $logoUrl
            ? "<img src=\"{$logoUrl}\" alt=\"logo\" style=\"max-height:48px;max-width:160px;object-fit:contain;display:block;margin:0 auto 8px\">"
            : '';

        $introHtml = $intro
            ? "<p style=\"color:#6b7280;font-size:14px;margin:0 0 20px\">" . htmlspecialchars($intro) . "</p>"
            : '';

        $subtituloHtml = $subtitulo
            ? "<p style=\"color:{$color};font-size:12px;font-weight:600;margin:0 0 4px;text-transform:uppercase;letter-spacing:.05em\">" . htmlspecialchars($subtitulo) . "</p>"
            : '';

        $pieHtml = $pie
            ? "<p style=\"margin:0;color:#9ca3af;font-size:11px\">" . htmlspecialchars($pie) . "</p>"
            : "<p style=\"margin:0;color:#9ca3af;font-size:11px\">{$nombreEmp}</p>";

        return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$encabezado}</title></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 16px">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%">

      <!-- Header -->
      <tr><td style="background:{$color};border-radius:12px 12px 0 0;padding:28px 32px;text-align:center">
        {$logoHtml}
        <h1 style="margin:0;color:white;font-size:20px;font-weight:700">{$nombreEmp}</h1>
        {$subtituloHtml}
      </td></tr>

      <!-- Body -->
      <tr><td style="background:white;padding:32px;border-left:1px solid #e5e7eb;border-right:1px solid #e5e7eb">
        <h2 style="margin:0 0 8px;color:#111827;font-size:18px">{$encabezado}</h2>
        {$introHtml}
        {$contentHtml}
      </td></tr>

      <!-- Footer -->
      <tr><td style="background:#f9fafb;padding:20px 32px;text-align:center;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 12px 12px">
        {$pieHtml}
      </td></tr>

    </table>
  </td></tr>
</table>
</body></html>
HTML;
    }
}
