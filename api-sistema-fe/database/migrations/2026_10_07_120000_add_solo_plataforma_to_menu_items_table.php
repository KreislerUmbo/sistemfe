<?php

// 07-oct-2026 — Portal web y la administración de recursos editan el catálogo central
// (compartido por todos los tenants). Solo el tenant dueño de la plataforma
// (config/plataforma.php) debe verlos: MenuResolver oculta el ítem (y con él sus
// hijos) en cualquier otro tenant, aun para su Super-Admin. Las rutas del API se
// bloquean aparte con el middleware tenant.plataforma.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->table('menu_items', function (Blueprint $table) {
            $table->boolean('solo_plataforma')->default(false)->after('giros_excluidos')
                ->comment('true = solo lo ve el tenant dueño de la plataforma (config/plataforma.php).');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('menu_items', function (Blueprint $table) {
            $table->dropColumn('solo_plataforma');
        });
    }
};
