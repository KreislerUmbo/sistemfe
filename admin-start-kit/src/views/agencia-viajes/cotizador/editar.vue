<template>
    <DefaultLayout>
        <div v-if="cargandoPagina" class="text-center py-5">
            <span class="spinner-border text-primary"></span>
        </div>

        <template v-else>
        <CabeceraCotizacion />

        <AvisosModificacion />

        <EdicionPasajeros />

        <EdicionCabecera />

        <PestanasAlternativas />

        <ChipsDestino />

        <div class="row g-3" v-if="alternativaActiva">
            <LienzoCotizador />

            <PanelPrecio />
        </div>

        <div v-else-if="cotizacion" class="text-center text-muted py-5">
            <!-- T-052: perdida, "+ Nueva" no se muestra (el backend responde 422). -->
            <template v-if="cotizacionPerdida">Esta cotización está cerrada como perdida y no tiene alternativas — reábrela para crear la primera.</template>
            <template v-else>Esta cotización todavía no tiene alternativas — creá la primera con el botón "+ Nueva" de arriba.</template>
        </div>

        <DrawerBiblioteca />

        <PromoverProveedorModal v-if="itemParaPromover" :item="itemParaPromover"
            @promovido="onProveedorPromovido" @close="itemParaPromover = null" />

        <!-- Teleport (a diferencia de PromoverProveedorModal de arriba): este
             modal se abre DESDE DENTRO del drawer de biblioteca (Teleport to
             body más arriba), que se queda abierto detrás — sin este Teleport
             quedaba por debajo del overlay del drawer en el DOM y sus clics
             se interceptaban (hallazgo real, reproducido en vivo 05-sep-2026). -->
        <Teleport to="body">
            <PromoverHotelProveedorModal v-if="hotelParaPromover" :hotel="hotelParaPromover"
                @promovido="onHotelPromovido" @close="hotelParaPromover = null" />
        </Teleport>
        </template>
    </DefaultLayout>
</template>

