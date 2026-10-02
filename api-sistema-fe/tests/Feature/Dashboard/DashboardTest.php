<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Http\Controllers\Advance\AdvanceController;
use App\Models\User;
use App\Services\Dashboard\DashboardService;
use App\Services\Tenancy\GiroActual;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Creditos\CreditosTestCase;

/**
 * Inicio con datos reales (retail / agencia): cada indicador con resultado exacto sobre un
 * dataset conocido. "Hoy" = 15/10/2026 (Lima). Reusa la base de los tests de Créditos (Postgres
 * real, transacción revertida, reloj fijo).
 */
final class DashboardTest extends CreditosTestCase
{
    private const VENTAS = ['list_sale', 'list_nota_electronica'];

    private User $admin;
    private int $clienteId;
    private int $categoriaId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(GiroActual::class, new GiroActual('retail'));
        $this->hoy('2026-10-15');
        $this->admin = $this->usuario(self::VENTAS);
        $this->clienteId = $this->cliente()->id;
        $this->categoriaId = DB::table('categories')->insertGetId(['title' => 'Cat dashboard', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function venta(string $fecha, string $total, array $extra = []): int
    {
        return DB::table('sales')->insertGetId([
            'user_id' => $this->admin->id,
            'client_id' => $this->clienteId,
            'igv' => 0,
            'total' => $total,
            'currency' => 'PEN',
            'type' => 'sale',
            'date' => $fecha,
            'tipo_comprobante_codigo' => '03',
            'serie' => 'B001',
            'correlativo' => 1,
            'cdr' => 'cdr.zip',
            'created_at' => "{$fecha} 15:00:00",
            'updated_at' => now(),
            ...$extra,
        ]);
    }

    private function notaCredito(int $ventaId, string $fecha, string $monto, string $estado = 'aceptado'): void
    {
        DB::table('notes')->insert([
            'sale_id' => $ventaId, 'tipo_doc' => '07', 'tipo_doc_afectado' => '03', 'serie_afectada' => 'B001',
            'correlativo_afectado' => 1, 'serie' => 'BC01', 'cod_motivo' => '01', 'des_motivo' => 'Anulación',
            'tipo_afectacion' => 'total', 'client_id' => $this->clienteId, 'cod_tipo_doc_cliente' => '1',
            'user_id' => $this->admin->id, 'currency' => 'PEN', 'mto_imp_venta' => $monto, 'status' => $estado,
            'created_at' => "{$fecha} 16:00:00", 'updated_at' => now(),
        ]);
    }

    private function producto(string $titulo, float $stock, bool $controla = true, ?string $sku = null): int
    {
        return DB::table('products')->insertGetId([
            'title' => $titulo, 'categorie_id' => $this->categoriaId, 'price_general' => 10, 'is_discount' => 0,
            'disponiblidad' => 1, 'unidad_medida' => 'NIU', 'stock' => $stock, 'include_igv' => 1, 'is_icbper' => 0,
            'is_ivap' => 0, 'controla_stock' => $controla, 'sku' => $sku, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function inicio(?User $usuario = null): array
    {
        $usuario ??= $this->admin;
        Auth::guard('api')->setUser($usuario);

        return app(DashboardService::class)->inicio($usuario);
    }

    public function test_ventas_netas_por_moneda_con_internas_y_comparacion_con_el_mes_anterior(): void
    {
        $hoy1 = $this->venta('2026-10-15', '100.00');
        $this->venta('2026-10-15', '50.00', ['tipo_comprobante_codigo' => 'NV', 'serie' => 'NV01']);
        $this->venta('2026-10-03', '250.00');
        $this->venta('2026-10-15', '40.00', ['currency' => 'USD']);
        $this->venta('2026-10-15', '999.00', ['type' => 'advance']);                 // adelanto: no es venta
        $this->venta('2026-10-15', '777.00', ['deleted_at' => now()]);               // eliminada
        $this->venta('2026-09-10', '200.00');                                        // mes anterior, antes del 15
        $this->venta('2026-09-20', '900.00');                                        // mes anterior, después del 15: no compara
        $this->notaCredito($hoy1, '2026-10-15', '30.00');
        $this->notaCredito($hoy1, '2026-10-15', '500.00', 'rechazado');              // rechazada: no resta

        $ventas = $this->inicio()['bloques']['ventas'];

        $hoyPen = collect($ventas['hoy'])->keyBy('moneda');
        $this->assertSame(['PEN', 'USD'], $hoyPen->keys()->all());
        $this->assertSame([
            'moneda' => 'PEN', 'bruto' => '150.00', 'notas_credito' => '30.00', 'notas_credito_cantidad' => 1, 'neto' => '120.00',
            'comprobantes' => 2, 'internos' => 1, 'fiscal' => '100.00', 'interno' => '50.00', 'ticket_promedio' => '75.00',
        ], $hoyPen['PEN']);
        $this->assertSame('40.00', $hoyPen['USD']['neto']);

        $mesPen = collect($ventas['mes'])->keyBy('moneda')['PEN'];
        $this->assertSame(['400.00', '370.00', '200.00', '85.0'], [$mesPen['bruto'], $mesPen['neto'], $mesPen['neto_mes_anterior'], $mesPen['variacion']]);
        $this->assertNull(collect($ventas['mes'])->keyBy('moneda')['USD']['variacion']);   // sin base en USD
        $this->assertSame(['desde' => '2026-09-01', 'hasta' => '2026-09-15'], $ventas['periodo_anterior']);
    }

    public function test_fecha_de_negocio_y_ventas_por_dia(): void
    {
        // Sin fecha de emisión: cuenta el día de registro en Lima (04:00 UTC del 15 = 23:00 del 14 en Lima).
        $this->venta('2026-10-14', '10.00', ['date' => null, 'created_at' => '2026-10-15 04:00:00']);
        $this->venta('2026-10-15', '20.00');
        $this->venta('2026-09-15', '5.00');   // fuera de los 30 días

        $dias = collect($this->inicio()['bloques']['ventas_dias']['dias'])->keyBy('fecha');
        $this->assertCount(30, $dias);
        $this->assertSame('2026-09-16', $dias->keys()->first());
        $this->assertSame(['PEN' => '10.00'], (array) $dias['2026-10-14']['montos']);
        $this->assertSame(['PEN' => '20.00'], (array) $dias['2026-10-15']['montos']);
        $this->assertSame(['PEN' => '0.00'], (array) $dias['2026-10-01']['montos']);
    }

    public function test_pendientes_sunat_por_enviar_con_error_y_sin_nota_de_venta(): void
    {
        $this->venta('2026-10-14', '10.00', ['correlativo' => null, 'cdr' => null]);                                 // por enviar
        $this->venta('2026-10-13', '20.00', ['cdr' => null, 'sunat_error_message' => 'RUC no válido']);             // con error
        $this->venta('2026-10-12', '30.00', ['type' => 'advance', 'correlativo' => null, 'cdr' => null]);         // adelanto por enviar
        $this->venta('2026-10-12', '40.00', ['tipo_comprobante_codigo' => 'NV', 'cdr' => null]);                   // NV: nunca va a SUNAT
        $this->venta('2026-10-12', '50.00');                                                                       // aceptada
        $this->notaCredito($this->venta('2026-10-01', '60.00'), '2026-10-02', '60.00', 'rechazado');

        $sunat = $this->inicio()['bloques']['sunat'];
        $this->assertSame([2, 1], [$sunat['por_enviar'], $sunat['con_error']]);
        $this->assertSame(['2026-10-12', '2026-10-13', '2026-10-14'], array_column($sunat['lista'], 'fecha'));   // la más antigua primero
        $this->assertSame([true, 'error', 'RUC no válido'], [$sunat['lista'][0]['adelanto'], $sunat['lista'][1]['estado'], $sunat['lista'][1]['error']]);
        $this->assertSame(['pendientes' => 0, 'rechazadas' => 1], $sunat['notas']);
    }

    public function test_por_cobrar_por_moneda_con_vencidas(): void
    {
        $vencida = $this->venta('2026-09-01', '300.00', ['saldo_pendiente' => '300.00', 'credit_type' => 'libre', 'fecha_limite_pago' => '2026-10-01', 'condicion_pago' => 'credito']);
        $this->venta('2026-10-10', '200.00', ['saldo_pendiente' => '150.50', 'credit_type' => 'libre', 'fecha_limite_pago' => '2026-11-01', 'condicion_pago' => 'credito']);
        $this->venta('2026-10-10', '80.00', ['currency' => 'USD', 'saldo_pendiente' => '80.00']);
        $this->venta('2026-10-10', '90.00', ['saldo_pendiente' => '0']);   // cobrada

        $porCobrar = collect($this->inicio()['bloques']['por_cobrar']['por_moneda'])->keyBy('moneda');
        $this->assertSame(['moneda' => 'PEN', 'ventas' => 2, 'clientes' => 1, 'saldo' => '450.50', 'vencidas' => 1, 'saldo_vencido' => '300.00'], $porCobrar['PEN']);
        $this->assertSame('80.00', $porCobrar['USD']['saldo']);
        $this->assertGreaterThan(0, $vencida);
    }

    public function test_productos_mas_vendidos_del_mes_y_sin_stock(): void
    {
        $arroz = $this->producto('Arroz', 10);
        $azucar = $this->producto('Azúcar', 0);
        $this->producto('Servicio', -3, false);                                                  // sin control de stock
        $this->producto('Adelanto a cuenta', 0, true, AdvanceController::SKU_PRODUCTO_ADELANTO);   // ficticio
        $detalle = fn (int $venta, int $producto, string $cantidad) => DB::table('sale_details')->insert([
            'sale_id' => $venta, 'product_id' => $producto, 'product_categorie_id' => $this->categoriaId, 'quantity' => $cantidad,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $detalle($this->venta('2026-10-10', '30.00'), $arroz, '3');
        $detalle($v = $this->venta('2026-10-11', '50.00'), $azucar, '5');
        $detalle($v, $arroz, '1');
        $detalle($this->venta('2026-09-30', '99.00'), $arroz, '50');   // mes anterior

        $productos = $this->inicio()['bloques']['productos'];
        $this->assertSame([
            ['id' => $azucar, 'producto' => 'Azúcar', 'cantidad' => '5', 'ventas' => 1],
            ['id' => $arroz, 'producto' => 'Arroz', 'cantidad' => '4', 'ventas' => 2],
        ], $productos['top']);
        $this->assertSame(1, $productos['sin_stock']);
        $this->assertSame('Azúcar', $productos['sin_stock_lista'][0]['producto']);
    }

    public function test_agencia_cotizaciones_del_mes_y_viajes_proximos(): void
    {
        $this->app->instance(GiroActual::class, new GiroActual('agencia_viajes'));
        $cotizacion = fn (string $codigo, string $creada) => DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'T', 'codigo' => $codigo, 'cliente_id' => $this->clienteId, 'destino' => 'Tarapoto',
            'created_at' => "{$creada} 15:00:00", 'updated_at' => now(),
        ]);
        $alternativa = fn (int $cot, string $estado) => DB::table('alternativas')->insertGetId([
            'cotizacion_id' => $cot, 'nombre' => 'Opción', 'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1,
            'tipo_cambio_origen' => 'manual', 'estado' => $estado, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $reserva = function (int $alt, string $estado, string $desde) {
            $id = DB::table('reserva')->insertGetId(['alternativa_id' => $alt, 'estado' => $estado, 'fecha_viaje_desde' => $desde, 'fecha_viaje_hasta' => $desde, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('reserva_pasajeros')->insert([['reserva_id' => $id, 'created_at' => now(), 'updated_at' => now()], ['reserva_id' => $id, 'created_at' => now(), 'updated_at' => now()]]);

            return $id;
        };

        $proxima = $reserva($alternativa($cotizacion('T-1', '2026-10-02'), 'aceptada'), 'activa', '2026-10-18');
        $reserva($alternativa($cotizacion('T-2', '2026-10-05'), 'aceptada'), 'cancelada', '2026-10-19');   // anulada
        $alternativa($cotizacion('T-3', '2026-10-06'), 'enviada');
        $cotizacion('T-4', '2026-10-07');                                                                  // borrador
        $reserva($alternativa($cotizacion('T-5', '2026-09-20'), 'aceptada'), 'activa', '2026-10-30');      // mes anterior y fuera de 7 días

        $bloques = $this->inicio($this->usuario(['cotizaciones.ver_todas', 'reservas.ver_todas']))['bloques'];

        $this->assertSame(['creadas' => 4, 'reservadas' => 1, 'enviadas' => 1, 'borrador' => 1, 'conversion' => '25.0'], $bloques['cotizaciones']);
        $this->assertSame(1, $bloques['proximos']['viajes_total']);
        $this->assertSame([$proxima, '2026-10-18', 2], [
            $bloques['proximos']['viajes'][0]['id'], $bloques['proximos']['viajes'][0]['desde'], $bloques['proximos']['viajes'][0]['pasajeros'],
        ]);
        $this->assertSame('2026-10-22', $bloques['proximos']['hasta']);
    }

    public function test_bloques_segun_permisos_y_giro(): void
    {
        $sinPermisos = $this->usuario([]);
        $this->assertSame([], $this->inicio($sinPermisos)['bloques']);

        // Sin permiso de notas: el bloque SUNAT no informa notas.
        $soloVentas = $this->usuario(['list_sale']);
        $this->assertNull($this->inicio($soloVentas)['bloques']['sunat']['notas']);

        // Agencia: sin productos; cotizaciones y próximos solo con sus permisos.
        $this->app->instance(GiroActual::class, new GiroActual('agencia_viajes'));
        $this->app->forgetInstance(DashboardService::class);
        $agencia = $this->inicio($this->usuario(['list_sale', 'cotizaciones.ver_todas', 'reservas.ver_todas']));
        $this->assertSame(['ventas', 'ventas_dias', 'sunat', 'por_cobrar', 'cotizaciones', 'proximos'], array_keys($agencia['bloques']));
        $this->assertSame('agencia_viajes', $agencia['giro']);
    }
}
