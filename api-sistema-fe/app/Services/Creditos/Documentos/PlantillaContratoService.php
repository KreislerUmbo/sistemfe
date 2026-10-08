<?php

declare(strict_types=1);

namespace App\Services\Creditos\Documentos;

use App\Enums\Creditos\TipoPlantilla;
use App\Models\Company;
use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoPlantilla;
use App\Models\User;
use App\Services\Creditos\AuditoriaCredito;
use App\Services\Creditos\Dinero;
use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Plantilla del contrato (Plan 1.15): HTML editable por el negocio con variables de lista
 * blanca. Se reemplazan como texto (nunca se evalúa como Blade/PHP) y el HTML se sanitiza
 * al guardar. Guardar crea una versión nueva; las anteriores no se tocan.
 */
class PlantillaContratoService
{
    /** Variables permitidas → descripción (se muestra en el editor). */
    public const VARIABLES = [
        'numero_credito' => 'N.º del crédito',
        'fecha_desembolso' => 'Fecha de entrega del dinero',
        'cliente_nombre' => 'Nombre completo del cliente',
        'cliente_documento' => 'Tipo y número de documento del cliente',
        'cliente_direccion' => 'Dirección del cliente',
        'monto_capital' => 'Monto prestado',
        'monto_capital_letras' => 'Monto prestado en letras',
        'tasa_interes' => 'Tasa de interés (ej. 20% sobre el total)',
        'interes_total' => 'Interés total',
        'monto_total' => 'Total a devolver',
        'monto_total_letras' => 'Total a devolver en letras',
        'numero_cuotas' => 'N.º de pagos',
        'frecuencia' => 'Frecuencia de pago (diaria, semanal…)',
        'fecha_primer_vencimiento' => 'Fecha del primer pago',
        'fecha_ultimo_vencimiento' => 'Fecha del último pago',
        'cronograma' => 'Tabla del cronograma de pagos',
        'interes_minimo' => 'Interés mínimo al cancelar antes (%)',
        'dias_gracia' => 'Días de gracia',
        'mora_regla' => 'Explicación de la mora',
        'garantes' => 'Garantes (cuando exista el módulo)',
        'prendas' => 'Prendas (cuando exista el módulo)',
        'empresa_razon_social' => 'Razón social de la empresa',
        'empresa_ruc' => 'RUC de la empresa',
        'empresa_direccion' => 'Dirección de la empresa',
        'fecha_hoy' => 'Fecha de emisión del documento',
    ];