<script setup lang="ts">
import { destinoParaIgvAgencia, IGV_EXONERADO } from '@/helpers/tratamientoTributarioAgencia';
import CampoFecha from '@/components/CampoFecha.vue';
import { ref, onMounted, onUnmounted, watch, provide } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import { useLayoutStore } from '@/stores/layout';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import RichTextEditor from '@/components/RichTextEditor.vue';
import HabitacionMatrixPicker from '@/components/AgenciaViajes/HabitacionMatrixPicker.vue';
import ProveedorBuscador from '@/components/AgenciaViajes/ProveedorBuscador.vue';
import GuiaBuscador from '@/components/AgenciaViajes/GuiaBuscador.vue';
import OpcionMayoristaForm from '@/components/AgenciaViajes/OpcionMayoristaForm.vue';
import TourIncluidoForm from '@/components/AgenciaViajes/TourIncluidoForm.vue';
import PasajeAereoForm from '@/components/AgenciaViajes/PasajeAereoForm.vue';
import ItemManualForm from '@/components/AgenciaViajes/ItemManualForm.vue';
import PromoverProveedorModal from '@/components/AgenciaViajes/PromoverProveedorModal.vue';
import PromoverHotelProveedorModal from '@/components/AgenciaViajes/PromoverHotelProveedorModal.vue';
import DestinoTreeSelect from '@/components/AgenciaViajes/DestinoTreeSelect.vue';
import CalculadoraTipoCambioModal from '@/components/AgenciaViajes/CalculadoraTipoCambioModal.vue';
import EnviarAlternativa from '@/components/AgenciaViajes/EnviarAlternativa.vue';
import RevisionAlternativa from '@/components/AgenciaViajes/RevisionAlternativa.vue';
import HistorialActividadesCotizacion from '@/components/AgenciaViajes/HistorialActividadesCotizacion.vue';
import AccionesPendientes from '@/components/Clientes/AccionesPendientes.vue';
import PreferenciasViajeroResumen from '@/components/AgenciaViajes/PreferenciasViajeroResumen.vue';
import CerrarComoPerdidaModal from '@/components/AgenciaViajes/CerrarComoPerdidaModal.vue';
import ExtenderVigenciaModal from '@/components/AgenciaViajes/ExtenderVigenciaModal.vue';
import AceptarAlternativaModal from '@/components/AgenciaViajes/AceptarAlternativaModal.vue';
import ModificacionDiferenciaPanel from '@/components/AgenciaViajes/ModificacionDiferenciaPanel.vue';
import PenalidadPasajeroModal from '@/components/AgenciaViajes/PenalidadPasajeroModal.vue';
import ClientFormQuick from '@/components/Sales/ClientFormQuick.vue';
import { servicioService } from '@/services/admin/servicioService';
import { useAgenciaViajesCatalogosStore } from '@/stores/agenciaViajesCatalogos';
import { formatDiaCorto, formatFecha, formatFechaHora, hoyPeru } from '@/helpers/fecha';
import type { AlternativaItem } from '@/types/agencia-viajes';
import { filasDelBloque } from '@/helpers/agencia-viajes/cotizador/bloquesLienzo';
import {
    badgeTratamientoTributario, desglosePasajerosTexto as desglosePasajerosTextoDe, descripcionDestinoServicio, etiquetaItem,
    iconoItem, nombreMayoristaDeGrupo, tarifaAereaVencida, textoPlano, toursDeGrupo,
} from '@/helpers/agencia-viajes/cotizador/presentacionItem';
import { useCotizacionEditor } from '@/composables/agencia-viajes/cotizador/useCotizacionEditor';
import { useEdicionPrecios } from '@/composables/agencia-viajes/cotizador/useEdicionPrecios';
import { useDestinosAlternativa } from '@/composables/agencia-viajes/cotizador/useDestinosAlternativa';
import { useDiasLienzo } from '@/composables/agencia-viajes/cotizador/useDiasLienzo';
import { useLineaTour } from '@/composables/agencia-viajes/cotizador/useLineaTour';
import { useBloquesLienzo } from '@/composables/agencia-viajes/cotizador/useBloquesLienzo';
import { useDrawerBiblioteca, pasoDrawerDe } from '@/composables/agencia-viajes/cotizador/useDrawerBiblioteca';
import { useHotelesLocales } from '@/composables/agencia-viajes/cotizador/useHotelesLocales';
import { useBibliotecaCotizador } from '@/composables/agencia-viajes/cotizador/useBibliotecaCotizador';
import { useOpcionesMayorista } from '@/composables/agencia-viajes/cotizador/useOpcionesMayorista';
import { useHotelesMayorista } from '@/composables/agencia-viajes/cotizador/useHotelesMayorista';
import { useOpcionalesMayorista } from '@/composables/agencia-viajes/cotizador/useOpcionalesMayorista';
import { usePasajerosCotizacion } from '@/composables/agencia-viajes/cotizador/usePasajerosCotizacion';
import { useCabeceraCotizacion } from '@/composables/agencia-viajes/cotizador/useCabeceraCotizacion';
import { useResultadoCotizacion } from '@/composables/agencia-viajes/cotizador/useResultadoCotizacion';
import { useAlternativas } from '@/composables/agencia-viajes/cotizador/useAlternativas';
import { useModificacionEnCotizador } from '@/composables/agencia-viajes/cotizador/useModificacionEnCotizador';
import { useRevisionAlternativa } from '@/composables/agencia-viajes/cotizador/useRevisionAlternativa';
import { useGuiaCotizador } from '@/composables/agencia-viajes/cotizador/useGuiaCotizador';
import { useItemsSueltos } from '@/composables/agencia-viajes/cotizador/useItemsSueltos';
import {
    CLAVE_NUCLEO, CLAVE_PRECIOS, CLAVE_PASAJEROS, CLAVE_CABECERA, CLAVE_RESULTADO, CLAVE_ALTERNATIVAS,
    CLAVE_DESTINOS, CLAVE_DIAS, CLAVE_LINEA_TOUR, CLAVE_BLOQUES, CLAVE_DRAWER, CLAVE_HOTELES_LOCALES,
    CLAVE_BIBLIOTECA, CLAVE_OPCIONES_MAYORISTA, CLAVE_HOTELES_MAYORISTA, CLAVE_OPCIONALES_MAYORISTA,
    CLAVE_MODIFICACION, CLAVE_REVISION, CLAVE_GUIA, CLAVE_ITEMS_SUELTOS, CLAVE_PAGINA,
} from '@/composables/agencia-viajes/cotizador/claves';
import DrawerBiblioteca from '@/components/AgenciaViajes/cotizador/DrawerBiblioteca.vue';
import BibliotecaLocal from '@/components/AgenciaViajes/cotizador/BibliotecaLocal.vue';
import BibliotecaGuia from '@/components/AgenciaViajes/cotizador/BibliotecaGuia.vue';
import LienzoCotizador from '@/components/AgenciaViajes/cotizador/LienzoCotizador.vue';
import CargaTourLienzo from '@/components/AgenciaViajes/cotizador/CargaTourLienzo.vue';
import GrupoOpcionesLienzo from '@/components/AgenciaViajes/cotizador/GrupoOpcionesLienzo.vue';
import ItemLienzo from '@/components/AgenciaViajes/cotizador/ItemLienzo.vue';
import PanelPrecio from '@/components/AgenciaViajes/cotizador/PanelPrecio.vue';
import CabeceraCotizacion from '@/components/AgenciaViajes/cotizador/CabeceraCotizacion.vue';
import AvisosModificacion from '@/components/AgenciaViajes/cotizador/AvisosModificacion.vue';
import EdicionPasajeros from '@/components/AgenciaViajes/cotizador/EdicionPasajeros.vue';
import EdicionCabecera from '@/components/AgenciaViajes/cotizador/EdicionCabecera.vue';
import PestanasAlternativas from '@/components/AgenciaViajes/cotizador/PestanasAlternativas.vue';
import ChipsDestino from '@/components/AgenciaViajes/cotizador/ChipsDestino.vue';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const route = useRoute();
const router = useRouter();
const cotizacionId = Number(route.params.id);

