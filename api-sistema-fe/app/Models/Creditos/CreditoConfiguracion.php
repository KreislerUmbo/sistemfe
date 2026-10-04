<?php

declare(strict_types=1);

namespace App\Models\Creditos;

use App\Enums\Creditos\ModoAsignacionCartera;
use App\Enums\Creditos\ValidacionTasacion;
use App\Services\Creditos\Motor\Enums\ReglaNoLaborable;
use App\Services\Creditos\Motor\Enums\TipoCargoReprogramacion;
use App\Services\Creditos\Motor\Enums\TopeMoraTipo;
use Illuminate\Database\Eloquent\Model;

/** Configuración del módulo; una sola fila sembrada por la migración (plan §2). */
class CreditoConfiguracion extends Model
{
    protected $table = 'credito_configuracion';

    protected $fillable = [
        'tasa_interes_minimo',
        'dias_gracia',
        'paso_redondeo',
        'dias_no_laborables',
        'saltar_feriados',
        'regla_no_laborable',
        'mora_cuenta_no_laborables',
        'dias_para_venta',
        'porcentaje_prestamo_max',
        'validacion_tasacion',
        'modo_asignacion_cartera',
        'max_creditos_activos',
        'deuda_maxima_cliente',
        'dias_atraso_bloqueo',
        'max_garantias_por_garante',
        'tasa_maxima',
        'max_numero_cuotas',
        'umbral_alerta_anulaciones',
        'cargo_reprogramacion_tipo',
        'cargo_reprogramacion_monto',
        'dias_aviso_garante',
        'dias_para_castigo',
        'tope_mora_tipo',
        'tope_mora_valor',
        'cobra_mora',
        'dias_max_pago_retroactivo',
        'asesor_cobra',
        'requisitos_ficha',
        'actualizado_por',
    ];

    protected function casts(): array
    {
        return [
            'dias_no_laborables' => 'array',
            'saltar_feriados' => 'boolean',
            'mora_cuenta_no_laborables' => 'boolean',
            'cobra_mora' => 'boolean',
            'asesor_cobra' => 'boolean',
            'requisitos_ficha' => 'array',
            'regla_no_laborable' => ReglaNoLaborable::class,
            'validacion_tasacion' => ValidacionTasacion::class,
            'modo_asignacion_cartera' => ModoAsignacionCartera::class,
            'cargo_reprogramacion_tipo' => TipoCargoReprogramacion::class,
            'tope_mora_tipo' => TopeMoraTipo::class,
            'deuda_maxima_cliente' => 'decimal:2',
            'cargo_reprogramacion_monto' => 'decimal:2',
        ];
    }

    /** La única fila de configuración (la siembra la migración). */
    public static function actual(): self
    {
        return static::query()->firstOrFail();
    }
}
