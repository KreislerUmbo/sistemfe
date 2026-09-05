<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_09_05_100100_create_configuracion_agencia_pdf_table.php
//
// Mejora del PDF de cotización (plan-mejora-pdf-cotizacion-cliente.md §4.1/
// §4.2) — tabla propia en vez de columnas nuevas en configuracion_agencia
// (Paso 0.5 del brief de ejecución): configuracion_agencia ya tiene 20+
// columnas de configuración comercial no relacionada, mezclar branding del
// PDF ahí la sobrecarga más. Singleton por tenant, mismo patrón que
// configuracion_agencia (la migración inserta la fila default en up()).
//
// logo/nombre_comercial/dirección/teléfono/email NO se duplican acá:
// Company (logo_horizontal/logo_vertical, razon_social_comercial, phone,
// email, address) ya existe y ya es lo que AlternativaController::pdf()
// usa hoy para el header del PDF (Sesión pdf-cotizacion) — confirmado
// contra el código real antes de escribir esta migración (Paso 0.5).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracion_agencia_pdf', function (Blueprint $table) {
            $table->id();
            $table->string('color_primario', 7)->nullable();
            $table->string('color_secundario', 7)->nullable();
            $table->string('color_categoria_local', 7)->nullable();
            $table->string('color_categoria_nacional', 7)->nullable();
            $table->string('color_categoria_internacional', 7)->nullable();
            $table->string('eslogan')->nullable();
            $table->json('redes_sociales')->nullable(); // [{red, usuario}]
            $table->boolean('mostrar_fotos_tour')->default(true);
            $table->boolean('mostrar_afiliaciones')->default(false);
            // Override total (plan §4.2) — path en el disco 'public', mismo
            // patrón que cualquier otra foto del vertical.
            $table->string('imagen_header_custom')->nullable();
            $table->string('imagen_footer_custom')->nullable();
            $table->timestamps();
        });

        // Singleton — fila default para todo tenant que corra esta
        // migración de acá en adelante (mismo criterio que
        // 2026_07_27_160300_create_configuracion_agencia_table.php).
        // Colores neutros de sistema: ningún tenant existente configuró
        // nada todavía, el PDF debe salir prolijo igual (plan §6).
        DB::table('configuracion_agencia_pdf')->insert([
            'color_primario' => '#1f2937',
            'color_secundario' => '#4b5563',
            'color_categoria_local' => '#2563eb',
            'color_categoria_nacional' => '#1f2937',
            'color_categoria_internacional' => '#7c3aed',
            'mostrar_fotos_tour' => true,
            'mostrar_afiliaciones' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracion_agencia_pdf');
    }
};
