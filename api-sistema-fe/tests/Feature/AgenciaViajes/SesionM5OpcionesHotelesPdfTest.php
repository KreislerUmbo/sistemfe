<?php

namespace Tests\Feature\AgenciaViajes;

use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaDestino;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\OpcionHotel;
use App\Models\AgenciaViajes\OpcionHotelTarifa;
use App\Models\AgenciaViajes\OpcionMayorista;
use App\Models\AgenciaViajes\Proveedor;
use App\Services\AgenciaViajes\AlternativaPdfService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// Sesión M5 — plan-ejecucion-matriz-hoteles-cotizador.md fila M5.
// AlternativaPdfService::opcionesHoteles() (extraído de AlternativaController
// el 09-sep-2026) es privado — se invoca vía reflexión, mismo patrón que
// Sesion12f3PdfPorDestinoTest para itinerarioAlternativa()/incluyePorDestino()
// (no se ejercita generar() completo/DomPDF, ningún otro test de la suite
// lo hace).
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
        $service = app(AlternativaPdfService::class);
        $method = new \ReflectionMethod(AlternativaPdfService::class, 'opcionesHoteles');
        $method->setAccessible(true);

        return $method->invoke($service, $alternativa->fresh(['items']));
    }

    private function invocarIncluye(Alternativa $alternativa): array
    {
        $service = app(AlternativaPdfService::class);
        $method = new \ReflectionMethod(AlternativaPdfService::class, 'incluyePorDestino');
        $method->setAccessible(true);

        return $method->invoke($service, $alternativa->fresh(['destinos.destinoAtractivo', 'items']));
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

    // Cachea proveedor_servicio_id por nombre de hotel dentro de un mismo
    // test — un hotel real con varios tipos de habitación cuelga SIEMPRE
    // del mismo proveedor_servicio_id (ProveedorTarifaController::store()
    // nunca crea uno nuevo por tarifa). Sin este cache, 2 llamadas con el
    // mismo $nombreHotel creaban 2 Proveedor/ProveedorServicio disjuntos
    // que solo coincidían en el texto — guardrail (18-sep-2026): agrupar
    // por identidad real en opcionesHoteles() detectó que este fixture no
    // reflejaba eso, ver commit que lo corrige.
    private array $proveedorServicioPorHotel = [];

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
        int $cantidad = 1,
    ): AlternativaItem {
        if (isset($this->proveedorServicioPorHotel[$nombreHotel])) {
            $proveedorServicioId = $this->proveedorServicioPorHotel[$nombreHotel];
        } else {
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
            $this->proveedorServicioPorHotel[$nombreHotel] = $proveedorServicioId;
        }
        $tarifaId = DB::table('proveedor_tarifas')->insertGetId([
            'proveedor_servicio_id' => $proveedorServicioId, 'tipo_tarifa' => 'publica', 'modalidad' => 'privado', 'moneda' => 'PEN',
            'precio_costo' => $precio * 0.7, 'margen_tipo' => 'fijo', 'margen_valor' => $precio * 0.3, 'precio_venta_adulto' => $precio,
            'tipo_habitacion' => $tipoHabitacion, 'vigente_desde' => '2026-01-01', 'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'proveedor', 'proveedor_tarifa_id' => $tarifaId,
            'grupo_opcion_id' => $grupoOpcionId, 'opcion_elegida' => $opcionElegida,
            'modo_precio' => 'tarifa_fija', 'cantidad' => $cantidad, 'moneda_costo' => 'PEN',
            'costo_snapshot' => $precio * 0.7, 'precio_venta_snapshot' => $precio, 'precio_convertido' => $precio,
        ]);
    }

    // Ítem de mayorista con matriz de hoteles (origen_tipo=mayorista +
    // opcion_hotel_tarifa_id) — mismo formato "hotel · tipo_habitacion" que
    // crearItemHotel(), pero por el camino de OpcionHotel/OpcionHotelTarifa
    // (Sesión M2) en vez de proveedor_tarifas. Acá `cantidad` es adultos,
    // no noches — precio_convertido YA es el paquete completo por persona.
    // Varios hoteles comparándose entre sí conviven bajo la MISMA
    // OpcionMayorista (un solo mayorista, varios hoteles de su matriz) —
    // "opcion_mayorista_alternativa_elegida_unique" no deja 2 filas
    // 'elegida' por alternativa, así que $opcionMayorista se reusa entre
    // llamadas en vez de crear una por hotel.
    private function crearItemHotelMayorista(
        Alternativa $alternativa,
        ?OpcionMayorista $opcionMayorista,
        ?string $grupoOpcionId,
        bool $opcionElegida,
        string $nombreHotel,
        string $tipoHabitacion,
        float $precio,
        int $cantidad,
    ): AlternativaItem {
        $opcion = $opcionMayorista ?? OpcionMayorista::create([
            'alternativa_id' => $alternativa->id,
            'proveedor_id' => Proveedor::create(['razon_social' => 'Mayorista Test M5 ' . random_int(1000, 9999), 'estado' => true])->id,
            'moneda' => 'PEN', 'estado' => 'elegida',
        ]);
        $hotel = OpcionHotel::create(['opcion_mayorista_id' => $opcion->id, 'nombre_hotel' => $nombreHotel, 'moneda' => 'PEN']);
        $tarifa = OpcionHotelTarifa::create(['opcion_hotel_id' => $hotel->id, 'tipo_habitacion' => $tipoHabitacion, 'precio_costo' => $precio * 0.7, 'precio_venta' => $precio]);

        return AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'mayorista',
            'opcion_mayorista_id' => $opcion->id, 'opcion_hotel_tarifa_id' => $tarifa->id,
            'grupo_opcion_id' => $grupoOpcionId, 'opcion_elegida' => $opcionElegida,
            'modo_precio' => 'tarifa_fija', 'cantidad' => $cantidad, 'moneda_costo' => 'PEN',
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

    // Guardrail (19-sep-2026) — hallazgo real del usuario sobre el PDF que
    // recibe el cliente: un hotel con 2 tipos de habitación (doble Y
    // matrimonial) donde solo UNO fue elegido no debe marcar ambos como
    // "elegida" — antes el resaltado era por FILA (hotel), así que el
    // cliente no podía saber cuál de los 2 precios arma el total.
    public function test_hotel_con_dos_tipos_marca_solo_el_tipo_elegido(): void
    {
        $alternativa = $this->crearAlternativa();
        $grupo = (string) Str::uuid();
        $this->crearItemHotel($alternativa, $grupo, false, 'Hotel Dos Tipos', 'doble', 100);
        $this->crearItemHotel($alternativa, $grupo, true, 'Hotel Dos Tipos', 'matrimonial', 120);

        $resultado = $this->invocar($alternativa);

        $fila = collect($resultado[0]['filas'])->firstWhere('hotel', 'Hotel Dos Tipos');
        $this->assertTrue($fila['elegida']);
        $this->assertSame('matrimonial', $fila['tipo_elegido']);
        $this->assertNotSame('doble', $fila['tipo_elegido']);
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

    // Guardrail de PDF (17-sep-2026) — hallazgo real revisando cómo queda
    // la tabla con noches>1: `cantidad` en un hotel de Local/Nacional es
    // NOCHES, y la tabla mostraba precio_convertido (unitario, UNA noche)
    // sin decirlo — un cliente con una estadía de varias noches podía leer
    // ese precio como el total. Ahora usa total_convertido, que ya resuelve
    // la multiplicación con la misma regla que el resto de la cotización.
    public function test_hotel_local_nacional_con_varias_noches_multiplica_el_precio_por_cantidad(): void
    {
        $alternativa = $this->crearAlternativa();
        $grupo = (string) Str::uuid();
        $this->crearItemHotel($alternativa, $grupo, false, 'Hotel Tres Noches', 'doble', 90, cantidad: 3);
        $this->crearItemHotel($alternativa, $grupo, false, 'Hotel Otro', 'doble', 110, cantidad: 3);

        $resultado = $this->invocar($alternativa);

        $fila = collect($resultado[0]['filas'])->firstWhere('hotel', 'Hotel Tres Noches');
        $this->assertEquals(270.0, $fila['precios']['doble'], 'precio de 1 noche (90) × 3 noches, no el precio unitario');
    }

    // Caso contrario, mismo hallazgo: en mayorista `cantidad` es adultos y
    // precio_convertido YA es el paquete completo "por persona" — multiplicar
    // de nuevo mostraría el total del grupo en una tabla que dice "tarifa
    // por persona", no el precio unitario. No debe tocarse.
    public function test_hotel_mayorista_con_varios_adultos_no_multiplica_el_precio(): void
    {
        $alternativa = $this->crearAlternativa();
        $grupo = (string) Str::uuid();
        $opcionMayorista = OpcionMayorista::create([
            'alternativa_id' => $alternativa->id,
            'proveedor_id' => Proveedor::create(['razon_social' => 'Mayorista Test M5', 'estado' => true])->id,
            'moneda' => 'PEN', 'estado' => 'elegida',
        ]);
        $this->crearItemHotelMayorista($alternativa, $opcionMayorista, $grupo, false, 'Hotel Paquete Mayorista', 'doble', 850, cantidad: 2);
        $this->crearItemHotelMayorista($alternativa, $opcionMayorista, $grupo, false, 'Hotel Paquete Otro', 'doble', 900, cantidad: 2);

        $resultado = $this->invocar($alternativa);

        $fila = collect($resultado[0]['filas'])->firstWhere('hotel', 'Hotel Paquete Mayorista');
        $this->assertEquals(850.0, $fila['precios']['doble'], 'precio de paquete por persona, sin multiplicar por adultos');
    }

    // Guardrail (18-sep-2026) — bug real encontrado en vivo: 2 hoteles
    // DISTINTOS (uno real del catálogo, uno ad-hoc) que coinciden en el
    // texto tipeado se fusionaban en una sola fila (agrupaba por nombre),
    // pisando el precio de uno con el del otro sin ningún aviso. Ahora
    // agrupa por identidad real (proveedor_servicio_id / opcion_hotel_id).
    public function test_hotel_catalogo_y_hotel_adhoc_con_el_mismo_nombre_no_se_fusionan(): void
    {
        $alternativa = $this->crearAlternativa();
        $grupo = (string) Str::uuid();
        $this->crearItemHotel($alternativa, $grupo, false, 'Hotel Rio Sol', 'simple', 120);

        $hotelAdhoc = OpcionHotel::create(['nombre_hotel' => 'Hotel Rio Sol', 'moneda' => 'PEN']);
        $tarifaAdhoc = OpcionHotelTarifa::create(['opcion_hotel_id' => $hotelAdhoc->id, 'tipo_habitacion' => 'simple', 'precio_costo' => 90, 'precio_venta' => 130]);
        AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'proveedor', 'opcion_hotel_tarifa_id' => $tarifaAdhoc->id,
            'grupo_opcion_id' => $grupo, 'opcion_elegida' => false,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 90, 'precio_venta_snapshot' => 130, 'precio_convertido' => 130,
        ]);

        $resultado = $this->invocar($alternativa);

        $this->assertCount(2, $resultado[0]['filas'], 'mismo nombre, pero son 2 hoteles distintos — no deben fusionarse en 1 fila');
        $precios = collect($resultado[0]['filas'])->pluck('precios.simple')->sort()->values();
        $this->assertEquals([120.0, 130.0], $precios->all());
    }

    // Guardrail (19-sep-2026, hallazgo del usuario) — generar() rechaza con
    // 422 si hay un grupo de opciones sin resolver, para no mandarle al
    // cliente un PDF cuyo total ya asumió en silencio la opción más barata
    // (AlternativaItem::calcularTotalEfectivo()) sin que la tabla lo marque.
    // La validación corre ANTES de armar el resto del PDF (Company/
    // ConfiguracionAgencia/etc. no hacen falta en el fixture).
    public function test_generar_rechaza_si_hay_un_grupo_sin_resolver(): void
    {
        $alternativa = $this->crearAlternativa();
        $grupo = (string) Str::uuid();
        $this->crearItemHotel($alternativa, $grupo, false, 'Hotel Rio Sol', 'simple', 120);
        $this->crearItemHotel($alternativa, $grupo, false, 'Cumbaza Hotel y Convenciones', 'simple', 90);

        $service = app(AlternativaPdfService::class);

        try {
            $service->generar((string) $alternativa->id);
            $this->fail('Se esperaba ValidationException por grupo sin resolver.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $mensaje = $e->errors()['grupo_opcion'][0];
            $this->assertStringContainsString('Hotel Rio Sol', $mensaje);
            $this->assertStringContainsString('Cumbaza Hotel y Convenciones', $mensaje);
        }
    }

    public function test_generar_no_rechaza_si_el_grupo_ya_esta_resuelto(): void
    {
        $alternativa = $this->crearAlternativa();
        $grupo = (string) Str::uuid();
        $this->crearItemHotel($alternativa, $grupo, false, 'Hotel Rio Sol', 'simple', 120);
        $this->crearItemHotel($alternativa, $grupo, true, 'Cumbaza Hotel y Convenciones', 'simple', 90);

        $service = app(AlternativaPdfService::class);

        try {
            $service->generar((string) $alternativa->id);
            $this->assertTrue(true, 'generó sin lanzar ninguna excepción — el guardrail no debía activarse.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->fail('No debería rechazar: el grupo ya tiene una opción elegida. Mensaje: '.$e->getMessage());
        }
    }

    public function test_generar_no_rechaza_sin_grupos_de_opciones(): void
    {
        $alternativa = $this->crearAlternativa();
        $this->crearItemHotel($alternativa, null, false, 'Hotel Suelto', 'doble', 150);

        $service = app(AlternativaPdfService::class);

        try {
            $service->generar((string) $alternativa->id);
            $this->assertTrue(true, 'generó sin lanzar ninguna excepción — el guardrail no debía activarse.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->fail('No debería rechazar: no hay ningún grupo de opciones. Mensaje: '.$e->getMessage());
        }
    }
}
