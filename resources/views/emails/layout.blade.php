<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>@yield('title', 'Ventas POS')</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 16px;">
    <tr>
      <td align="center">
        <table width="100%" style="max-width:580px;background:white;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.06);">

          {{-- Header --}}
          <tr>
            <td style="background:#2563eb;padding:32px 40px;text-align:center;">
              <table cellpadding="0" cellspacing="0" style="margin:0 auto;">
                <tr>
                  <td style="background:rgba(255,255,255,0.2);border-radius:12px;padding:10px 16px;display:inline-block;">
                    <span style="color:white;font-size:22px;font-weight:900;letter-spacing:-0.5px;">V</span>
                    <span style="color:white;font-size:17px;font-weight:700;margin-left:8px;">Ventas POS</span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          {{-- Body --}}
          <tr>
            <td style="padding:40px 40px 32px;">
              @yield('content')
            </td>
          </tr>

          {{-- Footer --}}
          <tr>
            <td style="background:#f8fafc;padding:24px 40px;border-top:1px solid #e2e8f0;text-align:center;">
              <p style="margin:0;font-size:12px;color:#94a3b8;line-height:1.6;">
                Ventas POS · Sistema punto de venta para México<br/>
                Si tienes dudas escríbenos a
                <a href="mailto:c.lira.prz@gmail.com" style="color:#2563eb;text-decoration:none;">c.lira.prz@gmail.com</a>
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
