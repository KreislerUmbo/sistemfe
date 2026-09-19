<?php

namespace App\Services\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Models\AgenciaViajes\AfiliacionTurismo;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaDestino;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\ConfiguracionAgenciaPdf;
use App\Models\AgenciaViajes\OpcionHotel;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Services\StorageUrl;
use App\Services\TextoFormatoService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

// Extraído de AlternativaController (09-sep-2026, auditoría de
// mantenibilidad project_agencia_viajes_auditoria_mantenibilidad_2026-09-05)
// — generar() (antes AlternativaController::pdf()) más los 15 métodos
// privados que solo esta sección usaba, sin ningún cambio de lógica ni de
// firma. El controller mezclaba esto con el CRUD de Alternativa
// (store/update/descuento global/duplicar) en un solo archivo de 1521
// líneas; acá queda autocontenido — solo depende de ImagenRecorteService
// (confirmado: ningún método de esta sección usa $request/auth(), ni toca
// el resto del estado del controller).
class AlternativaPdfService
{
    // Sesión M5 — sección "Opciones de hoteles" del PDF (plan-matriz-hoteles-
    // cotizador.md P10): un grupo de alternativa_items con grupo_opcion_id
    // se dibuja como tabla matriz (fila=hotel, columna=tipo de habitación),
    // igual formato que los 3 documentos reales que originaron este plan
    // (docs/auxiliares/). Reusa el resolver de nombre centralizado de M2
    // (ReservaController::resolverNombreItem(), audiencia 'cliente' — nunca
    // revela mayorista/proveedor, solo el hotel) en vez de reimplementar de
    // dónde sale el nombre del hotel: para cualquier ítem de Hotel esa
    // función SIEMPRE devuelve "{hotel} · {tipo_habitacion}" (confirmado
    // leyendo sus 2 ramas de hotel antes de escribir esto) — separar por
    // " · " alcanza, no hace falta duplicar la lógica de qué relación mirar
    // según origen_tipo/proveedor_tarifa_id/opcion_hotel_tarifa_id.
    private const ORDEN_TIPO_HABITACION = ['simple', 'matrimonial', 'doble', 'triple', 'familiar'];

    // Hallazgo del usuario (06-sep-2026) — hoja membretada real: el
    // header/footer debe quedar FIJO arriba/abajo de CADA página, no
    // subir/bajar con el contenido. dompdf soporta esto con
    // position:fixed dentro del margen reservado por @page (mismo truco
    // ya usado en reporte-operativo.blade.php) — pero el margen de @page
    // es un valor fijo en el CSS, así que hay que calcular acá cuánto
    // alto reservar según lo que realmente se va a imprimir arriba/abajo.
    //
    // Con imagen custom: la agencia sube su membrete con la proporción
    // que quiera (ver los 2 casos reales de DKM Xplore) — se mide el
    // archivo real y se calcula qué alto le corresponde al ancho
    // completo de la hoja A4 (el membrete hace bleed hasta el borde
    // físico, ver ANCHO_PAGINA_A4_MM en alturaBandaCompletaMm()).
    //
    // Sin imagen custom: el header/footer generado desde
    // ConfiguracionAgenciaPdf tiene un alto predecible (logo + 2 líneas
    // de contacto, +eslogan, +franja de afiliaciones) — reserva fija por
    // bloque presente, no medida en píxeles porque no hay ninguna imagen
    // que medir.
    private const ANCHO_PAGINA_A4_MM = 210.0;

    public function __construct(private ImagenRecorteService $imagenRecorte)
    {
    }

