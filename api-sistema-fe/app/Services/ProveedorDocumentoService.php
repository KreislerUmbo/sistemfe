<?php

namespace App\Services;

use App\Models\Proveedor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// T-134 — documento del proveedor: lo usan el controlador base y el de Agencia.
class ProveedorDocumentoService
{
    /**
     * Deja coherentes los datos de domicilio y documento antes de guardar. $actual = proveedor que
     * se edita (null al crear). Devuelve el mensaje de error, o null si está bien.
     */
    public static function preparar(array &$datos, ?Proveedor $actual = null): ?string
    {
        $valor = fn (string $campo) => array_key_exists($campo, $datos) ? $datos[$campo] : $actual?->{$campo};

        $noDomiciliado = (bool) $valor('es_no_domiciliado');
        if (array_key_exists('es_no_domiciliado', $datos)) {
            $datos['es_no_domiciliado'] = $noDomiciliado;
        }

        if (array_key_exists('ciudad', $datos)) {
            $datos['ciudad'] = TextoFormatoService::capitalizarNombrePropio($datos['ciudad']);
        }

        if ($noDomiciliado) {
            return blank($valor('pais')) ? 'Indica el país del proveedor no domiciliado.' : null;
        }

        if (filled($valor('pais'))) {
            $datos['pais'] = null;
        }

        // Solo se valida lo que cambia: un dato viejo mal escrito no bloquea editar lo demás.
        $cambiaDocumento = $actual === null
            || $valor('tipo_documento') !== $actual->tipo_documento
            || $valor('numero_documento') !== $actual->numero_documento
            || $actual->es_no_domiciliado;

        $numero = (string) $valor('numero_documento');
        if ($cambiaDocumento && $numero !== '' && $valor('tipo_documento') === 'RUC' && ! preg_match('/^\d{11}$/', $numero)) {
            return 'El RUC debe tener 11 dígitos.';
        }

        return null;
    }

    /**
     * El RUC no es único (varios establecimientos): al crear con un documento ya registrado se
     * responde 409 con los existentes, salvo que venga confirmar_duplicado.
     */
    public static function avisoDuplicado(Request $request, array $datos): ?JsonResponse
    {
        if ($request->boolean('confirmar_duplicado')) {
            return null;
        }

        $existentes = self::conElMismoDocumento($datos);
        if ($existentes->isEmpty()) {
            return null;
        }

        return response()->json([
            'code' => 409,
            'duplicado' => true,
            'message' => 'Ya existe un proveedor con ese documento.',
            'existentes' => $existentes,
        ], 409);
    }

    private static function conElMismoDocumento(array $datos): Collection
    {
        $numero = trim((string) ($datos['numero_documento'] ?? ''));
        if ($numero === '') {
            return collect();
        }

        $noDomiciliado = (bool) ($datos['es_no_domiciliado'] ?? false);

        $query = Proveedor::query()
            ->where('es_no_domiciliado', $noDomiciliado)
            ->whereRaw('lower(trim(numero_documento)) = ?', [mb_strtolower($numero)]);

        // El mismo número en otro país es otro documento.
        if ($noDomiciliado) {
            $query->whereRaw("lower(trim(coalesce(pais, ''))) = ?", [mb_strtolower(trim((string) ($datos['pais'] ?? '')))]);
        }

        return $query->orderBy('razon_social')->orderBy('id')
            ->get(['id', 'razon_social', 'nombre_comercial', 'ciudad', 'direccion', 'direccion_fiscal', 'estado']);
    }
}
