<?php

namespace App\Services\AgenciaViajes;

use App\Http\Controllers\Sale\ArchivoSunatController;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// T-070 — Borra la operación de prueba de un tenant de Agencia (cotizaciones,
// reservas, ventas, pagos, caja) y deja la configuración intacta (tours,
// tarifas, proveedores, clientes, usuarios). Se usa antes de empezar a operar
// en campo, para no arrastrar datos hechos con reglas que ya cambiaron.
//
// No se usa la cascada de la base: varias relaciones no tienen FK
// (sale_details/sale_payments → sales, cash_movements.reference_*) y donde
// sí hay CASCADE arrastra de más. Se borra por lista explícita, de hijos a
// padres. Corre sobre la conexión actual: el comando la envuelve en
// $tenant->run().
class LimpiarOperacionPrueba
{
    // Orden de hijos a padres (respeta las FK del esquema al 29-oct-2026).
    public const TABLAS_A_BORRAR = [
        // Ventas, notas, cobros y anticipos
        'sale_detail_items', 'payment_applications', 'payment_refunds', 'installments',
        'advance_applications', 'advance_refunds', 'commercial_quote_anticipos',
        'reserva_anticipos', 'reserva_ventas', 'note_details', 'notes', 'sale_payments',
        'sale_details', 'advances', 'payment_receipts', 'commercial_quote_items',
        'commercial_quotes', 'order_items', 'orders', 'sales',
        // Caja (las cajas y los conceptos se conservan)
        'cash_session_totals', 'cash_session_denominations', 'cash_movements', 'cash_sessions',
        // Reservas y salidas
        'salida_vehiculo_reservas', 'salida_vehiculos', 'salida_servicios',
        'reserva_item_vuelo_pasajero', 'reserva_item_checkins', 'reserva_item_pasajero', 'reserva_items',
        'salidas_operativas', 'reserva_modificaciones', 'reserva_pasajeros', 'reserva',
        // Cotizador
        'cotizacion_envios', 'cotizacion_pasaje_aereo', 'alternativa_items',
        'opciones_hotel_tarifas', 'opciones_hotel', 'opcion_mayorista_opcionales',
        'opcion_mayorista_tours', 'pago_proveedor', 'cronograma_pago_proveedor',
        'opcion_mayorista', 'alternativa_destinos', 'alternativas', 'cotizacion_pasajeros',
        'cotizaciones',
        // Otros
        'recordatorio_snooze_config', 'recordatorios', 'tipo_cambio_agencia',
    ];

    // Todo lo que no se borra. Una tabla que no está en ninguna de las dos
    // listas frena el comando: al agregar una tabla nueva hay que decidir acá
    // si es operación o configuración.
    public const TABLAS_QUE_SE_CONSERVAN = [
        // Tours
        'paquetes_plantilla', 'paquete_plantilla_items', 'paquete_plantilla_precios',
        'tour_itinerario_items', 'contenido_tour',
        // Proveedores, tarifas, guías y destinos
        'proveedores', 'proveedor_tarifas', 'proveedor_servicios', 'proveedor_amenidad',
        'proveedor_alojamiento_detalle', 'proveedor_tipos_config', 'reglas_cancelacion',
        'salidas_mayorista', 'guias', 'guia_tarifas', 'destinos_atractivos',
        'destino_servicio', 'servicios', 'suppliers',
        // Clientes (su saldo a favor vuelve a 0)
        'clients', 'client_addresses', 'pasajeros_catalogo', 'pasajero_documentos',
        // Configuración del negocio
        'configuracion_agencia', 'configuracion_agencia_afiliaciones', 'configuracion_agencia_pdf',
        'configuracion_codigos', 'configuracion_correo', 'cuentas_bancarias', 'companies',
        'sunat_configs', 'branches', 'serie_comprobantes', 'note_series', 'codigo_secuencias',
        'payment_methods', 'products', 'categories', 'cash_registers', 'cash_concepts',
        'feriados', 'tipos_recordatorio',
        // Usuarios, roles y auditoría
        'users', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions',
        'role_has_permissions', 'role_audit_logs', 'permission_shadow_logs', 'push_suscripciones',
        // Bitácoras de migraciones de datos
        'ajustes_exonerado_amazonia', 'ajustes_grupo_opcion', 'ajustes_moneda_caja',
        'ajustes_plantilla_tarifa', 'ajustes_zona_horaria',
        // Créditos (la guardia exige que no tengan operación)
        'creditos', 'credito_auditoria', 'credito_autorizaciones', 'credito_aviso_preferencias',
        'credito_avisos', 'credito_cargos', 'credito_cartera_diaria', 'credito_castigos',
        'credito_cliente_archivos', 'credito_cliente_fichas', 'credito_cliente_limites',
        'credito_condonaciones', 'credito_configuracion', 'credito_correlativos', 'credito_cuotas',
        'credito_documentos', 'credito_enlaces_cliente', 'credito_garantes', 'credito_gestiones',
        'credito_operaciones', 'credito_pago_aplicaciones', 'credito_pagos', 'credito_plantillas',
        'credito_reprogramacion_cuotas', 'credito_reprogramaciones', 'credito_saldo_favor_movimientos',
        'cartera_asignaciones', 'prendas', 'prenda_fotos',
        // Del framework
        'migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        'sessions', 'password_reset_tokens',
    ];

