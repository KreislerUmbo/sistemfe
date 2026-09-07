<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Tabla puente TENANT: qué afiliaciones marcó esta agencia + su número de
// registro propio. `afiliacion_id` es una FK LÓGICA hacia
// AfiliacionTurismo (central) — sin FK real de Postgres entre bases
// distintas, mismo patrón cross-boundary que codigo_detraccion/cod_motivo
// (CLAUDE.md). Tenant (sin CentralConnection).
class ConfiguracionAgenciaAfiliacion extends Model
{
    protected $table = 'configuracion_agencia_afiliaciones';

    protected $fillable = [
        'configuracion_agencia_pdf_id',
        'afiliacion_id',
        'numero_registro',
    ];

    // No hay belongsTo(AfiliacionTurismo::class) real: la relación cruza a
    // la conexión central, y AfiliacionTurismo::find() ya resuelve bien
    // solo (CentralConnection) — el controller la carga aparte y arma el
    // merge en PHP en vez de forzar una relación Eloquent cross-connection.
}
