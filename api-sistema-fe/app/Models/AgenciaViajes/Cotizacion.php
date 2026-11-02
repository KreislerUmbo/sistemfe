<?php

namespace App\Models\AgenciaViajes;

use App\Models\Client\Client;
use App\Models\Concerns\EscopablePorVendedor;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

// Header de cotización — plan-modulo-cotizaciones-reservas.md §3.1. Tenant
// (sin CentralConnection). cliente_id lleva belongsTo real a clients (core,
// App\Models\Client\Client), no un cliente propio del vertical.
//
// codigo/codigo_prefijo (Módulo 12, plan-modulo-codigos-numeracion.md,
// revisión 26-ago-2026 §11): hasta esta revisión se generaban acá mismo, vía
// un evento creating() que adivinaba el tipo ('cotizacion' vs 'venta_directa')
// a partir del string literal de codigo_prefijo ('VD'). Eso se retiró: ahora
// cada caller (CotizacionController::store()/VentaDirectaController::store())
// pide su código explícitamente a
// App\Services\AgenciaViajes\CodigoGeneradorService::generar('cotizacion'|
// 'venta_directa') ANTES de Cotizacion::create(). codigo_prefijo se
// sigue guardando (viene de ConfiguracionCodigo::prefijo en el momento de
// generar) porque CodigoGeneradorService::generarParaReserva() lo usa para
// extraer el resto del código al derivar el de la reserva (Str::after()).
//
// origen (09-oct-2026): 'cotizador' | 'venta_directa' | 'modificacion' (T-002). Una venta directa
// crea esta misma cadena como soporte interno de su reserva, pero no es una
// cotización comercial — el listado del Cotizador y el Inicio la excluyen
// (scopeDelCotizador()) y su reserva no deriva el código como la de una
// cotización (ver generarParaReserva()).
//
// reservas_generadas: contador acotado por cotización (§6.4), incrementado
// por generarParaReserva() — primera reserva sin sufijo, 2da+ con "-2","-3"...
class Cotizacion extends Model
{
    use EscopablePorVendedor;

    public const ORIGEN_COTIZADOR = 'cotizador';

    public const ORIGEN_VENTA_DIRECTA = 'venta_directa';

    // T-002: copia editable de una reserva aceptada ("Modificar reserva", D-AGE-030). Oculta de los
    // listados comerciales por delCotizador() y nunca se acepta por el camino normal.
    public const ORIGEN_MODIFICACION = 'modificacion';

    protected $table = 'cotizaciones';

    protected $fillable = [
        'codigo_prefijo',
        'codigo',
        'origen',
        'reservas_generadas',
        'cliente_id',
        // Fase 1b (plan-modulo-menus-y-roles.md §3.3) — scope de fila.
        // Nullable: cotizaciones creadas antes de esta fase quedan sin
        // dueño (ver migración add_vendedor_id_to_cotizaciones_table.php).
        'vendedor_id',
        'destino',
        'fecha_viaje_desde',
        'fecha_viaje_hasta',
    ];

    protected $casts = [
        'fecha_viaje_desde' => 'date',
        'fecha_viaje_hasta' => 'date',
    ];

    public function cliente()
    {
        return $this->belongsTo(Client::class, 'cliente_id');
    }

    public function vendedor()
    {
        return $this->belongsTo(User::class, 'vendedor_id');
    }

    public function pasajeros()
    {
        return $this->hasMany(CotizacionPasajero::class, 'cotizacion_id');
    }

    // orderBy('id'): sin esto Postgres no garantiza el orden, y un UPDATE (ej. recalcular el
    // total al agregar un hotel) reubicaba la alternativa: la pestaña 2 pasaba a ser la 1
    // (reporte del usuario 31-oct-2026; mismo bug que Alternativa::items()).
    public function alternativas()
    {
        return $this->hasMany(Alternativa::class, 'cotizacion_id')->orderBy('id');
    }

    // Lo que estadoResumen() lee; precargarlo en los listados evita una consulta por fila.
    public const RELACIONES_ESTADO_RESUMEN = ['alternativas.reserva', 'alternativas.modificacionAplicada.reserva'];