    public function generar(string $id)
    {
        $alternativa = Alternativa::with([
            'cotizacion.cliente',
            'destinos.destinoAtractivo',
            'items.proveedorTarifa.proveedorServicio.destinoServicio.servicio',
            'items.proveedorTarifa.proveedorServicio.destinoServicio.destinoAtractivo',
            'items.proveedorTarifa.proveedorServicio.proveedor',
            'items.guiaTarifa.guia',
            'items.cotizacionPasajeAereo',
            'items.opcionMayorista.proveedor',
            // Simulación Panamá (04-sep-2026) — Incluye/No incluye/Vuelo/
            // Tours opcionales del mayorista elegido, ver
            // mayoristasReferenciados().
            'items.opcionMayorista.opcionales',
            // Tours incluidos con itinerario real, ver itinerarioAlternativa().
            'items.opcionMayorista.tours.paquetePlantilla',
            // Sesión M2 — resolverNombreItem() con audiencia 'cliente'
            // también intenta el hotel de la matriz de un OpcionMayorista
            // (sin ProveedorTarifa real) antes de caer al genérico.
            'items.opcionHotelTarifa.opcionHotel',
            // 07-sep-2026 — mismo criterio, para un ítem que materializa un
            // OpcionMayoristaOpcional elegido de verdad (ver
            // ReservaController::resolverNombreItem()).
            'items.opcionMayoristaOpcional',
        ])->findOrFail($id);

        $this->validarGruposResueltos($alternativa);

        $config = \App\Models\AgenciaViajes\ConfiguracionAgencia::first();
        $empresa = \App\Models\Company::first();
        $cuentasBancarias = \App\Models\AgenciaViajes\CuentaBancaria::where('activo', true)
            ->orderBy('orden')->orderBy('id')->get();

        $pasajeros = \App\Models\AgenciaViajes\CotizacionPasajero::where('cotizacion_id', $alternativa->cotizacion_id)->get();

        $itinerario = $this->itinerarioAlternativa($alternativa);
        $incluyePorDestino = $this->incluyePorDestino($alternativa);
        $tourUnico = $this->tourUnicoDeAlternativa($alternativa);
        $opcionesHoteles = $this->opcionesHoteles($alternativa);

        // Simulación Panamá (04-sep-2026) — Incluye/No incluye/Vuelo/Tours
        // opcionales de la(s) opción(es) de mayorista realmente elegidas en
        // esta alternativa (ver mayoristasReferenciados()).
        $mayoristas = $this->mayoristasReferenciados($alternativa);
        // TextoFormatoService::textoLibreParaPdf() — bug real 06-sep-2026,
        // viñetas de Wingdings/Symbol pegadas desde Word (Zona de Uso
        // Privado de Unicode) salían como "?" en el PDF.
        //
        // Editor de texto enriquecido (07-sep-2026, pedido del usuario): estos
        // 3 campos (incluye/no_incluye/vuelo_detalle) pasan de <textarea>
        // plano a RichTextEditor (Quill) en el frontend — ya no es
        // necesariamente texto con saltos de línea literales, puede ser
        // HTML (<p>/<ul><li>/<strong>). textoLibreParaPdf() cubre ambos
        // casos: si detecta HTML real lo renderiza tal cual (Quill ya trae
        // su propia estructura); si es texto plano (dato cargado ANTES de
        // este cambio) reconstruye el <ul><li> — sin esto, texto plano con
        // "\n" literales perdía sus viñetas al renderizarse crudo (bug real
        // encontrado generando el PDF de una cotización ya existente).
        $mayoristasIncluye = $mayoristas
            ->map(fn ($m) => TextoFormatoService::textoLibreParaPdf($m->incluye))
            ->filter(fn ($html) => $html !== '')
            ->values();
        $mayoristasNoIncluye = $mayoristas
            ->map(fn ($m) => TextoFormatoService::textoLibreParaPdf($m->no_incluye))
            ->filter(fn ($html) => $html !== '')
            ->values();
        $mayoristasVuelo = $mayoristas
            ->filter(fn ($m) => filled($m->vuelo_aerolinea))
            ->map(fn ($m) => [
                'aerolinea' => $m->vuelo_aerolinea,
                'detalle' => TextoFormatoService::textoLibreParaPdf($m->vuelo_detalle),
            ])
            ->values();
        $mayoristasOpcionales = $this->mayoristasOpcionalesPendientes($alternativa, $mayoristas);

        // Sesión 12f-3 — el PDF comercial deja de mostrar precio por ítem
        // (decisión del usuario, ver brief 12f3 §0.3): estos totales siguen
        // calculándose igual que antes, solo que ya no viaja un $items con
        // precio por fila a la vista — la tabla de "Precio" quedó reducida
        // al bloque de totales.
        $totalOriginal = $alternativa->items->sum(
            fn (AlternativaItem $item) => (float) $item->precio_venta_snapshot * (float) $item->cantidad
        );
        $total = (float) $alternativa->total;
        $descuentoMonto = round($totalOriginal - $total, 2);

        // Mejora del PDF de cotización (plan-mejora-pdf-cotizacion-cliente.md)
        // — marca por agencia, fotos de portada/galería/hoteles, cinta de
        // categoría. Todo con defaults de sistema si el tenant no configuró
        // nada (§6 del plan): ConfiguracionAgenciaPdf::actual() nunca
        // devuelve null.
        $configPdf = ConfiguracionAgenciaPdf::actual();
        $categoria = $this->resolverCategoriaAlternativa($alternativa);
        $colorCategoria = $this->colorPorCategoria($categoria, $configPdf);
        // Guardrail de diseño (18-sep-2026) — se quitó la "cinta de
        // categoría" (badge suelto) y se reusa colorCategoria como acento
        // en el cuadro de cada día del itinerario. Los colores reales
        // configurados pueden ser bien saturados (ej. rojo puro para
        // "nacional" en agencia-demo) — usarlos a pleno como FONDO de un
        // cuadro se ve como alerta, no como diseño. colorCategoriaTinte es
        // el mismo color mezclado ~92% con blanco, para el fondo del
        // cuadro; el color real sin mezclar se reserva para el borde/texto
        // (acento angosto), donde su intensidad no compite con el
        // contenido.
        $colorCategoriaTinte = $this->tintClaro($colorCategoria);
        $afiliaciones = $this->afiliacionesParaMostrar($configPdf);
        [$fotoPortadaPrincipal, $fotosPortadaSecundarias] = $this->fotosTourParaPdf($alternativa, $configPdf);
        $hotelesInfo = $this->hotelesInfoParaPdf($opcionesHoteles);

        // Hallazgo del usuario (06-sep-2026, comparando contra el mockup
        // aprobado): el header/footer custom (membrete real de la agencia)
        // se imprimía como contenido normal — subía/bajaba con el flujo de
        // la página en vez de quedar fijo arriba/abajo como una hoja
        // membretada real. Fix: position:fixed dentro del margen de @page
        // (misma técnica ya usada en reporte-operativo.blade.php,
        // ".marca-generacion") — el alto reservado se calcula acá porque
        // la imagen la sube la agencia con la proporción que quiera.
        $alturaHeaderMm = $this->alturaHeaderMm($configPdf, $afiliaciones);
        $alturaFooterMm = $this->alturaFooterMm($configPdf);

        // Pedido del usuario (06-sep-2026) — Poppins en vez de Arial. Se
        // registra ANTES de loadView(), no después: confirmado con el PDF
        // real (grep de "Poppins"/"Helvetica" dentro de los bytes del
        // archivo) que registrarlo después de loadView() no tenía efecto —
        // dompdf ya resuelve font-family contra las fuentes conocidas
        // durante el parseo del CSS (loadHtml(), disparado por loadView()),
        // no en el render() posterior. Hay que resolver una instancia
        // propia de PDF (no vía loadView() del facade, que crea la suya)
        // para poder tocar getDomPDF() antes de cargarle el HTML.
        $pdf = app('dompdf.wrapper');
        \App\Services\PdfFontService::registrarPoppins($pdf->getDomPDF());

        $pdf = $pdf->loadView('pdf.agencia-viajes.alternativa', [
            'alternativa' => $alternativa,
            'cotizacion' => $alternativa->cotizacion,
            'cliente' => $alternativa->cotizacion->cliente,
            'empresa' => $empresa,
            // resolveParaPdf(), no resolve() — DomPDF corre con
            // enable_remote=false, así que la URL de resolve() (pensada
            // para el navegador) nunca carga como imagen embebida acá
            // dentro. Mismo bug ya corregido en SaleController/NotaController/
            // PaymentReceiptController/CommercialQuoteController/
            // ReporteOperativoController — quedaba pendiente acá a propósito
            // (29-ago-2026).
            'logoUrl' => \App\Services\StorageUrl::resolveParaPdf($empresa?->logo_horizontal),
            'config' => $config,
            'configPdf' => $configPdf,
            'headerCustomUrl' => StorageUrl::resolveParaPdf($configPdf->imagen_header_custom),
            'footerCustomUrl' => StorageUrl::resolveParaPdf($configPdf->imagen_footer_custom),
            'alturaHeaderMm' => $alturaHeaderMm,
            'alturaFooterMm' => $alturaFooterMm,
            'afiliaciones' => $afiliaciones,
            'categoria' => $categoria,
            'colorCategoria' => $colorCategoria,
            'colorCategoriaTinte' => $colorCategoriaTinte,
            'fotoPortadaPrincipal' => $fotoPortadaPrincipal,
            'fotosPortadaSecundarias' => $fotosPortadaSecundarias,
            'hotelesInfo' => $hotelesInfo,
            'cuentasBancarias' => $cuentasBancarias,
            'pasajeros' => $pasajeros,
            'itinerario' => $itinerario,
            'incluyePorDestino' => $incluyePorDestino,
            'tourUnico' => $tourUnico,
            'opcionesHoteles' => $opcionesHoteles,
            'mayoristasIncluye' => $mayoristasIncluye,
            'mayoristasNoIncluye' => $mayoristasNoIncluye,
            'mayoristasVuelo' => $mayoristasVuelo,
            'mayoristasOpcionales' => $mayoristasOpcionales,
            'total' => $total,
            'totalOriginal' => $totalOriginal,
            'descuentoMonto' => $descuentoMonto,
            'hayDescuento' => $descuentoMonto > 0.01,
        ]);

        $nombreArchivo = 'Cotizacion-' . ($alternativa->cotizacion->codigo ?? $alternativa->cotizacion_id) . '-' . \Illuminate\Support\Str::slug($alternativa->nombre) . '.pdf';

        return $pdf->download($nombreArchivo);
    }

