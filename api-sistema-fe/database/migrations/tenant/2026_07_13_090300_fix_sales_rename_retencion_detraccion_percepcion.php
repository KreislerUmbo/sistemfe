<?php
// Migración correctiva — NO EJECUTAR sin revisión.
//
// DECISIÓN (aprobada, confirmado por grep en app/ y admin-start-kit/src
// que 'total_retencion'/'total_detraccion'/'total_percepcion' no se usan
// en ningún lado — la app entera lee/escribe monto_retencion/
// monto_detraccion/monto_percepcion, ver Sale::$fillable, SaleResource,
// SaleController, GreenterService): alter_sales_add_sunat_fields.php
// (2026_07_03_125245) creó estas columnas con el nombre equivocado
// (total_*). Se corrige con RENAME COLUMN + se relaja default/nullable
// para que coincida con Postgres real (NULL, sin default).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sales RENAME COLUMN total_retencion TO monto_retencion');
        DB::statement('ALTER TABLE sales RENAME COLUMN total_detraccion TO monto_detraccion');
        DB::statement('ALTER TABLE sales RENAME COLUMN total_percepcion TO monto_percepcion');

        foreach (['monto_retencion', 'monto_detraccion', 'monto_percepcion'] as $col) {
            DB::statement("ALTER TABLE sales ALTER COLUMN {$col} DROP DEFAULT");
            DB::statement("ALTER TABLE sales ALTER COLUMN {$col} DROP NOT NULL");
        }
    }

    public function down(): void
    {
        foreach (['monto_retencion', 'monto_detraccion', 'monto_percepcion'] as $col) {
            DB::statement("UPDATE sales SET {$col} = 0 WHERE {$col} IS NULL");
            DB::statement("ALTER TABLE sales ALTER COLUMN {$col} SET DEFAULT 0");
            DB::statement("ALTER TABLE sales ALTER COLUMN {$col} SET NOT NULL");
        }

        DB::statement('ALTER TABLE sales RENAME COLUMN monto_retencion TO total_retencion');
        DB::statement('ALTER TABLE sales RENAME COLUMN monto_detraccion TO total_detraccion');
        DB::statement('ALTER TABLE sales RENAME COLUMN monto_percepcion TO total_percepcion');
    }
};
