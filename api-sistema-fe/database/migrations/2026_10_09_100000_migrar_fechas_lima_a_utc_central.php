
Warning: Module "openssl" is already loaded in Unknown on line 0
<?php

// Base CENTRAL (systems/system_categories con mutador; central_audit_logs con filas contagiadas).
// Homogenización de fechas, F3 (aprobada 08-oct-2026, opción A: todo instante en UTC).
// Hasta F2, 27 modelos guardaban created_at/updated_at en hora Lima (mutadores con
// date_default_timezone_set), y ese cambio de zona contagiaba otras tablas de la misma
// petición. Esta migración pasa a UTC (+5 h) solo las filas que quedaron en Lima, decididas
// fila por fila (ver App\Services\ZonaHoraria\DiagnosticoZonasService), y registra cada cambio
// en ajustes_zona_horaria para que down() lo revierta exacto.
//
// Va en el mismo despliegue que el código sin mutadores: deploy.sh corre las migraciones con el
// sitio en mantenimiento. Antes, en producción: fechas:migrar-utc --simular y un respaldo.

use App\Services\ZonaHoraria\MigracionFechasUtcService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->create(MigracionFechasUtcService::TABLA_REGISTRO, function (Blueprint $table) {
            $table->id();
            $table->string('tabla');
            $table->json('columnas');
            $table->json('ids');
            $table->unsignedInteger('filas');
            $table->timestamp('created_at')->nullable();
        });

        app(MigracionFechasUtcService::class)->aplicar(DB::connection('central'), central: true);
    }

    public function down(): void
    {
        app(MigracionFechasUtcService::class)->revertir(DB::connection('central'));
        Schema::connection('central')->dropIfExists(MigracionFechasUtcService::TABLA_REGISTRO);
    }
};
