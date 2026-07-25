<?php
// Migración correctiva — NO EJECUTAR sin revisión.
//
// DECISIÓN (aprobada): tax_configs es catálogo CENTRAL (compartido entre
// tenants en la futura arquitectura multi-tenant, igual que
// detraction_codes y note_motivos) — no es dato propio de un negocio.
// Esta migración no implementa esa separación todavía (eso es trabajo de
// la fase de tenancy); solo deja el schema en disco igual al real de
// Postgres, para que create_tax_configs_table.php (2026_06_28_045005)
// quede utilizable.
//
// La migración vieja creó: key(unique), group, label, value(10,6),
// description, active. Postgres real tiene un diseño completamente
// distinto: nombre, codigo_sunat, tipo (CHECK: IGV/ICBPER/IVAP/ISC/OTRO),
// tasa_porcentaje, fecha_inicio_vigencia, fecha_fin_vigencia,
// ubigeos_aplicables (json), es_activo, es_default. No hay mapeo 1:1
// confiable entre ambos diseños, así que se elimina el esquema viejo por
// completo y se reconstruye con el real (aprobado explícitamente).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_configs', function (Blueprint $table) {
            $table->dropColumn(['key', 'group', 'label', 'value', 'description', 'active']);
        });

        Schema::table('tax_configs', function (Blueprint $table) {
            $table->string('nombre', 50);
            $table->string('codigo_sunat', 10);
            $table->enum('tipo', ['IGV', 'ICBPER', 'IVAP', 'ISC', 'OTRO']);
            $table->decimal('tasa_porcentaje', 5, 2);
            $table->date('fecha_inicio_vigencia');
            $table->date('fecha_fin_vigencia')->nullable();
            $table->json('ubigeos_aplicables')->nullable();
            $table->boolean('es_activo')->default(true);
            $table->boolean('es_default')->default(false)->nullable();
        });

        // Postgres real tiene DEFAULT CURRENT_TIMESTAMP en created_at/updated_at
        // (la migración vieja usó ->timestamps(), que no fija default de BD).
        DB::statement('ALTER TABLE tax_configs ALTER COLUMN created_at SET DEFAULT CURRENT_TIMESTAMP');
        DB::statement('ALTER TABLE tax_configs ALTER COLUMN updated_at SET DEFAULT CURRENT_TIMESTAMP');

        // enum() en pgsql crea varchar(255)+CHECK; el real es varchar(10).
        DB::statement('ALTER TABLE tax_configs ALTER COLUMN tipo TYPE VARCHAR(10)');
    }

    public function down(): void
    {
        Schema::table('tax_configs', function (Blueprint $table) {
            $table->dropColumn([
                'nombre', 'codigo_sunat', 'tipo', 'tasa_porcentaje',
                'fecha_inicio_vigencia', 'fecha_fin_vigencia',
                'ubigeos_aplicables', 'es_activo', 'es_default',
            ]);
        });

        Schema::table('tax_configs', function (Blueprint $table) {
            $table->string('key')->unique();
            $table->string('group');
            $table->string('label');
            $table->decimal('value', 10, 6);
            $table->string('description')->nullable();
            $table->boolean('active')->default(true);
        });
    }
};
