<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

// Módulo Caja — Fase 5, Paso 5. Una fila por movimiento — el query ya viene
// filtrado (visibilidad + filtros del historial + type/payment_method_id/
// concept_id) desde CashMovementController::export(), esta clase solo
// mapea columnas. Primera vez que se usa maatwebsite/excel en el proyecto
// (no había ningún mecanismo de exportación previo que reutilizar).
class CashMovementsExport implements FromQuery, WithHeadings, WithMapping
{
    public function __construct(private Builder $query)
    {
    }

    public function query()
    {
        return $this->query;
    }

    public function headings(): array
    {
        return [
            'ID',
            'Fecha',
            'Sesión',
            'Sede',
            'Caja',
            'Cajero',
            'Tipo',
            'Método de pago',
            'Dirección',
            'Monto',
            'Concepto',
            'Descripción',
            'Contraparte',
            'Documento contraparte',
            'Estado',
            'Registrado por',
        ];
    }

    public function map($movimiento): array
    {
        return [
            $movimiento->id,
            $movimiento->created_at?->format('Y-m-d H:i:s'),
            $movimiento->cash_session_id,
            $movimiento->cashSession->cashRegister->branch->name ?? '',
            $movimiento->cashSession->cashRegister->name ?? '',
            $movimiento->cashSession->openedByUser->name ?? '',
            $movimiento->type,
            $movimiento->paymentMethod->name ?? '',
            $movimiento->direction,
            (float) $movimiento->amount,
            $movimiento->concept->name ?? '',
            $movimiento->description,
            $movimiento->counterparty_name,
            $movimiento->counterparty_document,
            $movimiento->status,
            $movimiento->createdByUser->name ?? '',
        ];
    }
}
