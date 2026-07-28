<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_28_160300_create_recordatorio_snooze_config_table.php
//
// Sesión 10 del vertical Agencia de Viajes — plan-modulo-cotizaciones-reservas.md
// §8bis: preferencia de posponer/omitir por usuario y tipo de recordatorio.
//
// snooze_minutos: unsignedSmallInteger libre (60 | 480 | cualquier valor
// que el usuario digite en minutos) — no un enum cerrado, el plan solo da
// 60/480 como sugerencias de UI, no como únicos valores válidos.
//
// omitir=true apaga ese tipo para el usuario, SALVO que algún recordatorio
// puntual de ese tipo tenga forzado=true (recordatorios.forzado) — esa
// excepción es lógica de aplicación (Sesión 11), esta tabla no la resuelve
// por sí sola.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recordatorio_snooze_config', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('users');
            $table->foreignId('tipo_id')->constrained('tipos_recordatorio');
            $table->unsignedSmallInteger('snooze_minutos');
            $table->boolean('omitir')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recordatorio_snooze_config');
    }
};
