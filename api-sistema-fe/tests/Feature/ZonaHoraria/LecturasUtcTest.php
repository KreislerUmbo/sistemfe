<?php

declare(strict_types=1);

namespace Tests\Feature\ZonaHoraria;

use App\Exports\CashMovementsExport;
use App\Http\Controllers\Cash\CashMovementController;
use App\Http\Resources\Sale\SaleResource;
use App\Models\Cash\Branch;
use App\Models\Sale\Note;
use App\Models\Sale\Sale;
use App\Services\QrCodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Feature\Creditos\CreditosTestCase;

/**
 * F2 de la homogenización de fechas: sin mutadores, todo instante se guarda en UTC y se lee
 * convirtiendo a hora de Perú. Casos de 19:00 a 24:00 de Perú, donde UTC ya es el día siguiente.
 */
final class LecturasUtcTest extends CreditosTestCase
{
    public function test_los_modelos_guardan_en_utc_y_ya_no_cambian_la_zona_de_php(): void
    {
        $this->hoy('2026-10-08', '20:30:00');   // 01:30 UTC del 09-oct
        date_default_timezone_set('UTC');

        $sede = Branch::create(['name' => 'Sede UTC test', 'is_active' => true]);

        $this->assertSame('2026-10-09 01:30:00', $sede->getRawOriginal('created_at'));
        $this->assertSame('UTC', date_default_timezone_get());
    }

    public function test_venta_de_noche_se_lista_y_filtra_en_su_dia_de_peru(): void
    {
        $this->hoy('2026-10-08', '20:30:00');
        date_default_timezone_set('UTC');
        $venta = Sale::factory()->create(['type_payment' => 1]);

        $datos = (new SaleResource($venta->fresh()))->toArray(request());
        $this->assertSame('2026-10-08', $datos['created_at_format']);
        $this->assertSame('2026-10-08 08:30 PM', $datos['created_at']);

        $delDia = fn (string $dia) => Sale::query()->filterMultiple(null, null, null, null, null, $dia, $dia)->pluck('id')->all();
        $this->assertContains($venta->id, $delDia('2026-10-08'));
        $this->assertNotContains($venta->id, $delDia('2026-10-09'));
    }

    public function test_qr_de_nota_emitida_de_noche_lleva_la_fecha_de_peru(): void
    {
        $nota = new Note();
        $nota->setRawAttributes([
            'tipo_doc' => '07', 'serie' => 'FC01', 'correlativo' => 5, 'mto_igv' => 18, 'mto_imp_venta' => 118,
            'cod_tipo_doc_cliente' => '6', 'hash_cpe' => 'abc', 'sunat_sent_at' => '2026-10-09 03:59:00',   // 22:59 del 08 en Perú
        ]);

        $partes = explode('|', app(QrCodeService::class)->cadenaQrNota($nota));

        $this->assertSame('2026-10-08', $partes[6]);
    }

    public function test_filtro_de_movimientos_de_caja_por_dia_de_peru(): void
    {
        $usuario = $this->usuario(['cash.view_all']);
        $sesion = $this->abrirCaja($usuario);
        $id = DB::table('cash_movements')->insertGetId([
            'cash_session_id' => $sesion->id, 'type' => 'income', 'payment_method_id' => $this->efectivo->id,
            'direction' => 'in', 'amount' => 10, 'status' => 'confirmed', 'created_by' => $usuario->id,
            'created_at' => '2026-10-09 02:15:00', 'updated_at' => '2026-10-09 02:15:00',   // 21:15 del 08 en Perú
        ]);
        $this->actingAs($usuario, 'api');

        $ids = function (string $dia): array {
            Excel::fake();
            Excel::matchByRegex();
            app(CashMovementController::class)->export(new Request(['date_from' => $dia, 'date_to' => $dia]));
            $exportados = [];
            Excel::assertDownloaded('/^movimientos_caja_/', function (CashMovementsExport $e) use (&$exportados): bool {
                $exportados = $e->query()->pluck('id')->all();

                return true;
            });

            return $exportados;
        };

        $this->assertContains($id, $ids('2026-10-08'));
        $this->assertNotContains($id, $ids('2026-10-09'));
    }
}
