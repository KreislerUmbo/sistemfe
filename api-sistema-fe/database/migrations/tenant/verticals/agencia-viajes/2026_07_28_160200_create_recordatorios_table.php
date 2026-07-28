<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_28_160200_create_recordatorios_table.php
//
// Sesión 10 del vertical Agencia de Viajes — plan-modulo-cotizaciones-reservas.md
// §8bis: mensajes flotantes (toast) en-app, automáticos o manuales.
//
// entidad_id: unsignedBigInteger nullable, SIN FK de Postgres — a
// diferencia de las FK diferidas que este vertical fue cerrando sesión a
// sesión (ver TODO.md Sesión 9c, "NO queda ninguna FK diferida pendiente"),
// esta NO es una tabla futura que todavía no existe: es genuinamente
// polimórfico. entidad_tipo ('reserva' | 'cotizacion' | 'cliente' |
// 'pago_proveedor' | 'libre') determina a cuál tabla apunta entidad_id
// (reserva, cotizaciones, clients del core, pago_proveedor) — ninguna FK
// real de Postgres puede expresar "apunta a una de 4 tablas distintas
// según otra columna". Se valida en aplicación qué tabla corresponde según
// entidad_tipo (Sesión 11), no en schema. 'libre' no tiene entidad
// (entidad_id null).
//
// tipo_id: FK real a tipos_recordatorio (esta misma sesión, migración
// anterior). usuario_id: FK real a users, nullable (null = para un
// rol_destino completo, no una persona). creado_por: FK real a users, NOT
// NULL (siempre hay un autor, sea el sistema vía un usuario técnico o un
// admin/vendedor real).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recordatorios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tipo_id')->constrained('tipos_recordatorio');
            $table->string('entidad_tipo'); // 'reserva' | 'cotizacion' | 'cliente' | 'pago_proveedor' | 'libre'
            $table->unsignedBigInteger('entidad_id')->nullable(); // polimórfico sin FK, ver comentario arriba
            $table->string('titulo');
            $table->text('mensaje');
            $table->timestamp('fecha_disparo');
            $table->foreignId('usuario_id')->nullable()->constrained('users'); // nullable si es para un rol completo
            $table->string('rol_destino'); // 'vendedor' | 'admin' | 'todos'
            $table->foreignId('creado_por')->constrained('users');
            $table->boolean('forzado')->default(false);
            $table->string('estado')->default('pendiente'); // 'pendiente' | 'visto' | 'pospuesto' | 'descartado'
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recordatorios');
    }
};
