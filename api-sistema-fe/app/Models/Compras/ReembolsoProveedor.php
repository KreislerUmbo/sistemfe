<?php

namespace App\Models\Compras;

use App\Models\Cash\CashMovement;
use App\Models\Cash\PaymentMethod;
use App\Models\CuentaBancaria;
use App\Models\Proveedor;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// T-140 — dinero que el proveedor devuelve: el saldo a favor de una compra o lo «a cuenta» de un
// pago. Nunca se borra: se anula. Los orígenes son los de PagoProveedor.
class ReembolsoProveedor extends Model
{
    protected $table = 'reembolsos_proveedor';

    // cash_movements.type y reference_type del ingreso de un reembolso a Caja.
    public const TIPO_MOVIMIENTO = 'reembolso_proveedor';

    public const VIGENTE = 'vigente';

    public const ANULADO = 'anulado';

    protected $fillable = [
        'proveedor_id', 'fecha', 'moneda', 'monto', 'tipo_cambio', 'compra_id', 'pago_proveedor_id',
        'origen', 'cash_movement_id', 'payment_method_id', 'cuenta_bancaria_id', 'numero_operacion',
        'estado', 'anulado_at', 'anulado_por', 'motivo_anulacion', 'observaciones', 'user_id',
    ];

    protected $casts = [
        'fecha' => 'date:Y-m-d',
        'monto' => 'decimal:2',
        'tipo_cambio' => 'decimal:4',
        'anulado_at' => 'datetime',
    ];

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class);
    }

    public function pago(): BelongsTo
    {
        return $this->belongsTo(PagoProveedor::class, 'pago_proveedor_id');
    }

    public function movimiento(): BelongsTo
    {
        return $this->belongsTo(CashMovement::class, 'cash_movement_id');
    }

    public function metodoPago(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function cuentaBancaria(): BelongsTo
    {
        return $this->belongsTo(CuentaBancaria::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function anulador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por')->withTrashed();
    }

    public function scopeVigentes(Builder $query): Builder
    {
        return $query->where('estado', self::VIGENTE);
    }

    public function estaAnulado(): bool
    {
        return $this->estado === self::ANULADO;
    }

    /**
     * Para las fichas de la compra y del pago: la lista (también los anulados) y lo reembolsado.
     *
     * @param  Collection<int, self>  $reembolsos
     */
    public static function paraFicha(Collection $reembolsos): array
    {
        $reembolsos->loadMissing(['metodoPago', 'cuentaBancaria', 'usuario', 'anulador']);
        $persona = fn ($usuario) => $usuario ? trim("{$usuario->name} {$usuario->surname}") : null;
        $total = $reembolsos->where('estado', self::VIGENTE)
            ->reduce(fn (BigDecimal $s, self $r) => $s->plus($r->monto), BigDecimal::of('0.00'));

        return [
            'reembolsado' => (string) $total->toScale(2),
            'reembolsos' => $reembolsos->map(fn (self $r) => [
                'id' => $r->id,
                'fecha' => $r->fecha->toDateString(),
                'moneda' => $r->moneda,
                'monto' => $r->monto,
                'tipo_cambio' => $r->tipo_cambio,
                'origen' => $r->origen,
                'estado' => $r->estado,
                'metodo_pago' => $r->metodoPago?->name,
                'cuenta' => $r->cuentaBancaria ? "{$r->cuentaBancaria->banco} {$r->cuentaBancaria->numero_cuenta}" : null,
                'numero_operacion' => $r->numero_operacion,
                'observaciones' => $r->observaciones,
                'registrado_por' => $persona($r->usuario),
                'anulado_at' => $r->anulado_at?->toISOString(),
                'anulado_por' => $persona($r->anulador),
                'motivo_anulacion' => $r->motivo_anulacion,
            ])->values()->all(),
        ];
    }
}