const cargandoPagina = ref(true);

// Núcleo: cotización, alternativa activa y refrescos (el registro de alRefrescar está al final).
const nucleo = useCotizacionEditor(cotizacionId);
provide(CLAVE_NUCLEO, nucleo);
const {
    cotizacion, alternativaActivaId, alternativaActiva, itemsSinAjuste, itemsAjuste, configAgencia,
    modificacionInfo, alternativaSoloLectura, versionCotizacion, cargarCotizacion,
    aplicarItemActualizado, aplicarAlternativaActualizada, alRefrescar,
} = nucleo;
// Precios: edición por ítem (debounce, H13), descuento global, piso y totales de vista previa.
const precios = useEdicionPrecios({ alternativaActiva, itemsSinAjuste, cargarCotizacion, aplicarItemActualizado, aplicarAlternativaActualizada });
provide(CLAVE_PRECIOS, precios);
const {
    edicionItems, alertasPiso, guardandoEdicion, inicializarEdicionItems, totalConvertidoLocal,
    totalEfectivoLocal, subtotalGrupo, filasResumen, totalLocal, descuentoGlobalLocal,
    descuentoGlobalMontoLocal, lineasFueraDePiso, aplicandoDescuentoGlobal, onEditarDescuentoGlobal,
    onEditarDescuentoGlobalMonto, onEditarPrecio, onEditarDescuentoPct, onEditarMontoDescuentoItem,
} = precios;
const modo = ref<'local' | 'intl' | 'guia'>('local');

