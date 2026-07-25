<?php
// Migración correctiva — NO EJECUTAR sin revisión.
// create_users_table.php (base) solo crea id/name/email/email_verified_at/
// password/remember_token/timestamps. Postgres real tiene muchas más
// columnas (usadas por App\Models\User — role_id, surname, phone, etc.)
// que nunca tuvieron migración. 'roles' ya existe desde
// 2025_12_01_201401_create_permission_tables.php, así que la FK de
// role_id es segura aquí.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('surname')->nullable()->after('remember_token');
            $table->unsignedBigInteger('role_id')->default(1)->nullable()->after('surname');
            $table->foreign('role_id')->references('id')->on('roles');
            $table->string('phone', 25)->nullable()->after('role_id');
            $table->string('avatar', 250)->nullable()->after('phone');
            $table->string('type_document', 15)->nullable()->after('avatar');
            $table->string('n_document', 15)->nullable()->after('type_document');
            $table->string('gender', 1)->default('1')->nullable()->after('n_document');
            $table->string('address', 150)->nullable()->after('gender');
            $table->smallInteger('state')->default(1)->nullable()->after('address');
            $table->softDeletes();
        });
        // real: 'surname' es varchar SIN longitud (no varchar(255)).
        DB::statement('ALTER TABLE users ALTER COLUMN surname TYPE VARCHAR');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropColumn([
                'surname', 'role_id', 'phone', 'avatar', 'type_document',
                'n_document', 'gender', 'address', 'state', 'deleted_at',
            ]);
        });
    }
};
