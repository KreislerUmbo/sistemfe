<?php

// T-120 — users.role_id tenía default 1 (Super-Admin): un usuario creado sin rol aparecía como
// Super-Admin en la pantalla. Sin default, un usuario sin rol queda con role_id NULL y sin permisos.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users ALTER COLUMN role_id DROP DEFAULT');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users ALTER COLUMN role_id SET DEFAULT 1');
    }
};
