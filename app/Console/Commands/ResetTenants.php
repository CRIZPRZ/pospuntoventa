<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Services\BaileysWhatsAppService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Borra TODOS los tenants y sus datos, dejando la plataforma limpia.
 *
 * Conserva: superadmins, planes (con sus stripe_price_id), permisos,
 * superadmin_config, stripe_webhook_events y el instalador desktop.
 *
 * Usa DELETE (no TRUNCATE) a propósito: los AUTO_INCREMENT no se reinician,
 * así una empresa nueva nunca reutiliza el id de una borrada. Varias cosas
 * viven fuera de MySQL amarradas al id (sesión Baileys "empresa-{id}", logo en
 * storage "config/{id}", cache "ventas_configuracion_{id}"); con ids nuevos no
 * hay forma de que un tenant nuevo herede las de uno viejo. Aun así se limpian.
 */
class ResetTenants extends Command
{
    protected $signature = 'tenants:reset
        {--execute : Ejecuta el borrado (sin esta opción solo muestra lo que haría)}
        {--confirm= : Texto de confirmación para modo no interactivo: "BORRAR TODO"}
        {--ignore-stripe : Continuar aunque haya empresas con suscripción Stripe}';

    protected $description = 'Elimina todos los tenants y sus datos (conserva superadmins, planes y permisos)';

    /** Tablas globales que NO se tocan. `users` y `personal_access_tokens` se filtran aparte. */
    private const KEEP = [
        'migrations',
        'planes',
        'permissions',
        'superadmin_config',
        'stripe_webhook_events',
        'users',
        'personal_access_tokens',
    ];

    /** Carpetas del disco public con archivos de tenants. `desktop/` (instalador) se conserva. */
    private const STORAGE_DIRS = ['config', 'productos'];

    private const CONFIRM_TEXT = 'BORRAR TODO';

    public function handle(): int
    {
        $tables     = $this->tablesToWipe();
        $superIds   = User::withoutGlobalScopes()->where('is_superadmin', true)->pluck('id');
        $empresas   = Empresa::withoutGlobalScopes()->count();
        $conStripe  = Empresa::withoutGlobalScopes()->whereNotNull('stripe_subscription_id')->get(['id', 'nombre', 'stripe_subscription_id']);
        $sesiones   = WhatsAppConfig::withoutGlobalScopes()->where('provider', 'baileys')->whereNotNull('session_key')->get();

        $this->warn('== Reset de tenants ' . ($this->option('execute') ? '(EJECUCIÓN)' : '(SIMULACIÓN — no borra nada)') . ' ==');
        $this->line("Base de datos: " . DB::connection()->getDatabaseName());
        $this->line("Empresas a borrar: {$empresas}");
        $this->line('Superadmins que se conservan: ' . $superIds->count()
            . ' (' . User::withoutGlobalScopes()->whereIn('id', $superIds)->pluck('email')->implode(', ') . ')');
        $this->line('Usuarios de tenants a borrar: ' . User::withoutGlobalScopes()->whereNotIn('id', $superIds)->count());
        $this->line('Sesiones de WhatsApp (Baileys) a cerrar: ' . $sesiones->count());

        $this->newLine();
        $this->table(['Tabla a vaciar', 'Filas'], collect($tables)->map(fn ($t) => [$t, DB::table($t)->count()])->all());
        $this->line('Se conservan: ' . implode(', ', self::KEEP) . ' (users/tokens solo de superadmins)');
        $this->line('Storage (disco public) a borrar: ' . implode('/, ', self::STORAGE_DIRS) . '/   — se conserva desktop/');

        if ($superIds->isEmpty()) {
            $this->error('No hay ningún usuario superadmin: abortando para no dejar la plataforma sin acceso.');
            return self::FAILURE;
        }

        if ($conStripe->isNotEmpty()) {
            $this->newLine();
            $this->error('Empresas con suscripción en Stripe (borrarlas aquí NO cancela el cobro):');
            $this->table(['id', 'empresa', 'subscription'], $conStripe->map(fn ($e) => [$e->id, $e->nombre, $e->stripe_subscription_id])->all());
            if (!$this->option('ignore-stripe')) {
                $this->error('Cancélalas primero en el dashboard de Stripe y vuelve a correr con --ignore-stripe.');
                return self::FAILURE;
            }
        }

        if (!$this->option('execute')) {
            $this->newLine();
            $this->info('Simulación terminada. Para borrar de verdad: php artisan tenants:reset --execute');
            return self::SUCCESS;
        }

        $confirm = $this->option('confirm') ?? $this->ask('Esto es IRREVERSIBLE. Escribe "' . self::CONFIRM_TEXT . '" para continuar');
        if ($confirm !== self::CONFIRM_TEXT) {
            $this->error('Confirmación incorrecta. No se borró nada.');
            return self::FAILURE;
        }

        // 1) Cerrar sesiones de WhatsApp: desvincula el dispositivo del teléfono y
        //    borra la sesión del volumen de Baileys (antes de perder los session_key).
        $baileys = app(BaileysWhatsAppService::class);
        foreach ($sesiones as $config) {
            try {
                $baileys->logout($config);
                $this->line("WhatsApp cerrado: {$config->session_key}");
            } catch (\Throwable $e) {
                $this->warn("No se pudo cerrar {$config->session_key}: {$e->getMessage()} (bórrala del volumen manualmente)");
            }
        }

        // 2) Base de datos.
        Schema::disableForeignKeyConstraints();
        try {
            DB::transaction(function () use ($tables, $superIds) {
                foreach ($tables as $table) {
                    DB::table($table)->delete();
                }

                DB::table('users')->whereNotIn('id', $superIds)->delete();
                DB::table('users')->whereIn('id', $superIds)->update(['empresa_id' => null, 'sucursal_id' => null]);

                DB::table('personal_access_tokens')
                    ->where(fn ($q) => $q->where('tokenable_type', '!=', User::class)->orWhereNotIn('tokenable_id', $superIds))
                    ->delete();
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }
        $this->info('Base de datos limpia.');

        // 3) Archivos de tenants (logos, imágenes de productos).
        foreach (self::STORAGE_DIRS as $dir) {
            Storage::disk('public')->deleteDirectory($dir);
        }
        $this->info('Storage de tenants borrado.');

        // 4) Cache (config por empresa/sucursal, merchants de CFDI Express, etc.).
        Cache::flush();
        $this->info('Cache vaciada.');

        $this->newLine();
        $this->info('Listo. Plataforma limpia. Los superadmins, planes y permisos se conservaron.');

        return self::SUCCESS;
    }

    /** Todas las tablas de ESTA base, menos las globales que se conservan. */
    private function tablesToWipe(): array
    {
        // Sin schema, MySQL lista las tablas de TODAS las bases visibles para el usuario
        // (en producción el mismo servidor hospeda otras apps). Limitar a la base actual.
        $connection = DB::connection();
        $schema     = $connection->getDriverName() === 'sqlite' ? 'main' : $connection->getDatabaseName();

        $tables = collect(Schema::getTableListing($schema, false))
            ->reject(fn ($name) => in_array($name, self::KEEP, true))
            ->values()
            ->all();

        sort($tables);

        return $tables;
    }
}