const pasajeros = usePasajerosCotizacion({ cotizacion, alternativaActiva, cargarCotizacion });
provide(CLAVE_PASAJEROS, pasajeros);
const {
    resumenPax, resumenPaxTotal, textoAdultosMayorista, notaMayoristaSinNinos,
    cantidadAdultosCotizacion, hayAlternativaAceptada, editandoPasajeros, formPasajeros, guardandoPasajeros,
    tipoPorEdadEdicion, abrirEdicionPasajeros, agregarPaxEdicion, guardarPasajeros,
} = pasajeros;
const cabecera = useCabeceraCotizacion({ cotizacion, cargarCotizacion });
provide(CLAVE_CABECERA, cabecera);
const {
    textoFechasViaje, editandoCabecera, formCabecera, clienteEditado, guardandoCabecera, clientSearchText,
    clientSuggestions, showClientSuggestions, showQuickClientModal, modoEdicionCliente, abrirEdicionCabecera,
    onClientSearchInput, seleccionarClienteEditado, limpiarClienteEditado, abrirNuevoCliente,
    abrirEdicionCliente, onClientSearchBlur, onClientCreated, guardarCabecera,
} = cabecera;
const resultado = useResultadoCotizacion({ cotizacion, alternativaActiva, alternativaActivaId, modificacionInfo, cargarCotizacion });
provide(CLAVE_RESULTADO, resultado);
const {
    historialRef, accionesRef, proximaAccion, cotizacionPerdida, puedeCambiarResultado, cerrandoComoPerdida,
    reabriendo, onResultadoCambiado, reabrirCotizacion, onAlternativaEnviada, vigenciaActiva,
    extendiendoVigencia, onVigenciaExtendida,
} = resultado;

const seleccionarAlternativa = (id: number) => {
    alternativaActivaId.value = id;
    if (modo.value === 'intl') cargarOpcionesMayorista();
};

const alternativas = useAlternativas({
    cotizacionId, cotizacion, alternativaActiva, alternativaActivaId, cargarCotizacion,
    navegar: (ruta) => { router.push(ruta); },
});
provide(CLAVE_ALTERNATIVAS, alternativas);
const {
    alcanzoLimiteAlternativas, reservaDeAlternativa, mostrarFormAlternativa, formAlternativa,
    mostrarCalculadoraTipoCambio, sugerenciaTipoCambioSunat, nombreSugeridoAlternativa, erroresAlternativa,
    creandoAlternativa, crearAlternativa, cambiandoMoneda, cambiarMonedaAlternativa, renombrarAlternativa,
    duplicando, duplicarAlternativa, descargandoPdf, descargandoCondiciones, descargarPdfAlternativa,
    descargarCondicionesGenerales, marcandoAceptada, aceptandoAlternativa, marcarAceptada, confirmarAceptada,
    eliminandoAlternativa, eliminarAlternativa,
} = alternativas;