    // Taxonomía de estados del listado (09-sep-2026, pedido del usuario:
    // "vamos analizando los estados de las cotizaciones") — Cotizacion NO
    // tiene columna 'estado' propia (vive en cada Alternativa, hasta 5 por
    // cotización, más el estado de la Reserva una vez que alguna se acepta).
    // Este método DERIVA un único estado resumen para mostrar en el
    // listado, sin duplicar esa fuente de verdad en una columna nueva.
    // Requiere RELACIONES_ESTADO_RESUMEN precargado (ver
    // CotizacionController::index()) — si no vino cargado, dispara el
    // lazy-load normal de Eloquent (aceptable fuera de listados masivos).
    //
    // Como aceptar una alternativa descarta automáticamente las demás
    // (AlternativaController::descartarOtras()), nunca hay más de una
    // 'aceptada' a la vez — y cancelar una Reserva (ReservaController::
    // cancelar()) NUNCA revierte el estado de la Alternativa a otra cosa,
    // se queda 'aceptada' para siempre con su Reserva marcada 'cancelada'
    // aparte. Por eso 'reservada' vs 'anulada' se distinguen mirando la
    // Reserva, no la Alternativa.
    //
    // 'vencida' (decisión del usuario): solo si TODAS las alternativas
    // 'enviada' ya pasaron su fecha_vencimiento — mientras quede una
    // vigente, la cotización sigue mostrándose como 'enviada' (todavía hay
    // una propuesta viva sobre la mesa).
    public function estadoResumen(): string
    {
        $alternativas = $this->alternativas;

        if ($alternativas->isEmpty()) {
            return 'borrador';
        }

        $aceptada = $alternativas->firstWhere('estado', 'aceptada');
        if ($aceptada) {
            return $aceptada->reserva && $aceptada->reserva->estado === 'cancelada' ? 'anulada' : 'reservada';
        }

        // T-072: tras aplicar una modificación la versión vendida queda 'reemplazada' y la reserva
        // ya apunta a la de la cotización de modificación; la original sigue siendo la cotización
        // de esa reserva (se llega por reserva_modificaciones.alternativa_vendida_id).
        $reemplazada = $alternativas->firstWhere('estado', Alternativa::ESTADO_REEMPLAZADA);
        if ($reemplazada) {
            return $reemplazada->modificacionAplicada?->reserva?->estado === 'cancelada' ? 'anulada' : 'reservada';
        }

        $enviadas = $alternativas->where('estado', 'enviada');
        if ($enviadas->isNotEmpty()) {
            $todasVencidas = $enviadas->every(fn (Alternativa $a) => $a->fecha_vencimiento && $a->fecha_vencimiento->isPast());

            return $todasVencidas ? 'vencida' : 'enviada';
        }

        if ($alternativas->every(fn (Alternativa $a) => $a->estado === 'descartada')) {
            return 'descartada';
        }

        return 'borrador';
    }

    // T-074 — la alternativa que se reservó (aceptada, o reemplazada por una modificación) y el
    // código de su reserva, para mostrarlos junto al estado del listado. Null si no hay reserva.
    // Requiere RELACIONES_ESTADO_RESUMEN precargado, igual que estadoResumen().
    public function alternativaReservada(): ?array
    {
        $alternativa = $this->alternativas->firstWhere('estado', 'aceptada')
            ?? $this->alternativas->firstWhere('estado', Alternativa::ESTADO_REEMPLAZADA);
        if (! $alternativa) {
            return null;
        }

        $reserva = $alternativa->estado === 'aceptada' ? $alternativa->reserva : $alternativa->modificacionAplicada?->reserva;

        return [
            'nombre' => $alternativa->nombre,
            'reserva_id' => $reserva?->id,
            'reserva_codigo' => $reserva?->codigo,
        ];
    }

    public function scopeDelCotizador(Builder $query): Builder
    {
        return $query->where('origen', self::ORIGEN_COTIZADOR);
    }

    public function esModificacion(): bool
    {
        return $this->origen === self::ORIGEN_MODIFICACION;
    }

    public function esVentaDirecta(): bool
    {
        return $this->origen === self::ORIGEN_VENTA_DIRECTA;
    }

    protected static function permisoVerTodas(): string
    {
        return 'cotizaciones.ver_todas';
    }

    protected static function aplicarFiltroVendedor(Builder $query, int $vendedorId): void
    {
        $query->where('vendedor_id', $vendedorId);
    }
}