    // Contadores de códigos que nacen de la operación. 'tour' y 'paquete'
    // no: los tours se conservan y sus códigos no deben repetirse. El de
    // reserva deriva del de cotización (configuracion_codigos.deriva_de).
    public const SECUENCIAS_A_REINICIAR = ['cotizacion', 'venta_directa'];

    // Si hay créditos de verdad, no es un tenant de Agencia puro: no se toca.
    private const TABLAS_DE_CREDITOS_CON_OPERACION = ['creditos', 'credito_pagos', 'prendas'];

    // Motivos para no limpiar el tenant. Vacío = se puede.
    public function motivosParaNoLimpiar(Tenant $tenant): array
    {
        $motivos = [];

        if ($tenant->giro !== 'agencia_viajes') {
            $motivos[] = "El giro del tenant es '{$tenant->giro}': este comando solo conoce las tablas de Agencia de Viajes.";
        }

        if ($tenant->sunat_modo !== 'pruebas' && DB::table('sales')->whereNotNull('sunat_sent_at')->exists()) {
            $motivos[] = 'El tenant está en SUNAT producción y tiene comprobantes enviados: esos números no se pueden reusar.';
        }

        foreach (self::TABLAS_DE_CREDITOS_CON_OPERACION as $tabla) {
            if ($this->existe($tabla) && DB::table($tabla)->exists()) {
                $motivos[] = "La tabla '{$tabla}' tiene datos: el tenant opera Créditos.";
            }
        }

        $sinClasificar = $this->tablasSinClasificar();
        if ($sinClasificar) {
            $motivos[] = 'Tablas que el comando no sabe si borrar o conservar (agregarlas a una de las dos listas de '
                . self::class . '): ' . implode(', ', $sinClasificar);
        }

        $referencias = $this->tablasConservadasQueApuntanABorradas();
        if ($referencias) {
            $motivos[] = 'Tablas que se conservan pero tienen FK hacia tablas que se borran: ' . implode(', ', $referencias);
        }

        return $motivos;
    }

    // Lo que se borraría, sin tocar nada.
    public function simular(): array
    {
        $filas = [];
        foreach (self::TABLAS_A_BORRAR as $tabla) {
            if ($this->existe($tabla)) {
                $filas[$tabla] = DB::table($tabla)->count();
            }
        }

        return [
            'filas' => $filas,
            'archivos' => count($this->archivosDeLaOperacion()),
            'series' => DB::table('serie_comprobantes')->whereColumn('correlativo_actual', '>', DB::raw('correlativo_inicial - 1'))->pluck('serie')->all(),
            'secuencias' => DB::table('codigo_secuencias')->whereIn('tipo', self::SECUENCIAS_A_REINICIAR)->where('ultimo_correlativo', '>', 0)->pluck('tipo')->all(),
            'clientes_con_saldo' => DB::table('clients')->where('saldo_a_favor', '<>', 0)->count(),
        ];
    }

