<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('camaras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->string('nombre');
            $table->enum('tipo', ['ip', 'usb', 'mjpeg', 'aws_kinesis'])->default('ip');
            $table->string('url_rtsp')->nullable();
            $table->string('usuario')->nullable();
            $table->string('password')->nullable();
            $table->string('url_stream')->nullable()->comment('HLS/MJPEG URL directa si no usa RTSP');
            $table->string('aws_stream_name')->nullable()->comment('Kinesis Video Stream name');
            $table->string('aws_region')->nullable();
            $table->boolean('activo')->default(true);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('camaras');
    }
};
