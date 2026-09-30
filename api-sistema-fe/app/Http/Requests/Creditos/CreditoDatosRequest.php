<?php

declare(strict_types=1);

namespace App\Http\Requests\Creditos;

use App\Models\Creditos\CreditoConfiguracion;
use App\Rules\Creditos\MontoSoles;
use App\Services\Creditos\Dinero;
use App\Services\Creditos\Dto\DatosCredito;
use App\Services\Creditos\Motor\Dto\Frecuencia;
use App\Services\Creditos\Motor\Enums\FrecuenciaUnidad;
use App\Services\Creditos\Motor\Enums\ReglaNoLaborable;
use App\Services\Creditos\Motor\Enums\TopeMoraTipo;
use App\Services\Creditos\Motor\Enums\UnidadTasa;
use App\Services\Creditos\Motor\Fecha;
use App\Services\Creditos\Motor\Tasa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Condiciones de un crédito (preview, crear, editar borrador). Lo no enviado toma el default de
 * credito_configuracion (00 1.3: config → crédito, editable al crear). Los montos llegan en
 * soles y salen en centavos; el backend calcula todo total (00 §4).
 */
class CreditoDatosRequest extends FormRequest
{
    private const PORCENTAJE = 'regex:/^\d{1,4}(\.\d{1,4})?$/';

    protected bool $requiereCliente = true;

    private ?CreditoConfiguracion $config = null;

    public function authorize(): bool
    {
        return true;   // permission:creditos.* en la ruta; alcance y reglas finas en el servicio
    }

    public function rules(): array
    {
        $config = $this->config();
        $tasaMaxima = $config->tasa_maxima === null ? [] : ['lte:' . $config->tasa_maxima];

        return [
            'cliente_id' => [$this->requiereCliente ? 'required' : 'prohibited', 'integer', 'exists:clients,id'],
            'monto_capital' => ['required', new MontoSoles(Dinero::aCentavos($config->paso_redondeo))],
            'tasa_interes' => ['required', 'numeric', 'min:0', self::PORCENTAJE, ...$tasaMaxima],
            'unidad_tasa' => ['required', Rule::enum(UnidadTasa::class)],
            'frecuencia_unidad' => ['required', Rule::enum(FrecuenciaUnidad::class)],
            'frecuencia_intervalo' => ['nullable', 'integer', 'min:1', 'max:365'],
            'dias_quincena' => ['nullable', 'required_if:frecuencia_unidad,quincena', 'array', 'size:2'],
            'dias_quincena.*' => [function (string $atributo, mixed $valor, \Closure $fail): void {
                if ($valor !== Frecuencia::ULTIMO_DIA && ! (is_int($valor) && $valor >= 1 && $valor <= 31)) {
                    $fail("Cada día de quincena debe ser del 1 al 31 o 'ultimo'.");
                }
            }],
            'numero_cuotas' => ['required', 'integer', 'min:1', 'max:' . $config->max_numero_cuotas],
            'fecha_desembolso' => ['required', 'date_format:Y-m-d'],
            'fecha_primer_vencimiento' => ['nullable', 'date_format:Y-m-d', 'after:fecha_desembolso'],
            'payment_method_id' => ['nullable', 'integer', Rule::exists('payment_methods', 'id')->where('is_active', true)],
            'dias_no_laborables' => ['nullable', 'array', 'max:6'],   // al menos un día laborable
            'dias_no_laborables.*' => ['integer', 'between:1,7', 'distinct'],
            'saltar_feriados' => ['nullable', 'boolean'],
            'regla_no_laborable' => ['nullable', Rule::enum(ReglaNoLaborable::class)],
            'mora_cuenta_no_laborables' => ['nullable', 'boolean'],
            'tasa_interes_minimo' => ['nullable', 'numeric', 'min:0', 'max:100', self::PORCENTAJE],
            'dias_gracia' => ['nullable', 'integer', 'min:0', 'max:365'],
            'tope_mora_tipo' => ['nullable', Rule::enum(TopeMoraTipo::class)],
            'tope_mora_valor' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }

    public function aDatos(?int $clienteId = null): DatosCredito
    {
        $c = $this->config();
        $tope = $this->filled('tope_mora_tipo') ? TopeMoraTipo::from($this->input('tope_mora_tipo')) : $c->tope_mora_tipo;

        return new DatosCredito(
            $clienteId ?? (int) $this->input('cliente_id'),
            Dinero::aCentavos($this->input('monto_capital')),
            Tasa::desdeTexto((string) $this->input('tasa_interes')),
            UnidadTasa::from($this->input('unidad_tasa')),
            new Frecuencia(
                FrecuenciaUnidad::from($this->input('frecuencia_unidad')),
                (int) $this->input('frecuencia_intervalo', 1),
                $this->input('dias_quincena'),
            ),
            (int) $this->input('numero_cuotas'),
            Fecha::desdeTexto($this->input('fecha_desembolso')),
            $this->filled('fecha_primer_vencimiento') ? Fecha::desdeTexto($this->input('fecha_primer_vencimiento')) : null,
            array_map('intval', $this->input('dias_no_laborables', $c->dias_no_laborables)),
            $this->boolean('saltar_feriados', $c->saltar_feriados),
            $this->filled('regla_no_laborable') ? ReglaNoLaborable::from($this->input('regla_no_laborable')) : $c->regla_no_laborable,
            $this->boolean('mora_cuenta_no_laborables', $c->mora_cuenta_no_laborables),
            Tasa::desdeTexto((string) $this->input('tasa_interes_minimo', $c->tasa_interes_minimo)),
            (int) $this->input('dias_gracia', $c->dias_gracia),
            $tope,
            $tope === TopeMoraTipo::SinTope ? null : (int) $this->input('tope_mora_valor', $c->tope_mora_valor),
            Dinero::aCentavos($c->paso_redondeo),
            $this->filled('payment_method_id') ? (int) $this->input('payment_method_id') : null,
        );
    }

    protected function config(): CreditoConfiguracion
    {
        return $this->config ??= CreditoConfiguracion::actual();
    }
}
