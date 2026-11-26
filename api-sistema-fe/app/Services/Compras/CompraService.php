<?php

namespace App\Services\Compras;

use App\Models\Compras\Compra;
use App\Models\Compras\CompraPeriodo;
use App\Models\Proveedor;
use App\Models\Sale\TipoComprobante;
use App\Rules\ArchivoSubido;
use App\Services\HoraPeru;
use Brick\Math\BigDecimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * T-135 — reglas del comprobante de compra (D3-D9). Todo rechazo sale como CompraException.
 * El IGV no se recalcula: se registra lo que dice el comprobante.
 */
class CompraService
{
    // D5: tipos que exigen RUC; 04 (liquidación de compra) acepta además DNI.
    private const TIPOS_CON_RUC = ['01', '02', '05', '07', '08', '14'];

    private const TIPO_LIQUIDACION = '04';

    private const TIPO_NO_DOMICILIADO = '91';

    private const ADJUNTOS = ['pdf' => 'adjunto_pdf', 'xml' => 'adjunto_xml'];

    public function registrar(Request $request, int $userId): Compra
    {
        return $this->guardar($request, new Compra(['user_id' => $userId]));
    }

    public function editar(Request $request, Compra $compra): Compra
    {
        return $this->guardar($request, $compra);
    }

    /** D8: anular, nunca borrar. */
    public function anular(Compra $compra, ?string $motivo, int $userId): Compra
    {
        $motivo = trim((string) $motivo);
        if ($motivo === '') {
            throw new CompraException('Indica el motivo de la anulación.');
        }
        if (mb_strlen($motivo) > 255) {
            throw new CompraException('El motivo no puede pasar de 255 caracteres.');
        }

        return DB::transaction(function () use ($compra, $motivo, $userId) {
            $actual = Compra::whereKey($compra->id)->lockForUpdate()->firstOrFail();
            if ($actual->estaAnulada()) {
                throw new CompraException('La compra ya está anulada.');
            }
            $this->exigirPeriodoAbierto(CompraPeriodo::bloquear($actual->periodo));

            // T-136: aquí va la regla «con pagos aplicados no se anula hasta desaplicarlos».

            $actual->update(['anulada_at' => now(), 'anulada_por' => $userId, 'motivo_anulacion' => $motivo]);

            return $actual;
        });
    }

    private function guardar(Request $request, Compra $compra): Compra
    {
        $datos = $this->validar($request);
        $nuevos = [];

        try {
            return DB::transaction(function () use ($request, $compra, $datos, &$nuevos) {
                $actual = $compra->exists ? Compra::whereKey($compra->id)->lockForUpdate()->firstOrFail() : $compra;
                if ($actual->estaAnulada()) {
                    throw new CompraException('Una compra anulada no se puede editar.');
                }

                // Se bloquean el periodo de antes y el nuevo, siempre en el mismo orden.
                $periodos = array_unique(array_filter([$actual->periodo, $datos['periodo']]));
                sort($periodos);
                foreach ($periodos as $periodo) {
                    $this->exigirPeriodoAbierto(CompraPeriodo::bloquear($periodo));
                }

                $proveedor = Proveedor::findOrFail($datos['proveedor_id']);
                $this->exigirDocumento($proveedor, $datos['tipo_comprobante_codigo']);

                $documento = $this->normalizar($proveedor->numero_documento);
                $datos['proveedor_documento'] = $documento === '' ? null : $documento;
                $datos['clave_proveedor'] = $documento === '' ? "id:{$proveedor->id}" : $documento;
                $datos['compra_referencia_id'] = $this->referencia($datos, $actual);

                if ($existente = $this->duplicada($datos, $actual->id)) {
                    throw $this->yaRegistrada($existente);
                }

                $actual->fill($datos);
                foreach (self::ADJUNTOS as $tipo => $columna) {
                    if ($archivo = $request->file($columna)) {
                        $actual->{$columna} = $nuevos[] = $this->guardarAdjunto($archivo, $tipo);
                    }
                }
                $actual->save();

                return $actual;
            });
        } catch (\Throwable $e) {
            // La transacción se deshizo: los archivos recién subidos quedarían huérfanos.
            if ($nuevos !== []) {
                Storage::disk('private')->delete($nuevos);
            }

            // Carrera: dos registros a la vez, lo frena el índice único parcial (D3).
            if ($e instanceof UniqueConstraintViolationException && ($existente = $this->duplicada($datos + [
                'clave_proveedor' => $this->clave(Proveedor::findOrFail($datos['proveedor_id'])),
            ], $compra->id))) {
                throw $this->yaRegistrada($existente);
            }

            throw $e;
        }
    }

