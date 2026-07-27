<?php
// Migración correctiva — NO EJECUTAR sin revisión.
// alter_sales_add_sunat_fields.php (2026_07_03_125245) creó varias
// columnas como NOT NULL DEFAULT 0 (o con default fijo), pero Postgres
// real las tiene NULLABLE sin default. Cambio no destructivo (solo
// relaja restricciones) — se usa SQL crudo porque este proyecto no tiene
// doctrine/dbal instalado (requerido por Blueprint::change()).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sales ALTER COLUMN cod_tipo_doc_cliente TYPE VARCHAR(2)');
        DB::statement('ALTER TABLE sales ALTER COLUMN cod_tipo_doc_cliente DROP DEFAULT');
        DB::statement('ALTER TABLE sales ALTER COLUMN cod_tipo_doc_cliente DROP NOT NULL');

        foreach (['mto_oper_gravadas', 'mto_oper_exoneradas', 'mto_oper_inafectas', 'mto_oper_exportacion'] as $col) {
            DB::statement("ALTER TABLE sales ALTER COLUMN {$col} DROP DEFAULT");
            DB::statement("ALTER TABLE sales ALTER COLUMN {$col} DROP NOT NULL");
        }

        foreach (['porcentaje_detraccion', 'porcentaje_percepcion'] as $col) {
            DB::statement("ALTER TABLE sales ALTER COLUMN {$col} DROP DEFAULT");
            DB::statement("ALTER TABLE sales ALTER COLUMN {$col} DROP NOT NULL");
        }

        // destino: real es varchar(30) NULLABLE sin default (la migración vieja
        // usó enum(), que en pgsql crea varchar(255)+CHECK, y le puso
        // NOT NULL DEFAULT 'amazonia').
        DB::statement('ALTER TABLE sales ALTER COLUMN destino TYPE VARCHAR(30)');
        DB::statement('ALTER TABLE sales ALTER COLUMN destino DROP DEFAULT');
        DB::statement('ALTER TABLE sales ALTER COLUMN destino DROP NOT NULL');

        // codigo_detraccion: real es bpchar(3) en 'sales' (a diferencia de
        // 'products', donde el real SÍ es varchar(3) — inconsistencia propia
        // de Postgres real, se replica tal cual).
        DB::statement('ALTER TABLE sales ALTER COLUMN codigo_detraccion TYPE CHAR(3)');

        // sale_details.tipo_isc: la migración vieja lo dejó NOT NULL DEFAULT '01',
        // el real es NULLABLE (con el mismo default).
        DB::statement('ALTER TABLE sale_details ALTER COLUMN tipo_isc DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE sales ALTER COLUMN cod_tipo_doc_cliente TYPE VARCHAR(1) USING left(cod_tipo_doc_cliente, 1)");
        DB::statement("ALTER TABLE sales ALTER COLUMN cod_tipo_doc_cliente SET DEFAULT '1'");
        DB::statement('ALTER TABLE sales ALTER COLUMN cod_tipo_doc_cliente SET NOT NULL');

        foreach (['mto_oper_gravadas', 'mto_oper_exoneradas', 'mto_oper_inafectas', 'mto_oper_exportacion'] as $col) {
            DB::statement("UPDATE sales SET {$col} = 0 WHERE {$col} IS NULL");
            DB::statement("ALTER TABLE sales ALTER COLUMN {$col} SET DEFAULT 0");
            DB::statement("ALTER TABLE sales ALTER COLUMN {$col} SET NOT NULL");
        }

        foreach (['porcentaje_detraccion', 'porcentaje_percepcion'] as $col) {
            DB::statement("UPDATE sales SET {$col} = 0 WHERE {$col} IS NULL");
            DB::statement("ALTER TABLE sales ALTER COLUMN {$col} SET DEFAULT 0");
            DB::statement("ALTER TABLE sales ALTER COLUMN {$col} SET NOT NULL");
        }

        DB::statement("UPDATE sales SET destino = 'amazonia' WHERE destino IS NULL");
        DB::statement("ALTER TABLE sales ALTER COLUMN destino TYPE VARCHAR(255)");
        DB::statement("ALTER TABLE sales ALTER COLUMN destino SET DEFAULT 'amazonia'");
        DB::statement('ALTER TABLE sales ALTER COLUMN destino SET NOT NULL');

        DB::statement('ALTER TABLE sales ALTER COLUMN codigo_detraccion TYPE VARCHAR(3)');

        DB::statement("UPDATE sale_details SET tipo_isc = '01' WHERE tipo_isc IS NULL");
        DB::statement('ALTER TABLE sale_details ALTER COLUMN tipo_isc SET NOT NULL');
    }
};
