<?php

declare(strict_types=1);

namespace App\Http\Requests\Creditos;

use App\Rules\Creditos\MontoSoles;
use App\Services\Creditos\Dinero;
use App\Services\Creditos\Dto\SolicitudCobro;
use App\Services\Creditos\Motor\Enums\DestinoExcedente;
use App\Services\Creditos\Motor\Fecha;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Cobro (00 1.4-1.5) y pago retroactivo (1.12: fecha_pago pasada + motivo; el plazo y el permiso los valida el servicio). */
class CobrarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'monto_recibido' => ['required', new MontoSoles()],
            'destino_excedente' => ['nullable', Rule::enum(DestinoExcedente::class)],
            'usar_saldo_a_favor' => ['nullable', 'boolean'],
            'payment_method_id' => ['required_unless:usar_saldo_a_favor,true', 'nullable', 'integer', Rule::exists('payment_methods', 'id')->where('is_active', true)],
            'pagado_por_cliente_id' => ['nullable', 'integer', 'exists:clients,id'],
            'referencia' => ['nullable', 'string', 'max:100'],
            'observaciones' => ['nullable', 'string', 'max:500'],
            'fecha_pago' => ['nullable', 'date_format:Y-m-d'],
            'motivo' => ['nullable', 'required_with:fecha_pago', 'string', 'max:500'],
            ...ClaveIdempotencia::reglas(),
        ];
    }

    public function aSolicitud(): SolicitudCobro
    {
        return new SolicitudCobro(
            Dinero::aCentavos($this->input('monto_recibido')),
            DestinoExcedente::tryFrom((string) $this->input('destino_excedente')) ?? DestinoExcedente::Devolver,
            $this->filled('payment_method_id') ? (int) $this->input('payment_method_id') : null,
            (string) $this->input('clave_idempotencia'),
            $this->boolean('usar_saldo_a_favor'),
            $this->filled('pagado_por_cliente_id') ? (int) $this->input('pagado_por_cliente_id') : null,
            $this->input('referencia'),
            $this->input('observaciones'),
            $this->filled('fecha_pago') ? Fecha::desdeTexto($this->input('fecha_pago')) : null,
            $this->input('motivo'),
        );
    }
}
