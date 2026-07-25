<?php

namespace Tests\Feature;

use App\Http\Controllers\Sale\FacturacionElectronicaController;
use App\Models\Client\Client;
use App\Models\Sale\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

// reservarCorrelativo() (FacturacionElectronicaController, privado) — Corre
// contra sistemafe_test_migrations (Postgres real, 76 migraciones, sin
// exclusiones). Nunca contra sv_facturacion ni ningún tenant real.
//
// Diagnóstico previo (ver conversación), confirmado por lectura de código:
// - Solo toca 'sales' (sin tabla de contador separada); el lock es
//   SELECT ... FOR UPDATE sobre la última fila de esa 'serie'.
// - Abre y commitea su PROPIA transacción (DB::transaction) — el caller
//   (enviarSunat()) no la envuelve en ninguna transacción exterior.
// - n_operacion nunca se toca acá; solo se setea en enviarSunat() tras
//   éxito SUNAT.
// Consecuencia: un correlativo ya reservado NO puede revertirse desde
// afuera una vez que reservarCorrelativo() retorna — es la causa raíz
// documentada en el propio código de los "correlativos huérfanos"
// (ver comentario sobre la venta #16). Es comportamiento esperado, no
// un bug — así lo confirmó el usuario antes de escribir este archivo.
class ReservarCorrelativoTest extends TestCase
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

        // Mismo fixture que GreenterServiceFormaPagoTest: users.role_id tiene
        // default(1) a nivel de Postgres, esta base recién migrada no trae
        // seeds.
        DB::table('roles')->insert([
            'id' => 1,
            'name' => 'test-role',
            'guard_name' => 'api',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        // Si un test hizo commit real (Paso 3), esto es un rollback de una
        // transacción vacía reabierta a propósito — no falla.
        DB::rollBack();
        parent::tearDown();
    }

    private function reservar(Sale $venta): int
    {
        $controller = app(FacturacionElectronicaController::class);
        $method = new ReflectionMethod(FacturacionElectronicaController::class, 'reservarCorrelativo');
        $method->setAccessible(true);

        return $method->invoke($controller, $venta);
    }

    // Paso 2, primer requisito: dos llamadas secuenciales (misma serie),
    // cada una completa (reservarCorrelativo ya es atómica por sí sola —
    // no hace falta envolverla en una transacción extra), deben devolver
    // correlativos distintos y consecutivos.
    public function test_dos_reservas_secuenciales_devuelven_correlativos_consecutivos(): void
    {
        $venta1 = Sale::factory()->create(['serie' => 'F001', 'correlativo' => null, 'n_operacion' => null]);
        $venta2 = Sale::factory()->create(['serie' => 'F001', 'correlativo' => null, 'n_operacion' => null]);

        $c1 = $this->reservar($venta1);
        $c2 = $this->reservar($venta2);

        $this->assertSame(1, $c1); // primera venta con correlativo en esta serie (test aislado)
        $this->assertSame(2, $c2);
        $this->assertNotSame($c1, $c2);

        $this->assertSame($c1, $venta1->fresh()->correlativo);
        $this->assertSame($c2, $venta2->fresh()->correlativo);
    }

    // Tercera reserva encadenada — confirma que la secuencia sigue subiendo
    // de a uno, no solo que las primeras dos difieren.
    public function test_tres_reservas_secuenciales_son_estrictamente_consecutivas(): void
    {
        $ventas = Sale::factory()->count(3)->create(['serie' => 'B001', 'correlativo' => null, 'n_operacion' => null]);

        $correlativos = $ventas->map(fn (Sale $v) => $this->reservar($v))->all();

        $this->assertSame([1, 2, 3], $correlativos);
    }

    // Paso 2, segundo requisito: comportamiento REAL confirmado por lectura de
    // código y aceptado explícitamente por el usuario como "esperado, no bug".
    // reservarCorrelativo() ya hizo commit de su propia transacción cuando
    // retorna — si algo falla DESPUÉS (fuera de su alcance, ej. en
    // enviarSunat() al armar el XML), el correlativo queda persistido de
    // todas formas. No hay ningún mecanismo que lo revierta.
    //
    // No existe una tabla de correlativos separada con un campo de estado
    // (reservado/confirmado/anulado) — confirmado leyendo create_sales_table
    // y reservarCorrelativo(): 'correlativo' y 'n_operacion' son columnas
    // planas de 'sales'. El "estado" de una reserva huérfana ES la fila de
    // la venta misma: correlativo seteado + n_operacion null +
    // sunat_error_message/sunat_sent_at (si el fallo ocurrió dentro del
    // try/catch de enviarSunat(), que es el camino real que se simula acá).
    // Ese es el rastro que este test protege — no un campo de estado
    // dedicado, porque no existe ninguno.
    public function test_correlativo_queda_persistido_si_algo_falla_despues_de_reservar(): void
    {
        // n_operacion explícito en null: el factory por default simula una
        // venta YA aceptada por SUNAT (con n_operacion fake) — el escenario
        // real donde reservarCorrelativo() se llama por primera vez es
        // antes de que exista ningún n_operacion.
        $venta = Sale::factory()->create(['serie' => 'F001', 'correlativo' => null, 'n_operacion' => null]);

        $correlativo = $this->reservar($venta);

        // Simula exactamente el catch de enviarSunat() (líneas 227-237 de
        // FacturacionElectronicaController) — mismo shape de update(), fuera
        // de cualquier transacción, tal como ocurre en el código real
        // (enviarSunat() no envuelve nada en DB::transaction()).
        $mensajeError = 'Simulación: Greenter/SUNAT rechaza el envío después de reservar.';
        try {
            throw new \RuntimeException($mensajeError);
        } catch (\RuntimeException $e) {
            $venta->update([
                'sunat_error_code' => null,
                'sunat_error_message' => $e->getMessage(),
                'sunat_sent_at' => now(),
            ]);
        }

        $fresca = $venta->fresh();

        // El correlativo sigue ahí — nunca se revierte.
        $this->assertSame($correlativo, $fresca->correlativo);

        // El rastro contable que sí existe en este diseño (sin tabla de
        // estado dedicada): se sabe que el número se reservó y nunca se
        // confirmó, y por qué.
        $this->assertNull($fresca->n_operacion);
        $this->assertNull($fresca->xml);
        $this->assertNull($fresca->cdr);
        $this->assertSame($mensajeError, $fresca->sunat_error_message);
        $this->assertNotNull($fresca->sunat_sent_at);

        // No queda ninguna venta duplicada/huérfana asociada a ese
        // correlativo — sigue siendo la misma fila que ya existía antes de
        // reservar, reservarCorrelativo() nunca crea una Sale nueva.
        $this->assertSame(
            1,
            Sale::where('serie', 'F001')->where('correlativo', $correlativo)->count()
        );

        // reservarCorrelativo() no toca installments en absoluto — confirma
        // que no aparece ninguna fila ahí como efecto colateral del fallo.
        $this->assertSame(0, $fresca->installments()->count());

        // Confirma que el hueco es real: la siguiente venta de la misma serie
        // sigue avanzando desde el correlativo "huérfano", nunca lo reutiliza.
        $ventaSiguiente = Sale::factory()->create(['serie' => 'F001', 'correlativo' => null, 'n_operacion' => null]);
        $siguiente = $this->reservar($ventaSiguiente);
        $this->assertSame($correlativo + 1, $siguiente);
    }

    // ── Paso 3 (opcional): lock real entre dos conexiones Postgres ──────
    // A diferencia de todos los demás tests de esta clase (y de
    // GreenterServiceFormaPagoTest), este SÍ necesita una fila realmente
    // COMMITEADA — un lock de fila entre dos sesiones de Postgres distintas
    // solo es observable si la fila es visible fuera de la transacción que
    // la creó (READ COMMITTED no deja ver filas de una transacción ajena
    // todavía abierta). Rompe a propósito, solo acá, el patrón "cero
    // persistencia real" del resto de la clase — se compensa con limpieza
    // manual garantizada en finally{}, y termina reabriendo una transacción
    // vacía para que el tearDown() normal (DB::rollBack()) no falle.
    //
    // No usa pcntl_fork ni procesos separados — dos conexiones Laravel/PDO
    // en el mismo proceso PHP ya son dos sesiones Postgres reales e
    // independientes, que es lo único que hace falta para que el lock
    // exista de verdad. No reproduce una race real (no hay ejecución
    // interleaved genuina, todo sigue siendo secuencial dentro de un único
    // proceso PHP) — prueba mutua exclusión vía lock_timeout corto en vez
    // de una carrera cronometrada: la segunda conexión debe fallar al
    // intentar tomar el mismo lock mientras la primera lo sostiene abierto.
    public function test_lock_bloquea_segunda_conexion_mientras_la_primera_lo_sostiene(): void
    {
        // Cierra la transacción de setUp() — la fila de 'roles' queda
        // commiteada (inofensiva, ya la limpiamos con las demás al final).
        DB::commit();

        $venta = Sale::factory()->create(['serie' => 'F002', 'correlativo' => 1]);

        $bloqueada = false;
        $mensajeError = null;

        try {
            // Conexión A: toma el lock y lo sostiene abierto (sin commit todavía).
            DB::connection('pgsql')->beginTransaction();
            DB::connection('pgsql')->select(
                "select * from sales where serie = ? order by correlativo desc for update",
                ['F002']
            );

            // Conexión B: sesión Postgres completamente distinta (mismo host/BD,
            // PDO/backend PID separado). lock_timeout corto para no colgar el
            // test — si el lock es real, esto debe fallar rápido en vez de esperar.
            config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
            DB::purge('pgsql_b');
            DB::connection('pgsql_b')->beginTransaction();
            DB::connection('pgsql_b')->statement("SET LOCAL lock_timeout = '300ms'");

            try {
                DB::connection('pgsql_b')->select(
                    "select * from sales where serie = ? order by correlativo desc for update",
                    ['F002']
                );
            } catch (\Throwable $e) {
                $bloqueada = true;
                $mensajeError = $e->getMessage();
            }

            DB::connection('pgsql_b')->rollBack();
            DB::connection('pgsql')->rollBack(); // libera el lock de A
        } finally {
            // Limpieza manual — a esta altura ya no hay ninguna transacción
            // que revertir sola, borramos lo que sí se commiteó de verdad.
            DB::connection('pgsql')->table('sales')->where('id', $venta->id)->delete();
            DB::connection('pgsql')->table('users')->where('id', $venta->user_id)->delete();
            DB::connection('pgsql')->table('clients')->where('id', $venta->client_id)->delete();
            DB::connection('pgsql')->table('roles')->where('id', 1)->delete();

            // Reabre una transacción vacía para que el tearDown() de la clase
            // (DB::rollBack()) tenga algo válido que revertir.
            DB::beginTransaction();
        }

        $this->assertTrue(
            $bloqueada,
            'Se esperaba que la segunda conexión no pudiera tomar el lock mientras la primera lo sostiene abierto.'
        );
        $this->assertNotNull($mensajeError);
    }
}