    // Borra todo en una transacción; los archivos, recién después del commit
    // (si la transacción falla, los archivos siguen ahí).
    public function ejecutar(): array
    {
        $resumen = $this->simular();
        $archivos = $this->archivosDeLaOperacion();

        DB::transaction(function () {
            foreach (self::TABLAS_A_BORRAR as $tabla) {
                if ($this->existe($tabla)) {
                    DB::table($tabla)->delete();
                }
            }

            DB::table('serie_comprobantes')->update(['correlativo_actual' => DB::raw('correlativo_inicial - 1')]);
            DB::table('codigo_secuencias')->whereIn('tipo', self::SECUENCIAS_A_REINICIAR)->update(['ultimo_correlativo' => 0]);
            DB::table('clients')->where('saldo_a_favor', '<>', 0)->update(['saldo_a_favor' => 0]);
        });

        $disco = Storage::disk('public');
        $borrados = 0;
        foreach ($archivos as $ruta) {
            if ($disco->exists($ruta)) {
                $disco->delete($ruta);
                $borrados++;
            }
        }

        Cache::flush();

        $resumen['archivos_borrados'] = $borrados;
        $resumen['archivos_no_encontrados'] = count($archivos) - $borrados;

        return $resumen;
    }

    public function tablasSinClasificar(): array
    {
        $conocidas = array_merge(self::TABLAS_A_BORRAR, self::TABLAS_QUE_SE_CONSERVAN);

        return array_values(array_diff($this->tablasDelEsquema(), $conocidas));
    }

    // Una FK desde una tabla conservada hacia una borrada haría fallar el
    // DELETE (o, con CASCADE/SET NULL, cambiaría datos que se conservan).
    // Solo cuenta si la tabla conservada tiene filas (p. ej. creditos →
    // cash_movements, vacía en un tenant de Agencia).
    public function tablasConservadasQueApuntanABorradas(): array
    {
        $fks = DB::select("
            select distinct tc.table_name as origen, ccu.table_name as destino
            from information_schema.table_constraints tc
            join information_schema.constraint_column_usage ccu on ccu.constraint_name = tc.constraint_name
            where tc.constraint_type = 'FOREIGN KEY' and tc.table_schema = current_schema()
        ");

        $resultado = [];
        foreach ($fks as $fk) {
            if (in_array($fk->origen, self::TABLAS_QUE_SE_CONSERVAN, true)
                && in_array($fk->destino, self::TABLAS_A_BORRAR, true)
                && DB::table($fk->origen)->exists()) {
                $resultado[] = "{$fk->origen} → {$fk->destino}";
            }
        }

        return $resultado;
    }

    // XML y CDR de ventas y notas (se guardan como "/storage/xml/…", ver
    // ArchivoSunatController::rutaEnDisco()) y adjuntos de movimientos de caja.
    private function archivosDeLaOperacion(): array
    {
        $guardados = collect()
            ->merge(DB::table('sales')->pluck('xml'))
            ->merge(DB::table('sales')->pluck('cdr'))
            ->merge(DB::table('notes')->pluck('xml'))
            ->merge(DB::table('notes')->pluck('cdr'))
            ->map(fn ($g) => ArchivoSunatController::rutaEnDisco($g))
            ->merge(DB::table('cash_movements')->pluck('attachment_path'));

        return $guardados->filter()->unique()->values()->all();
    }

    private ?array $tablas = null;

    private function tablasDelEsquema(): array
    {
        return $this->tablas ??= array_map(
            fn ($fila) => $fila->tablename,
            DB::select('select tablename from pg_tables where schemaname = current_schema()')
        );
    }

    private function existe(string $tabla): bool
    {
        return in_array($tabla, $this->tablasDelEsquema(), true);
    }
}
