<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\GuiaController;
use App\Models\AgenciaViajes\Guia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// T-039 (CA3): los buscadores de guía consultan con ?search=; per_page acotado a 1-100.
class GuiaBusquedaTest extends TestCase
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

    public function test_index_busca_mas_alla_de_la_primera_pagina_y_acota_per_page(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            Guia::create(['nombre' => sprintf('Guía %02d', $i), 'documento' => sprintf('%08d', $i), 'telefono' => '999000000']);
        }

        $respuesta = app(GuiaController::class)->index(Request::create('/guias', 'GET', [
            'search' => 'Guía 20', 'per_page' => 1000,
        ]));

        $this->assertSame(422, $respuesta->getStatusCode());

        $respuesta = app(GuiaController::class)->index(Request::create('/guias', 'GET', [
            'search' => 'Guía 20', 'per_page' => 100,
        ]));
        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertSame('Guía 20', $respuesta->getData(true)['guias'][0]['nombre']);
        $this->assertSame(100, $respuesta->getData(true)['paginate']);
    }
}
