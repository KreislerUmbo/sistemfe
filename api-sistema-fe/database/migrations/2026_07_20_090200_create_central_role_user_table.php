<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCentralRoleUserTable extends Migration
{
    // 'central' consolidada — ver comentario en create_central_users_table.php.
    protected $connection = 'central';

    public function up(): void
    {
        Schema::create('central_role_user', function (Blueprint $table) {
            $table->foreignId('central_user_id')->constrained('central_users')->cascadeOnDelete();
            $table->foreignId('central_role_id')->constrained('central_roles')->cascadeOnDelete();
            $table->primary(['central_user_id', 'central_role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('central_role_user');
    }
}
