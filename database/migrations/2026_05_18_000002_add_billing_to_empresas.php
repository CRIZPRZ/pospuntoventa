<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->foreignId('plan_id')->nullable()->constrained('planes')->nullOnDelete();
            $table->timestamp('plan_vigente_hasta')->nullable();
            $table->string('plan_estado')->default('sin_plan'); // sin_plan, trial, activo, vencido
            $table->decimal('plan_precio_pactado', 10, 2)->nullable(); // override del precio del plan
            $table->string('stripe_customer_id')->nullable()->unique();
            $table->string('stripe_subscription_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropForeign(['plan_id']);
            $table->dropColumn(['plan_id', 'plan_vigente_hasta', 'plan_estado', 'plan_precio_pactado', 'stripe_customer_id', 'stripe_subscription_id']);
        });
    }
};
