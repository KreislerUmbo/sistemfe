<?php
// Migración correctiva — revisada y aprobada.
//
// DECISIÓN (aprobada): mapeo code→codigo, description→nombre,
// percentage→tasa_porcentaje, min_amount→monto_minimo, active→estado,
// más 3 columnas nuevas sin equivalente en la migración vieja (tipo,
// vigente_desde, vigente_hasta).
//
// NOTA: el bloque original también intentaba agregar products.codigo_detraccion
// + una foreign key real hacia detraction_codes.codigo. Se quitó: products vive
// en la base de cada tenant y detraction_codes en la base central — son bases
// físicas distintas, y Postgres no soporta FK entre bases distintas. Esa columna
// ya se agrega correctamente (sin FK física, solo relación a nivel de app) en
// database/migrations/tenant/core/2026_07_14_090000_alter_products_add_codigo_detraccion.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        // ── Reconstruir detraction_codes ──────────────────────────────
        DB::statement('ALTER TABLE detraction_codes DROP CONSTRAINT IF EXISTS detraction_codes_code_unique');
        DB::statement('ALTER TABLE detraction_codes RENAME COLUMN code TO codigo');
        DB::statement('ALTER TABLE detraction_codes ALTER COLUMN codigo TYPE CHAR(3)');
        DB::statement('ALTER TABLE detraction_codes RENAME COLUMN description TO nombre');
        DB::statement('ALTER TABLE detraction_codes ALTER COLUMN nombre TYPE VARCHAR(100)');
        DB::statement('ALTER TABLE detraction_codes RENAME COLUMN percentage TO tasa_porcentaje');
        DB::statement('ALTER TABLE detraction_codes ALTER COLUMN tasa_porcentaje TYPE NUMERIC(5,2)');
        DB::statement('ALTER TABLE detraction_codes ALTER COLUMN tasa_porcentaje SET DEFAULT 0');
        DB::statement('ALTER TABLE detraction_codes RENAME COLUMN min_amount TO monto_minimo');
        DB::statement('ALTER TABLE detraction_codes ALTER COLUMN monto_minimo TYPE NUMERIC(5,2)');
        DB::statement('ALTER TABLE detraction_codes ALTER COLUMN monto_minimo DROP DEFAULT');
        DB::statement('ALTER TABLE detraction_codes ALTER COLUMN monto_minimo DROP NOT NULL');
        DB::statement('ALTER TABLE detraction_codes RENAME COLUMN active TO estado');
        DB::statement('ALTER TABLE detraction_codes ALTER COLUMN anexo TYPE VARCHAR(2)');
        DB::statement('ALTER TABLE detraction_codes ALTER COLUMN anexo DROP NOT NULL');
        Schema::table('detraction_codes', function (Blueprint $table) {
            $table->enum('tipo', ['BIEN', 'SERVICIO'])->default('BIEN')->after('tasa_porcentaje');
            $table->date('vigente_desde')->nullable();
            $table->date('vigente_hasta')->nullable();
        });
        // enum() en pgsql crea varchar(255)+CHECK; el real es varchar(10).
        DB::statement('ALTER TABLE detraction_codes ALTER COLUMN tipo TYPE VARCHAR(10)');
        DB::statement('ALTER TABLE detraction_codes ADD CONSTRAINT detraction_codes_codigo_key UNIQUE (codigo)');
        DB::statement('ALTER TABLE detraction_codes ALTER COLUMN created_at SET DEFAULT CURRENT_TIMESTAMP');
        DB::statement('ALTER TABLE detraction_codes ALTER COLUMN updated_at SET DEFAULT CURRENT_TIMESTAMP');
    }
    public function down(): void
    {
        DB::statement('ALTER TABLE detraction_codes DROP CONSTRAINT IF EXISTS detraction_codes_codigo_key');
        Schema::table('detraction_codes', function (Blueprint $table) {
            $table->dropColumn(['tipo', 'vigente_desde', 'vigente_hasta']);
        });
        DB::statement('ALTER TABLE detraction_codes RENAME COLUMN codigo TO code');
        DB::statement('ALTER TABLE detraction_codes ALTER COLUMN code TYPE VARCHAR(3)');
        DB::statement('ALTER TABLE detraction_codes RENAME COLUMN nombre TO description');
        DB::statement('ALTER TABLE detraction_codes ALTER COLUMN description TYPE VARCHAR(255)');
        DB::statement('ALTER TABLE detraction_codes RENAME COLUMN tasa_porcentaje TO percentage');
        DB::statement('ALTER TABLE detraction_codes ALTER COLUMN percentage TYPE NUMERIC(6,4)');
        DB::statement('ALTER TABLE detraction_codes RENAME COLUMN monto_minimo TO min_amount');
        DB::statement('ALTER TABLE detraction_codes ALTER COLUMN min_amount TYPE NUMERIC(10,2)');
        DB::statement("ALTER TABLE detraction_codes ALTER COLUMN min_amount SET DEFAULT 700.00");
        DB::statement('ALTER TABLE detraction_codes ALTER COLUMN min_amount SET NOT NULL');
        DB::statement('ALTER TABLE detraction_codes RENAME COLUMN estado TO active');
        DB::statement('ALTER TABLE detraction_codes ALTER COLUMN anexo TYPE VARCHAR(1) USING left(anexo, 1)');
        DB::statement('ALTER TABLE detraction_codes ALTER COLUMN anexo SET NOT NULL');
        DB::statement('ALTER TABLE detraction_codes ADD CONSTRAINT detraction_codes_code_unique UNIQUE (code)');
    }
};