    // Si TODOS los ítems de la alternativa comparten el mismo tour_origen_id
    // (caso más común: una alternativa = un tour), devuelve ese
    // PaquetePlantilla para poder mostrar no_incluye/recomendaciones/
    // lugar_recojo/horarios. Si hay ítems de varios tours distintos, o
    // ítems sin tour_origen_id mezclados con ítems que sí lo tienen, NO hay
    // "el tour" de esta alternativa — devuelve null a propósito, ver
    // decisión de diseño #4 (no concatenar texto de varios tours).
    private function tourUnicoDeAlternativa(Alternativa $alternativa): ?\App\Models\AgenciaViajes\PaquetePlantilla
    {
        $tourIds = $alternativa->items->pluck('tour_origen_id')->unique();

        if ($tourIds->count() !== 1 || $tourIds->first() === null) {
            return null;
        }

        return \App\Models\AgenciaViajes\PaquetePlantilla::find($tourIds->first());
    }

    // Simulación Panamá (04-sep-2026) — OpcionMayorista distintas
    // referenciadas por los ítems de la alternativa (vía
    // AlternativaItem::opcionMayorista(), ya eager-cargada con
    // ->opcionales en pdf()). Los ítems de un grupo de hotel (Sesión M5)
    // solo se pueden agregar cuando la opción ya está 'elegida' (guard ya
    // existente en el frontend/backend) — todo lo que aparezca acá es por
    // definición la opción elegida, no hace falta filtrar por estado de
    // nuevo.
    private function mayoristasReferenciados(Alternativa $alternativa): \Illuminate\Support\Collection
    {
        return $alternativa->items
            ->map(fn (AlternativaItem $item) => $item->opcionMayorista)
            ->filter()
            ->unique('id')
            ->values();
    }

    // 07-sep-2026 — un opcional ya agregado de verdad al lienzo (sección
    // "Opcionales" del cotizador, ver AlternativaItemController::
    // crearItemMayorista()) deja de ser "algo que el cliente puede agregar
    // aparte": ya está cobrado, sumado al total de "Precio". Si siguiera
    // listado acá igual que los demás, el PDF le diría al cliente que
    // puede agregarlo por separado cuando en realidad ya lo está pagando
    // — confuso y potencialmente un doble mensaje sobre el mismo cargo.
    // Se excluye de esta lista informativa.
    private function mayoristasOpcionalesPendientes(Alternativa $alternativa, \Illuminate\Support\Collection $mayoristas): \Illuminate\Support\Collection
    {
        $opcionalesYaAgregados = $alternativa->items->pluck('opcion_mayorista_opcional_id')->filter()->unique();

        return $mayoristas->flatMap(fn ($m) => $m->opcionales)
            ->reject(fn ($opcional) => $opcionalesYaAgregados->contains($opcional->id))
            ->values();
    }

