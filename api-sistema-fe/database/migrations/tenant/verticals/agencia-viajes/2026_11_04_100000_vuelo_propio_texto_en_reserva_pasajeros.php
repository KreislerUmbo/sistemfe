<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// T-077 — El vuelo que el pasajero compró por su cuenta pasa de 6 columnas
// (aerolínea, fecha y hora de ida y de vuelta) a un solo texto libre
// `vuelo_propio`, que sale como observación en el reporte operativo.
// Si algún pasajero ya tenía datos, se copian al texto antes de borrar las
// columnas viejas: no se pierde nada. El vuelo que vende la agencia
// (reserva_item_vuelo_pasajero) no se toca.
return new class extends Migration
{
    private const COLUMNAS_VIEJAS = [
        'vuelo_aerolinea_ida',
        'vuelo_fecha_ida',
        'vuelo_hora_ida',
        'vuelo_aerolinea_vuelta',
        'vuelo_fecha_vuelta',
        'vuelo_hora_vuelta',
    ];

    public function up(): void
    {
        Schema::table('reserva_pasajeros', function (Blueprint $table) {
            $table->text('vuelo_propio')->nullable();
        });

        if (Schema::hasColumn('reserva_pasajeros', 'vuelo_aerolinea_ida')) {
            $filas = DB::table('reserva_pasajeros')
                ->where(function ($q) {
                    foreach (self::COLUMNAS_VIEJAS as $col) {
                        $q->orWhereNotNull($col);
                    }
                })
                ->get(array_merge(['id'], self::COLUMNAS_VIEJAS));

            foreach ($filas as $fila) {
                $texto = $this->armarTexto($fila);
                if ($texto !== '') {
                    DB::table('reserva_pasajeros')->where('id', $fila->id)->update(['vuelo_propio' => $texto]);
                }
            }

            Schema::table('reserva_pasajeros', function (Blueprint $table) {
                $table->dropColumn(self::COLUMNAS_VIEJAS);
            });
        }
    }

    public function down(): void
    {
        Schema::table('reserva_pasajeros', function (Blueprint $table) {
            $table->string('vuelo_aerolinea_ida')->nullable();
            $table->date('vuelo_fecha_ida')->nullable();
            $table->time('vuelo_hora_ida')->nullable();
            $table->string('vuelo_aerolinea_vuelta')->nullable();
            $table->date('vuelo_fecha_vuelta')->nullable();
            $table->time('vuelo_hora_vuelta')->nullable();
            $table->dropColumn('vuelo_propio');
        });
    }

    private function armarTexto(object $f): string
    {
        $tramo = function (?string $aerolinea, ?string $fecha, ?string $hora, string $prefijo): string {
            $partes = array_filter([
                $aerolinea,
                $fecha ? date('d/m', strtotime($fecha)) : null,
                $hora ? substr($hora, 0, 5) : null,
            ]);

            return $partes ? $prefijo.' '.implode(' ', $partes) : '';
        };

        $ida = $tramo($f->vuelo_aerolinea_ida, $f->vuelo_fecha_ida, $f->vuelo_hora_ida, 'Ida:');
        $vuelta = $tramo($f->vuelo_aerolinea_vuelta, $f->vuelo_fecha_vuelta, $f->vuelo_hora_vuelta, 'Vuelta:');

        return mb_substr(implode(' · ', array_filter([$ida, $vuelta])), 0, 500);
    }
};
