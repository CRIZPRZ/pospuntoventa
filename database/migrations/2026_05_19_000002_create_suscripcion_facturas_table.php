<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suscripcion_facturas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('stripe_invoice_id')->nullable()->index();
            $table->string('cfdi_uuid')->nullable();
            $table->string('cfdi_facturapi_id')->nullable();
            $table->longText('cfdi_xml')->nullable();
            $table->string('cfdi_status')->default('timbrado'); // timbrado, cancelado
            $table->json('receptor')->nullable();               // RFC, nombre, CP, régimen, uso_cfdi
            $table->decimal('monto', 12, 2)->default(0);
            $table->string('moneda', 10)->default('MXN');
            $table->string('concepto')->nullable();             // descripción del pago
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suscripcion_facturas');
    }
};