    // Sesión 12f-3 — agrupa los AlternativaItem de la alternativa por
    // destino real (alternativa_destino_id), tratando null como
    // perteneciente al PRIMER destino (mismo fallback que
    // itemsDelDestinoActivo en editar.vue, 12f-2, por los ítems legacy que
    // 12c dejó sin alternativa_destino_id resuelto). Compartido por
    // itinerarioAlternativa()/incluyePorDestino() para no duplicar el
    // criterio de fallback en dos lados. $alternativa->destinos siempre
    // trae al menos 1 fila (garantía de 12b/12c/12f-2, destroy() bloquea
    // borrar el último) — no hay caso real de array vacío acá.
    private function itemsPorDestino(Alternativa $alternativa): array
    {
        $destinos = $alternativa->destinos;

        if ($destinos->isEmpty()) {
            return [];
        }

        $primerDestinoId = $destinos->first()->id;

        return $destinos->map(fn (AlternativaDestino $destino) => [
            'destino' => $destino,
            'items' => $alternativa->items->filter(
                fn (AlternativaItem $item) => ($item->alternativa_destino_id ?? $primerDestinoId) === $destino->id
            )->values(),
        ])->values()->all();
    }

    // Itinerario narrativo — concatena el paqueteItinerario() de cada tour
    // DISTINTO presente en los ítems de CADA destino, en el orden en que
    // aparecen, con offset de día que se reinicia al entrar a un destino
    // nuevo (mismo criterio que dia_referencial en el cotizador desde
    // 12f-2). Generaliza ComboExplosionService::itinerarioDerivado()
    // (pensado para un combo específico) a "los tours que realmente
    // aparecen acá", porque una alternativa puede mezclar tours sin venir
    // de un combo formal. Devuelve un array de BLOQUES (uno por destino
    // con al menos un paso), no un array plano de pasos — 12f-3, antes era
    // plano y asumía un solo destino.
    private function itinerarioAlternativa(Alternativa $alternativa): array
    {
        $bloques = [];

        foreach ($this->itemsPorDestino($alternativa) as $grupo) {
            $destino = $grupo['destino'];
            $tourIdsLocal = $grupo['items']->pluck('tour_origen_id')->filter()->unique()->values();

            $pasos = [];
            $offsetDia = 0;

            foreach ($tourIdsLocal as $tourId) {
                $offsetDia = $this->agregarPasosDeTour(PaquetePlantilla::find($tourId), $pasos, $offsetDia);
            }

            // Tours incluidos de un paquete de mayorista (OpcionMayoristaTour,
            // 04-sep-2026) — misma fuente de itinerario que un tour_origen_id
            // de Local/Nacional, solo que llega vía la opción de mayorista.
            //
            // Guardrail (18-sep-2026) — ya no son SIEMPRE un PaquetePlantilla:
            // una fila ad-hoc (paquete_plantilla_id null, ver migración
            // add_adhoc_a_opcion_mayorista_tours) es logística pura de ESTE
            // itinerario (Arribo/Retorno/traslado), con su nombre/descripcion
            // propios acá mismo — nunca un producto del catálogo. Se procesa
            // en el MISMO orden ('orden', el "Día" que ve el vendedor) que
            // los reales, sin separarlos en 2 pasadas: el vendedor los
            // intercala (Día 1 Arribo ad-hoc, Día 2 Tour real, Día 3 Retorno
            // ad-hoc...), así que agruparlos por tipo rompería la secuencia.
            $mayoristasDelGrupo = $grupo['items']->map(fn (AlternativaItem $item) => $item->opcionMayorista)->filter()->unique('id');
            foreach ($mayoristasDelGrupo as $opcionMayorista) {
                foreach ($opcionMayorista->tours()->orderBy('orden')->get() as $tourVinculo) {
                    if ($tourVinculo->paquete_plantilla_id) {
                        $offsetDia = $this->agregarPasosDeTour(
                            PaquetePlantilla::find($tourVinculo->paquete_plantilla_id),
                            $pasos,
                            $offsetDia
                        );

                        continue;
                    }

                    $offsetDia++;
                    $pasos[] = [
                        'dia' => $offsetDia,
                        'hora' => null,
                        'descripcion' => TextoFormatoService::sanitizarHtmlParaPdf($tourVinculo->descripcion),
                        'tour_nombre' => $tourVinculo->nombre,
                        'tour_fotos' => [],
                        'atractivo_nombre' => null,
                    ];
                }
            }

            if (empty($pasos)) {
                continue;
            }

            $bloques[] = [
                'destino_id' => $destino->id,
                'destino_nombre' => $destino->destinoAtractivo?->nombre ?? $destino->destino_texto ?? 'Destino',
                'fecha_inicio' => $destino->fecha_inicio,
                'fecha_fin' => $destino->fecha_fin,
                'pasos' => $pasos,
            ];
        }

        return $bloques;
    }

