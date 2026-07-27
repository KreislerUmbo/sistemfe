<?php
// database/migrations/2026_07_27_150000_create_proveedor_tipos_and_temporadas_tables.php
//
// Sesión 1 del vertical Agencia de Viajes (plan-hoja-de-ruta-ejecucion.md) —
// plan-modulo-proveedores.md §2.6 y su sección "Lo que sigue pendiente de
// verdad" (24-jul-2026): ambos catálogos son centrales (compartidos entre
// tenants del mismo giro, mismo criterio que tipos_comprobante/note_motivos/
// detraction_codes/tax_configs), pero a diferencia de esos, NO son
// universales — son exclusivos del vertical agencia de viajes. Por eso
// llevan columna `giro` explícita en cada fila (NOT NULL, sin default): deja
// el filtro en el dato mismo, no solo implícito por qué código se ejecuta, y
// permite que un vertical futuro tenga su propio concepto de "temporadas"/
// "tipos de proveedor" sin pisarse.
//
// Alcance de esta migración: SOLO proveedor_tipos y temporadas.
// proveedor_tipos_config (por tenant) y temporada_ocurrencias quedan para
// sesiones posteriores (Sesión 3 en adelante) — no se tocan acá.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Explícito, mismo patrón que add_giro_tipo_sunat_modo_to_tenants_table.php
    // (Sesión 0) — no depende de DB_CONNECTION/DB_DATABASE (ver comentario en
    // config/database.php sobre por qué 'central' es una clave fija).
    protected $connection = 'central';

    public function up(): void
    {
        Schema::create('proveedor_tipos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre'); // ej. "Hotel", "Transporte", "Mayorista", "Guía"
            $table->string('slug')->unique();
            $table->string('giro'); // NOT NULL, sin default — cada fila lo especifica explícito al insertarse
            $table->boolean('activo')->default(true); // baja a nivel de todo el sistema, no por tenant
            $table->timestamps();
        });

        Schema::create('temporadas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre'); // ej. "Temporada Alta", "Fiestas Patrias", "Navidad y Año Nuevo"
            $table->string('tipo'); // 'fija' (mismo rango cada año) | 'movil' (varía por año, ej. Semana Santa)
            $table->string('giro'); // NOT NULL, sin default — mismo criterio que proveedor_tipos.giro
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temporadas');
        Schema::dropIfExists('proveedor_tipos');
    }
};
