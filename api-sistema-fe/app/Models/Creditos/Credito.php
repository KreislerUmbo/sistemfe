<?php

declare(strict_types=1);

namespace App\Models\Creditos;

use App\Enums\Creditos\CreditoEstado;
use App\Enums\Creditos\ModoMora;
use App\Enums\Creditos\MotivoCierre;
use App\Enums\Creditos\OrigenRegistro;
use App\Models\Cash\CashMovement;
use App\Models\Cash\PaymentMethod;
use App\Models\Client\Client;
use App\Services\Creditos\Motor\Enums\FrecuenciaUnidad;
use App\Services\Creditos\Motor\Enums\MetodoCalculo;
use App\Services\Creditos\Motor\Enums\ReglaNoLaborable;
use App\Services\Creditos\Motor\Enums\TopeMoraTipo;
use App\Services\Creditos\Motor\Enums\UnidadTasa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Crédito (préstamo) de un cliente (plan §2). Condiciones congeladas al activar. */
class Credito extends Model
{
    protected $table = 'creditos';

    protected $fillable = [
        'cliente_id',
        'numero_credito',
        'monto_capital',
        'tasa_interes',
        'unidad_tasa',
        'metodo_calculo',
        'interes_total',
        'frecuencia_unidad',
        'frecuencia_intervalo',
        'dias_quincena',
        'dias_no_laborables',
        'saltar_feriados',
        'regla_no_laborable',
        'mora_cuenta_no_laborables',
        'numero_cuotas',
        'fecha_desembolso',
        'payment_method_id',
        'cash_movement_id',
        'fecha_primer_vencimiento',
        'tasa_interes_minimo',
        'modo_mora',
        'dias_gracia',
        'paso_redondeo',
        'tope_mora_tipo',
        'tope_mora_valor',
        'cobra_mora',
        'estado',
        'fecha_castigo',
        'origen_registro',
        'credito_renovado_id',
        'version_cronograma_actual',
        'motivo_cierre',
        'registrado_por',
        'asesor_id',
        'motivo_anulacion',
        'anulado_por',
        'anulado_en',
    ];

    protected function casts(): array
    {
        return [
            'monto_capital' => 'decimal:2',
            'interes_total' => 'decimal:2',
            'unidad_tasa' => UnidadTasa::class,
            'metodo_calculo' => MetodoCalculo::class,
            'frecuencia_unidad' => FrecuenciaUnidad::class,
            'dias_quincena' => 'array',
            'dias_no_laborables' => 'array',
            'saltar_feriados' => 'boolean',
            'regla_no_laborable' => ReglaNoLaborable::class,
            'mora_cuenta_no_laborables' => 'boolean',
            'cobra_mora' => 'boolean',
            'fecha_desembolso' => 'date',
            'fecha_primer_vencimiento' => 'date',
            'modo_mora' => ModoMora::class,
            'tope_mora_tipo' => TopeMoraTipo::class,
            'estado' => CreditoEstado::class,
            'fecha_castigo' => 'date',
            'origen_registro' => OrigenRegistro::class,
            'motivo_cierre' => MotivoCierre::class,
            'anulado_en' => 'datetime',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'cliente_id');
    }

    public function cuotas(): HasMany
    {
        return $this->hasMany(CreditoCuota::class, 'credito_id');
    }

    /**
     * Cuotas de la versión vigente del cronograma (1.8): toda consulta de negocio parte de aquí.
     * Solo sobre una instancia: load()/with() arman la relación sobre un modelo vacío, sin
     * version_cronograma_actual, y devolverían cero cuotas. Para cargarla usar cargarCuotasVigentes().
     */
    public function cuotasVigentes(): HasMany
    {
        return $this->cuotas()
            ->where('version_cronograma', $this->version_cronograma_actual)
            ->orderBy('numero_cuota');
    }

    /** Deja cargada la relación cuotasVigentes (reemplazo de load('cuotasVigentes')). */
    public function cargarCuotasVigentes(): static
    {
        return $this->setRelation('cuotasVigentes', $this->cuotasVigentes()->get());
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(CreditoPago::class, 'credito_id');
    }

    public function aplicaciones(): HasMany
    {
        return $this->hasMany(CreditoPagoAplicacion::class, 'credito_id');
    }

    public function condonaciones(): HasMany
    {
        return $this->hasMany(CreditoCondonacion::class, 'credito_id');
    }

    public function castigos(): HasMany
    {
        return $this->hasMany(CreditoCastigo::class, 'credito_id');
    }

    public function garantes(): HasMany
    {
        return $this->hasMany(CreditoGarante::class, 'credito_id');
    }

    public function prendas(): HasMany
    {
        return $this->hasMany(Prenda::class, 'credito_id');
    }

    public function gestiones(): HasMany
    {
        return $this->hasMany(CreditoGestion::class, 'credito_id');
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(CreditoDocumento::class, 'credito_id');
    }

    public function reprogramaciones(): HasMany
    {
        return $this->hasMany(CreditoReprogramacion::class, 'credito_id');
    }

    public function cargos(): HasMany
    {
        return $this->hasMany(CreditoCargo::class, 'credito_id');
    }

    public function autorizaciones(): HasMany
    {
        return $this->hasMany(CreditoAutorizacion::class, 'credito_id');
    }

    public function creditoRenovado(): BelongsTo
    {
        return $this->belongsTo(self::class, 'credito_renovado_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function cashMovement(): BelongsTo
    {
        return $this->belongsTo(CashMovement::class, 'cash_movement_id');
    }
}