// ── Lienzo: destinos, días, línea de tour y bloques ──────────────────
const destinosAlternativa = useDestinosAlternativa({ alternativaActiva, itemsSinAjuste, cargarCotizacion });
provide(CLAVE_DESTINOS, destinosAlternativa);
const {
    alternativaDestinos, destinoActivoId, mostrarChipsDestino, destinoAtractivoIdActivo, resolverDestinoActivo,
    itemsDelDestinoActivo, guardandoDestino, mostrarFormDestino, destinoEnEdicionId, nuevoDestinoAtractivoId,
    nuevoDestinoTexto, nuevoDestinoFechaInicio, nuevoDestinoFechaFin, abrirFormDestino, abrirFormEditarDestinoActivo,
    guardarNuevoDestino, eliminarDestinoActivo,
} = destinosAlternativa;
const dias = useDiasLienzo({ itemsDelDestinoActivo });
provide(CLAVE_DIAS, dias);
const {
    diaActivo, diasCreados, diasDesdeCombo, inicializarDias, diaSiguiente, diaActivoParaAgregar, agregarDia,
    itemsSinDia, mostrarTabsDia, itemsDelDiaActivo, itemsVisiblesLienzo,
} = dias;
const lineaTour = useLineaTour({ cotizacion, alternativaActiva, alternativaSoloLectura, cargarCotizacion, subtotalGrupo, diaActivo });
provide(CLAVE_LINEA_TOUR, lineaTour);
const {
    lineaTourBloqueada, temporadaDeCarga, desglosePrecioTour, incluyeDeCarga, incluyeAbierto, toggleIncluye,
    guardandoPrecioCarga, borradorPrecioCarga, onPrecioCarga, onMoverCarga, eliminandoCarga, eliminarCargaTour,
    temporadasDesactualizadas, cargarTemporadasTours, recalculandoTemporadas, recalcularTemporadas,
} = lineaTour;
const bloques = useBloquesLienzo({
    alternativaActiva, itemsSinAjuste, cargarCotizacion, aplicarAlternativaActualizada, totalEfectivoLocal,
    alternativaDestinos, itemsVisiblesLienzo, diaActivo,
});
provide(CLAVE_BLOQUES, bloques);
const {
    bloquesLienzo, gruposPrecioPanel, gruposPrecioPorDestino, eligiendoGrupoItemId, elegirGrupoDesdeLienzo,
    onReasignarDiaItem, onMoverBloque, eliminandoBloqueId, eliminarBloque, gruposExpandido, toggleGrupoExpandido,
    destinosExpandido, toggleDestinoExpandido,
} = bloques;

// ── Drawer de biblioteca, hoteles Local/Nacional y biblioteca ─────────
// El drawer va primero: los hoteles (y más abajo el ítem manual) registran en él su reinicio al
// volver al grid. Los hoteles van antes que la biblioteca porque ella les pasa el clic en una tarifa.
const drawer = useDrawerBiblioteca({ aplicarItemActualizado, cargarCotizacion });
provide(CLAVE_DRAWER, drawer);
const {
    drawerBibliotecaAbierto, abrirDrawerBiblioteca, cerrarDrawerBiblioteca, volverAGridDrawer, alVolverAGrid,
    onServicioSueltoAgregado,
} = drawer;
const hotelesLocales = useHotelesLocales({
    cotizacion, alternativaActiva, itemsSinAjuste, configAgencia, alternativaDestinos, destinoActivoId,
    diaActivoParaAgregar, alVolverAGrid, onServicioSueltoAgregado,
});
provide(CLAVE_HOTELES_LOCALES, hotelesLocales);
const {
    nochesHotelDefault, matrizHotelActiva, modoPrecioPendiente, grupoHotelAbiertoDiaActivo, clicBibliotecaItem,
    confirmandoModoPrecio, confirmarModoPrecio, agregarItemProveedorHotel, mostrarFormHotelLocal, tarifaHotelLocalVacia,
    formHotelLocal, guardandoHotelLocal, guardarHotelLocal, agregarItemHotelAdhocLocal, agregarGrupoHotelAdhocLocal,
    agregarGrupoProveedorHotel, subiendoFotoHotelAdhoc, onSubirFotoHotelAdhoc, onEliminarFotoHotelAdhoc,
} = hotelesLocales;
const biblioteca = useBibliotecaCotizador({
    alternativaActiva, itemsSinAjuste, configAgencia, cargarCotizacion, alternativaDestinos, destinoActivoId,
    diaActivoParaAgregar, diasCreados, diasDesdeCombo, drawerBibliotecaAbierto, cerrarDrawerBiblioteca, clicBibliotecaItem,
});
provide(CLAVE_BIBLIOTECA, biblioteca);
const {
    proveedorTipos, chipsFijos, chipsProveedores, mostrarMasChips, chipMasActivo, chipActivo, seleccionarChip,
    bibliotecaSearch, filtroDestinoId, filtroDestinoTexto, filtroServicioId, filtroProveedorId, serviciosFiltro,
    cargandoBiblioteca, cargarBiblioteca, onBibliotecaSearch, etiquetaCategoriaPaquete, bibliotecaPaginaActual,
    bibliotecaTotalPaginas, filasBibliotecaPagina, cargandoFilaBibliotecaId, onClicFilaBiblioteca, vistaPreviaCombo,
    modoToursNuevo,
} = biblioteca;

