<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCentralRolesTable extends Migration
{
    // 'central' consolidada — ver comentario en create_central_users_table.php.
    protected $connection = 'central';

    public function up(): void
    {
        Schema::create('central_roles', function (Blueprint $table) {
            $table->id();
            // Valores previstos: 'superadmin', 'soporte', 'solo-lectura'. Sin
            // seeder de datos todavía (ver CentralRoleSeeder, más adelante en
            // esta misma fase) — esta migración solo crea la tabla.
            $table->string('name')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('central_roles');
    }
}