    // Extraído (18-sep-2026) de itinerarioAlternativa() — arma los pasos de
    // itinerario de UN tour real de catálogo (Local/Nacional directo, o de
    // mayorista con paquete_plantilla_id), acumulando $pasos por referencia
    // y devolviendo el offsetDia actualizado (mismo criterio de siempre:
    // offsetDia += el mayor dia_relativo de ESTE tour, para que el próximo
    // tour siga la numeración de días donde este terminó).
    private function agregarPasosDeTour(?PaquetePlantilla $tour, array &$pasos, int $offsetDia): int
    {
        if (! $tour) {
            return $offsetDia;
        }

        // Hallazgo real (feedback del usuario sobre el PDF ya en
        // producción): 'destino_atractivo_id' es un campo ESTRUCTURADO por
        // paso ("Tio Yacu", "Baños Termales", "Orquideario"...),
        // independiente de la 'descripcion' en texto libre — confirmado
        // contra datos reales de agencia-demo, el nombre del atractivo
        // casi nunca se repite tal cual dentro de la prosa de
        // 'descripcion'. Sin el eager-load y sin pasarlo a la vista, el
        // PDF nunca lo mostraba pese a que el dato ya está cargado por el
        // vendedor al armar el tour en el catálogo.
        $pasosDelTour = $tour->paqueteItinerario()->with('destinoAtractivo')->orderBy('dia_relativo')->orderBy('orden')->get();
        $maxDiaDelTour = 0;

        // Simulación Panamá (04-sep-2026) — fotos del tour, mismo patrón
        // que 'tour_nombre': se repiten por paso (el blade solo las
        // imprime una vez, en el primer paso del día) para no duplicar la
        // lógica de "una vez por tour" en la vista.
        //
        // Hallazgo del usuario (06-sep-2026): estas fotos se pasaban como
        // el ORIGINAL sin recortar (solo resolveParaPdf(), sin
        // ImagenRecorteService) — dompdf no conocía el tamaño real hasta
        // decodificar la imagen, y con fotos de celular de
        // proporción/resolución arbitraria terminaba pisando el título del
        // día siguiente ("el título de los tours es montado por las
        // imágenes"). Recorte 4:3 fijo (mismo servicio que
        // portada/galería/hoteles) elimina la ambigüedad de tamaño — el
        // blade además fija width/height explícitos en el <img>, dompdf ya
        // no tiene que adivinar.
        //
        // Máximo 3 fotos (pedido del usuario, 07-sep-2026) — mismo
        // criterio que la tira de hoteles (fachada + 1-2 de habitación);
        // un tour con muchas fotos cargadas no debe generar un PDF de
        // tamaño impredecible.
        $fotosDelTour = $this->imagenRecorte->recortarVariasParaPdf(array_slice($tour->fotos ?? [], 0, 3));

        foreach ($pasosDelTour as $paso) {
            $pasos[] = [
                'dia' => $offsetDia + $paso->dia_relativo,
                'hora' => $paso->hora,
                'descripcion' => TextoFormatoService::sanitizarHtmlParaPdf($paso->descripcion),
                'tour_nombre' => $tour->nombre,
                'tour_fotos' => $fotosDelTour,
                'atractivo_nombre' => $paso->destinoAtractivo?->nombre,
            ];
            $maxDiaDelTour = max($maxDiaDelTour, $paso->dia_relativo);
        }

        return $offsetDia + $maxDiaDelTour;
    }

    // Sesión 12f-3 — "Incluye" (lista de nombres, sin precio) agrupada por
    // destino con el mismo criterio que itinerarioAlternativa(). Un bloque
    // por destino con al menos 1 ítem.
    //
    // Hallazgo real 01-sep-2026 (revisión posterior a 12f-3, contra datos
    // reales de agencia-demo): un combo multi-día genera un AlternativaItem
    // POR CADA TOUR del día (ej. "Transporte / Traslado Ida y Vuelta" en el
    // tour del día 1, otra vez en el del día 2, otra vez en el del día 3) —
    // nombre() ya deduplicaba el itinerario (agrupa por tour_origen_id
    // único), pero "Incluye" mapeaba 1:1 cada ítem, así que el mismo texto
    // salía repetido varias veces seguidas en el PDF. Se deduplica acá por
    // nombre — decisión confirmada con el usuario: una sola línea, sin
    // contador "(3x)" (el itinerario de arriba ya cuenta la historia
    // completa día por día; "Incluye" es un checklist, no un log).
    private function incluyePorDestino(Alternativa $alternativa): array
    {
        $bloques = [];

        foreach ($this->itemsPorDestino($alternativa) as $grupo) {
            // Sesión M5 — encontrado renderizando el PDF real contra
            // agencia-demo: un ítem con grupo_opcion_id (matriz de
            // hoteles) ya aparece completo en su propia sección
            // "Opciones de hoteles" (tabla hotel × tipo de habitación,
            // ver opcionesHoteles() más abajo) — listarlo TAMBIÉN acá,
            // una línea por cada combinación hotel/habitación del grupo
            // (5 líneas en la verificación real), duplicaba la misma
            // información dos veces en el mismo documento. Los ítems
            // SIN grupo (el 100% de "Incluye" antes de este plan) siguen
            // exactamente igual.
            $itemsSinGrupoDeHotel = $grupo['items']->whereNull('grupo_opcion_id');

            if ($itemsSinGrupoDeHotel->isEmpty()) {
                continue;
            }

            $destino = $grupo['destino'];

            $bloques[] = [
                'destino_id' => $destino->id,
                'destino_nombre' => $destino->destinoAtractivo?->nombre ?? $destino->destino_texto ?? 'Destino',
                'fecha_inicio' => $destino->fecha_inicio,
                'fecha_fin' => $destino->fecha_fin,
                'nombres' => $itemsSinGrupoDeHotel
                    ->map(fn (AlternativaItem $item) => ReservaController::resolverNombreItem($item, null, 'cliente'))
                    ->unique()
                    ->values(),
            ];
        }

        return $bloques;
    }

