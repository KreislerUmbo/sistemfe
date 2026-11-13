<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Http\Controllers\AgenciaViajes\ProveedorTarifaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\CotizacionPasajeAereo;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\DestinoServicio;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorServicio;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\Servicio;
use App\Models\AgenciaViajes\Temporada;
use App\Models\AgenciaViajes\TemporadaOcurrencia;
use App\Services\AgenciaViajes\RevisionAlternativaService;
use App\Services\HoraPeru;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\AgenciaViajes\Concerns\ConAccesoATodasLasReservas;
use Tests\TestCase;

// T-022 — las 6 revisiones de "¿lista para el cliente?" (RevisionAlternativaService) y
// "Actualizar al precio nuevo" (AlternativaItemController::actualizarTarifa). Postgres real
// (sistemafe_test_migrations), transacción por test revertida; la base central (temporadas)
// apunta a la misma base de pruebas.
class RevisionAlternativaServicioTest extends TestCase
{
    use ConAccesoATodasLasReservas;

    private string $viaje;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => env('DB_HOST', '127.0.0.1'),
            'database.connections.pgsql.port' => env('DB_PORT', '5432'),
            'database.connections.pgsql.database' => 'sistemafe_test_migrations',
            'database.connections.pgsql.username' => env('DB_USERNAME', 'root'),
            'database.connections.pgsql.password' => env('DB_PASSWORD', ''),
            'database.connections.central.database' => 'sistemafe_test_migrations',
        ]);
        DB::purge('pgsql');
        DB::purge('central');
        $this->asegurarTablasDeTemporadas();
        DB::beginTransaction();
        DB::connection('central')->beginTransaction();

        $this->verTodasLasReservas();
        $this->viaje = CarbonImmutable::parse(HoraPeru::hoyTexto())->addDays(30)->toDateString();
        $this->minimo(20);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::connection('central')->rollBack();
        DB::rollBack();
        parent::tearDown();
    }

    // La base de pruebas se arma una vez y no trae las tablas centrales de temporadas: se crean
    // acá si faltan (mismo recurso que AerolineaCatalogoTest). Las filas de cada test se revierten.
    private function asegurarTablasDeTemporadas(): void
    {
        foreach ([
            'temporadas' => 'database/migrations/2026_07_27_150000_create_proveedor_tipos_and_temporadas_tables.php',
            'temporada_ocurrencias' => 'database/migrations/2026_07_27_190000_create_temporada_ocurrencias_table.php',
        ] as $tabla => $migracion) {
            if (! Schema::connection('central')->hasTable($tabla)) {
                Artisan::call('migrate', ['--path' => [$migracion], '--force' => true]);
            }
        }
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    private function minimo(float $pct, ?int $diasVigencia = null): void
    {
        ConfiguracionAgencia::query()->delete();
        ConfiguracionAgencia::create(['margen_minimo_aceptable_pct' => $pct, 'dias_vigencia_cotizacion' => $diasVigencia]);
    }

    private function alternativa(string $estado = 'borrador', ?string $viaje = 'defecto', array $extra = []): Alternativa
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10_000_000, 99_999_999),
            'full_name' => 'Ana Pérez', 'email' => 'ana@cliente.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $viaje = $viaje === 'defecto' ? $this->viaje : $viaje;
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'CDKM-'.uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => $viaje, 'fecha_viaje_hasta' => $viaje,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 30]);

        return Alternativa::create($extra + [
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Opción 1', 'estado' => $estado,
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
    }

    private function tarifa(array $cambios = []): ProveedorTarifa
    {
        static $servicioId = null;
        if ($servicioId === null || ! ProveedorServicio::find($servicioId)) {
            $destino = DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']);
            $servicio = Servicio::create(['nombre' => 'Traslado']);
            $ds = DestinoServicio::create(['destino_atractivo_id' => $destino->id, 'servicio_id' => $servicio->id]);
            $proveedor = Proveedor::create(['razon_social' => 'Transportes Test SAC', 'estado' => true]);
            $servicioId = ProveedorServicio::create(['proveedor_id' => $proveedor->id, 'destino_servicio_id' => $ds->id])->id;
        }

        return ProveedorTarifa::create($cambios + [
            'proveedor_servicio_id' => $servicioId,
            'tipo_tarifa' => 'publica', 'modalidad' => 'privado', 'moneda' => 'PEN',
            'precio_costo' => 80, 'margen_tipo' => 'porcentaje', 'margen_valor' => 25,
            'precio_venta_adulto' => 100,
            'vigente_desde' => HoraPeru::hoyTexto(),
            'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
        ]);
    }

    // Ítem cualquiera de la alternativa (manual por defecto, tarifa_fija, PEN).
    private function item(Alternativa $alternativa, float $costo, float $venta, array $extra = []): AlternativaItem
    {
        return AlternativaItem::create($extra + [
            'alternativa_id' => $alternativa->id,
            'origen_tipo' => AlternativaItem::ORIGEN_MANUAL,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => $costo, 'precio_venta_snapshot' => $venta, 'precio_convertido' => $venta,
            'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
        ]);
    }

    private function itemDeTarifa(Alternativa $alternativa, ProveedorTarifa $tarifa, array $extra = []): AlternativaItem
    {
        return $this->item($alternativa, (float) $tarifa->precio_costo, (float) $tarifa->precio_venta_adulto, $extra + [
            'origen_tipo' => AlternativaItem::ORIGEN_PROVEEDOR,
            'proveedor_tarifa_id' => $tarifa->id,
        ]);
    }

    private function revisar(Alternativa $alternativa): array
    {
        return app(RevisionAlternativaService::class)->revisar($alternativa->fresh());
    }

    private function entrada(array $revision, string $codigo): ?array
    {
        return collect($revision)->firstWhere('codigo', $codigo);
    }

    private function codigos(array $revision): array
    {
        return array_column($revision, 'codigo');
    }

    private function editarPrecio(ProveedorTarifa $tarifa, float $venta, float $costo = 80): array
    {
        $respuesta = app(ProveedorTarifaController::class)->update(new Request([
            'tipo_tarifa' => 'publica', 'modalidad' => 'privado', 'moneda' => 'PEN',
            'precio_costo' => $costo, 'margen_tipo' => 'porcentaje', 'margen_valor' => 25,
            'precio_venta_adulto' => $venta, 'vigente_desde' => HoraPeru::hoyTexto(),
            'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
        ]), (string) $tarifa->id);

        return $respuesta->getData(true);
    }

    // ── CA1 / CA2 — bloquea ──────────────────────────────────────────

    public function test_ca1_alternativa_sin_items_bloquea(): void
    {
        $revision = $this->revisar($this->alternativa());

        $this->assertSame(['sin_servicios'], $this->codigos($revision));
        $this->assertSame('bloquea', $revision[0]['nivel']);
        $this->assertSame('lienzo', $revision[0]['destino']['seccion']);
    }

    public function test_ca2_item_con_precio_cero_bloquea_con_su_id(): void
    {
        $alt = $this->alternativa();
        $this->item($alt, 50, 120);
        $cero = $this->item($alt, 50, 0);

        $entrada = $this->entrada($this->revisar($alt), 'precio_cero');

        $this->assertSame('bloquea', $entrada['nivel']);
        $this->assertSame([$cero->id], $entrada['destino']['item_ids']);
        $this->assertSame(1, $entrada['cantidad']);
    }

    public function test_ca2_cada_fila_de_un_grupo_de_hoteles_abierto_se_revisa(): void
    {
        $alt = $this->alternativa();
        $grupo = (string) \Illuminate\Support\Str::uuid();
        $this->item($alt, 50, 120, ['grupo_opcion_id' => $grupo]);
        $cero = $this->item($alt, 50, 0, ['grupo_opcion_id' => $grupo]);

        $revision = $this->revisar($alt);

        $this->assertSame([$cero->id], $this->entrada($revision, 'precio_cero')['destino']['item_ids']);
        // Que el grupo esté abierto no es un pendiente.
        $this->assertCount(1, $revision);
    }

    // ── CA3 — markup ─────────────────────────────────────────────────

    public function test_ca3_venta_bajo_el_costo_avisa_aunque_el_minimo_sea_0(): void
    {
        $this->minimo(0);
        $alt = $this->alternativa();
        $item = $this->item($alt, 100, 90);

        $entrada = $this->entrada($this->revisar($alt), 'markup_bajo');

        $this->assertSame('aviso', $entrada['nivel']);
        $this->assertSame([$item->id], $entrada['destino']['item_ids']);
    }

    public function test_ca3_markup_5_con_minimo_10_avisa_y_con_minimo_0_no(): void
    {
        $alt = $this->alternativa();
        $this->item($alt, 100, 105);

        $this->minimo(10);
        $this->assertNotNull($this->entrada($this->revisar($alt), 'markup_bajo'));

        $this->minimo(0);
        $this->assertNull($this->entrada($this->revisar($alt), 'markup_bajo'));
    }

    public function test_ca3_se_compara_el_markup_no_el_margen(): void
    {
        // Costo 100, venta 125: markup 25 % (margen 20 %). Con mínimo 20 no avisa.
        $this->minimo(20);
        $alt = $this->alternativa();
        $this->item($alt, 100, 125);

        $this->assertSame([], $this->revisar($alt));
    }

    public function test_ca3_sin_costo_no_se_evalua_el_markup(): void
    {
        $alt = $this->alternativa();
        $this->item($alt, 0, 80);

        $this->assertSame([], $this->revisar($alt));
    }

    public function test_ca3_el_costo_se_convierte_a_la_moneda_de_la_alternativa(): void
    {
        // Costo 100 USD a 3.75 = S/ 375; venta S/ 400 (markup 6.7 %) con mínimo 20 → avisa.
        $alt = $this->alternativa(extra: ['tipo_cambio_aplicado' => 3.75]);
        $this->item($alt, 100, 400, ['moneda_costo' => 'USD', 'precio_venta_snapshot' => 106.67, 'precio_convertido' => 400]);

        $this->assertNotNull($this->entrada($this->revisar($alt), 'markup_bajo'));
    }

    // ── CA4 / CA6 — tarifas ──────────────────────────────────────────

    public function test_ca4_editar_el_precio_de_una_tarifa_usada_avisa_precio_nuevo(): void
    {
        $alt = $this->alternativa();
        $tarifa = $this->tarifa();
        $item = $this->itemDeTarifa($alt, $tarifa);

        $payload = $this->editarPrecio($tarifa, 150);
        $nueva = $payload['proveedor_tarifa'];
        $this->assertSame($tarifa->id, $nueva['version_anterior_id']);

        $revision = $this->revisar($alt);
        $entrada = $this->entrada($revision, 'tarifa_precio_nuevo');

        $this->assertSame('aviso', $entrada['nivel']);
        $this->assertSame([$item->id], $entrada['destino']['item_ids']);
        $this->assertSame($item->id, $entrada['detalle'][0]['item_id']);
        $this->assertSame($nueva['id'], $entrada['detalle'][0]['tarifa_nueva_id']);
        $this->assertEqualsWithDelta(100.0, $entrada['detalle'][0]['precio_actual'], 0.001);
        $this->assertEqualsWithDelta(150.0, $entrada['detalle'][0]['precio_nuevo'], 0.001);
        // La versión vieja también está vencida, pero el aviso útil es uno solo.
        $this->assertNull($this->entrada($revision, 'tarifa_no_vigente'));
    }

    public function test_ca4_la_cadena_de_versiones_apunta_a_la_vigente(): void
    {
        $alt = $this->alternativa();
        $v1 = $this->tarifa();
        $this->itemDeTarifa($alt, $v1);
        $v2 = $this->editarPrecio($v1, 150)['proveedor_tarifa'];
        $v3 = $this->editarPrecio(ProveedorTarifa::find($v2['id']), 170)['proveedor_tarifa'];

        $entrada = $this->entrada($this->revisar($alt), 'tarifa_precio_nuevo');

        $this->assertSame($v3['id'], $entrada['detalle'][0]['tarifa_nueva_id']);
    }

    public function test_ca6_tarifa_vencida_antes_del_viaje_avisa(): void
    {
        $alt = $this->alternativa();
        $tarifa = $this->tarifa(['vigente_hasta' => CarbonImmutable::parse($this->viaje)->subDay()->toDateString()]);
        $item = $this->itemDeTarifa($alt, $tarifa);

        $entrada = $this->entrada($this->revisar($alt), 'tarifa_no_vigente');

        $this->assertSame('aviso', $entrada['nivel']);
        $this->assertSame([$item->id], $entrada['destino']['item_ids']);
    }

    public function test_ca6_tarifa_que_vale_hasta_el_dia_del_viaje_no_avisa(): void
    {
        $alt = $this->alternativa();
        $this->itemDeTarifa($alt, $this->tarifa(['vigente_hasta' => $this->viaje]));

        $this->assertSame([], $this->revisar($alt));
    }

    public function test_ca6_tarifa_desactivada_avisa(): void
    {
        $alt = $this->alternativa();
        $this->itemDeTarifa($alt, $this->tarifa(['activo' => false]));

        $this->assertNotNull($this->entrada($this->revisar($alt), 'tarifa_no_vigente'));
    }

    public function test_ca6_tarifa_de_temporada_sin_ocurrencia_que_cubra_el_viaje_avisa(): void
    {
        $temporada = Temporada::create(['nombre' => 'Fiestas', 'tipo' => 'alta', 'giro' => 'agencia_viajes']);
        $viaje = CarbonImmutable::parse($this->viaje);
        TemporadaOcurrencia::create([
            'temporada_id' => $temporada->id, 'anio' => (int) $viaje->format('Y'),
            'fecha_desde' => $viaje->addDays(10)->toDateString(), 'fecha_hasta' => $viaje->addDays(20)->toDateString(),
        ]);
        $alt = $this->alternativa();
        $item = $this->itemDeTarifa($alt, $this->tarifa(['temporada_id' => $temporada->id]));

        $entrada = $this->entrada($this->revisar($alt), 'tarifa_no_vigente');

        $this->assertSame([$item->id], $entrada['destino']['item_ids']);
    }

    public function test_ca6_tarifa_de_temporada_que_cubre_el_viaje_no_avisa(): void
    {
        $temporada = Temporada::create(['nombre' => 'Fiestas', 'tipo' => 'alta', 'giro' => 'agencia_viajes']);
        $viaje = CarbonImmutable::parse($this->viaje);
        TemporadaOcurrencia::create([
            'temporada_id' => $temporada->id, 'anio' => (int) $viaje->format('Y'),
            'fecha_desde' => $viaje->subDays(3)->toDateString(), 'fecha_hasta' => $viaje->addDays(3)->toDateString(),
        ]);
        $alt = $this->alternativa();
        $this->itemDeTarifa($alt, $this->tarifa(['temporada_id' => $temporada->id]));

        $this->assertSame([], $this->revisar($alt));
    }

    public function test_ca6_sin_fecha_de_viaje_no_se_evalua_la_vigencia_ni_la_temporada(): void
    {
        $alt = $this->alternativa('borrador', null);
        $this->itemDeTarifa($alt, $this->tarifa(['vigente_hasta' => CarbonImmutable::parse(HoraPeru::hoyTexto())->subDays(5)->toDateString()]));

        $this->assertNull($this->entrada($this->revisar($alt), 'tarifa_no_vigente'));
    }

    // ── CA5 — actualizar al precio nuevo ─────────────────────────────

    public function test_ca5_actualizar_tarifa_reengancha_conserva_descuento_y_recalcula_el_total(): void
    {
        $alt = $this->alternativa();
        $tarifa = $this->tarifa();
        $item = $this->itemDeTarifa($alt, $tarifa, ['descuento_pct' => 10, 'precio_convertido' => 90]);
        $alt->update(['total' => 90]);
        $nueva = $this->editarPrecio($tarifa, 150, 100)['proveedor_tarifa'];

        $respuesta = app(AlternativaItemController::class)->actualizarTarifa(new Request(), (string) $item->id);

        $this->assertSame(200, $respuesta->getStatusCode(), json_encode($respuesta->getData(true)));
        $item = $item->fresh();
        $this->assertSame($nueva['id'], $item->proveedor_tarifa_id);
        $this->assertEqualsWithDelta(150.0, (float) $item->precio_venta_snapshot, 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $item->costo_snapshot, 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $item->descuento_pct, 0.001);
        $this->assertEqualsWithDelta(135.0, (float) $item->precio_convertido, 0.001);
        $this->assertEqualsWithDelta(135.0, (float) $alt->fresh()->total, 0.001);
        $this->assertNull($this->entrada($this->revisar($alt), 'tarifa_precio_nuevo'));
    }

    public function test_ca5_actualizar_tarifa_en_alternativa_aceptada_da_422(): void
    {
        $alt = $this->alternativa('aceptada');
        $tarifa = $this->tarifa();
        $item = $this->itemDeTarifa($alt, $tarifa);
        $this->editarPrecio($tarifa, 150);

        $respuesta = app(AlternativaItemController::class)->actualizarTarifa(new Request(), (string) $item->id);

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertSame($tarifa->id, $item->fresh()->proveedor_tarifa_id);
    }

    public function test_ca5_sin_version_siguiente_da_422(): void
    {
        $alt = $this->alternativa();
        $item = $this->itemDeTarifa($alt, $this->tarifa());

        $respuesta = app(AlternativaItemController::class)->actualizarTarifa(new Request(), (string) $item->id);

        $this->assertSame(422, $respuesta->getStatusCode());
    }

    public function test_ca5_ignora_un_id_enviado_por_el_frontend(): void
    {
        $alt = $this->alternativa();
        $tarifa = $this->tarifa();
        $otra = $this->tarifa(['precio_venta_adulto' => 1]);
        $item = $this->itemDeTarifa($alt, $tarifa);
        $nueva = $this->editarPrecio($tarifa, 150)['proveedor_tarifa'];

        app(AlternativaItemController::class)->actualizarTarifa(new Request(['proveedor_tarifa_id' => $otra->id]), (string) $item->id);

        $this->assertSame($nueva['id'], $item->fresh()->proveedor_tarifa_id);
    }

    // ── CA7 / CA8 — aéreo y propuesta vencida ────────────────────────

    private function pasajeAereo(Alternativa $alt, Carbon|CarbonImmutable|null $validaHasta): AlternativaItem
    {
        $item = $this->item($alt, 300, 400, ['origen_tipo' => AlternativaItem::ORIGEN_PASAJE_AEREO]);
        CotizacionPasajeAereo::create([
            'alternativa_item_id' => $item->id, 'aerolinea' => 'LATAM', 'moneda' => 'PEN',
            'tarifa_base_adulto' => 300, 'fecha_cotizado' => now(),
            'tarifa_valida_hasta' => $validaHasta?->utc()->format('Y-m-d H:i:s'),
            'costo_total' => 300, 'precio_venta_total' => 400,
        ]);

        return $item;
    }

    public function test_ca7_pasaje_aereo_con_tarifa_vencida_avisa(): void
    {
        $alt = $this->alternativa();
        $item = $this->pasajeAereo($alt, CarbonImmutable::now()->subHour());

        $entrada = $this->entrada($this->revisar($alt), 'pasaje_tarifa_vencida');

        $this->assertSame('aviso', $entrada['nivel']);
        $this->assertSame([$item->id], $entrada['destino']['item_ids']);
        $this->assertSame('pasaje_aereo', $entrada['destino']['seccion']);
    }

    public function test_ca7_pasaje_que_vence_antes_que_la_propuesta_avisa(): void
    {
        $this->minimo(20, 7);
        $alt = $this->alternativa();
        $this->pasajeAereo($alt, CarbonImmutable::now()->addDays(2));

        $this->assertNotNull($this->entrada($this->revisar($alt), 'pasaje_tarifa_vencida'));
    }

    public function test_ca7_pasaje_vigente_o_sin_plazo_no_avisa(): void
    {
        $this->minimo(20, 7);
        $alt = $this->alternativa();
        $this->pasajeAereo($alt, CarbonImmutable::now()->addDays(20));
        $this->pasajeAereo($alt, null);

        $this->assertSame([], $this->revisar($alt));
    }

    public function test_ca8_alternativa_enviada_y_vencida_avisa(): void
    {
        $alt = $this->alternativa('enviada', 'defecto', ['fecha_vencimiento' => now()->subDay()]);
        $this->item($alt, 50, 100);

        $entrada = $this->entrada($this->revisar($alt), 'propuesta_vencida');

        $this->assertSame('aviso', $entrada['nivel']);
        $this->assertSame('datos_cotizacion', $entrada['destino']['seccion']);
    }

    public function test_ca8_enviada_vigente_o_borrador_con_fecha_pasada_no_avisa(): void
    {
        $vigente = $this->alternativa('enviada', 'defecto', ['fecha_vencimiento' => now()->addDays(3)]);
        $this->item($vigente, 50, 100);
        $this->assertSame([], $this->revisar($vigente));

        $borrador = $this->alternativa('borrador', 'defecto', ['fecha_vencimiento' => now()->subDay()]);
        $this->item($borrador, 50, 100);
        $this->assertNull($this->entrada($this->revisar($borrador), 'propuesta_vencida'));
    }

    // ── CA9 / CA11 — silencio y agrupación ───────────────────────────

    public function test_ca9_alternativa_correcta_devuelve_lista_vacia(): void
    {
        $alt = $this->alternativa();
        $this->item($alt, 80, 100);
        $this->itemDeTarifa($alt, $this->tarifa());
        $this->pasajeAereo($alt, CarbonImmutable::now()->addDays(60));

        $this->assertSame([], $this->revisar($alt));
    }

    public function test_ca11_tres_items_con_el_mismo_problema_son_una_sola_entrada(): void
    {
        $alt = $this->alternativa();
        $ids = [];
        foreach ([1, 2, 3] as $i) {
            $ids[] = $this->item($alt, 100, 90 + $i)->id;
        }

        $revision = $this->revisar($alt);

        $this->assertSame(['markup_bajo'], $this->codigos($revision));
        $this->assertSame(3, $revision[0]['cantidad']);
        $this->assertEqualsCanonicalizing($ids, $revision[0]['destino']['item_ids']);
    }
}
