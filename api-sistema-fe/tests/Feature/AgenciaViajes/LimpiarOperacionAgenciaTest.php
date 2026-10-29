<?php

namespace Tests\Feature\AgenciaViajes;

use App\Models\Tenant;
use App\Services\AgenciaViajes\LimpiarOperacionPrueba;
use App\Services\HoraPeru;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\AgenciaViajes\Concerns\SalidasFixtures;
use Tests\TestCase;

// T-070 — Limpieza de la operación de prueba de un tenant de Agencia.
// Arma una operación chica (tour con tarifa, reserva, venta con XML/CDR,
// caja, tipo de cambio) y comprueba qué se borra y qué queda igual.
class LimpiarOperacionAgenciaTest extends TestCase
{
    use SalidasFixtures;

    private int $tourId;

    private int $tarifaId;

    private int $clienteId;

    private function armarOperacion(): void
    {
        Storage::fake('public');

        $tour = $this->crearTour();
        $proveedor = $this->proveedor();
        $item = $this->incluye($tour, 40, '20', 'amazonia', $proveedor);
        $this->tourId = $tour->id;

        $servicioId = DB::table('proveedor_servicios')->insertGetId([
            'proveedor_id' => $proveedor->id, 'destino_servicio_id' => $item->destino_servicio_id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->tarifaId = DB::table('proveedor_tarifas')->insertGetId([
            'proveedor_servicio_id' => $servicioId, 'tipo_tarifa' => 'publica', 'modalidad' => 'privado',
            'moneda' => 'PEN', 'precio_costo' => 40, 'margen_tipo' => 'porcentaje', 'margen_valor' => 0,
            'precio_venta_adulto' => 40, 'vigente_desde' => HoraPeru::hoyTexto(),
            'tip_afe_igv' => '20', 'destino_tributario' => 'amazonia', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $reserva = $this->reservaConTour($tour, [['adulto', 40]]);
        $this->clienteId = (int) DB::table('cotizaciones')
            ->join('alternativas', 'alternativas.cotizacion_id', '=', 'cotizaciones.id')
            ->where('alternativas.id', $reserva->alternativa_id)->value('cotizaciones.cliente_id');
        DB::table('clients')->where('id', $this->clienteId)->update(['saldo_a_favor' => 25]);

        $userId = Auth::guard('api')->id();
        $branchId = DB::table('branches')->insertGetId(['name' => 'Sucursal T-070', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('serie_comprobantes')->insert([
            'branch_id' => $branchId, 'tipo_comprobante_codigo' => '01', 'moneda' => 'PEN', 'serie' => 'F070',
            'correlativo_actual' => 5, 'correlativo_inicial' => 1, 'fecha_inicio' => HoraPeru::hoyTexto(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('codigo_secuencias')->where('tipo', 'cotizacion')->update(['ultimo_correlativo' => 7]);
        DB::table('codigo_secuencias')->where('tipo', 'tour')->update(['ultimo_correlativo' => 3]);

        $ventaId = DB::table('sales')->insertGetId([
            'user_id' => $userId, 'client_id' => $this->clienteId, 'igv' => 0, 'total' => 40, 'serie' => 'F070',
            'xml' => '/storage/xml/T070-F070-1.xml', 'cdr' => '/storage/cdrs/T070.zip',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Storage::disk('public')->put('xml/T070-F070-1.xml', '<xml/>');
        Storage::disk('public')->put('cdrs/T070.zip', 'zip');
        DB::table('sale_details')->insert([
            'sale_id' => $ventaId, 'product_id' => DB::table('products')->value('id'),
            'product_categorie_id' => DB::table('categories')->value('id'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sale_payments')->insert([
            'sale_id' => $ventaId, 'moneda_pago' => 'PEN', 'monto_pagado' => 40, 'tipo_cambio' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('reserva_ventas')->insert([
            'reserva_id' => $reserva->id, 'sale_id' => $ventaId,
            'reserva_item_ids' => json_encode(DB::table('reserva_items')->where('reserva_id', $reserva->id)->pluck('id')),
            'reserva_pasajero_ids' => json_encode(DB::table('reserva_pasajeros')->where('reserva_id', $reserva->id)->pluck('id')),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $cajaId = DB::table('cash_registers')->insertGetId(['branch_id' => $branchId, 'name' => 'Caja T-070', 'created_at' => now(), 'updated_at' => now()]);
        $sesionId = DB::table('cash_sessions')->insertGetId([
            'cash_register_id' => $cajaId, 'opened_by' => $userId, 'opening_amount' => 0, 'opened_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('cash_movements')->insert([
            'cash_session_id' => $sesionId, 'type' => 'sale', 'payment_method_id' => DB::table('payment_methods')->value('id'),
            'direction' => 'in', 'amount' => 40, 'reference_type' => 'sale', 'reference_id' => $ventaId,
            'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('tipo_cambio_agencia')->insert([
            'fecha' => HoraPeru::hoyTexto(), 'origen' => 'manual', 'valor' => 3.75, 'registrado_por' => $userId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function conteos(array $tablas): array
    {
        $existentes = array_map(fn ($f) => $f->tablename, DB::select('select tablename from pg_tables where schemaname = current_schema()'));

        return collect($tablas)->intersect($existentes)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }

    private function catalogo(): array
    {
        return [
            'tours' => DB::table('paquetes_plantilla')->orderBy('id')->get()->toArray(),
            'items' => DB::table('paquete_plantilla_items')->orderBy('id')->get()->toArray(),
            'tarifas' => DB::table('proveedor_tarifas')->orderBy('id')->get()->toArray(),
        ];
    }

    private function tenantDeAgencia(array $atributos = []): Tenant
    {
        $tenant = new Tenant;
        $tenant->giro = $atributos['giro'] ?? 'agencia_viajes';
        $tenant->sunat_modo = $atributos['sunat_modo'] ?? 'pruebas';

        return $tenant;
    }

    public function test_simulacion_no_modifica_nada(): void
    {
        $this->armarOperacion();
        $todas = array_merge(LimpiarOperacionPrueba::TABLAS_A_BORRAR, LimpiarOperacionPrueba::TABLAS_QUE_SE_CONSERVAN);
        $antes = $this->conteos($todas);

        $simulacion = app(LimpiarOperacionPrueba::class)->simular();

        $this->assertSame($antes, $this->conteos($todas));
        $this->assertSame(1, $simulacion['filas']['sales']);
        $this->assertSame(1, $simulacion['filas']['reserva']);
        $this->assertSame(2, $simulacion['archivos']);
        $this->assertContains('F070', $simulacion['series']);
        $this->assertTrue(Storage::disk('public')->exists('xml/T070-F070-1.xml'));
    }

    public function test_ejecutar_borra_operacion_y_conserva_configuracion(): void
    {
        $this->armarOperacion();
        $conservadasAntes = $this->conteos(LimpiarOperacionPrueba::TABLAS_QUE_SE_CONSERVAN);
        $catalogoAntes = $this->catalogo();

        app(LimpiarOperacionPrueba::class)->ejecutar();

        foreach ($this->conteos(LimpiarOperacionPrueba::TABLAS_A_BORRAR) as $tabla => $n) {
            $this->assertSame(0, $n, "La tabla {$tabla} debería quedar vacía");
        }
        $this->assertSame($conservadasAntes, $this->conteos(LimpiarOperacionPrueba::TABLAS_QUE_SE_CONSERVAN));
        // CA3: tours, sus ítems y las tarifas quedan idénticos.
        $this->assertEquals($catalogoAntes, $this->catalogo());
        $this->assertNotNull(DB::table('paquetes_plantilla')->find($this->tourId));
        $this->assertNotNull(DB::table('proveedor_tarifas')->find($this->tarifaId));
    }

    public function test_reinicia_solo_correlativos_de_operacion(): void
    {
        $this->armarOperacion();

        app(LimpiarOperacionPrueba::class)->ejecutar();

        $this->assertSame(0, (int) DB::table('serie_comprobantes')->where('serie', 'F070')->value('correlativo_actual'));
        $this->assertSame(0, (int) DB::table('codigo_secuencias')->where('tipo', 'cotizacion')->value('ultimo_correlativo'));
        $this->assertSame(3, (int) DB::table('codigo_secuencias')->where('tipo', 'tour')->value('ultimo_correlativo'));
    }

    public function test_conserva_clientes_y_borra_tipo_cambio(): void
    {
        $this->armarOperacion();

        app(LimpiarOperacionPrueba::class)->ejecutar();

        $this->assertSame(0.0, (float) DB::table('clients')->where('id', $this->clienteId)->value('saldo_a_favor'));
        $this->assertSame(0, DB::table('tipo_cambio_agencia')->count());
    }

    // La base de tests junta las tablas centrales con las del tenant: el
    // motivo "sin clasificar" siempre aparece acá (en un tenant real, no).
    private function motivos(array $atributos = []): array
    {
        return array_values(array_filter(
            app(LimpiarOperacionPrueba::class)->motivosParaNoLimpiar($this->tenantDeAgencia($atributos)),
            fn ($m) => ! str_starts_with($m, 'Tablas que el comando no sabe')
        ));
    }

    public function test_guardias_abortan(): void
    {
        $this->assertSame([], $this->motivos());
        $this->assertNotEmpty($this->motivos(['giro' => 'retail']));

        $this->armarOperacion();
        DB::table('sales')->update(['sunat_sent_at' => now()]);
        $this->assertSame([], $this->motivos());
        $this->assertNotEmpty($this->motivos(['sunat_modo' => 'produccion']));

        DB::table('creditos')->insert([
            'cliente_id' => $this->clienteId, 'monto_capital' => 100, 'tasa_interes' => 1, 'unidad_tasa' => 'mensual',
            'interes_total' => 1, 'frecuencia_unidad' => 'mes', 'dias_no_laborables' => '[]', 'numero_cuotas' => 1,
            'fecha_desembolso' => HoraPeru::hoyTexto(), 'tasa_interes_minimo' => 0, 'registrado_por' => Auth::guard('api')->id(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertNotEmpty(array_filter($this->motivos(), fn ($m) => str_contains($m, "'creditos'")));
    }

    public function test_una_tabla_nueva_sin_clasificar_frena_la_limpieza(): void
    {
        DB::statement('create table tabla_nueva_t070 (id serial primary key)');

        $motivos = app(LimpiarOperacionPrueba::class)->motivosParaNoLimpiar($this->tenantDeAgencia());

        $this->assertNotEmpty(array_filter($motivos, fn ($m) => str_contains($m, 'tabla_nueva_t070')));
    }

    public function test_falla_revierte_todo(): void
    {
        $this->armarOperacion();
        $antes = $this->conteos(LimpiarOperacionPrueba::TABLAS_A_BORRAR);
        // Una venta referida desde una tabla que no se borra hace fallar el DELETE de sales,
        // el último paso de su grupo: lo ya borrado antes tiene que volver.
        DB::statement('create table ref_t070 (sale_id bigint references sales(id))');
        DB::table('ref_t070')->insert(['sale_id' => DB::table('sales')->value('id')]);

        try {
            app(LimpiarOperacionPrueba::class)->ejecutar();
            $this->fail('Debió fallar');
        } catch (\Illuminate\Database\QueryException) {
        }

        $this->assertSame($antes, $this->conteos(LimpiarOperacionPrueba::TABLAS_A_BORRAR));
        $this->assertTrue(Storage::disk('public')->exists('xml/T070-F070-1.xml'));
    }

    public function test_borra_archivos_de_ventas(): void
    {
        $this->armarOperacion();
        DB::table('sales')->insert([
            'user_id' => Auth::guard('api')->id(), 'client_id' => $this->clienteId, 'igv' => 0,
            'xml' => '/storage/xml/no-existe.xml', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $resumen = app(LimpiarOperacionPrueba::class)->ejecutar();

        $this->assertFalse(Storage::disk('public')->exists('xml/T070-F070-1.xml'));
        $this->assertFalse(Storage::disk('public')->exists('cdrs/T070.zip'));
        $this->assertSame(2, $resumen['archivos_borrados']);
        $this->assertSame(1, $resumen['archivos_no_encontrados']);
    }
}
