<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Dominio de correo de cada agencia autenticado en Brevo
// (plan-modulo-envio-correo-cotizacion.md §3.1). Central porque lo maneja el
// Superadmin y la API key de Brevo solo vive en el entorno central. Un dominio
// por tenant en la primera entrega (V1: cuenta Brevo de UmboSystem).
class CreateTenantDominiosCorreoTable extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::create('tenant_dominios_correo', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->unique();
            $table->string('dominio', 190);
            // pendiente: registrado en Brevo, esperando los DNS de la agencia.
            // verificado: Brevo confirmó la autenticación. fallido: el último chequeo no pasó.
            $table->string('brevo_estado', 20)->default('pendiente');
            $table->json('dns_records')->nullable();
            $table->text('ultimo_error')->nullable();
            $table->timestamp('verificado_en')->nullable();
            $table->timestamp('ultimo_chequeo_en')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_dominios_correo');
    }
}
