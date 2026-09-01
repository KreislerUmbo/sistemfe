<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_09_01_130000_create_contenido_tour_table.php
//
// Sesión 12e (auditoria-arquitectonica-agencia-viajes.md §9.1) — contenido
// reutilizable (descripción/fotos) desacoplado del precio del mayorista:
// el precio de un tour internacional siempre lo fija el mayorista
// cotización por cotización, pero la descripción/fotos se repiten. SIN
// precio, SIN moneda, SIN vigencia a propósito — eso vive siempre en la
// oferta del mayorista (OpcionMayorista/OpcionMayoristaOpcional).
//
// destino_atractivo_id nullable: el buscador de esta sesión (frontend,
// cotizador/editar.vue) no filtra por destino todavía (esa selección de
// destino activo llega recién en 12f) — no forzar un dato que la UI no
// puede proveer confiablemente hoy. activo (reversible, default true) sin
// endpoint de toggle en esta sesión — mismo campo que ya usan
// proveedor_tarifas/guia_tarifas, se conecta cuando haga falta gestión.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contenido_tour', function (Blueprint $table) {
            $table->id();
            $table->foreignId('destino_atractivo_id')->nullable()->constrained('destinos_atractivos')->nullOnDelete();
            $table->string('categoria'); // 'incluido' | 'opcional' | 'excursion'
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->text('incluye')->nullable();
            $table->text('no_incluye')->nullable();
            $table->json('fotos')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contenido_tour');
    }
};
