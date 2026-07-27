<?php

// Módulo Caja — Fase 0 (plan-modulo-caja.md §3). Catálogo tenant-scoped que
// unifica conceptos de ingreso/egreso manual de caja (obligatorio en
// cash_movements cuando type = manual_income/manual_expense, plan §6).
//
// 'direction' es un string validado a nivel de aplicación ('in'|'out'), no
// un $table->enum() de Blueprint como en el resto del proyecto (installments,
// payment_applications, etc.) — decisión explícita del plan §3, no un
// descuido: Blueprint::enum() en Postgres solo crea varchar+CHECK (no un
// tipo ENUM nativo), así que agregar un valor nuevo requiere migración de
// todas formas con cualquiera de las dos opciones; se prefiere string simple
// aquí porque cash_movements.type (Fase 1) va a tener una lista mucho más
// larga y más propensa a crecer (transfer_in/out, mobile_settlement,
// employee_advance ya anticipados en plan §12).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_concepts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('direction');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_concepts');
    }
};
