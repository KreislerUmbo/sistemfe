<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

// Catálogo central, exclusivo del vertical agencia de viajes (columna
// `giro`) — plan-modulo-proveedores.md §2.6. CentralConnection obligatorio:
// sin el trait, este modelo terminaría consultando la BD del tenant activo
// por error (bug recurrente ya documentado en
// arquitectura-multitenant-backend.md), mismo criterio que TipoComprobante.
class ProveedorTipo extends Model
{
    use CentralConnection;

    // Slug real del catálogo central — cambiado a mano en producción,
    // no es el que generaría Str::slug('Mayorista') por defecto (eso
    // daría 'mayorista'). Ver ProveedorTipoSeeder y
    // OpcionMayoristaController::store().
    public const SLUG_MAYORISTA = 'agencia-mayorista';

    // Mismo patrón que SLUG_MAYORISTA: 'Hotel' se editó a mano a este slug
    // (no es el que generaría Str::slug('Hotel'), que daría 'hotel') — el
    // seeder nunca tuvo el mismo override, así que un entorno reseedeado
    // desde cero (ej. producción) quedaba con slug='hotel' mientras todo
    // el código (detalle.vue::esHotel, ProveedorTarifaController::
    // proveedorEsHotel(), ProveedorController) compara contra este valor.
    // Bug real encontrado 2026-08-28: producción tenía el código de
    // check-in/check-out/tipo de habitación desplegado, pero la UI
    // quedaba oculta porque esta comparación nunca daba true ahí.
    public const SLUG_ALOJAMIENTO = 'alojamiento-hoteles';

    protected $table = 'proveedor_tipos';

    protected $fillable = [
        'nombre',
        'slug',
        'giro',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];
}