    // Guardrail (19-sep-2026, hallazgo del usuario) — antes se podía generar
    // el PDF que se manda al cliente con un grupo de opciones (matriz de
    // hoteles) todavía "sin resolver" (ninguna marcada como elegida).
    // AlternativaItem::calcularTotalEfectivo() ya arma el total en ese caso
    // con la opción MÁS BARATA del grupo, en silencio — el cliente veía un
    // total que en realidad ya asumía un hotel puntual, sin que la tabla de
    // "Opciones de hoteles" lo marcara como tal (ninguna fila queda
    // resaltada). Bloquear acá, antes de generar, en vez de solo avisar en
    // el PDF: es más seguro que confiar en que el vendedor se acuerde de
    // resolverlo cada vez, y evita mandarle al cliente un precio que
    // después hay que corregir si termina eligiendo la opción más cara.
    private function validarGruposResueltos(Alternativa $alternativa): void
    {
        $grupos = AlternativaItem::agruparPorGrupoOpcion($alternativa->items)['grupos'];

        $gruposSinResolver = $grupos->reject(
            fn (array $grupo) => $grupo['items']->contains('opcion_elegida', true)
        );

        if ($gruposSinResolver->isEmpty()) {
            return;
        }

        $nombresPorGrupo = $gruposSinResolver->map(
            fn (array $grupo) => $grupo['items']
                ->map(fn (AlternativaItem $item) => explode(' · ', ReservaController::resolverNombreItem($item, null, 'cliente'), 2)[0])
                ->unique()
                ->implode(' / ')
        );

        throw ValidationException::withMessages([
            'grupo_opcion' => 'No se puede generar el PDF: falta elegir una opción en '
                .($nombresPorGrupo->count() > 1 ? 'estos grupos de hotel' : 'este grupo de hotel')
                .' — '.$nombresPorGrupo->implode('; ').'. Marcá una opción como elegida antes de enviar la cotización al cliente.',
        ]);
    }

    private function opcionesHoteles(Alternativa $alternativa): array
    {
        $grupos = AlternativaItem::agruparPorGrupoOpcion($alternativa->items)['grupos'];

        return $grupos->map(function (array $grupo) {
            $filasPorHotel = [];
            $tiposPresentes = [];

            foreach ($grupo['items'] as $item) {
                $nombreCompleto = ReservaController::resolverNombreItem($item, null, 'cliente');
                $partes = explode(' · ', $nombreCompleto, 2);

                // Un grupo de opciones es exclusivo de Hotel (P2 del
                // diseño) — cualquier ítem que no resuelva al formato
                // "hotel · tipo_habitacion" es un dato inesperado, se
                // omite de la tabla en vez de romper el PDF con un
                // índice inexistente.
                if (count($partes) !== 2) {
                    continue;
                }

                [$hotel, $tipoHabitacion] = $partes;
                $tiposPresentes[$tipoHabitacion] = true;

                // Guardrail (18-sep-2026) — agrupar por NOMBRE (texto
                // resuelto) fusionaba silenciosamente 2 hoteles distintos
                // que coincidieran en el nombre tipeado (un hotel real del
                // catálogo y uno ad-hoc, o 2 proveedores distintos con el
                // mismo nombre comercial) en una sola fila, pisando sus
                // precios entre sí. La identidad real es proveedor_servicio_id
                // (mismo criterio ya usado en editar.vue::
                // grupoHotelAbiertoDiaActivo) para un hotel de catálogo, u
                // opcion_hotel_id para uno ad-hoc — nunca el texto.
                $idHotel = $item->proveedor_tarifa_id
                    ? 'proveedor:' . $item->proveedorTarifa->proveedor_servicio_id
                    : 'adhoc:' . $item->opcionHotelTarifa?->opcion_hotel_id;

                // Sesión de guardrails (17-sep-2026) — `cantidad` significa
                // cosas distintas según el origen: en mayorista ya es
                // "adultos" y precio_convertido YA es el total del paquete
                // por persona (multiplicar de nuevo lo rompería); en un
                // hotel de Local/Nacional (origen_tipo='proveedor', real o
                // ad-hoc) `cantidad` es noches — la tabla mostraba el precio
                // de UNA noche sin decirlo, y un cliente con una estadía de
                // varias noches podía leerlo como el total. total_convertido
                // ya resuelve la multiplicación con la misma regla que el
                // resto de la cotización (getTotalConvertidoAttribute()).
                $precio = $item->origen_tipo === AlternativaItem::ORIGEN_MAYORISTA
                    ? (float) $item->precio_convertido
                    : (float) $item->total_convertido;

                $filasPorHotel[$idHotel] ??= ['hotel' => $hotel, 'precios' => [], 'elegida' => false, 'tipo_elegido' => null, 'opcion_hotel_id' => $item->opcionHotelTarifa?->opcion_hotel_id];
                $filasPorHotel[$idHotel]['precios'][$tipoHabitacion] = $precio;
                if ($item->opcion_elegida) {
                    $filasPorHotel[$idHotel]['elegida'] = true;
                    // Hallazgo del usuario (19-sep-2026): un hotel puede tener
                    // varios tipos de habitación en la tabla (doble/matrimonial)
                    // pero la elección real es de UNO solo — resaltar la fila
                    // entera (como antes) marcaba ambos precios como "elegida"
                    // sin decir cuál de los dos arma el total. tipo_elegido
                    // guarda el tipo específico para resaltar solo esa celda.
                    $filasPorHotel[$idHotel]['tipo_elegido'] = $tipoHabitacion;
                }
            }

            // ?: en vez de comparar contra `false` explícito hubiera sido un
            // bug real acá: array_search() devuelve 0 (índice legítimo) para
            // 'simple', el primer elemento del catálogo — 0 es falsy en PHP,
            // así que ?: 99 lo hubiera tratado como "no encontrado" y
            // mandado 'simple' al final en vez de primero (encontrado por
            // el test de esta sesión antes de mergear).
            $tiposHabitacion = collect(array_keys($tiposPresentes))
                ->sortBy(function (string $t) {
                    $posicion = array_search($t, self::ORDEN_TIPO_HABITACION, true);

                    return $posicion !== false ? $posicion : 99;
                })
                ->values()
                ->all();

            return [
                'grupo_opcion_id' => $grupo['grupo_opcion_id'],
                'tipos_habitacion' => $tiposHabitacion,
                'filas' => array_values($filasPorHotel),
                'resuelto' => collect($filasPorHotel)->contains('elegida', true),
            ];
        })->filter(fn (array $g) => count($g['filas']) > 0)->values()->all();
    }