// ── Comparador de mayoristas ──────────────────────────────────────────
// T-039: los mayoristas y hoteles se buscan en el servidor (ProveedorBuscador con tipo-id); acá
// solo queda el id de cada tipo del catálogo real, que se llena en onMounted.
const tipoMayoristaId = ref<number | null>(null);
const tipoHotelId = ref<number | null>(null);

const opcionesDeMayorista = useOpcionesMayorista({
    alternativaActiva, cargarCotizacion, alternativaDestinos, destinoActivoId, mostrarChipsDestino,
    diaActivoParaAgregar, cantidadAdultosCotizacion, onServicioSueltoAgregado,
});
provide(CLAVE_OPCIONES_MAYORISTA, opcionesDeMayorista);
const {
    opcionesMayorista, opcionHotelesActivaId, mostrarFormMayorista, opcionMayoristaEnEdicionId,
    cargarOpcionesMayorista, verHoteles, eliminandoOpcionMayoristaId, eliminarOpcionMayorista,
    eligiendoOpcionId, elegirOpcion, descartandoOpcionId, descartarOpcion, reactivandoOpcionId, reactivarOpcion,
    abrirFormEditarOpcionMayorista, onOpcionMayoristaGuardada, agregarItemMayorista, agregarGrupoMayorista,
} = opcionesDeMayorista;

const hotelesMayorista = useHotelesMayorista({ cargarCotizacion, cargarOpcionesMayorista });
provide(CLAVE_HOTELES_MAYORISTA, hotelesMayorista);
const {
    mostrarFormHotel, formHotel, fotosHotelNuevo, onFotosHotelNuevoSeleccionadas, quitarFotoHotelNuevo,
    onCambiarProveedorHotel, tarifasHotelParaTipo, onElegirTarifaRegistrada, guardandoHotelMayorista, guardarHotel,
    onGuardarHotelMatrix, onEliminarHotelMatrix, onAgregarFotosHotelMatrix, onEliminarFotoHotelMatrix,
    hotelParaPromover, onPromoverHotelMatrix, onHotelPromovido, onGuardarTarifaMatrix, onEliminarTarifaMatrix,
    onAgregarTarifaMatrix, tarifasHotelPlanas,
} = hotelesMayorista;

const opcionalesMayorista = useOpcionalesMayorista({
    alternativaActiva, destinoActivoId, diaActivoParaAgregar, cantidadAdultosCotizacion,
    cargarOpcionesMayorista, onServicioSueltoAgregado,
});
provide(CLAVE_OPCIONALES_MAYORISTA, opcionalesMayorista);
const {
    mostrarOpcionalesId, mostrarFormOpcional, formOpcional, verOpcionales, guardandoOpcional, guardarOpcional,
    opcionalEnEdicionId, formEdicionOpcional, abrirEdicionOpcional, guardandoEdicionOpcional, guardarEdicionOpcional,
    eliminandoOpcionalId, eliminarOpcionalCargado, agregandoOpcionalAlLienzoId, agregarOpcionalAlLienzo,
    mostrarToursId, mostrarFormTourIncluido, tourEnEdicionId, verTours, onTourIncluidoAgregado, quitarTourIncluido,
    abrirEdicionTour, onTourIncluidoActualizado,
} = opcionalesMayorista;

