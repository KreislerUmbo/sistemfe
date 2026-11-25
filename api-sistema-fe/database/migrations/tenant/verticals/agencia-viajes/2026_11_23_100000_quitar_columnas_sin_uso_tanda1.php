<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_11_23_100000_quitar_columnas_sin_uso_tanda1.php
//
// T-083, tanda 1: columnas que el código ya no lee ni escribe (auditoría de Agencia del 09-nov).
// Conteo de producción del 23-nov-2026 (`inventario:columnas-sin-uso`): todas en 0 en dakamu.
//   - proveedores.pais_id/departamento_id/provincia_id/distrito_id: nunca se usaron (la dirección
//     del proveedor es texto libre en `direccion`).
//   - salidas_mayorista.fecha_retorno: ninguna pantalla la muestra ni la pide.
//   - reserva_item_pasajero.checkin_realizado/checkin_hora: el check-in vive en reserva_item_checkins
//     desde T-041 (2026_11_03_200100 copió lo que había).
// Salvaguarda: si en algún tenant hay datos, la migración se detiene sin tocar nada (no se pierde
// información en silencio). down() vuelve a crear las columnas vacías y nullable.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UBIGEO = ['pais_id', 'departamento_id', 'provincia_id', 'distrito_id'];

    public function up(): void
    {
        $this->verificarSinDatos();

        // T-132: en una agencia nueva core/ creó proveedores ya sin las columnas de ubigeo.
        $ubigeo = $this->ubigeoPresente();
        if ($ubigeo !== []) {
            Schema::table('proveedores', function (Blueprint $table) use ($ubigeo) {
                $table->dropColumn($ubigeo);
            });
        }
        Schema::table('salidas_mayorista', function (Blueprint $table) {
            $table->dropColumn('fecha_retorno');
        });
        Schema::table('reserva_item_pasajero', function (Blueprint $table) {
            $table->dropColumn(['checkin_realizado', 'checkin_hora']);
        });
    }

    public function down(): void
    {
        $faltantes = array_values(array_diff(self::UBIGEO, $this->ubigeoPresente()));
        Schema::table('proveedores', function (Blueprint $table) use ($faltantes) {
            foreach ($faltantes as $columna) {
                $table->unsignedBigInteger($columna)->nullable();
            }
        });
        Schema::table('salidas_mayorista', function (Blueprint $table) {
            $table->date('fecha_retorno')->nullable(); // era NOT NULL; vuelve vacía
        });
        Schema::table('reserva_item_pasajero', function (Blueprint $table) {
            $table->boolean('checkin_realizado')->default(false);
            $table->timestamp('checkin_hora')->nullable();
        });
    }

    private function ubigeoPresente(): array
    {
        return array_values(array_filter(self::UBIGEO, fn ($columna) => Schema::hasColumn('proveedores', $columna)));
    }

    private function verificarSinDatos(): void
    {
        $conDatos = [];

        foreach ($this->ubigeoPresente() as $columna) {
            if (DB::table('proveedores')->whereNotNull($columna)->where($columna, '!=', 0)->exists()) {
                $conDatos[] = "proveedores.$columna";
            }
        }
        if (DB::table('salidas_mayorista')->whereNotNull('fecha_retorno')->exists()) {
            $conDatos[] = 'salidas_mayorista.fecha_retorno';
        }
        // Un check-in viejo marcado que no esté en la tabla nueva se perdería.
        $checkinSinCopiar = DB::table('reserva_item_pasajero as rip')
            ->where('rip.checkin_realizado', true)
            ->whereNotExists(fn ($q) => $q->from('reserva_item_checkins as c')
                ->whereColumn('c.reserva_item_id', 'rip.reserva_item_id')
                ->whereColumn('c.reserva_pasajero_id', 'rip.reserva_pasajero_id'))
            ->exists();
        if ($checkinSinCopiar) {
            $conDatos[] = 'reserva_item_pasajero.checkin_realizado (sin copia en reserva_item_checkins)';
        }

        if ($conDatos !== []) {
            throw new RuntimeException('T-083: hay datos en columnas que se iban a borrar: '.implode(', ', $conDatos).'. Revisarlos antes de migrar.');
        }
    }
};
