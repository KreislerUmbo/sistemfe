<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_28_090000_create_tipo_cambio_agencia_table.php
//
// Sesión 7a del vertical Agencia de Viajes (plan-hoja-de-ruta-ejecucion.md) —
// plan-modulo-cotizaciones-reservas.md §3.4: histórico completo de tipo de
// cambio, "dia" (mercado) u "agencia" (fijado internamente) — nunca se
// sobrescribe, se inserta una fila nueva cada vez que se usa un valor
// distinto. alternativas.tipo_cambio_aplicado/tipo_cambio_origen guardan el
// snapshot usado por cada alternativa (Sesión 7a, misma migración).
//
// registrado_por: FK real a users (mismo modelo que Sale::user(),
// App\Models\User, tabla 'users' — tenant, ya existe desde
// 0001_01_01_000000_create_users_table.php).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipo_cambio_agencia', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->string('origen'); // 'dia' | 'agencia'
            $table->decimal('valor', 10, 4);
            $table->foreignId('registrado_por')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipo_cambio_agencia');
    }
};
