<?php

namespace App\Models\AgenciaViajes;

use App\Services\StorageUrl;
use Illuminate\Database\Eloquent\Model;

// plan-modulo-cotizaciones-reservas.md §2.4. Tenant (sin CentralConnection).
// Exclusivo de opcion_mayorista (paquetes internacionales con fecha fija)
// desde la consolidación de hoteles — paquete_plantilla_id (atarse a un
// combo/tour) fue eliminado, ver
// 2026_08_11_090500_drop_paquete_plantilla_id_from_opciones_hotel_table.php.
// opcion_mayorista_id desde Sesión 7b
// (2026_07_28_100400_add_opcion_mayorista_foreign_to_opciones_hotel_table.php
// — cierra la FK diferida desde Sesión 5), proveedor_id desde Sesión 3.
class OpcionHotel extends Model
{
    protected $table = 'opciones_hotel';

    protected $fillable = [
        'opcion_mayorista_id',
        'proveedor_id',
        // Sesión M3 (Ronda 4/P12) — informativo, guard contra promover el
        // mismo hotel ad-hoc dos veces. No relinkea nada retroactivamente
        // (mismo criterio que alternativa_items.proveedor_promovido_id).
        'proveedor_promovido_id',
        'nombre_hotel',
        'categoria_estrellas',
        'moneda',
        'edad_max_infante_gratis',
        'edad_max_nino_cama_adicional',
        // 05-sep-2026 — máx. 3, ver OpcionHotelController::agregarFotos()/
        // eliminarFoto() (mismo patrón que destinos_atractivos/paquetes_plantilla,
        // límite propio en vez del genérico de FotoUploadService).
        'fotos',
    ];

    protected $casts = [
        'fotos' => 'array',
    ];

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }

    public function proveedorPromovido()
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_promovido_id');
    }

    public function opcionMayorista()
    {
        return $this->belongsTo(OpcionMayorista::class, 'opcion_mayorista_id');
    }

    public function opcionesHotelTarifas()
    {
        return $this->hasMany(OpcionHotelTarifa::class, 'opcion_hotel_id');
    }

    // Bug real (05-sep-2026, reportado por el usuario probando "Agregar
    // imágenes" en el comparador de mayoristas): `fotos` cambió de forma
    // (string[] -> {path, tipo_foto}[], mejora del PDF de cotización,
    // plan-mejora-pdf-cotizacion-cliente.md §4.5) pero OpcionMayoristaController
    // (flujo Internacional/mayorista) seguía llamando
    // StorageUrl::resolveMuchas($hotel->fotos) directo, que espera
    // string[] — "Argument #1 ($path) must be of type ?string, array
    // given". Extraído acá (antes vivía duplicado en
    // OpcionHotelController) para que cualquier controller que resuelva
    // fotos de un OpcionHotel use la MISMA normalización, sin poder
    // repetir el bug en un tercer lugar.
    //
    // normalizarFotos() es defensivo — una entrada vieja (string suelto,
    // de antes de la migración de datos 2026_09_05_100400) no debe
    // romper el resolve, aunque la migración ya debería haber convertido
    // todo lo real.
    public static function normalizarFotos(array $fotos): array
    {
        return array_map(
            fn ($f) => is_array($f) ? $f : ['path' => $f, 'tipo_foto' => 'habitacion'],
            $fotos
        );
    }

    public static function fotosResueltas(array $fotos): array
    {
        return array_map(
            fn (array $f) => ['path' => $f['path'], 'tipo_foto' => $f['tipo_foto'] ?? 'habitacion', 'url' => StorageUrl::resolve($f['path'])],
            self::normalizarFotos($fotos)
        );
    }
}
