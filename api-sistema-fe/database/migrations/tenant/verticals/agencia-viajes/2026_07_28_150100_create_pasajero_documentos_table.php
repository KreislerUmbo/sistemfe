<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_28_150100_create_pasajero_documentos_table.php
//
// Sesión 9c del vertical Agencia de Viajes — plan-modulo-cotizaciones-reservas.md
// §6.5.
//
// archivo: SOLO el path de almacenamiento PRIVADO (fuera de carpetas
// públicas/servidas directo) — mismo criterio ya usado para el certificado
// SUNAT (disco `private`, sin symlink público, plan-panel-superadmin.md
// Fase B.2). El endpoint autenticado que sirve el archivo (verifica
// permiso, nunca un link directo descargable/indexable) es Sesión 11, NO
// se modela acá — esta migración solo guarda dónde vive.
//
// pasajero_catalogo_id: FK real a pasajeros_catalogo (esta misma sesión,
// migración anterior).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pasajero_documentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pasajero_catalogo_id')->constrained('pasajeros_catalogo');
            $table->string('tipo_documento'); // 'dni' | 'pasaporte' | 'carne_extranjeria' | 'otro'
            $table->string('numero_documento');
            $table->date('fecha_vencimiento')->nullable();
            $table->string('archivo')->nullable(); // path en disco privado, ver comentario arriba
            $table->date('fecha_registro');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pasajero_documentos');
    }
};
