<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaDestino;
use App\Models\AgenciaViajes\AlternativaItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// Sesión M5 — plan-ejecucion-matriz-hoteles-cotizador.md fila M5.
// AlternativaController::opcionesHoteles() es privado — se invoca vía
// reflexión, mismo patrón que Sesion12f3PdfPorDestinoTest para
// itinerarioAlternativa()/incluyePorDestino() (no se ejercita pdf()
// completo/DomPDF, ningún otro test de la suite lo hace).
class SesionM5OpcionesHotelesPdfTest extends TestCase
{
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
        ]);
        DB::purge('pgsql');
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function invocar(Alternativa $alternativa): array
    {
        $controller = app(AlternativaController::class);
        $method = new \ReflectionMethod(AlternativaController::class, 'opcionesHoteles');
        $method->setAccessible(true);

        return $method->invoke($controller, $alternativa->fresh(['items']));
    }

    private function invocarIncluye(Alternativa $alternativa): array
    {
        $controller = app(AlternativaController::class);
        $method = new \ReflectionMethod(AlternativaController::class, 'incluyePorDestino');
        $method->setAccessible(true);

        return $method->invoke($controller, $alternativa->fresh(['destinos.destinoAtractivo', 'items']));
    }

    private function crearAlternativa(): Alternativa
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '55667788', 'full_name' => 'Cliente Test M5',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-M5-' . random_int(1000, 9999), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa M5', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
    }

    // Ítem real de hotel (origen_tipo=proveedor + proveedor_tarifa_id) —
    // el único camino por el que resolverNombreItem() devuelve
    // "{hotel} · {tipo_habitacion}", que opcionesHoteles() necesita para
    // pivotar la matriz.
    private function crearItemHotel(
        Alternativa $alternativa,
        ?string $grupoOpcionId,
        bool $opcionElegida,
        string $nombreHotel,
        string $tipoHabitacion,
        float $precio,
    ): AlternativaItem {
        $destinoAtractivoId = DB::table('destinos_atractivos')->insertGetId([
            'nombre' => 'Zona Test M5 ' . random_int(1000, 9999), 'tipo' => 'lugar', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $servicioId = DB::table('servicios')->insertGetId(['nombre' => 'Hospedaje Test M5', 'created_at' => now(), 'updated_at' => now()]);
        $destinoServicioId = DB::table('destino_servicio')->insertGetId([
            'destino_atractivo_id' => $destinoAtractivoId, 'servicio_id' => $servicioId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedorId = DB::table('proveedores')->insertGetId([
            'razon_social' => $nombreHotel, 'estado' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedorServicioId = DB::table('proveedor_servicios')->insertGetId([
            'proveedor_id' => $proveedorId, 'destino_servicio_id' => $destinoServicioId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $tarifaId = DB::table('proveedor_tarifas')->insertGetId([
            'proveedor_servicio_id' => $proveedorServicioId, 'tipo_tarifa' => 'publica', 'modalidad' => 'privado', 'moneda' => 'PEN',
            'precio_costo' => $precio * 0.7, 'margen_tipo' => 'fijo', 'margen_valor' => $precio * 0.3, 'precio_venta_adulto' => $precio,
            'tipo_habitacion' => $tipoHabitacion, 'vigente_desde' => '2026-01-01', 'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'proveedor', 'proveedor_tarifa_id' => $tarifaId,
            'grupo_opcion_id' => $grupoOpcionId, 'opcion_elegida' => $opcionElegida,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => $precio * 0.7, 'precio_venta_snapshot' => $precio, 'precio_convertido' => $precio,
        ]);
    }

    public function test_sin_grupos_devuelve_array_vacio(): void
    {
        $alternativa = $this->crearAlternativa();
        $this->crearItemHotel($alternativa, null, false, 'Hotel Suelto', 'doble', 150);

        $this->assertSame([], $this->invocar($alternativa));
    }

    public function test_grupo_con_dos_hoteles_mismo_tipo_habitacion_una_columna(): void
    {
        $alternativa = $this->crearAlternativa();
        $grupo = (string) Str::uuid();
        $this->crearItemHotel($alternativa, $grupo, false, 'Hotel Amasisa', 'doble', 120);
        $this->crearItemHotel($alternativa, $grupo, false, 'Hotel Marco Antonio', 'doble', 140);

        $resultado = $this->invocar($alternativa);

        $this->assertCount(1, $resultado);
        $this->assertSame(['doble'], $resultado[0]['tipos_habitacion']);
        $this->assertCount(2, $resultado[0]['filas']);
        $this->assertFalse($resultado[0]['resuelto']);
        $nombres = array_column($resultado[0]['filas'], 'hotel');
        $this->assertContains('Hotel Amasisa', $nombres);
        $this->assertContains('Hotel Marco Antonio', $nombres);
    }

    public function test_un_hotel_con_varios_tipos_habitacion_pivota_a_columnas(): void
    {
        $alternativa = $this->crearAlternativa();
        $grupo = (string) Str::uuid();
        $this->crearItemHotel($alternativa, $grupo, false, 'Hotel Rio Mayo', 'doble', 199);
        $this->crearItemHotel($alternativa, $grupo, false, 'Hotel Rio Mayo', 'triple', 175);
        $this->crearItemHotel($alternativa, $grupo, false, 'Hotel Rio Mayo', 'familiar', 150);

        $resultado = $this->invocar($alternativa);

        $this->assertCount(1, $resultado[0]['filas'], 'un solo hotel, aunque tenga 3 tarifas, es UNA fila con 3 columnas');
        $fila = $resultado[0]['filas'][0];
        $this->assertSame('Hotel Rio Mayo', $fila['hotel']);
        $this->assertEquals(199.0, $fila['precios']['doble']);
        $this->assertEquals(175.0, $fila['precios']['triple']);
        $this->assertEquals(150.0, $fila['precios']['familiar']);
    }

    public function test_hotel_sin_una_columna_de_otro_hotel_del_grupo_queda_sin_precio(): void
    {
        $alternativa = $this->crearAlternativa();
        $grupo = (string) Str::uuid();
        $this->crearItemHotel($alternativa, $grupo, false, 'Hotel A', 'doble', 100);
        $this->crearItemHotel($alternativa, $grupo, false, 'Hotel A', 'familiar', 180);
        $this->crearItemHotel($alternativa, $grupo, false, 'Hotel B', 'doble', 110);
        // Hotel B no tiene tarifa 'familiar' — su fila no debe traer esa clave.

        $resultado = $this->invocar($alternativa);

        $this->assertSame(['doble', 'familiar'], $resultado[0]['tipos_habitacion']);
        $filaB = collect($resultado[0]['filas'])->firstWhere('hotel', 'Hotel B');
        $this->assertArrayNotHasKey('familiar', $filaB['precios']);
        $this->assertArrayHasKey('doble', $filaB['precios']);
    }

    public function test_columnas_se_ordenan_por_el_catalogo_no_por_orden_de_insercion(): void
    {
        $alternativa = $this->crearAlternativa();
        $grupo = (string) Str::uuid();
        // Insertadas fuera de orden a propósito: familiar, simple, doble.
        $this->crearItemHotel($alternativa, $grupo, false, 'Hotel X', 'familiar', 200);
        $this->crearItemHotel($alternativa, $grupo, false, 'Hotel X', 'simple', 90);
        $this->crearItemHotel($alternativa, $grupo, false, 'Hotel X', 'doble', 130);

        $resultado = $this->invocar($alternativa);

        $this->assertSame(['simple', 'doble', 'familiar'], $resultado[0]['tipos_habitacion']);
    }

    public function test_grupo_resuelto_marca_la_fila_elegida(): void
    {
        $alternativa = $this->crearAlternativa();
        $grupo = (string) Str::uuid();
        $this->crearItemHotel($alternativa, $grupo, false, 'Hotel No Elegido', 'doble', 100);
        $this->crearItemHotel($alternativa, $grupo, true, 'Hotel Elegido', 'doble', 120);

        $resultado = $this->invocar($alternativa);

        $this->assertTrue($resultado[0]['resuelto']);
        $elegida = collect($resultado[0]['filas'])->firstWhere('hotel', 'Hotel Elegido');
        $noElegida = collect($resultado[0]['filas'])->firstWhere('hotel', 'Hotel No Elegido');
        $this->assertTrue($elegida['elegida']);
        $this->assertFalse($noElegida['elegida']);
    }

    public function test_dos_grupos_distintos_generan_dos_bloques_independientes(): void
    {
        $alternativa = $this->crearAlternativa();
        $grupoHospedajeCusco = (string) Str::uuid();
        $grupoHospedajeLima = (string) Str::uuid();
        $this->crearItemHotel($alternativa, $grupoHospedajeCusco, false, 'Hotel Cusco A', 'doble', 300);
        $this->crearItemHotel($alternativa, $grupoHospedajeCusco, false, 'Hotel Cusco B', 'doble', 320);
        $this->crearItemHotel($alternativa, $grupoHospedajeLima, false, 'Hotel Lima A', 'doble', 250);

        $resultado = $this->invocar($alternativa);

        $this->assertCount(2, $resultado);
        $this->assertCount(2, $resultado[0]['filas']);
        $this->assertCount(1, $resultado[1]['filas']);
    }

    public function test_items_sueltos_sin_grupo_no_aparecen_en_ningun_bloque(): void
    {
        $alternativa = $this->crearAlternativa();
        $grupo = (string) Str::uuid();
        $this->crearItemHotel($alternativa, $grupo, false, 'Hotel Del Grupo', 'doble', 100);
        $this->crearItemHotel($alternativa, null, false, 'Hotel Suelto Sin Grupo', 'doble', 999);

        $resultado = $this->invocar($alternativa);

        $this->assertCount(1, $resultado);
        $this->assertCount(1, $resultado[0]['filas']);
        $this->assertSame('Hotel Del Grupo', $resultado[0]['filas'][0]['hotel']);
    }

    // Hallazgo real generando el PDF de verdad contra agencia-demo (no en
    // el diseño original de M5): un grupo de 2 hoteles × varios tipos de
    // habitación listaba las 5 combinaciones, UNA POR UNA, también en
    // "Incluye" — redundante con la tabla nueva de "Opciones de hoteles",
    // que ya muestra exactamente esa información en el mismo documento.
    public function test_incluye_por_destino_excluye_items_con_grupo_opcion_pero_conserva_los_sueltos(): void
    {
        $alternativa = $this->crearAlternativa();
        AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => 'Tarapoto', 'orden' => 1]);
        $grupo = (string) Str::uuid();
        $this->crearItemHotel($alternativa, $grupo, false, 'Hotel A', 'doble', 100);
        $this->crearItemHotel($alternativa, $grupo, false, 'Hotel B', 'doble', 110);
        $itemSuelto = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'descripcion_manual' => 'Traslado aeropuerto',
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 10, 'precio_venta_snapshot' => 15, 'precio_convertido' => 15,
        ]);

        $bloques = $this->invocarIncluye($alternativa);

        $this->assertCount(1, $bloques);
        $this->assertSame(['Traslado aeropuerto'], $bloques[0]['nombres']->all());
    }
}
