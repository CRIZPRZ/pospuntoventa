<?php

namespace App\Console\Commands;

use App\Models\MercadoLibreConfig;
use App\Services\MercadoLibreService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RefreshMercadoLibreToken extends Command
{
    protected $signature = 'ml:refresh-token';
    protected $description = 'Refresh Mercado Libre OAuth token if expiring soon';

    public function __construct(
        protected MercadoLibreService $mlService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $config = MercadoLibreConfig::first();

        if (! $config) {
            $this->info('No hay configuración de Mercado Libre');
            return Command::SUCCESS;
        }

        if (! $config->access_token) {
            $this->warn('No hay token de acceso configurado');
            return Command::SUCCESS;
        }

        if (! $config->isTokenExpiringSoon()) {
            $this->info('El token sigue válido, no necesita refresh');
            return Command::SUCCESS;
        }

        try {
            $this->info('Refrescando token de Mercado Libre...');
            $this->mlService->refreshToken($config);
            $this->info('Token refrescado exitosamente');
            Log::info('Token de ML refrescado por comando programado');
        } catch (\Exception $e) {
            $this->error("Error al refrescar token: {$e->getMessage()}");
            Log::error("Error refrescando token de ML: {$e->getMessage()}");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
