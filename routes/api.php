<?php

use App\Http\Controllers\Api\SuperAdmin\EmpresaController as SuperAdminEmpresaController;
use App\Http\Controllers\Api\SuperAdmin\PlanController as SuperAdminPlanController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\AbonoController;
use App\Http\Controllers\Api\CotizacionController;
use App\Http\Controllers\Api\PedidoController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CajaController;
use App\Http\Controllers\Api\CategoriaController;
use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\Api\ConfiguracionController;
use App\Http\Controllers\Api\CortesController;
use App\Http\Controllers\Api\MercadoLibreController;
use App\Http\Controllers\Api\ProductoController;
use App\Http\Controllers\Api\RegisterController;
use App\Http\Controllers\Api\UsuarioController;
use App\Http\Controllers\Api\VentaController;
use App\Http\Controllers\Api\ReportesController;
use App\Http\Controllers\Api\ProveedorController;
use App\Http\Controllers\Api\PagoProveedorController;
use App\Http\Controllers\Api\RolPermisoController;
use App\Http\Controllers\Api\FacturacionController;
use App\Http\Controllers\Api\SucursalController;
use Illuminate\Support\Facades\Route;

Route::post('register', [RegisterController::class, 'register']);

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot-password', [PasswordResetController::class, 'forgotPassword']);
    Route::post('reset-password',  [PasswordResetController::class, 'resetPassword']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

// Billing sin check.trial — el tenant siempre debe poder ver su plan y pagar
Route::middleware('auth:sanctum')->group(function () {
    Route::get('planes/mi-plan', [BillingController::class, 'miPlan']);
    Route::post('checkout/crear-sesion', [BillingController::class, 'crearSesion']);
    Route::post('billing/portal', [BillingController::class, 'crearPortal']);
});

Route::middleware(['auth:sanctum', 'check.trial'])->group(function () {

    // Dashboard
    Route::middleware('can:ver dashboard')->group(function () {
    });

    // Productos
    Route::middleware('can:ver productos')->group(function () {
        Route::get('productos/buscar', [ProductoController::class, 'buscar']);
        Route::get('productos', [ProductoController::class, 'index']);
        Route::get('productos/{producto}', [ProductoController::class, 'show']);
    });
    Route::middleware('can:gestionar productos')->group(function () {
        Route::post('productos', [ProductoController::class, 'store']);
        Route::post('productos/importar', [ProductoController::class, 'importar']);
        Route::put('productos/{producto}', [ProductoController::class, 'update']);
        Route::delete('productos/{producto}', [ProductoController::class, 'destroy']);
        Route::post('productos/{producto}/imprimir-etiqueta', [ProductoController::class, 'imprimirEtiqueta']);
    });

    // Categorías
    Route::middleware('can:ver categorias')->group(function () {
        Route::get('categorias', [CategoriaController::class, 'index']);
        Route::get('categorias/{categoria}', [CategoriaController::class, 'show']);
    });
    Route::middleware('can:gestionar categorias')->group(function () {
        Route::post('categorias', [CategoriaController::class, 'store']);
        Route::put('categorias/{categoria}', [CategoriaController::class, 'update']);
        Route::delete('categorias/{categoria}', [CategoriaController::class, 'destroy']);
    });

    // Clientes
    Route::middleware('can:ver clientes')->group(function () {
        Route::get('clientes', [ClienteController::class, 'index']);
        Route::get('clientes/{cliente}', [ClienteController::class, 'show']);
        Route::get('clientes/{cliente}/resumen', [AbonoController::class, 'resumen']);
    });
    Route::middleware('can:gestionar clientes')->group(function () {
        Route::post('clientes', [ClienteController::class, 'store']);
        Route::put('clientes/{cliente}', [ClienteController::class, 'update']);
        Route::delete('clientes/{cliente}', [ClienteController::class, 'destroy']);
    });

    // Abonos
    Route::middleware('can:ver abonos')->group(function () {
        Route::get('abonos', [AbonoController::class, 'index']);
    });
    Route::middleware('can:gestionar abonos')->group(function () {
        Route::post('abonos', [AbonoController::class, 'store']);
    });

    // Ventas
    Route::middleware('can:ver ventas')->group(function () {
        Route::get('ventas', [VentaController::class, 'index']);
        Route::get('ventas/{venta}', [VentaController::class, 'show']);
        Route::get('ventas/{venta}/ticket', [VentaController::class, 'ticket']);
        Route::post('ventas/{venta}/imprimir-termico', [VentaController::class, 'imprimirTermico']);
     });
    Route::middleware('can:realizar ventas')->group(function () {
        Route::post('ventas', [VentaController::class, 'store']);
    });
    Route::middleware('can:cancelar ventas')->group(function () {
        Route::post('ventas/{venta}/cancelar', [VentaController::class, 'cancelar']);
    });

    // Caja
    Route::prefix('caja')->group(function () {
        Route::get('actual', [CajaController::class, 'actual']);
        Route::post('abrir', [CajaController::class, 'abrir']);
        Route::post('cerrar', [CajaController::class, 'cerrar']);
        Route::get('cortes', [CajaController::class, 'cortes']);
        Route::get('cortes/{caja}', [CajaController::class, 'corte']);
    });

    // Cortes
    Route::middleware('can:ver cortes')->group(function () {
        Route::get('cortes/hoy', [CortesController::class, 'hoy']);
        Route::get('cortes/{id}/ticket', [CortesController::class, 'ticket']);
    });
    Route::middleware('can:gestionar cortes')->group(function () {
        Route::post('cortes/generar', [CortesController::class, 'generar']);
    });

    // Reportes
    Route::middleware('can:ver reportes')->group(function () {
        Route::prefix('reportes')->group(function () {
            Route::get('general', [ReportesController::class, 'general']);
            Route::get('ventas-por-dia', [ReportesController::class, 'ventasPorDia']);
            Route::get('productos-top', [ReportesController::class, 'productosTop']);
            Route::get('categorias', [ReportesController::class, 'categoriasReporte']);
            Route::get('stock-bajo', [ReportesController::class, 'stockBajo']);
        });
    });

    // Proveedores
    Route::middleware('can:ver proveedores')->group(function () {
        Route::get('proveedores', [ProveedorController::class, 'index']);
        Route::get('proveedores/{proveedor}', [ProveedorController::class, 'show']);
    });
    Route::middleware('can:gestionar proveedores')->group(function () {
        Route::post('proveedores', [ProveedorController::class, 'store']);
        Route::put('proveedores/{proveedor}', [ProveedorController::class, 'update']);
        Route::patch('proveedores/{proveedor}/toggle', [ProveedorController::class, 'toggle']);
        Route::delete('proveedores/{proveedor}', [ProveedorController::class, 'destroy']);
    });

    // Pagos a proveedores
    Route::middleware('can:ver pagos proveedores')->group(function () {
        Route::get('pagos-proveedores', [PagoProveedorController::class, 'index']);
    });
    Route::middleware('can:gestionar pagos proveedores')->group(function () {
        Route::post('pagos-proveedores', [PagoProveedorController::class, 'store']);
        Route::put('pagos-proveedores/{pagoProveedor}', [PagoProveedorController::class, 'update']);
        Route::delete('pagos-proveedores/{pagoProveedor}', [PagoProveedorController::class, 'destroy']);
    });

    // Usuarios
    Route::middleware('can:ver usuarios')->group(function () {
        Route::get('usuarios', [UsuarioController::class, 'index']);
        Route::get('usuarios/{usuario}', [UsuarioController::class, 'show']);
    });
    Route::middleware('can:gestionar usuarios')->group(function () {
        Route::post('usuarios', [UsuarioController::class, 'store']);
        Route::put('usuarios/{usuario}', [UsuarioController::class, 'update']);
        Route::patch('usuarios/{usuario}/toggle', [UsuarioController::class, 'toggle']);
        Route::delete('usuarios/{usuario}', [UsuarioController::class, 'destroy']);
    });

    // Configuración
    Route::middleware('can:gestionar configuracion')->group(function () {
        Route::get('configuracion', [ConfiguracionController::class, 'show']);
        Route::put('configuracion', [ConfiguracionController::class, 'update']);
        Route::patch('configuracion', [ConfiguracionController::class, 'update']);
        Route::post('configuracion/logo', [ConfiguracionController::class, 'uploadLogo']);
        Route::delete('configuracion/logo', [ConfiguracionController::class, 'deleteLogo']);
    });

    // Cotizaciones
    Route::middleware('can:ver cotizaciones')->group(function () {
        Route::get('cotizaciones', [CotizacionController::class, 'index']);
        Route::get('cotizaciones/{cotizacion}', [CotizacionController::class, 'show']);
        Route::get('cotizaciones/{cotizacion}/ticket', [CotizacionController::class, 'ticket']);
    });
    Route::middleware('can:gestionar cotizaciones')->group(function () {
        Route::post('cotizaciones', [CotizacionController::class, 'store']);
        Route::put('cotizaciones/{cotizacion}', [CotizacionController::class, 'update']);
        Route::delete('cotizaciones/{cotizacion}', [CotizacionController::class, 'destroy']);
        Route::post('cotizaciones/{cotizacion}/convertir', [CotizacionController::class, 'convertir']);
        Route::post('cotizaciones/{cotizacion}/convertir-pedido', [CotizacionController::class, 'convertirAPedido']);
        Route::post('cotizaciones/{cotizacion}/enviar', [CotizacionController::class, 'enviar']);
    });

    // Pedidos
    Route::middleware('can:ver pedidos')->group(function () {
        Route::get('pedidos', [PedidoController::class, 'index']);
        Route::get('pedidos/{pedido}', [PedidoController::class, 'show']);
    });
    Route::middleware('can:gestionar pedidos')->group(function () {
        Route::post('pedidos', [PedidoController::class, 'store']);
        Route::put('pedidos/{pedido}', [PedidoController::class, 'update']);
        Route::patch('pedidos/{pedido}/status', [PedidoController::class, 'cambiarStatus']);
        Route::delete('pedidos/{pedido}', [PedidoController::class, 'destroy']);
        Route::post('pedidos/{pedido}/enviar', [PedidoController::class, 'enviar']);
        Route::post('pedidos/{pedido}/recordar', [PedidoController::class, 'recordar']);
    });

    // Sucursales
    Route::middleware('can:ver sucursales')->group(function () {
        Route::get('sucursales', [SucursalController::class, 'index']);
        Route::get('sucursales/{sucursal}', [SucursalController::class, 'show']);
        Route::get('sucursales/{sucursal}/usuarios', [SucursalController::class, 'usuarios']);
    });
    Route::middleware('can:gestionar sucursales')->group(function () {
        Route::post('sucursales', [SucursalController::class, 'store']);
        Route::put('sucursales/{sucursal}', [SucursalController::class, 'update']);
        Route::delete('sucursales/{sucursal}', [SucursalController::class, 'destroy']);
        Route::post('sucursales/{sucursal}/usuarios', [SucursalController::class, 'addUsuario']);
        Route::delete('sucursales/{sucursal}/usuarios/{usuario}', [SucursalController::class, 'removeUsuario']);
        Route::post('sucursales/{sucursal}/importar-catalogo', [SucursalController::class, 'importarCatalogo']);
    });

    // Roles y permisos
    Route::middleware('can:gestionar roles')->group(function () {
        Route::apiResource('roles', RolPermisoController::class)->except(['show']);
        Route::get('permisos', [RolPermisoController::class, 'permisos']);
    });

    // Facturación CFDI
    Route::prefix('facturacion')->group(function () {
        Route::post('setup',      [FacturacionController::class, 'setup']);
        Route::post('upload-csd', [FacturacionController::class, 'uploadCsd']);
        Route::post('test',       [FacturacionController::class, 'test']);
        Route::post('keys',       [FacturacionController::class, 'saveKeys']);
        Route::post('reset',           [FacturacionController::class, 'reset']);
        Route::post('actualizar-legal', [FacturacionController::class, 'actualizarLegal']);
        Route::post('confirmar-csd',    [FacturacionController::class, 'confirmarCsd']);
    });
    Route::middleware('can:gestionar facturacion')->group(function () {
        Route::post('ventas/{venta}/facturar',      [FacturacionController::class, 'facturar']);
        Route::post('ventas/{venta}/cfdi/cancelar', [FacturacionController::class, 'cancelarCfdi']);
        Route::post('ventas/{venta}/cfdi/reenviar', [FacturacionController::class, 'reenviarEmail']);
    });
    Route::middleware('can:ver facturacion')->group(function () {
        Route::get('ventas/{venta}/cfdi/xml',       [FacturacionController::class, 'downloadXml']);
        Route::get('ventas/{venta}/cfdi/pdf',       [FacturacionController::class, 'downloadPdf']);
    });

    // Mercado Libre
    Route::prefix('mercado-libre')->group(function () {

        Route::middleware('can:gestionar mercado libre')->group(function () {
            Route::get('config', [MercadoLibreController::class, 'config']);
            Route::post('config', [MercadoLibreController::class, 'saveConfig']);
            Route::get('auth-url', [MercadoLibreController::class, 'authUrl']);
            Route::post('disconnect', [MercadoLibreController::class, 'disconnect']);
            Route::post('refresh', [MercadoLibreController::class, 'refresh']);
            Route::post('sync-all-stock', [MercadoLibreController::class, 'syncAllStock']);
            Route::get('categories', [MercadoLibreController::class, 'searchCategories']);
            Route::get('site-categories', [MercadoLibreController::class, 'getSiteCategories']);
            Route::get('categories/{categoryId}/children', [MercadoLibreController::class, 'getCategoryChildren']);
            Route::get('categories/{categoryId}/attributes', [MercadoLibreController::class, 'getCategoryAttributes']);
            Route::get('categories/{categoryId}', [MercadoLibreController::class, 'getCategoryInfo']);
            Route::get('products', [MercadoLibreController::class, 'productsMeli']);
            Route::post('create-test-user', [MercadoLibreController::class, 'createTestUser']);
            Route::post('toggle-sandbox', [MercadoLibreController::class, 'toggleSandbox']);
            Route::post('clear-test-user', [MercadoLibreController::class, 'clearTestUser']);
        });

        Route::middleware('can:ver mercado libre')->group(function () {
            Route::post('productos/{producto}/publish', [MercadoLibreController::class, 'publish']);
            Route::post('productos/{producto}/sync-stock', [MercadoLibreController::class, 'syncStock']);
            Route::post('productos/{producto}/sync-price', [MercadoLibreController::class, 'syncPrice']);
            Route::post('productos/{producto}/pause', [MercadoLibreController::class, 'pause']);
            Route::post('productos/{producto}/reactivate', [MercadoLibreController::class, 'reactivate']);
            Route::delete('productos/{producto}/unlink', [MercadoLibreController::class, 'unlink']);
            Route::get('productos/{producto}/info', [MercadoLibreController::class, 'productInfo']);
            Route::get('mis-publicaciones', [MercadoLibreController::class, 'misPublicaciones']);
            Route::post('importar', [MercadoLibreController::class, 'importarProductos']);
            Route::get('kits/{productoMeli}', [MercadoLibreController::class, 'getKitComponentes']);
            Route::post('kits/{productoMeli}', [MercadoLibreController::class, 'setKitComponentes']);
            Route::delete('kits/{productoMeli}', [MercadoLibreController::class, 'deleteKitComponentes']);
        });
    });
});

// SuperAdmin — solo accesible para is_superadmin = true
Route::middleware(['auth:sanctum', 'superadmin'])->prefix('superadmin')->group(function () {
    Route::get('empresas', [SuperAdminEmpresaController::class, 'index']);
    Route::post('empresas', [SuperAdminEmpresaController::class, 'store']);
    Route::get('empresas/{empresa}', [SuperAdminEmpresaController::class, 'show']);
    Route::put('empresas/{empresa}', [SuperAdminEmpresaController::class, 'update']);
    Route::delete('empresas/{empresa}', [SuperAdminEmpresaController::class, 'destroy']);
    Route::get('empresas/{empresa}/modulos', [SuperAdminEmpresaController::class, 'modulos']);
    Route::post('empresas/{empresa}/modulos/toggle', [SuperAdminEmpresaController::class, 'toggleModulo']);
    Route::post('empresas/{empresa}/modulos', [SuperAdminEmpresaController::class, 'setModulos']);
    Route::post('empresas/{empresa}/asignar-plan', [SuperAdminEmpresaController::class, 'asignarPlan']);

    // Planes CRUD
    Route::get('planes', [SuperAdminPlanController::class, 'index']);
    Route::post('planes', [SuperAdminPlanController::class, 'store']);
    Route::get('planes/{plan}', [SuperAdminPlanController::class, 'show']);
    Route::put('planes/{plan}', [SuperAdminPlanController::class, 'update']);
    Route::delete('planes/{plan}', [SuperAdminPlanController::class, 'destroy']);
});

// Stripe Webhook — NO auth, verificación por firma
Route::post('webhook/stripe', [WebhookController::class, 'stripe']);

// Planes públicos — NO auth, para landing page
Route::get('planes', [BillingController::class, 'planesPublicos']);

// Mercado Libre OAuth callback (no auth required - ML redirects here)
Route::get('mercado-libre/callback', [MercadoLibreController::class, 'callback'])->name('mercado-libre.callback');

// Mercado Libre webhook (no auth required - ML sends directly)
Route::post('mercado-libre/webhook', [MercadoLibreController::class, 'webhook']);
