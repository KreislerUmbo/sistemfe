<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// T-133 (CA2) — `suppliers` pasa a `proveedores` y Caja queda apuntando al proveedor correcto.
class RemapearContraparteProveedorTest extends TestCase
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
        // Movimientos sin sesión ni método de pago reales: sin FK dentro de la transacción
        // (Postgres las revisa de nuevo al actualizar una fila insertada en la misma).
        DB::statement('SET LOCAL session_replication_role = replica');

        // La tabla ya no existe en una base al día: se recrea dentro de la transacción.
        if (! Schema::hasTable('suppliers')) {
            (require database_path('migrations/tenant/core/2026_07_18_090100_create_suppliers_table.php'))->up();
        }
        DB::table('suppliers')->delete();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function proveedor(string $razonSocial, ?string $documento, bool $estado = true): int
    {
        return DB::table('proveedores')->insertGetId([
            'razon_social' => $razonSocial, 'numero_documento' => $documento, 'estado' => $estado,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function supplier(int $id, string $nombre, ?string $documento, bool $activo = true): int
    {
        DB::table('suppliers')->insert([
            'id' => $id, 'name' => $nombre, 'document' => $documento, 'phone' => '999000111', 'is_active' => $activo,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function movimiento(?int $contraparteId, string $nombre, string $tipo = 'proveedor'): int
    {
        return DB::table('cash_movements')->insertGetId([
            'cash_session_id' => 1, 'type' => 'manual_expense', 'payment_method_id' => 1, 'direction' => 'out',
            'amount' => 10, 'counterparty_type' => $tipo, 'counterparty_id' => $contraparteId,
            'counterparty_name' => $nombre, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function remapear(): void
    {
        (require database_path('migrations/tenant/core/2026_11_25_200100_remapear_contraparte_proveedor_de_caja.php'))->up();
    }

    private function fila(int $movimientoId): object
    {
        return DB::table('cash_movements')->find($movimientoId);
    }

    public function test_ca2_une_por_documento_crea_el_resto_y_remapea_los_movimientos(): void
    {
        $inactivo = $this->proveedor('T133 Duplicado inactivo', '20555000111', false);
        $agencia = $this->proveedor('T133 Hotel de Agencia SAC', '20555000111');
        $otro = $this->proveedor('T133 Otro proveedor', '20555000999');

        // Ids de suppliers que chocan a propósito con los de proveedores.
        $coincide = $this->supplier($otro, 'T133 Hotel (en Caja)', ' 20555000111 ');
        $nuevo = $this->supplier($agencia, 'T133 Agua y gaseosas', '10777000222', false);
        $sinDocumento = $this->supplier($inactivo, 'T133 Señora del mercado', null);
        $repetido = $this->supplier($otro + 100, 'T133 Agua (repetido)', '10777000222');

        $movCoincide = $this->movimiento($coincide, 'T133 Hotel (en Caja)');
        $movNuevo = $this->movimiento($nuevo, 'T133 Agua y gaseosas');
        $movSinDocumento = $this->movimiento($sinDocumento, 'T133 Señora del mercado');
        $movRepetido = $this->movimiento($repetido, 'T133 Agua (repetido)');
        $movHuerfano = $this->movimiento(987654, 'T133 Borrado');
        $movLibre = $this->movimiento(null, 'T133 Texto libre');
        $movCliente = $this->movimiento($coincide, 'T133 Un cliente', 'cliente');
        $antes = DB::table('proveedores')->count();

        $this->remapear();

        $this->assertSame($antes + 2, DB::table('proveedores')->count(), 'Se crean solo el de agua y la señora.');
        $this->assertSame($agencia, $this->fila($movCoincide)->counterparty_id, 'Coincide por documento con el activo.');

        $creado = DB::table('proveedores')->find($this->fila($movNuevo)->counterparty_id);
        $this->assertSame(
            ['T133 Agua y gaseosas', '10777000222', '999000111', false, null],
            [$creado->razon_social, $creado->numero_documento, $creado->telefono, $creado->estado, $creado->tipo_id]
        );
        $this->assertSame($creado->id, $this->fila($movRepetido)->counterparty_id);

        $sinDoc = DB::table('proveedores')->find($this->fila($movSinDocumento)->counterparty_id);
        $this->assertSame('T133 Señora del mercado', $sinDoc->razon_social);

        $this->assertSame('T133 Hotel (en Caja)', $this->fila($movCoincide)->counterparty_name, 'El nombre guardado no cambia.');
        $this->assertNull($this->fila($movHuerfano)->counterparty_id);
        $this->assertNull($this->fila($movLibre)->counterparty_id);
        $this->assertSame($coincide, $this->fila($movCliente)->counterparty_id, 'La contraparte cliente no se toca.');
        $this->assertSame('10.00', $this->fila($movNuevo)->amount);
    }

    public function test_ca2_sin_datos_no_crea_ni_cambia_nada(): void
    {
        $movimiento = $this->movimiento(null, 'T133 Proveedor Fantasma SAC');
        $antes = DB::table('proveedores')->count();

        $this->remapear();

        $this->assertSame($antes, DB::table('proveedores')->count());
        $this->assertNull($this->fila($movimiento)->counterparty_id);
    }
}