    // Mejora del PDF de cotización (plan-mejora-pdf-cotizacion-cliente.md
    // §7) — la cinta de categoría usa la "más alta" presente en la
    // alternativa: internacional > nacional > local. Un ítem de mayorista
    // (opcion_mayorista_id) es SIEMPRE internacional por definición del
    // negocio (OpcionHotel::class docblock: "opcion_mayorista exclusivo de
    // paquetes internacionales con fecha fija") — no tiene
    // paquetes_plantilla.categoria propia porque no nace de un tour del
    // catálogo. Un ítem con tour_origen_id hereda la categoría de ESE tour.
    // Cualquier otro origen (manual/guía/pasaje aéreo/hotel local ad-hoc)
    // no tiene una señal de categoría propia — cae a 'nacional' (el color
    // intermedio por defecto), simplificación deliberada: no hay hoy un
    // campo de categoría a nivel de esos orígenes, y bloquear la cinta
    // hasta que alguien lo agregue sería peor que un default razonable.
    private function resolverCategoriaAlternativa(Alternativa $alternativa): string
    {
        $tourIds = $alternativa->items->pluck('tour_origen_id')->filter()->unique();
        $categoriasDeTours = $tourIds->isEmpty()
            ? collect()
            : PaquetePlantilla::whereIn('id', $tourIds)->pluck('categoria');

        $categorias = $alternativa->items->map(
            fn (AlternativaItem $item) => $item->opcion_mayorista_id ? 'internacional' : null
        )->filter()->merge($categoriasDeTours);

        foreach (['internacional', 'nacional', 'local'] as $prioridad) {
            if ($categorias->contains($prioridad)) {
                return $prioridad;
            }
        }

        return 'nacional';
    }

    private function colorPorCategoria(string $categoria, ConfiguracionAgenciaPdf $configPdf): string
    {
        return match ($categoria) {
            'internacional' => $configPdf->color_categoria_internacional,
            'local' => $configPdf->color_categoria_local,
            default => $configPdf->color_categoria_nacional,
        };
    }