    private function validar(Request $request): array
    {
        $entrada = $request->all();
        foreach (['serie', 'numero'] as $campo) {
            if (isset($entrada[$campo])) {
                $entrada[$campo] = $this->normalizar((string) $entrada[$campo]);
            }
        }

        $tipos = TipoComprobante::where('uso_compras', true)->pluck('codigo')->all();
        $monto = ['required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'];

        $validator = Validator::make($entrada, [
            'proveedor_id' => ['required', 'integer', 'exists:proveedores,id'],
            'tipo_comprobante_codigo' => ['required', 'string', Rule::in($tipos)],
            'serie' => ['required', 'string', 'max:20'],
            'numero' => ['required', 'string', 'max:20'],
            'fecha_emision' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.HoraPeru::hoyTexto()],
            'periodo' => ['nullable', 'regex:'.CompraPeriodo::FORMATO],
            'moneda' => ['required', Rule::in(['PEN', 'USD'])],
            'tipo_cambio' => ['nullable', 'required_if:moneda,USD', 'numeric', 'gt:0', 'max:999999', 'decimal:0,4'],
            'base_gravada' => $monto,
            'igv' => $monto,
            'exonerado' => $monto,
            'inafecto' => $monto,
            'otros' => $monto,
            'total' => $monto,
            'condicion' => ['required', Rule::in(['contado', 'credito'])],
            'fecha_vencimiento' => ['nullable', 'required_if:condicion,credito', 'date_format:Y-m-d'],
            'compra_referencia_id' => ['nullable', 'integer'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'adjunto_pdf' => ArchivoSubido::pdf(),
            'adjunto_xml' => ArchivoSubido::xml(),
        ], [
            'tipo_comprobante_codigo.in' => 'Ese tipo de comprobante no se usa en compras.',
            'fecha_emision.before_or_equal' => 'La fecha de emisión no puede ser futura.',
            'periodo.regex' => 'El periodo va como AAAA-MM.',
            'tipo_cambio.required_if' => 'En dólares se necesita el tipo de cambio.',
            'tipo_cambio.gt' => 'El tipo de cambio debe ser mayor a cero.',
            'fecha_vencimiento.required_if' => 'A crédito se necesita la fecha de vencimiento.',
            '*.min' => 'Los montos no pueden ser negativos.',
            '*.decimal' => 'Hay un monto con demasiados decimales.',
            'adjunto_pdf.mimes' => 'El adjunto PDF debe ser un PDF.',
            'adjunto_xml.mimes' => 'El adjunto XML debe ser un XML.',
            'adjunto_xml.extensions' => 'El adjunto XML debe ser un XML.',
            'adjunto_pdf.max' => 'El PDF no puede superar los 10 MB.',
            'adjunto_xml.max' => 'El XML no puede superar los 10 MB.',
        ], [
            'proveedor_id' => 'proveedor',
            'tipo_comprobante_codigo' => 'tipo de comprobante',
            'numero' => 'número',
            'fecha_emision' => 'fecha de emisión',
            'condicion' => 'condición',
        ]);

        if ($validator->fails()) {
            throw new CompraException($validator->errors()->first(), 422, ['errors' => $validator->errors()->toArray()]);
        }

        $datos = collect($validator->validated())->except(['adjunto_pdf', 'adjunto_xml'])->all();

        $suma = BigDecimal::zero();
        foreach (Compra::MONTOS as $campo) {
            $suma = $suma->plus((string) $datos[$campo]);
        }
        if (! $suma->isEqualTo((string) $datos['total'])) {
            throw new CompraException("El total no cuadra: la suma de los montos es {$suma->toScale(2)}.");
        }

        // D6: por defecto, el mes de la emisión; nunca anterior. El tope de 12 meses es solo un
        // aviso en pantalla hasta que el contador lo confirme (P13).
        $mesEmision = substr($datos['fecha_emision'], 0, 7);
        $datos['periodo'] = ($datos['periodo'] ?? null) ?: $mesEmision;
        if ($datos['periodo'] < $mesEmision) {
            throw new CompraException('El periodo de anotación no puede ser anterior al mes de emisión.');
        }

        $datos['tipo_cambio'] = $datos['moneda'] === 'USD' ? $datos['tipo_cambio'] : null;

        if ($datos['condicion'] === 'contado') {
            $datos['fecha_vencimiento'] = null;
        } elseif ($datos['fecha_vencimiento'] < $datos['fecha_emision']) {
            throw new CompraException('El vencimiento no puede ser anterior a la emisión.');
        }

        $datos['observaciones'] = $datos['observaciones'] ?? null;

        return $datos;
    }

