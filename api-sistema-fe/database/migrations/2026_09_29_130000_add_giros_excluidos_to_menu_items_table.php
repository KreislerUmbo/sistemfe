<?php

// Módulo Créditos (plan §4, decisión Fase 2) — los ítems retail del menú tienen
// giro=NULL (compartidos con agencia_viajes, que también factura) y el
// Super-Admin pasa todos los permisos por Gate::before, así que un tenant de giro
// 'creditos' vería Productos/Ventas/Series. `giro` admite un solo valor, por eso
// se agrega una lista de exclusión: MenuResolver oculta el ítem (y con él sus
// hijos) si el giro del tenant está en giros_excluidos.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->table('menu_items', function (Blueprint $table) {
            $table->json('giros_excluidos')->nullable()->after('giro')
                ->comment('Giros para los que el ítem (y sus hijos) no se muestra, ej. ["creditos"]. null = ninguno.');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('menu_items', function (Blueprint $table) {
            $table->dropColumn('giros_excluidos');
        });
    }
};