// ── Modificación de reserva (T-007) y revisión antes de enviar (T-022) ─
const modificacionCotizador = useModificacionEnCotizador({
    modificacionInfo, itemsSinAjuste, cargarCotizacion, navegar: (ruta) => { router.push(ruta); },
    alternativaDestinos, destinoActivoId, mostrarChipsDestino, diaActivo, mostrarTabsDia,
});
provide(CLAVE_MODIFICACION, modificacionCotizador);
const {
    modificacion, avisoEdadEstimada, procesandoModificacion, descargandoPdfModificacion, idsParaRevisar,
    idsParaRevisarConfirmables, claseResaltado, irAItem, confirmarRevisados, abrirComprobanteBloqueo,
    penalidadPasajero, penalidadInicial, guardandoPenalidad, errorPenalidad, abrirPenalidad, guardarPenalidad,
    borrarPenalidad, descargarPdfModificacion, descartarModificacion, aplicarModificacion,
} = modificacionCotizador;
const revision = useRevisionAlternativa({
    versionCotizacion, alternativaActivaId, totalLocal, itemsSinAjuste, irAItem, extendiendoVigencia,
    drawerBibliotecaAbierto,
});
provide(CLAVE_REVISION, revision);
const { revisionActual, claveRevision, nombreItemRevision, irARevision } = revision;

// ── Guías ─────────────────────────────────────────────────────────────
const guia = useGuiaCotizador({ alternativaActiva, configAgencia, diaActivoParaAgregar, destinoActivoId, onServicioSueltoAgregado });
provide(CLAVE_GUIA, guia);
const {
    guiaSeleccionadaIdCotizador, tarifasGuiaCotizador, guiaTarifaSeleccionadaCotizador, cantidadDiasGuia,
    agregandoGuia, tipAfeIgvGuia, destinoTributarioGuia, cargarTarifasGuiaCotizador, agregarItemGuiaCotizador,
} = guia;

// ── Ítem manual / pasaje aéreo / eliminar ítems ───────────────────────
const itemsSueltos = useItemsSueltos({
    alternativaSoloLectura, cargarCotizacion, aplicarItemActualizado, aplicarAlternativaActualizada,
    drawerBibliotecaAbierto, alVolverAGrid,
});
provide(CLAVE_ITEMS_SUELTOS, itemsSueltos);
const {
    mostrarFormManual, mostrarFormPasajeAereo, itemPasajeAereoEnEdicion, itemManualEnEdicion,
    formManualSoloLectura, formPasajeSoloLectura, avisoItemReservado, abrirEdicionManual, itemParaPromover,
    abrirPromoverProveedor, onProveedorPromovido, onServicioSueltoActualizado, onPasajeAereoAgregado,
    abrirEdicionPasajeAereo, toggleFormPasajeAereo, onPasajeAereoActualizado, eliminandoItemId, eliminarItem,
    eliminandoGrupoId, eliminarGrupoLienzo,
} = itemsSueltos;
const { pasoDrawer, tituloDrawer } = pasoDrawerDe({ matrizHotelActiva, modoPrecioPendiente, mostrarFormManual });

const desglosePasajerosTexto = (item: AlternativaItem) => desglosePasajerosTextoDe(item, cotizacion.value?.pasajeros ?? []);
provide(CLAVE_PAGINA, {
    modo, seleccionarAlternativa, tipoMayoristaId, tipoHotelId, desglosePasajerosTexto, pasoDrawer, tituloDrawer,
});

// Panel de precio: cada destino arranca expandido (el vendedor colapsa los que no le interesan).
watch(alternativaDestinos, (destinos) => {
    for (const d of destinos) destinosExpandido.value.add(d.id);
}, { immediate: true });

