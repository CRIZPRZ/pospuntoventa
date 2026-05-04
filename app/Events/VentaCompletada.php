<?php

namespace App\Events;

use App\Models\Venta;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VentaCompletada
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Venta $venta,
        public array $productoIds = [],
    ) {}
}
