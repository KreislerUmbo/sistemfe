<?php

namespace App\Services\Compras;

use App\Models\AgenciaViajes\CompraAsignacion;
use App\Models\Compras\Compra;
use App\Models\Compras\CompraLinea;
use App\Models\Compras\CompraPeriodo;
use App\Models\Product\Product;
use App\Models\Proveedor;
use App\Models\Sale\TipoComprobante;
use App\Models\User;
use App\Rules\ArchivoSubido;
use App\Services\HoraPeru;
use App\Services\Stock\StockService;
use App\Services\Tenancy\GiroActual;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
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

    // T-154 (D2): una compra con productos no cambia nada que afecte al stock o al costo.
    private const FIJA_CON_PRODUCTOS = 'Esta compra tiene productos: anúlala y regístrala de nuevo';

    // T-162: las líneas solo se registran al crear, muevan o no stock.
    private const FIJAS_LAS_LINEAS = 'Las líneas se registran al crear la compra: para cambiarlas, anúlala y regístrala de nuevo';

    // La pantalla manda `lineas` como un texto JSON: campo por campo pasarían de max_input_vars.
    private const MAX_LINEAS = 100;

    public function __construct(private StockService $stock, private PagoProveedorService $pagos) {}

    public function registrar(Request $request, int $userId): Compra
    {
        // T-136 (D3): «Pagada ahora». Sin compras.pagar la compra se registra igual, pendiente.
        $pago = $request->input('pago');
        $usuario = $pago ? User::findOrFail($userId) : null;

        return $this->guardar(
            $request,
            new Compra(['user_id' => $userId]),
            $usuario?->can('compras.pagar') ? fn (Compra $compra) => $this->pagarAhora($compra, $pago, $usuario) : null,
        );
    }

    // Compra, pago por el total y aplicación en la misma transacción: si el pago falla, no queda nada.
    private function pagarAhora(Compra $compra, mixed $pago, User $usuario): void
    {
        if (! is_array($pago)) {
            throw new CompraException('Los datos del pago no se pudieron leer.');
        }
        if ($compra->condicion !== 'contado' || $compra->tipo_comprobante_codigo === Compra::TIPO_NOTA_CREDITO) {
            throw new CompraException('Solo una compra al contado se paga al registrarla.');
        }
        // Un comprobante en cero no tiene nada que pagar.
        if (BigDecimal::of($compra->total)->isZero()) {
            return;
        }

        $this->pagos->registrar([
            ...Arr::only($pago, ['origen', 'payment_method_id', 'cuenta_bancaria_id', 'numero_operacion', 'fecha']),
            'proveedor_id' => $compra->proveedor_id,
            'moneda' => $compra->moneda,
            'monto' => $compra->total,
            // En USD, el pago usa el T.C. de la compra: sin diferencia de cambio.
            'tipo_cambio' => $compra->tipo_cambio,
            'aplicaciones' => [['compra_id' => $compra->id, 'monto' => $compra->total]],
        ], $usuario);
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
            // T-140: la compra que modifica la nota, bloqueada: un reembolso no puede apoyarse en la
            // nota mientras se anula. Antes que el periodo, el mismo orden que guardar() (compras,
            // periodos y productos): al revés, anular la nota y editar esa compra a la vez se traban.
            $referencia = $actual->tipo_comprobante_codigo === Compra::TIPO_NOTA_CREDITO && $actual->compra_referencia_id
                ? Compra::whereKey($actual->compra_referencia_id)->lockForUpdate()->firstOrFail()
                : null;
            $this->exigirPeriodoAbierto(CompraPeriodo::bloquear($actual->periodo));

            // T-140: primero los reembolsos, que a su vez traban los pagos.
            $this->exigirSinReembolsos($actual);
            if ($referencia) {
                $this->exigirSinReembolsos($referencia);
            }

            // T-136: con pagos aplicados (o por aprobar) no se anula hasta desaplicarlos.
            if ($actual->aplicaciones()->activas()->exists()) {
                throw new CompraException('Desaplica primero los pagos de esta compra.');
            }

            // T-154 (D8): primero se anula la nota de crédito. T-162 (usuario, 27-nov): cualquiera, tenga
            // o no productos; si no, quedaría vigente sobre una compra anulada y sin poder editarse.
            $nota = Compra::vigentes()
                ->where('compra_referencia_id', $actual->id)
                ->where('tipo_comprobante_codigo', Compra::TIPO_NOTA_CREDITO)
                ->orderBy('id')
                ->first();
            if ($nota) {
                throw new CompraException("Anula primero la nota de crédito {$nota->serie}-{$nota->numero}");
            }

            $actual->update(['anulada_at' => now(), 'anulada_por' => $userId, 'motivo_anulacion' => $motivo]);

            // Solo las líneas con producto movieron stock.
            $lineas = $actual->lineas()->whereNotNull('product_id')->get();
            if ($lineas->isNotEmpty()) {
                $actual->tipo_comprobante_codigo === Compra::TIPO_NOTA_CREDITO
                    ? $this->stock->revertirNotaCompra($actual, $lineas)
                    : $this->stock->revertirCompra($actual, $lineas);
            }

            return $actual;
        });
    }

    private function guardar(Request $request, Compra $compra, ?Closure $alRegistrar = null): Compra
    {
        $datos = $this->validar($request);
        $nuevos = [];

        try {
            return DB::transaction(function () use ($request, $compra, $datos, &$nuevos, $alRegistrar) {
                $actual = $compra->exists ? Compra::whereKey($compra->id)->lockForUpdate()->firstOrFail() : $compra;
                if ($actual->estaAnulada()) {
                    throw new CompraException('Una compra anulada no se puede editar.');
                }

                if ($actual->exists) {
                    $this->exigirSinReparto($actual, $datos);
                    $this->exigirQueRespeteSusReembolsos($actual, $datos);
                    $this->exigirQueRespeteSusPagos($actual, $datos);
                }

                $lineas = $datos['lineas'];
                unset($datos['lineas']);
                $datos['con_credito_fiscal'] ??= (bool) $actual->con_credito_fiscal;

                if ($actual->exists) {
                    $this->exigirSinCambiosDeStock($actual, $datos, $lineas);
                    $lineas = []; // las líneas solo se registran al crear (D2)
                } else {
                    $this->exigirTipoConLineas($datos['tipo_comprobante_codigo'], $lineas);
                }

                // Orden de bloqueo: compras, periodos y productos. La compra que modifica una nota
                // se bloquea para que dos notas no devuelvan lo mismo, ni se anule a la vez.
                // T-140: también la que modificaba hasta ahora (puede tener reembolsos).
                $referencias = array_filter([
                    in_array($datos['tipo_comprobante_codigo'], Compra::TIPOS_NOTA, true) ? ($datos['compra_referencia_id'] ?? null) : null,
                    $actual->exists ? $actual->compra_referencia_id : null,
                ]);
                if ($referencias) {
                    Compra::whereIn('id', $referencias)->orderBy('id')->lockForUpdate()->get();
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
                $datos['clave_proveedor'] = $proveedor->claveDocumento();
                $datos['compra_referencia_id'] = $this->referencia($datos, $actual);
                // T-140: la nota que sostiene un reembolso no cambia lo que acredita.
                if ($actual->exists && $actual->tipo_comprobante_codigo === Compra::TIPO_NOTA_CREDITO
                    && $actual->compra_referencia_id && $this->cambiaLoQueAcredita($actual, $datos)) {
                    $this->exigirSinReembolsos(Compra::findOrFail($actual->compra_referencia_id));
                }

                if ($existente = $this->duplicada($datos, $actual->id)) {
                    throw $this->yaRegistrada($existente);
                }

                $esNotaCredito = $datos['tipo_comprobante_codigo'] === Compra::TIPO_NOTA_CREDITO;
                if ($lineas) {
                    $lineas = $this->prepararLineas($lineas, $datos);
                    if ($esNotaCredito) {
                        $this->exigirDevolvible($this->conProducto($lineas), $datos['compra_referencia_id']);
                    }
                }
                // Primero lo de la compra que se edita (si otras notas la modifican), luego lo de la nota.
                if ($actual->exists) {
                    $this->exigirQueCubraSusNotas($actual, $datos);
                }
                if ($esNotaCredito && $this->cambiaLoQueAcredita($actual, $datos)) {
                    $this->exigirDentroDelOriginal($datos, $actual->id);
                }

                $actual->fill($datos);
                foreach (self::ADJUNTOS as $tipo => $columna) {
                    if ($archivo = $request->file($columna)) {
                        $actual->{$columna} = $nuevos[] = $this->guardarAdjunto($archivo, $tipo);
                    }
                }
                $actual->save();

                if ($lineas) {
                    $actual->lineas()->createMany($lineas);
                    if ($deStock = $this->conProducto($lineas)) {
                        $esNotaCredito
                            ? $this->stock->salirPorNotaCompra($actual, $deStock)
                            : $this->stock->entrarPorCompra($actual, $deStock);
                    }
                }

                if ($alRegistrar) {
                    $alRegistrar($actual);
                }

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
        if (is_string($entrada['lineas'] ?? null)) {
            // Si no es una lista válida, queda el texto y lo rechaza la regla `array`.
            $lineas = json_decode($entrada['lineas'], true);
            // Un objeto JSON (`{"a": {...}}`) no es una lista de líneas.
            $entrada['lineas'] = is_array($lineas) && array_is_list($lineas) ? $this->comoFormulario($lineas) : $entrada['lineas'];
        }
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
            'con_credito_fiscal' => ['nullable', 'boolean'],
            'lineas' => ['nullable', 'array', 'max:'.self::MAX_LINEAS],
            'lineas.*' => ['array'],
            'lineas.*.product_id' => ['nullable', 'integer'],
            'lineas.*.descripcion' => ['nullable', 'required_without:lineas.*.product_id', 'string', 'max:500'],
            'lineas.*.unidad_medida' => ['nullable', 'string', 'max:25'],
            'lineas.*.codigo_proveedor' => ['nullable', 'string', 'max:50'],
            'lineas.*.tipo_afectacion_igv' => ['required', Rule::in(CompraLinea::AFECTACIONES)],
            'lineas.*.cantidad' => ['required', 'numeric', 'gt:0', 'max:999999999', 'decimal:0,6'],
            'lineas.*.factor' => ['nullable', 'numeric', 'gt:0', 'max:999999', 'decimal:0,6'],
            'lineas.*.valor_unitario' => ['required', 'numeric', 'min:0', 'max:999999999999', 'decimal:0,6'],
            'lineas.*.valor_venta' => $monto,
            'lineas.*.igv' => $monto,
            'lineas.*.total' => $monto,
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
            'lineas.array' => 'Las líneas no se pudieron leer.',
            'lineas.max' => 'Una compra admite hasta '.self::MAX_LINEAS.' líneas.',
            'lineas.*.descripcion.required_without' => 'Escribe la descripción de cada línea sin producto.',
            'lineas.*.descripcion.max' => 'La descripción de una línea no puede pasar de 500 caracteres.',
            'lineas.*.codigo_proveedor.max' => 'El código del proveedor no puede pasar de 50 caracteres.',
            'lineas.*.tipo_afectacion_igv.required' => 'Elige la afectación del IGV de cada línea.',
            'lineas.*.tipo_afectacion_igv.in' => 'Hay una línea con una afectación del IGV que no se admite en compras.',
            'lineas.*.cantidad.required' => 'Hay una línea sin cantidad.',
            'lineas.*.cantidad.gt' => 'La cantidad de cada línea debe ser mayor a cero.',
            'lineas.*.factor.gt' => 'El factor debe ser mayor a cero.',
            'lineas.*.*.required' => 'Completa los montos de cada línea.',
            'lineas.*.*.min' => 'Los montos no pueden ser negativos.',
            'lineas.*.*.decimal' => 'Hay una línea con demasiados decimales.',
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

        // Sin enviar queda en null: al editar se conserva lo guardado.
        $datos['con_credito_fiscal'] = isset($datos['con_credito_fiscal']) ? (bool) $datos['con_credito_fiscal'] : null;
        $datos['lineas'] = array_values($datos['lineas'] ?? []);

        return $datos;
    }

    // El texto JSON no pasa por TrimStrings ni ConvertEmptyStringsToNull: se hace aquí, para que un
    // `product_id: ""` sea una línea sin producto y no «el producto no existe».
    private function comoFormulario(array $lineas): array
    {
        return array_map(fn ($linea) => is_array($linea) ? array_map(function ($valor) {
            if (! is_string($valor)) {
                return $valor;
            }
            $valor = trim($valor);

            return $valor === '' ? null : $valor;
        }, $linea) : $linea, $lineas);
    }

    // T-152 (Q5, Q13): el reparto suma el total en la moneda de la compra. Solo Agencia tiene la
    // tabla; con la compra ya bloqueada, no se cruza con quien la esté repartiendo.
    private function exigirSinReparto(Compra $compra, array $datos): void
    {
        $cambia = $datos['moneda'] !== $compra->moneda
            || ! BigDecimal::of((string) $datos['total'])->isEqualTo($compra->total);

        if ($cambia && app(GiroActual::class)->es('agencia_viajes')
            && CompraAsignacion::where('compra_id', $compra->id)->exists()) {
            throw new CompraException('Esta compra tiene reparto: quita el reparto antes de cambiar el total o la moneda.');
        }
    }

    // T-140: con un reembolso vigente la compra no cambia lo que sostiene su saldo a favor.
    private function exigirQueRespeteSusReembolsos(Compra $compra, array $datos): void
    {
        $cambia = (int) $datos['proveedor_id'] !== (int) $compra->proveedor_id
            || $datos['moneda'] !== $compra->moneda
            || ! BigDecimal::of((string) $datos['total'])->isEqualTo($compra->total)
            || ($datos['tipo_comprobante_codigo'] === Compra::TIPO_NOTA_CREDITO
                && $compra->tipo_comprobante_codigo !== Compra::TIPO_NOTA_CREDITO);

        if ($cambia) {
            $this->exigirSinReembolsos($compra);
        }
    }

    private function exigirSinReembolsos(Compra $compra): void
    {
        if ($compra->reembolsos()->vigentes()->exists()) {
            throw new CompraException("La compra {$compra->serie}-{$compra->numero} tiene reembolsos: anúlalos primero.");
        }
    }

    // T-136: con pagos aplicados (o por aprobar) no cambian proveedor ni moneda, y el total no baja
    // de lo pagado más sus notas de crédito. Con la compra ya bloqueada.
    private function exigirQueRespeteSusPagos(Compra $compra, array $datos): void
    {
        if (! $compra->aplicaciones()->activas()->exists()) {
            return;
        }
        if ((int) $datos['proveedor_id'] !== (int) $compra->proveedor_id || $datos['moneda'] !== $compra->moneda) {
            throw new CompraException('Esta compra tiene pagos: desaplícalos antes de cambiar el proveedor o la moneda.');
        }
        if ($datos['tipo_comprobante_codigo'] === Compra::TIPO_NOTA_CREDITO) {
            throw new CompraException('Esta compra tiene pagos: no puede pasar a ser una nota de crédito.');
        }

        // Solo si el total cambia: una compra pagada con saldo a favor se sigue editando.
        $total = BigDecimal::of((string) $datos['total']);
        if ($total->isEqualTo($compra->total)) {
            return;
        }
        $saldo = $this->pagos->saldos([$compra->id])[$compra->id];
        $minimo = $saldo['aplicado']->plus($saldo['por_aprobar'])->plus($saldo['notas']);
        if ($total->isLessThan($minimo)) {
            throw new CompraException("El total no puede ser menor que lo pagado y sus notas de crédito ({$minimo->toScale(2)}).");
        }
    }

    // D2. Sin líneas en el pedido se conservan las guardadas. La compra que modifica una nota
    // también queda fija: de ella salen los máximos de D3.
    private function exigirSinCambiosDeStock(Compra $compra, array $datos, array $lineas): void
    {
        $guardadas = $compra->lineas()->get();
        if ($guardadas->isEmpty()) {
            if ($lineas) {
                throw new CompraException(self::FIJAS_LAS_LINEAS);
            }

            return;
        }

        // T-162: sin productos no hubo stock: la cabecera se edita como la de una compra sin líneas.
        if ($guardadas->whereNotNull('product_id')->isEmpty()) {
            $this->exigirTipoConLineas($datos['tipo_comprobante_codigo'], $guardadas->toArray());
            if ($lineas && ! $this->mismasLineas($lineas, $guardadas)) {
                throw new CompraException(self::FIJAS_LAS_LINEAS);
            }

            return;
        }

        $referencia = in_array($datos['tipo_comprobante_codigo'], Compra::TIPOS_NOTA, true)
            ? (int) ($datos['compra_referencia_id'] ?? 0) : 0;
        $mismoCambio = $datos['tipo_cambio'] === null || $compra->tipo_cambio === null
            ? $datos['tipo_cambio'] === $compra->tipo_cambio
            : BigDecimal::of((string) $datos['tipo_cambio'])->isEqualTo($compra->tipo_cambio);

        $cambia = (int) $datos['proveedor_id'] !== (int) $compra->proveedor_id
            || $datos['tipo_comprobante_codigo'] !== $compra->tipo_comprobante_codigo
            || $datos['moneda'] !== $compra->moneda
            || ! $mismoCambio
            || $datos['con_credito_fiscal'] !== $compra->con_credito_fiscal
            || $referencia !== (int) $compra->compra_referencia_id
            || ($lineas && ! $this->mismasLineas($lineas, $guardadas));

        if ($cambia) {
            throw new CompraException(self::FIJA_CON_PRODUCTOS);
        }
    }

    /** Las líneas en una forma comparable, venga cada número como venga escrito. */
    // T-162: las líneas enviadas al editar, comparables con las guardadas. Un texto que no se envía
    // (un cliente de T-154 no los conoce) se toma de la guardada; uno enviado y distinto no cuadra.
    private function mismasLineas(array $enviadas, Collection $guardadas): bool
    {
        $guardadas = $guardadas->values()->all();
        $completas = array_map(function (array $linea, int $i) use ($guardadas) {
            foreach (['descripcion', 'unidad_medida', 'codigo_proveedor', 'tipo_afectacion_igv'] as $campo) {
                if (! array_key_exists($campo, $linea) && isset($guardadas[$i])) {
                    $linea[$campo] = $guardadas[$i][$campo];
                }
            }

            return $linea;
        }, $enviadas, array_keys($enviadas));

        return $this->firma($completas) === $this->firma($guardadas);
    }

    // Números con la misma escala y textos sin espacios, venga cada uno como venga escrito.
    private function firma(array $lineas): array
    {
        return array_map(function ($linea) {
            $numeros = array_map(
                fn (string $campo) => (string) BigDecimal::of((string) ($linea[$campo] ?? ($campo === 'factor' ? 1 : 0)))
                    ->toScale(6, RoundingMode::HALF_UP),
                ['product_id', 'factor', 'cantidad', 'valor_unitario', 'valor_venta', 'igv', 'total'],
            );
            $textos = array_map(
                fn (string $campo) => trim((string) ($linea[$campo] ?? '')),
                ['descripcion', 'unidad_medida', 'codigo_proveedor', 'tipo_afectacion_igv'],
            );

            return implode('|', [...$numeros, ...$textos]);
        }, $lineas);
    }

    // Las líneas con producto son de los tipos de T-154; T-162 suma otros solo para las demás.
    private function exigirTipoConLineas(string $tipo, array $lineas): void
    {
        if (! $lineas || in_array($tipo, Compra::TIPOS_CON_LINEAS, true)) {
            return;
        }
        $hayProductos = (bool) $this->conProducto($lineas);
        if (! $hayProductos && in_array($tipo, Compra::TIPOS_CON_LINEAS_SIN_PRODUCTO, true)) {
            return;
        }

        throw new CompraException($hayProductos
            ? 'Ese tipo de comprobante no lleva productos.'
            : 'Ese tipo de comprobante no lleva líneas.');
    }

    /** Las únicas que tocan stock, costo y devoluciones por unidades. */
    private function conProducto(array $lineas): array
    {
        return array_values(array_filter($lineas, fn (array $linea) => ($linea['product_id'] ?? null) !== null));
    }

    // Valida cada línea y, si tiene producto, calcula su costo por unidad base, en soles (D1, D5, D6).
    private function prepararLineas(array $lineas, array $datos): array
    {
        $productos = Product::withTrashed()->whereIn('id', array_column($this->conProducto($lineas), 'product_id'))
            ->get()->keyBy('id');
        $cambio = (string) ($datos['tipo_cambio'] ?? 1);
        // Una devolución puede incluir un producto que ya se eliminó del catálogo.
        $admiteEliminados = $datos['tipo_comprobante_codigo'] === Compra::TIPO_NOTA_CREDITO;

        $preparadas = [];
        foreach ($lineas as $i => $linea) {
            $numero = $i + 1;
            $producto = null;
            if (($linea['product_id'] ?? null) !== null) {
                $producto = $productos->get((int) $linea['product_id']);
                if (! $producto || ($producto->trashed() && ! $admiteEliminados)) {
                    throw new CompraException("El producto de la línea {$numero} no existe.");
                }
            }

            // La pantalla manda JSON: aquí no pasó el recorte de espacios de Laravel.
            $descripcion = trim((string) ($linea['descripcion'] ?? ''))
                ?: mb_substr((string) $producto?->title, 0, 500);
            if ($descripcion === '') {
                throw new CompraException("Escribe la descripción de la línea {$numero}.");
            }

            $unidad = trim((string) ($linea['unidad_medida'] ?? ''))
                ?: ($producto?->unidad_medida ?: CompraLinea::UNIDAD_SERVICIO);
            // La del producto pasa aunque no esté en la lista: la ficha no la valida en el servidor.
            if (! in_array($unidad, CompraLinea::UNIDADES, true) && $unidad !== $producto?->unidad_medida) {
                throw new CompraException("«{$descripcion}»: la unidad de medida {$unidad} no existe.");
            }

            // El factor convierte presentaciones a unidades de stock: sin producto no aplica.
            $factor = BigDecimal::of((string) ($producto ? ($linea['factor'] ?? 1) : 1));
            $cantidad = BigDecimal::of((string) $linea['cantidad']);
            $valorVenta = $cantidad->multipliedBy((string) $linea['valor_unitario'])->toScale(2, RoundingMode::HALF_UP);
            if (! $valorVenta->isEqualTo((string) $linea['valor_venta'])) {
                throw new CompraException("«{$descripcion}»: el valor de venta debe ser cantidad × valor unitario ({$valorVenta}).");
            }

            // `in` de la validación acepta el número 10: aquí se compara como texto.
            $afectacion = (string) $linea['tipo_afectacion_igv'];
            if ($afectacion !== CompraLinea::AFECTACION_GRAVADA
                && ! BigDecimal::of((string) $linea['igv'])->isZero()) {
                throw new CompraException("«{$descripcion}»: una línea exonerada, inafecta o de exportación no lleva IGV.");
            }

            // Sin crédito fiscal el total es el costo: tiene que ser valor de venta + IGV.
            $total = $valorVenta->plus((string) $linea['igv']);
            if (! $total->isEqualTo((string) $linea['total'])) {
                throw new CompraException("«{$descripcion}»: el total debe ser valor de venta + IGV ({$total}).");
            }

            $costo = null;
            if ($producto) {
                $unidades = $cantidad->multipliedBy($factor);
                if ($unidades->isLessThan('0.000001')) {
                    throw new CompraException("«{$descripcion}»: la cantidad por el factor es demasiado chica.");
                }

                $monto = (string) ($datos['con_credito_fiscal'] ? $linea['valor_venta'] : $linea['total']);
                $costo = BigDecimal::of($monto)->multipliedBy($cambio)->dividedBy($unidades, 6, RoundingMode::HALF_UP);
                // decimal(18,6): pasado ese tope la base lo rechazaría con un 500.
                if ($costo->isGreaterThan('999999999999.999999')) {
                    throw new CompraException("«{$descripcion}»: el costo por unidad sale demasiado alto; revisa la cantidad y el factor.");
                }
            }

            $preparadas[] = [
                'product_id' => $producto?->id,
                'descripcion' => $descripcion,
                'unidad_medida' => $unidad,
                'codigo_proveedor' => trim((string) ($linea['codigo_proveedor'] ?? '')) ?: null,
                'tipo_afectacion_igv' => $afectacion,
                'factor' => (string) $factor,
                'cantidad' => (string) $cantidad,
                'valor_unitario' => (string) $linea['valor_unitario'],
                'valor_venta' => (string) $linea['valor_venta'],
                'igv' => (string) $linea['igv'],
                'total' => (string) $linea['total'],
                'costo_unitario_base' => $costo === null ? null : (string) $costo,
            ];
        }

        return $preparadas;
    }

    // T-162 (CA6): las notas de crédito vigentes de una compra, juntas, no pasan de su total.
    private function exigirDentroDelOriginal(array $datos, ?int $idPropio): void
    {
        $original = Compra::findOrFail($datos['compra_referencia_id']);
        if ($original->moneda !== $datos['moneda']) {
            throw new CompraException("La nota de crédito va en la misma moneda que la compra que modifica ({$original->moneda}).");
        }

        $queda = BigDecimal::of($original->total)->minus($this->acreditado($original->id, $idPropio));
        if (BigDecimal::of((string) $datos['total'])->isGreaterThan($queda)) {
            throw new CompraException("La nota de crédito pasa del monto de la compra que modifica: quedan {$queda->toScale(2)} por acreditar.");
        }
    }

    // Lo mismo visto desde la compra: al editarla no puede quedar por debajo de sus notas.
    // Revisión de T-162: con notas que la modifican, tampoco puede volverse nota (una nota no
    // modifica otra nota) ni cambiar de proveedor (la nota es del mismo proveedor que su compra).
    private function exigirQueCubraSusNotas(Compra $compra, array $datos): void
    {
        $tieneNotas = Compra::vigentes()
            ->where('compra_referencia_id', $compra->id)
            ->whereIn('tipo_comprobante_codigo', Compra::TIPOS_NOTA)
            ->exists();
        if (! $tieneNotas) {
            return;
        }
        if (in_array($datos['tipo_comprobante_codigo'], Compra::TIPOS_NOTA, true)) {
            throw new CompraException('Esta compra tiene notas que la modifican: no puede pasar a ser una nota.');
        }
        if ($datos['clave_proveedor'] !== $compra->clave_proveedor) {
            throw new CompraException('Esta compra tiene notas que la modifican: no se puede cambiar su proveedor.');
        }

        $acreditado = $this->acreditado($compra->id, null);
        if ($acreditado->isZero()) {
            return;
        }
        if ($datos['moneda'] !== $compra->moneda) {
            throw new CompraException('Esta compra tiene notas de crédito: no se puede cambiar su moneda.');
        }
        // Solo si el total cambia: una compra anterior a T-162 por debajo de sus notas se sigue editando.
        $total = BigDecimal::of((string) $datos['total']);
        if (! $total->isEqualTo($compra->total) && $total->isLessThan($acreditado)) {
            throw new CompraException("El total no puede ser menor que sus notas de crédito ({$acreditado->toScale(2)}).");
        }
    }

    // T-162 (usuario, 27-nov): el tope de la nota se revisa al crearla o si cambia lo que acredita
    // (tipo, total, moneda o compra que modifica). Así, una nota anterior a T-162 que no lo cumple
    // se sigue editando, por ejemplo para adjuntarle el XML.
    private function cambiaLoQueAcredita(Compra $nota, array $datos): bool
    {
        return ! $nota->exists
            || $datos['tipo_comprobante_codigo'] !== $nota->tipo_comprobante_codigo
            || $datos['moneda'] !== $nota->moneda
            || ! BigDecimal::of((string) $datos['total'])->isEqualTo($nota->total)
            || (int) $datos['compra_referencia_id'] !== (int) $nota->compra_referencia_id;
    }

    private function acreditado(int $compraId, ?int $sinNota): BigDecimal
    {
        return Compra::vigentes()
            ->where('compra_referencia_id', $compraId)
            ->where('tipo_comprobante_codigo', Compra::TIPO_NOTA_CREDITO)
            ->when($sinNota, fn ($q) => $q->where('id', '!=', $sinNota))
            ->pluck('total')
            ->reduce(fn (BigDecimal $suma, $total) => $suma->plus((string) $total), BigDecimal::of('0.00'));
    }

    // D3: solo productos de la compra que modifica, sin pasar de lo que queda por devolver.
    private function exigirDevolvible(array $lineas, int $referenciaId): void
    {
        $maximos = $this->devolvible($referenciaId);

        $pedidas = [];
        foreach ($lineas as $linea) {
            $id = $linea['product_id'];
            $pedidas[$id] = ($pedidas[$id] ?? BigDecimal::zero())
                ->plus(BigDecimal::of($linea['cantidad'])->multipliedBy($linea['factor']));
        }

        foreach ($pedidas as $id => $pedida) {
            $maximo = $maximos[$id] ?? BigDecimal::zero();
            if ($pedida->isGreaterThan($maximo)) {
                $titulo = Product::withTrashed()->find($id)->title;
                $numero = (float) (string) $maximo->toScale(6, RoundingMode::HALF_UP);

                throw new CompraException(
                    "«{$titulo}»: de la compra que modifica se pueden devolver como máximo {$numero} unidades.",
                    422,
                    ['product_id' => $id, 'maximo' => $numero],
                );
            }
        }
    }

    /**
     * Unidades base que quedan por devolver de una compra: lo comprado menos lo de sus notas de
     * crédito vigentes.
     *
     * @return array<int, BigDecimal> product_id => unidades
     */
    public function devolvible(int $compraId): array
    {
        $notas = Compra::vigentes()
            ->where('compra_referencia_id', $compraId)
            ->where('tipo_comprobante_codigo', Compra::TIPO_NOTA_CREDITO)
            ->pluck('id');
        // T-162: las líneas sin producto se devuelven por monto, no por unidades.
        $lineas = CompraLinea::whereIn('compra_id', $notas->push($compraId))->whereNotNull('product_id')->get();

        $quedan = [];
        // Primero lo comprado: un producto que no está en la compra no aparece.
        foreach ($lineas->where('compra_id', $compraId) as $linea) {
            $quedan[$linea->product_id] = ($quedan[$linea->product_id] ?? BigDecimal::zero())
                ->plus(BigDecimal::of($linea->cantidad)->multipliedBy($linea->factor));
        }
        foreach ($lineas->where('compra_id', '!=', $compraId) as $linea) {
            if (isset($quedan[$linea->product_id])) {
                $quedan[$linea->product_id] = $quedan[$linea->product_id]
                    ->minus(BigDecimal::of($linea->cantidad)->multipliedBy($linea->factor));
            }
        }

        return $quedan;
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
        // Una nota modifica un comprobante, no otra nota (la pantalla tampoco las ofrece).
        if (in_array($referencia->tipo_comprobante_codigo, Compra::TIPOS_NOTA, true)) {
            throw new CompraException('La compra de referencia no puede ser otra nota.');
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
        return $proveedor->claveDocumento();
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
