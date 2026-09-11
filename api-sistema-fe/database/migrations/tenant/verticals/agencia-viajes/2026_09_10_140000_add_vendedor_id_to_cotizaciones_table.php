<?php
// Fase 1b (plan-modulo-menus-y-roles.md §3.3) — scope de fila por vendedor.
// Hallazgo real al implementar EscopablePorVendedor: ni `cotizaciones` ni
// `reserva` tenían ninguna columna que registrara quién creó la fila — el
// diseño de §3.3 (`where('vendedor_id', auth()->id())`) asumía una columna
// que no existía en ningún lado (confirmado con grep completo de
// app/Models/AgenciaViajes/ antes de escribir esta migración).
//
// Nullable a propósito: cotizaciones ya creadas antes de esta fase quedan
// sin dueño — el scope las trata como "sin vendedor asignado", visibles
// solo para roles con `cotizaciones.ver_todas` (Administrador/Supervisor/
// Contador/Super-Admin) hasta que alguien las reasigne a mano. No se
// backfillea con un valor inventado (ej. el primer Super-Admin) — eso
// falsearía un dato que nunca existió.
//
// `reserva` NO recibe su propia columna: hereda el vendedor por join vía
// alternativa->cotizacion (mismo criterio que ya usa el proyecto para la
// fecha de viaje antes del fix de Fase 1 Cotización↔Reserva — evitar
// duplicar un dato que ya vive en la cotización padre). Ver
// EscopablePorVendedor::class.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->unsignedBigInteger('vendedor_id')->nullable()->after('cliente_id');
            $table->foreign('vendedor_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->dropForeign(['vendedor_id']);
            $table->dropColumn('vendedor_id');
        });
    }
};
