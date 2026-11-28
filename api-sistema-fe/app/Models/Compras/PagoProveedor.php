<?php

namespace App\Models\Compras;

use App\Models\Cash\CashMovement;
use App\Models\Cash\PaymentMethod;
use App\Models\CuentaBancaria;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// T-136 — dinero que sale a un proveedor, una vez. Nunca se borra: se anula.
class PagoProveedor extends Model
{
    protected $table = 'pagos_proveedor';

    // cash_movements.type y reference_type del egreso de un pago desde Caja.
    public const TIPO_MOVIMIENTO = 'pago_proveedor';

    public const ORIGEN_CAJA = 'caja';

    public const ORIGEN_BANCO = 'banco';

    // Catálogo abierto: T-163 y Rendiciones agregan los suyos.
    public const ORIGENES = [self::ORIGEN_CAJA, self::ORIGEN_BANCO];

    public const PENDIENTE = 'pendiente_aprobacion';

    public const VIGENTE = 'vigente';

    public const ANULADO = 'anulado';

    public const ESTADOS = [self::PENDIENTE, self::VIGENTE, self::ANULADO];

    protected $fillable = [
        'proveedor_id', 'fecha', 'moneda', 'monto', 'tipo_cambio', 'origen',
        'cash_movement_id', 'payment_method_id', 'cuenta_bancaria_id', 'numero_operacion',
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

    public function aplicaciones(): HasMany
    {
        return $this->hasMany(AplicacionPagoProveedor::class)->orderBy('id');
    }

    public function reembolsos(): HasMany
    {
        return $this->hasMany(ReembolsoProveedor::class)->orderBy('id');
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

    public function estaAnulado(): bool
    {
        return $this->estado === self::ANULADO;
    }
}