watch(modo, (m) => { if (m === 'intl') cargarOpcionesMayorista(); });
// Cambiar de chip re-filtra el comparador sin pedirlo al backend: opcionesMayorista ya trae todas
// las opciones de la alternativa y el filtro es del lado del cliente (cargarOpcionesMayorista()).
watch(destinoActivoId, () => { if (modo.value === 'intl') cargarOpcionesMayorista(); });
watch(alternativaActivaId, () => {
    inicializarEdicionItems();
    resolverDestinoActivo();
    cargarTemporadasTours();
    inicializarDias();
    vistaPreviaCombo.value = null;
    cerrarDrawerBiblioteca();
    if (modo.value === 'intl') cargarOpcionesMayorista();
});

// Qué se reinicia tras recargar la cotización ('completo') o aplicar un ítem/alternativa
// devueltos por el backend ('parcial'), en este orden.
alRefrescar((tipo) => {
    inicializarEdicionItems();
    if (tipo === 'completo') {
        resolverDestinoActivo();
        cargarTemporadasTours();
    }
    inicializarDias();
    // T-007: la diferencia de una modificación depende de los ítems: se vuelve a pedir (sin frenar la pantalla).
    if (modificacionInfo.value?.estado === 'abierta') void modificacion.cargar();
});

const layoutStore = useLayoutStore();

onMounted(async () => {
    try {
        // Más espacio horizontal para el lienzo; onUnmounted lo devuelve a 'default'.
        layoutStore.setLeftSideBarSize('collapsed');

        // Servicios para los filtros de la biblioteca; en paralelo, no bloquean la carga.
        servicioService.listar({ per_page: 200 }).then((res) => { serviciosFiltro.value = res.servicios ?? []; });

        // proveedor_tipos es el catálogo real (editable desde el panel superadmin, no los 4 valores
        // del seeder): alimenta los chips de la biblioteca y la búsqueda de mayoristas. No depende de
        // configuracion-agencia: en paralelo. Vienen cacheados (TTL 60 s, ver agenciaViajesCatalogos.ts).
        const catalogos = useAgenciaViajesCatalogosStore();
        // configAgencia decide si "Descuento global" se lee como % o como monto fijo: una copia vieja
        // del caché haría que el vendedor tipee un monto que el sistema trate como porcentaje (o al
        // revés). Se invalida antes de pedirla para que esta pantalla siempre traiga la real.
        catalogos.invalidarConfigAgencia();
        const [tiposList, config] = await Promise.all([
            catalogos.obtenerProveedorTipos(),
            catalogos.obtenerConfigAgencia(),
        ]);
        proveedorTipos.value = tiposList;
        // inicializarEdicionItems() (vía alRefrescar, al cargar la cotización) necesita configAgencia
        // resuelto para derivar el monto de descuento por ítem: por eso cargarCotizacion() va después.
        configAgencia.value = config;

        // Los slugs reales de proveedor_tipos son 'agencia-mayorista' y 'alojamiento-hoteles' (los
        // hoteles sirven para "usar tarifa registrada" en una opción de mayorista); 'mayorista' y
        // 'hotel' no matchean nada (ver ProveedorController::tarifasHotel()).
        tipoMayoristaId.value = tiposList.find((t) => t.slug === 'agencia-mayorista')?.id ?? null;
        tipoHotelId.value = tiposList.find((t) => t.slug === 'alojamiento-hoteles')?.id ?? null;

        await Promise.all([cargarCotizacion(), cargarBiblioteca()]);
        // T-007: el aviso de edades estimadas llega en la respuesta de crear la modificación.
        try {
            const clave = `modificacion-edad-estimada-${cotizacionId}`;
            const guardado = sessionStorage.getItem(clave);
            if (guardado) { avisoEdadEstimada.value = JSON.parse(guardado); sessionStorage.removeItem(clave); }
        } catch { /* sin storage: sin aviso */ }
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo cargar la cotización.', 'error');
    } finally {
        cargandoPagina.value = false;
    }
});

onUnmounted(() => {
    layoutStore.setLeftSideBarSize('default');
});
</script>
