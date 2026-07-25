<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Central\CentralAuditLog;
use Illuminate\Http\Request;

// Panel superadmin — vista global de auditoría (todas las acciones sensibles ya quedaban
// registradas por AuditLogger::log() desde varias fases anteriores, pero hasta ahora no
// existía ningún endpoint para leerlas). Filtros opcionales, combinables: auditable_type +
// auditable_id (el frontend los arma para el selector de "tenant" — ver
// plan-panel-superadmin.md, sección Audit Logs) y action (lista cerrada de las 25 acciones
// reales, auditadas con grep en esta misma sesión).
class CentralAuditLogController extends Controller
{
    public function index(Request $request)
    {
        $query = CentralAuditLog::with('centralUser:id,name,email')->latest();

        if ($request->filled('auditable_type')) {
            $query->where('auditable_type', $request->input('auditable_type'));
        }
        if ($request->filled('auditable_id')) {
            $query->where('auditable_id', $request->input('auditable_id'));
        }
        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }

        return response()->json($query->paginate(20));
    }
}