    // Mezcla un color hex con blanco — dompdf no soporta color-mix()/rgba()
    // de forma confiable en todas sus versiones, así que el tinte se
    // calcula acá y se manda ya resuelto a hex. $peso es cuánto del color
    // ORIGINAL queda (0.08 = 8% color + 92% blanco); default pensado para
    // un fondo de cuadro sutil, no para el acento de borde/texto (ese usa
    // el color sin mezclar).
    private function tintClaro(string $hex, float $peso = 0.08): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = implode('', array_map(fn ($c) => $c.$c, str_split($hex)));
        }
        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return '#f5f5f5';
        }

        [$r, $g, $b] = array_map(fn ($c) => hexdec($c), str_split($hex, 2));
        $mezclar = fn ($canal) => (int) round($canal * $peso + 255 * (1 - $peso));

        return sprintf('#%02x%02x%02x', $mezclar($r), $mezclar($g), $mezclar($b));
    }

    // plan §4.3 — solo arma la franja si mostrar_afiliaciones=true Y la
    // agencia marcó al menos una. AfiliacionTurismo vive en la base
    // central (CentralConnection) — un solo whereIn() para resolver todas
    // las marcadas, sin N+1.
    private function afiliacionesParaMostrar(ConfiguracionAgenciaPdf $configPdf): \Illuminate\Support\Collection
    {
        if (! $configPdf->mostrar_afiliaciones || ! $configPdf->exists) {
            return collect();
        }

        $marcadas = $configPdf->afiliaciones()->get();
        if ($marcadas->isEmpty()) {
            return collect();
        }

        $catalogo = AfiliacionTurismo::whereIn('id', $marcadas->pluck('afiliacion_id'))->get()->keyBy('id');

        return $marcadas->map(function ($m) use ($catalogo) {
            $afiliacion = $catalogo->get($m->afiliacion_id);

            return $afiliacion ? [
                'nombre' => $afiliacion->nombre,
                'logo' => StorageUrl::resolveParaPdf($afiliacion->logo_path),
            ] : null;
        })->filter()->values();
    }

    private function alturaHeaderMm(ConfiguracionAgenciaPdf $configPdf, \Illuminate\Support\Collection $afiliaciones): float
    {
        if ($configPdf->imagen_header_custom) {
            return $this->alturaBandaCompletaMm($configPdf->imagen_header_custom);
        }

        $altura = 28.0; // logo + nombre comercial + RUC/teléfono/email
        if (! empty($configPdf->eslogan)) {
            $altura += 4.0;
        }
        if ($afiliaciones->isNotEmpty()) {
            $altura += 10.0;
        }

        return $altura;
    }

    private function alturaFooterMm(ConfiguracionAgenciaPdf $configPdf): float
    {
        if ($configPdf->imagen_footer_custom) {
            // +14mm: el aviso de condiciones generales (footer-legal) va
            // SIEMPRE arriba del membrete custom, es contenido legal, no
            // branding — el override total del plan §4.2 solo reemplaza
            // eslogan/redes, no este aviso.
            return $this->alturaBandaCompletaMm($configPdf->imagen_footer_custom) + 14.0;
        }

        return empty($configPdf->redes_sociales) ? 16.0 : 22.0;
    }

    private function alturaBandaCompletaMm(string $path): float
    {
        if (! Storage::disk('public')->exists($path)) {
            return 20.0;
        }

        // getimagesize() alcanza acá — solo hace falta el ancho/alto real
        // del archivo, no manipular la imagen (eso ya lo hace
        // ImagenRecorteService para las fotos de tour/hotel).
        $medidas = @getimagesize(Storage::disk('public')->path($path));
        if (! $medidas || $medidas[0] <= 0) {
            return 20.0;
        }

        return round(self::ANCHO_PAGINA_A4_MM * ($medidas[1] / $medidas[0]), 1);
    }

    // plan §4.5 — portada (1 principal + hasta 2 secundarias) + galería de
    // itinerario (hasta 4), ambas desde el PRIMER tour CON FOTOS de la
    // alternativa (una alternativa multi-tour de todas formas necesita UN
    // solo set de fotos de portada, no uno por tour). Recorte 4:3 centrado
    // vía ImagenRecorteService, nunca el original (evita el estiramiento
    // que dompdf produciría con object-fit, que no soporta).
    //
    // Bug real (07-sep-2026, pedido del usuario "que aparezca en todo
    // caso"): esta función solo miraba alternativa_items.tour_origen_id
    // (Local/Nacional) — nunca revisaba los tours enganchados por
    // mayorista (Internacional, OpcionMayoristaTour), que es el 100% del
    // uso real de este tenant. Ahora arma la MISMA secuencia de tours que
    // ya usa itinerarioAlternativa() (tour_origen_id directo + tours de
    // mayorista por 'orden') y busca el PRIMERO que sí tenga foto_portada
    // marcada — no simplemente el primero de la lista: confirmado con
    // datos reales que "Día 1: Arribo a Cusco" no tenía portada marcada,
    // pero "Día 2: Tour Valle Sagrado..." sí — quedarse con el primero a
    // secas (como hacía antes) habría seguido sin mostrar nada.
    private function fotosTourParaPdf(Alternativa $alternativa, ConfiguracionAgenciaPdf $configPdf): array
    {
        if (! $configPdf->mostrar_fotos_tour) {
            return [null, []];
        }

        $tourIds = $alternativa->items->pluck('tour_origen_id')->filter()->values();

        foreach ($this->mayoristasReferenciados($alternativa) as $opcionMayorista) {
            $tourIds = $tourIds->concat(
                $opcionMayorista->tours()->orderBy('orden')->pluck('paquete_plantilla_id')
            );
        }

        $tour = $tourIds->unique()->values()
            ->map(fn ($tourId) => PaquetePlantilla::find($tourId))
            ->first(fn ($tour) => $tour && $tour->foto_portada);

        if (! $tour) {
            return [null, []];
        }

        // array_unique() (07-sep-2026, bug real encontrado con datos reales):
        // fotos_destacadas_pdf tenía la MISMA foto repetida dos veces (el
        // vendedor pudo haberla marcado "destacada" dos veces desde el
        // panel) — sin esto, las 2 secundarias de la portada podían
        // terminar mostrando la foto repetida en vez de dos fotos
        // distintas, según el orden del array.
        $destacadas = array_values(array_unique($tour->fotos_destacadas_pdf ?? []));
        $secundarias = array_values(array_diff($destacadas, [$tour->foto_portada]));

        // Pedido del usuario (07-sep-2026, con captura real: "veo más
        // imágenes duplicadas más abajo"): antes acá también se armaba una
        // sección "Galería de itinerario" con hasta 4 fotos destacadas,
        // aparte de la portada — pero esas MISMAS fotos ya se repetían en
        // las fotos por día del itinerario (fotosDelTour, agregado
        // 04-sep-2026, DESPUÉS de que se diseñara esta galería) y en la
        // propia portada. Con tours de pocas fotos (el caso real que
        // expuso el bug tenía solo 3 en total), la misma foto terminaba
        // saliendo 2-3 veces en el documento. Se quita la galería por
        // completo — portada + fotos por día ya cubren lo que la galería
        // pretendía mostrar, sin agregar nada nuevo.
        return [
            $this->imagenRecorte->recortar4x3ParaPdf($tour->foto_portada),
            $this->imagenRecorte->recortarVariasParaPdf(array_slice($secundarias, 0, 2)),
        ];
    }

    // plan §4.5 — sección "Fotos referenciales de los hoteles": por cada
    // hotel YA listado en la tabla de precios (opcionesHoteles(), que ahora
    // lleva 'opcion_hotel_id' por fila), su tira fachada+habitación(es) +
    // check-in/check-out si el hotel está ligado a un Proveedor real con
    // ProveedorAlojamientoDetalle cargado. Un hotel sin ninguna foto no
    // entra al mapa — el blade omite su bloque por completo (plan: "nunca
    // se deja un casillero en blanco").
    private function hotelesInfoParaPdf(array $opcionesHoteles): array
    {
        $idsHotel = collect($opcionesHoteles)
            ->flatMap(fn (array $grupo) => collect($grupo['filas'])->pluck('opcion_hotel_id'))
            ->filter()
            ->unique()
            ->values();

        if ($idsHotel->isEmpty()) {
            return [];
        }

        $hoteles = OpcionHotel::with('proveedor.alojamientoDetalle')->whereIn('id', $idsHotel)->get();

        $info = [];
        foreach ($hoteles as $hotel) {
            $fotos = collect($hotel->fotos ?? []);
            $fachada = $fotos->firstWhere('tipo_foto', 'fachada');
            $habitaciones = $fotos->where('tipo_foto', 'habitacion')->take(2);

            $tira = collect([$fachada])->filter()->concat($habitaciones)
                ->map(fn (array $f) => $this->imagenRecorte->recortar4x3ParaPdf($f['path']))
                ->filter()
                ->values();

            if ($tira->isEmpty()) {
                continue;
            }

            $detalle = $hotel->proveedor?->alojamientoDetalle;

            $info[$hotel->id] = [
                'fotos' => $tira->all(),
                'check_in' => $detalle?->hora_checkin,
                'check_out' => $detalle?->hora_checkout,
            ];
        }

        return $info;
    }
}