    /** Etiquetas que deja el editor; todo lo demás se quita (su texto se conserva). */
    private const ETIQUETAS = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'ol', 'ul', 'li', 'h1', 'h2', 'h3', 'h4',
        'table', 'thead', 'tbody', 'tr', 'th', 'td', 'span', 'div', 'hr', 'blockquote', 'sub', 'sup'];
    /** Etiquetas que se eliminan con su contenido. */
    private const PELIGROSAS = ['script', 'style', 'iframe', 'object', 'embed', 'link', 'meta', 'form', 'input', 'button', 'textarea', 'select', 'svg', 'math', 'img'];
    private const ATRIBUTOS = ['colspan', 'rowspan'];

    public function __construct(private readonly AuditoriaCredito $auditoria)
    {
    }

    public function vigente(): CreditoPlantilla
    {
        $plantilla = CreditoPlantilla::where('tipo', TipoPlantilla::Contrato)->where('activa', true)->orderByDesc('version')->first();
        if ($plantilla === null) {
            throw new HttpException(422, 'No hay una plantilla de contrato activa. Configúrala en Créditos → Configuración → Contrato.');
        }

        return $plantilla;
    }

    public function guardar(string $html, User $usuario): CreditoPlantilla
    {
        $limpio = $this->sanitizar($html);
        $desconocidas = $this->variablesDesconocidas($limpio);
        if ($desconocidas !== []) {
            throw new HttpException(422, 'Variables no reconocidas: ' . implode(', ', array_map(static fn (string $v): string => "{{$v}}", $desconocidas)) . '.');
        }
        if (trim(strip_tags($limpio)) === '') {
            throw new HttpException(422, 'El contrato no puede quedar vacío.');
        }

        return DB::transaction(function () use ($limpio, $usuario): CreditoPlantilla {
            $anterior = CreditoPlantilla::where('tipo', TipoPlantilla::Contrato)->lockForUpdate()->orderByDesc('version')->first();
            CreditoPlantilla::where('tipo', TipoPlantilla::Contrato)->where('activa', true)->update(['activa' => false]);
            $nueva = CreditoPlantilla::create([
                'tipo' => TipoPlantilla::Contrato,
                'version' => ($anterior?->version ?? 0) + 1,
                'contenido' => $limpio,
                'activa' => true,
                'creado_por' => $usuario->id,
            ]);
            $this->auditoria->registrar('plantilla.contrato', $nueva, null, ['version' => $anterior?->version], ['version' => $nueva->version], null, $usuario);

            return $nueva;
        });
    }

    /** HTML del contrato con las variables reemplazadas (los valores ya vienen escapados). */
    public function renderizar(string $plantilla, array $valores): string
    {
        $reemplazos = [];
        foreach (self::VARIABLES as $clave => $_) {
            $reemplazos['{' . $clave . '}'] = $valores[$clave] ?? '';
        }

        // El editor deja cada variable dentro de un párrafo; una tabla no puede ir dentro de <p>.
        $plantilla = preg_replace('#<p>\s*\{cronograma\}\s*</p>#', '{cronograma}', $plantilla) ?? $plantilla;

        return strtr($plantilla, $reemplazos);
    }

    /** @return array<string, string> valores escapados para HTML (salvo {cronograma}, que es una tabla armada aquí). */
    public function valores(Credito $credito): array
    {
        $credito->loadMissing('cliente')->cargarCuotasVigentes();
        $empresa = Company::first();
        $cuotas = $credito->cuotasVigentes;
        $total = Dinero::aCentavos($credito->monto_capital) + Dinero::aCentavos($credito->interes_total);
        $cliente = $credito->cliente;

        $texto = [
            'numero_credito' => $credito->numero_credito ?? '—',
            'fecha_desembolso' => FormatoDocumento::fecha($credito->fecha_desembolso),
            'cliente_nombre' => $cliente?->full_name ?? '—',
            'cliente_documento' => trim(($cliente?->type_document ?? '') . ' ' . ($cliente?->n_document ?? '')),
            'cliente_direccion' => $cliente?->address ?? '—',
            'monto_capital' => FormatoDocumento::soles((string) $credito->monto_capital),
            'monto_capital_letras' => FormatoDocumento::enLetras((string) $credito->monto_capital),
            'tasa_interes' => FormatoDocumento::tasa($credito),
            'interes_total' => FormatoDocumento::soles((string) $credito->interes_total),
            'monto_total' => FormatoDocumento::soles($total),
            'monto_total_letras' => FormatoDocumento::enLetras($total),
            'numero_cuotas' => (string) $credito->numero_cuotas,
            'frecuencia' => FormatoDocumento::frecuencia($credito),
            'fecha_primer_vencimiento' => FormatoDocumento::fecha($cuotas->first()?->fecha_vencimiento),
            'fecha_ultimo_vencimiento' => FormatoDocumento::fecha($cuotas->last()?->fecha_vencimiento),
            'interes_minimo' => rtrim(rtrim((string) $credito->tasa_interes_minimo, '0'), '.') . '%',
            'dias_gracia' => (string) $credito->dias_gracia,
            'mora_regla' => FormatoDocumento::reglaMora($credito),
            'garantes' => 'Sin garantes.',
            'prendas' => 'Sin prendas.',
            'empresa_razon_social' => $empresa?->razon_social ?? '—',
            'empresa_ruc' => $empresa?->n_document ?? '—',
            'empresa_direccion' => $empresa?->address ?? '—',
            'fecha_hoy' => \App\Services\HoraPeru::ahora()->format('d/m/Y'),
        ];
        $escapados = array_map(static fn (string $v): string => e($v), $texto);

        $filas = $cuotas->map(static fn ($c): string => sprintf(
            '<tr><td>%d</td><td>%s</td><td class="derecha">%s</td><td class="derecha">%s</td><td class="derecha">%s</td></tr>',
            $c->numero_cuota, e(FormatoDocumento::fecha($c->fecha_vencimiento)), e(FormatoDocumento::soles((string) $c->monto_capital)),
            e(FormatoDocumento::soles((string) $c->monto_interes)), e(FormatoDocumento::soles((string) $c->monto_total)),
        ))->implode('');
        $escapados['cronograma'] = '<table class="cronograma"><thead><tr><th>N.º</th><th>Vence</th><th class="derecha">Capital</th>'
            . '<th class="derecha">Interés</th><th class="derecha">Cuota</th></tr></thead><tbody>' . $filas . '</tbody></table>';

        return $escapados;
    }

    public function sanitizar(string $html): string
    {
        $documento = new DOMDocument();
        $previo = libxml_use_internal_errors(true);
        $documento->loadHTML('<?xml encoding="utf-8" ?><div id="raiz">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previo);

        $raiz = $documento->getElementById('raiz');
        if ($raiz === null) {
            return '';
        }
        $this->limpiarNodo($raiz);

        $salida = '';
        foreach ($raiz->childNodes as $hijo) {
            $salida .= $documento->saveHTML($hijo);
        }

        return trim($salida);
    }

    /** @return list<string> */
    public function variablesDesconocidas(string $html): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $html, $m);

        return array_values(array_unique(array_diff($m[1], array_keys(self::VARIABLES))));
    }

    private function limpiarNodo(DOMNode $nodo): void
    {
        foreach (iterator_to_array($nodo->childNodes) as $hijo) {
            if ($hijo->nodeType === XML_COMMENT_NODE || $hijo->nodeType === XML_PI_NODE) {
                $nodo->removeChild($hijo);
                continue;
            }
            if (! $hijo instanceof DOMElement) {
                continue;
            }
            $etiqueta = strtolower($hijo->tagName);
            if (in_array($etiqueta, self::PELIGROSAS, true)) {
                $nodo->removeChild($hijo);
                continue;
            }
            $this->limpiarNodo($hijo);
            if (! in_array($etiqueta, self::ETIQUETAS, true)) {
                // Etiqueta desconocida: se conserva su contenido, sin la etiqueta.
                while ($hijo->firstChild !== null) {
                    $nodo->insertBefore($hijo->firstChild, $hijo);
                }
                $nodo->removeChild($hijo);
                continue;
            }
            foreach (iterator_to_array($hijo->attributes) as $atributo) {
                $nombre = strtolower($atributo->nodeName);
                $permitido = in_array($nombre, self::ATRIBUTOS, true)
                    || ($nombre === 'class' && preg_match('/^(ql-[a-z0-9-]+\s*)+$/', $atributo->nodeValue) === 1);
                if (! $permitido) {
                    $hijo->removeAttribute($atributo->nodeName);
                }
            }
        }
    }
}
