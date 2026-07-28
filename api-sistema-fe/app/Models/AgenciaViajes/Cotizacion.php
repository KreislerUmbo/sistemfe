<?php

namespace App\Models\AgenciaViajes;

use App\Models\Client\Client;
use Illuminate\Database\Eloquent\Model;

// Header de cotización — plan-modulo-cotizaciones-reservas.md §3.1. Tenant
// (sin CentralConnection). cliente_id lleva belongsTo real a clients (core,
// App\Models\Client\Client), no un cliente propio del vertical.
//
// codigo: calculado {codigo_prefijo}-{año}-{correlativo}, correlativo propio
// POR PREFIJO (reinicia también por año, ver generarCodigo() abajo — el
// propio formato del código ya lo hace natural: la búsqueda del último
// correlativo filtra por "{prefijo}-{año}-%"). Resuelto acá, en el modelo,
// vía el evento creating() — NO es una columna generada de Postgres.
//
// Mismo patrón de concurrencia que
// CreditPaymentController::siguienteNumeroRecibo()/
// FacturacionElectronicaController::reservarCorrelativo(): lockForUpdate()
// + MAX()+1. Igual que esos dos casos, el lock solo es real si el llamador
// (controller del CRUD real, Sesión 11) envuelve la creación en una
// transacción — sin eso, el lockForUpdate() no tiene nada que bloquear.
class Cotizacion extends Model
{
    protected $table = 'cotizaciones';

    protected $fillable = [
        'codigo_prefijo',
        'codigo',
        'cliente_id',
        'destino',
        'fecha_viaje_tentativa',
    ];

    protected $casts = [
        'fecha_viaje_tentativa' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (Cotizacion $cotizacion) {
            if (empty($cotizacion->codigo) && ! empty($cotizacion->codigo_prefijo)) {
                $cotizacion->codigo = static::generarCodigo($cotizacion->codigo_prefijo);
            }
        });
    }

    public static function generarCodigo(string $prefijo): string
    {
        $anio = now()->year;
        $patron = "{$prefijo}-{$anio}-%";

        $ultimo = static::where('codigo_prefijo', $prefijo)
            ->where('codigo', 'like', $patron)
            ->lockForUpdate()
            ->orderByDesc('id')
            ->first();

        $siguiente = 1;
        if ($ultimo) {
            $partes = explode('-', $ultimo->codigo);
            $siguiente = ((int) end($partes)) + 1;
        }

        return sprintf('%s-%d-%03d', $prefijo, $anio, $siguiente);
    }

    public function cliente()
    {
        return $this->belongsTo(Client::class, 'cliente_id');
    }

    public function pasajeros()
    {
        return $this->hasMany(CotizacionPasajero::class, 'cotizacion_id');
    }

    public function alternativas()
    {
        return $this->hasMany(Alternativa::class, 'cotizacion_id');
    }
}
