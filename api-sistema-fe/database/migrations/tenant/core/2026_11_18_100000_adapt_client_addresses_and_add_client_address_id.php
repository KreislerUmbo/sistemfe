<?php

// T-065 — "Otras direcciones" del cliente: la tabla client_addresses (vacía y sin uso hasta hoy)
// se adapta al modelo de dirección del cliente, y venta/nota guardan cuál dirección se eligió.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No se pierden datos en silencio: si alguien ya guardó direcciones, se para.
        $filas = DB::table('client_addresses')->count();
        if ($filas > 0) {
            throw new RuntimeException(
                "T-065: client_addresses tiene {$filas} fila(s). La migración asume la tabla vacía; "
                . 'revisar esos datos antes de alterar la tabla.'
            );
        }

        Schema::table('client_addresses', function (Blueprint $table) {
            $table->string('nombre', 100)->nullable(false)->change();
            $table->string('direccion', 255)->nullable(false)->change();
            $table->renameColumn('departamento', 'region');
            $table->dropColumn(['principal', 'state']);
        });

        Schema::table('client_addresses', function (Blueprint $table) {
            $table->string('ubigeo_region', 25)->nullable();
            $table->string('ubigeo_provincia', 25)->nullable();
            $table->string('ubigeo_distrito', 25)->nullable();
            $table->boolean('activo')->default(true);
            $table->index('client_id');
        });

        foreach (['sales', 'notes'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->unsignedBigInteger('client_address_id')->nullable();
                $table->foreign('client_address_id')->references('id')->on('client_addresses');
                $table->index('client_address_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['sales', 'notes'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                $table->dropForeign($tabla . '_client_address_id_foreign');
                $table->dropIndex($tabla . '_client_address_id_index');
                $table->dropColumn('client_address_id');
            });
        }

        Schema::table('client_addresses', function (Blueprint $table) {
            $table->dropIndex('client_addresses_client_id_index');
            $table->dropColumn(['ubigeo_region', 'ubigeo_provincia', 'ubigeo_distrito', 'activo']);
        });

        Schema::table('client_addresses', function (Blueprint $table) {
            $table->renameColumn('region', 'departamento');
            $table->string('principal', 150)->nullable();
            $table->string('state', 50)->nullable();
            $table->string('nombre', 100)->nullable()->change();
            $table->string('direccion', 100)->nullable()->change();
        });
    }
};