    private function exigirPeriodoAbierto(CompraPeriodo $periodo): void
    {
        if ($periodo->estaCerrado()) {
            throw new CompraException("El periodo {$periodo->periodo} está cerrado");
        }
    }

    // D5 — «se pide completar el RUC en la primera compra».
    private function exigirDocumento(Proveedor $proveedor, string $tipo): void
    {
        $documento = $this->normalizar($proveedor->numero_documento);

        // El no domiciliado registra su comprobante del exterior aunque no tenga documento.
        if ($proveedor->es_no_domiciliado && $tipo === self::TIPO_NO_DOMICILIADO) {
            return;
        }

        $mensaje = match (true) {
            $documento === '' => 'Completa el documento del proveedor antes de registrar su compra.',
            in_array($tipo, self::TIPOS_CON_RUC, true) && ! preg_match('/^\d{11}$/', $documento) => 'Este tipo de comprobante necesita que el proveedor tenga RUC de 11 dígitos.',
            $tipo === self::TIPO_LIQUIDACION && ! preg_match('/^(\d{8}|\d{11})$/', $documento) => 'La liquidación de compra necesita el DNI o RUC del proveedor.',
            default => null,
        };

        if ($mensaje !== null) {
            throw new CompraException($mensaje, 422, ['requiere_documento' => true, 'proveedor_id' => $proveedor->id]);
        }
    }

    // En una NC/ND la compra que modifica es obligatoria, del mismo proveedor (D3) y vigente.
    private function referencia(array $datos, Compra $compra): ?int
    {
        if (! in_array($datos['tipo_comprobante_codigo'], Compra::TIPOS_NOTA, true)) {
            return null;
        }

        $id = (int) ($datos['compra_referencia_id'] ?? 0);
        if ($id === 0) {
            throw new CompraException('Indica la compra que modifica esta nota.');
        }

        $referencia = Compra::find($id);
        if (! $referencia || $referencia->id === $compra->id) {
            throw new CompraException('La compra de referencia no es válida.');
        }
        if ($referencia->clave_proveedor !== $datos['clave_proveedor']) {
            throw new CompraException('La compra de referencia es de otro proveedor.');
        }
        if ($referencia->estaAnulada()) {
            throw new CompraException('La compra de referencia está anulada.');
        }

        return $referencia->id;
    }

    private function duplicada(array $datos, ?int $idPropio): ?Compra
    {
        return Compra::vigentes()
            ->with('proveedor')
            ->where('clave_proveedor', $datos['clave_proveedor'])
            ->where('tipo_comprobante_codigo', $datos['tipo_comprobante_codigo'])
            ->where('serie', $datos['serie'])
            ->where('numero', $datos['numero'])
            ->when($idPropio, fn ($q) => $q->where('id', '!=', $idPropio))
            ->first();
    }

    private function yaRegistrada(Compra $existente): CompraException
    {
        return new CompraException('Ese comprobante ya está registrado.', 409, ['existente' => [
            'id' => $existente->id,
            'serie' => $existente->serie,
            'numero' => $existente->numero,
            'periodo' => $existente->periodo,
            'total' => $existente->total,
            'moneda' => $existente->moneda,
            'proveedor' => $existente->proveedor?->razon_social,
            'ciudad' => $existente->proveedor?->ciudad,
        ]]);
    }

    private function clave(Proveedor $proveedor): string
    {
        $documento = $this->normalizar($proveedor->numero_documento);

        return $documento === '' ? "id:{$proveedor->id}" : $documento;
    }

    // Mayúsculas y sin espacios.
    private function normalizar(?string $valor): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', '', (string) $valor));
    }

    // D9: disco 'private' del tenant, con nombre aleatorio y la extensión que corresponde. El XML
    // no se interpreta, solo se guarda.
    private function guardarAdjunto(UploadedFile $archivo, string $tipo): string
    {
        return Storage::disk('private')->putFileAs("compras/{$tipo}", $archivo, Str::uuid().'.'.$tipo);
    }
}
