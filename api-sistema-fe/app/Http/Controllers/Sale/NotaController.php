<?php

namespace App\Http\Controllers\Sale;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Sale\Note;
use App\Services\QrCodeService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class NotaController extends Controller
{
    // ── Representación impresa (PDF) de la nota ────────────────────────
    // Copia estructural de SaleController::pdf() — mismo patrón de
    // selección de formato (?format= o preferencia del usuario) y de
    // acceso vía URL firmada temporal (ver pdfSignedUrl()). Soporta los
    // mismos 2 formatos: 'a4' y 'ticket80mm'.
    public function pdf(string $id, Request $request)
    {
        $nota = Note::with(['client', 'user', 'note_details.product'])->find($id);

        if (!$nota) {
            return abort(404);
        }

        $formato = $request->query('format');
        if (!in_array($formato, ['a4', 'ticket80mm'])) {
            $formato = $nota->user->formato_impresion_default ?? 'a4';
        }

        $empresa = Company::first();
        $qr      = app(QrCodeService::class)->generarQrNota($nota);
        $vista   = $formato === 'ticket80mm' ? 'pdf.nota_ticket80mm' : 'pdf.nota_a4';

        $pdf = Pdf::loadView($vista, compact('nota', 'empresa', 'qr'));

        if ($formato === 'ticket80mm') {
            // Ancho fijo 80mm (≈226.77pt). Dompdf no soporta alto "auto" real,
            // así que se estima el alto según cantidad de ítems.
            $alto_estimado = 480 + ($nota->note_details->count() * 40);
            $pdf->setPaper([0, 0, 226.77, $alto_estimado], 'portrait');
        } else {
            $pdf->setPaper('a4', 'portrait');
        }

        return $pdf->stream('nota_' . $id . '.pdf');
    }

    // ── Generar URL firmada temporal para imprimir/ver el PDF ────────
    // Mismo patrón que SaleController::pdfSignedUrl() — requiere estar
    // autenticado, devuelve una URL con firma + expiración (10 min) para
    // la ruta pública 'notas.pdf'.
    public function pdfSignedUrl(Request $request, string $id)
    {
        $nota = Note::with('user')->findOrFail($id);

        $formato = $request->query('format');
        if (!in_array($formato, ['a4', 'ticket80mm'])) {
            $formato = $nota->user->formato_impresion_default ?? 'a4';
        }

        $url = URL::temporarySignedRoute('notas.pdf', now()->addMinutes(10), [
            'id'     => $id,
            'format' => $formato,
        ]);

        return response()->json(['url' => $url]);
    }
}
