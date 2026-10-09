<?php

declare(strict_types=1);

namespace App\Services\ZonaHoraria;

/**
 * Homogenización de fechas (opción A, aprobada 08-oct-2026): todo instante se guarda en UTC.
 * Este catálogo describe cómo están guardados los datos HOY, antes de migrar. Lo usa el
 * diagnóstico (fechas:diagnosticar) y lo usará la migración de datos (F3).
 *
 * Verificado contra los logs de Postgres de los tenants locales (26-ago a 08-oct-2026).
 * Todo lo que no figura acá se espera en UTC.
 */
final class CatalogoZonasFecha
{
    public const LIMA = 'LIMA';
    public const UTC = 'UTC';
    public const MIXTA = 'MIXTA';

    /**
     * Tablas cuyos modelos fuerzan hora Lima en created_at/updated_at (mutadores con
     * date_default_timezone_set) => columnas de instante además de created_at/updated_at que
     * también quedan en Lima (se llenan con now() después de que corrió el mutador).
     * Las columnas de fecha de negocio (sales.date, sale_payments.date_payment, fecha_pago…) NO
     * están: no son instantes y no se migran.
     *
     * @var array<string, list<string>>
     */
    public const TABLAS_LIMA = [
        'sales' => ['sunat_sent_at'],
        'sale_details' => [],
        'sale_payments' => [],
        'notes' => ['sunat_sent_at'],
        'note_details' => [],
        'cash_sessions' => [],             // opened_at/closed_at se guardan en UTC (controller)
        'cash_movements' => ['corrected_at'],
        'cash_registers' => [],
        'cash_session_totals' => [],
        'cash_session_denominations' => [],
        'branches' => [],
        'cash_concepts' => [],
        'payment_methods' => [],
        'suppliers' => [],
        'advances' => ['corrected_at'],
        'advance_applications' => [],
        'advance_refunds' => [],
        'installments' => ['anulado_en'],
        'payment_receipts' => ['anulado_en'],
        'payment_applications' => [],
        'payment_refunds' => [],
        'commercial_quotes' => ['converted_at'],
        'products' => [],
        'categories' => [],
        'clients' => [],
    ];

    /**
     * Sin mutador, pero siempre se escriben después de un modelo "Lima" en la misma petición
     * (los logs no muestran ninguna fila UTC). A confirmar con el diagnóstico en cada servidor.
     *
     * @var list<string>
     */
    public const TABLAS_LIMA_COLATERAL = [
        'sale_detail_items',
        'reserva_ventas',
        'reserva_anticipos',
        'commercial_quote_items',
        'commercial_quote_anticipos',
    ];

    /**
     * Tablas "UTC" con MUCHAS filas en Lima según qué se guardó antes en la misma petición: no
     * sirven de ancla. Ojo: cualquier tabla UTC puede tener alguna fila Lima suelta por el mismo
     * contagio (ej. agencia-demo destino_servicio 43/44); por eso la corrección es fila por fila.
     *
     * @var list<string>
     */
    public const TABLAS_MIXTAS = [
        'credito_auditoria',
        'credito_pago_aplicaciones',
        'cartera_asignaciones',
        'users',
        'role_audit_logs',
    ];

    /** Base central: modelos AdminPortal con el mismo mutador. @var list<string> */
    public const TABLAS_LIMA_CENTRAL = ['systems', 'system_categories'];

    public static function zonaEsperada(string $tabla, bool $central = false): string
    {
        if ($central) {
            return in_array($tabla, self::TABLAS_LIMA_CENTRAL, true) ? self::LIMA : self::UTC;
        }
        if (isset(self::TABLAS_LIMA[$tabla]) || in_array($tabla, self::TABLAS_LIMA_COLATERAL, true)) {
            return self::LIMA;
        }

        return in_array($tabla, self::TABLAS_MIXTAS, true) ? self::MIXTA : self::UTC;
    }
}
