<template>
    <div class="d-flex flex-wrap align-items-center gap-1 mb-2">
        <span v-for="chip in chipsFijos" :key="chip.proveedorTipoId ?? chip.tipo"
            class="badge rounded-pill px-3 py-2" style="cursor:pointer;font-weight:500;font-size:0.8rem;"
            :class="chipActivo(chip) ? 'bg-primary' : 'bg-light text-dark border'"
            @click="seleccionarChip(chip)">
            <i class="fas me-1" :class="chip.icono" style="font-size:1rem;"></i>{{ chip.nombre }}
        </span>
        <div class="position-relative">
            <span class="badge rounded-pill px-3 py-2" style="cursor:pointer;font-weight:500; font-size:0.8rem;"
                :class="chipMasActivo ? 'bg-primary' : 'bg-light text-dark border'"
                @click="mostrarMasChips = !mostrarMasChips">
                <i class="fas fa-ellipsis-h me-1" style="font-size:1rem;"></i>{{ chipMasActivo ? chipMasActivo.nombre : 'Más' }} <i class="fas fa-caret-down ms-1" ></i>
            </span>
            <div v-if="mostrarMasChips" class="border rounded shadow-sm bg-white p-1 position-absolute"
                style="z-index:1090;min-width:180px;top:100%;left:0;">
                <div v-for="chip in chipsProveedores" :key="chip.proveedorTipoId ?? chip.nombre"
                    class="small py-1 px-2 rounded" style="cursor:pointer"
                    :class="chipActivo(chip) ? 'bg-primary text-white' : ''"
                    @mousedown.prevent="seleccionarChip(chip); mostrarMasChips = false">
                    {{ chip.nombre }}
                </div>
            </div>
        </div>
    </div>
    <input type="text" class="form-control form-control-sm mb-2" placeholder="Buscar..."
        v-model="bibliotecaSearch" @input="onBibliotecaSearch">

    <!-- Comparación de varios hoteles Local/Nacional — el grupo es
         dato real (AlternativaItem.grupo_opcion_id), así que este
         aviso sigue apareciendo aunque se cierre el drawer y se
         vuelva más tarde. Clic en cualquier hotel de la grilla lo
         suma a esta misma comparación (ver grupoAbierto en
         matrizHotelActiva). -->
    <div v-if="grupoHotelAbiertoDiaActivo" class="alert alert-info py-2 px-3 small mb-2 d-flex justify-content-between align-items-center">
        <span><i class="fas fa-layer-group me-1"></i>Comparando {{ grupoHotelAbiertoDiaActivo.hoteles }} hotel(es) para este día — elige otro hotel de Alojamiento para sumarlo, o termina la comparación desde el lienzo.</span>
    </div>

    <!-- 27-ago-2026 — mismos filtros combinables que ya tenía
         la biblioteca de paquetes/detalle.vue. servicio/proveedor
         solo aplican a tarifas de proveedor (el backend excluye
         tours/paquetes del resultado si cualquiera está activo). -->
    <div class="row g-2 mb-2">
        <div class="col-12 col-md-4">
            <DestinoTreeSelect v-model="filtroDestinoId" nivel-max="lugar" placeholder="Zona o destino..." @update:label="filtroDestinoTexto = $event" />
        </div>
        <div class="col-6 col-md-4">
            <select class="form-select form-select-sm" v-model="filtroServicioId">
                <option :value="null">Cualquier servicio</option>
                <option v-for="s in serviciosFiltro" :key="s.id" :value="s.id">{{ s.nombre }}</option>
            </select>
        </div>
        <div class="col-6 col-md-4">
            <ProveedorBuscador v-model="filtroProveedorId" placeholder="Cualquier proveedor" />
        </div>
    </div>
    <!-- T-035 (R3): qué destino está filtrando la lista; la ✕ lo quita. -->
    <div v-if="filtroDestinoId" class="small mb-2" data-test="biblioteca-filtro-destino">
        <span class="badge bg-primary-subtle text-primary border d-inline-flex align-items-center gap-2 px-2 py-1">
            Mostrando: {{ filtroDestinoTexto || 'destino elegido' }} y sus lugares
            <button type="button" class="btn-close btn-close-sm" style="font-size:.6rem" aria-label="Quitar filtro de destino" @click="filtroDestinoId = null"></button>
        </span>
    </div>

    <div class="biblioteca-grid position-relative" :style="cargandoBiblioteca ? 'opacity:.45;pointer-events:none' : ''" :aria-busy="cargandoBiblioteca">
        <div v-if="cargandoBiblioteca" class="position-absolute top-0 start-50 translate-middle-x mt-2" style="z-index:2;grid-column:1 / -1"><span class="spinner-border spinner-border-sm text-primary"></span></div>
        <div v-for="fila in filasBibliotecaPagina" :key="fila.kind + '-' + fila.data.id"
            class="border rounded p-2 small lib-item"
            :style="cargandoFilaBibliotecaId === (fila.kind + '-' + fila.data.id) ? 'cursor:wait;opacity:.6' : 'cursor:pointer'"
            @click="onClicFilaBiblioteca(fila)">
            <div v-if="cargandoFilaBibliotecaId === (fila.kind + '-' + fila.data.id)" class="text-center py-2">
                <span class="spinner-border spinner-border-sm text-primary"></span>
            </div>
            <template v-else-if="fila.kind === 'proveedor'">
                <div class="d-flex justify-content-between">
                    <span>
                        <i class="fas me-1 text-primary" :class="fila.data.tipo_habitacion ? 'fa-bed' : 'fa-concierge-bell'"></i>
                        {{ fila.data.proveedor_servicio?.proveedor?.nombre_comercial ?? fila.data.proveedor_servicio?.proveedor?.razon_social }}
                        <span v-if="fila.data.proveedor_servicio?.proveedor?.es_referencial" class="badge bg-secondary-subtle text-secondary border ms-1" style="font-size:10px">Referencial</span>
                    </span>
                    <span class="text-muted">{{ fila.data._rangoHabitaciones ? 'desde ' : '' }}{{ fila.data.moneda }} {{ Number(fila.data.precio_venta_adulto).toFixed(0) }}</span>
                </div>
                <div class="text-muted" style="font-size:11px" v-if="fila.data.tipo_habitacion">
                    {{ fila.data.proveedor_servicio?.destino_servicio?.servicio?.nombre }}
                    · {{ fila.data._rangoHabitaciones ? 'varias habitaciones' : fila.data.tipo_habitacion }}
                </div>
                <div class="text-muted" style="font-size:11px" v-else>
                    {{ descripcionDestinoServicio(fila.data.proveedor_servicio?.destino_servicio) }}
                    <span class="badge bg-light text-dark border ms-1" style="font-size:10px">{{ fila.data.tipo_tarifa }} · {{ fila.data.modalidad }}</span>
                </div>
            </template>
            <template v-else>
                <div class="d-flex justify-content-between align-items-start">
                    <span>
                        <i class="fas me-1 text-primary" :class="fila.data.tipo_resultado === 'paquete' ? 'fa-layers' : 'fa-suitcase-rolling'"></i>
                        {{ fila.data.nombre }}
                    </span>
                    <span class="badge bg-info-subtle text-info border" style="font-size:10px;white-space:nowrap">
                        {{ fila.data.resumen_items.tours != null ? `${fila.data.resumen_items.tours} tours · ${fila.data.resumen_items.items} ítems` : `${fila.data.resumen_items.items} ítems` }}
                    </span>
                </div>
                <div class="text-muted" style="font-size:11px">
                    {{ etiquetaCategoriaPaquete(fila.data.categoria) }}<span v-if="fila.data.codigo"> · {{ fila.data.codigo }}</span>
                </div>
                <!-- Fila 13, fase 1b — precio por persona del tour y los que no
                     se pueden cargar como línea en el modo nuevo. -->
                <div v-if="fila.data.tipo_resultado === 'tour'" class="mt-1" style="font-size:11px">
                    <span v-if="fila.data.uso === 'solo_itinerario'" class="badge bg-secondary-subtle text-secondary">Solo itinerario (mayorista)</span>
                    <span v-else-if="fila.data.precio_venta_final != null" class="fw-semibold">{{ fila.data.moneda ?? 'PEN' }} {{ Number(fila.data.precio_venta_final).toFixed(2) }} <span class="text-muted fw-normal">por persona</span></span>
                    <span v-else-if="modoToursNuevo" class="badge bg-warning-subtle text-warning-emphasis"><i class="fas fa-triangle-exclamation me-1"></i>Sin precio: complétalo en el catálogo</span>
                </div>
            </template>
        </div>
        <div v-if="filasBibliotecaPagina.length === 0" class="text-muted small text-center py-3" style="grid-column: 1 / -1">Sin resultados.</div>
    </div>

    <nav v-if="bibliotecaTotalPaginas > 1" class="d-flex justify-content-between align-items-center mt-2">
        <button class="btn btn-sm btn-outline-secondary" :disabled="bibliotecaPaginaActual === 1" @click="bibliotecaPaginaActual--">« Anterior</button>
        <span class="small text-muted">Página {{ bibliotecaPaginaActual }} de {{ bibliotecaTotalPaginas }}</span>
        <button class="btn btn-sm btn-outline-secondary" :disabled="bibliotecaPaginaActual === bibliotecaTotalPaginas" @click="bibliotecaPaginaActual++">Siguiente »</button>
    </nav>

    <small class="text-muted d-block mt-2"><i class="fas fa-hand-pointer me-1"></i>Clic para agregar al día activo</small>
    <button class="btn btn-outline-secondary btn-sm w-100 mt-2" @click="mostrarFormManual = true"><i class="fas fa-plus me-1"></i>Servicio sin catálogo</button>

    <!-- Sesión M3/M4 — hotel ad-hoc LOCAL (sin proveedor registrado),
         espejo del "Agregar hotel a esta opción" que ya existía solo
         para el flujo mayorista/Internacional. -->
    <button class="btn btn-outline-secondary btn-sm w-100 mt-1" @click="mostrarFormHotelLocal = !mostrarFormHotelLocal">
        <i class="fas fa-bed me-1"></i>Hotel sin catálogo
    </button>
    <div v-if="mostrarFormHotelLocal" class="border rounded p-2 mt-2">
        <label class="form-label mb-1 small text-secondary">Nombre del hotel</label>
        <input type="text" class="form-control form-control-sm mb-1" placeholder="Nombre del hotel" v-model="formHotelLocal.nombre_hotel">
        <label class="form-label mb-1 small text-secondary">¿Es un proveedor ya registrado? <span class="text-muted fw-normal">(opcional)</span></label>
        <div class="position-relative mb-1">
            <ProveedorBuscador v-model="formHotelLocal.proveedor_id" :tipo-id="tipoHotelId" placeholder="Buscar hotel ya registrado…" />
        </div>
        <p v-if="formHotelLocal.proveedor_id" class="text-success small mb-2"><i class="fas fa-check-circle me-1"></i>Vinculado</p>
        <p class="text-muted fst-italic mb-2" style="font-size:11px">
            Vincularlo evita cargar el mismo hotel 2 veces con nombres distintos — si ya está en Proveedores, elígelo acá en vez de dejarlo suelto.
        </p>
        <label class="form-label mb-1 small text-secondary">Moneda</label>
        <select class="form-select form-select-sm mb-1" v-model="formHotelLocal.moneda">
            <option value="PEN">PEN</option>
            <option value="USD">USD</option>
        </select>
        <div class="row g-1 mb-1">
            <div class="col-3"><label class="form-label mb-0 small text-secondary">Habitación</label></div>
            <div class="col-2"><label class="form-label mb-0 small text-secondary">Costo</label></div>
            <div class="col-2"><label class="form-label mb-0 small text-secondary">Venta</label></div>
            <div class="col-3"><label class="form-label mb-0 small text-secondary">Tributario</label></div>
            <div class="col-2"><label class="form-label mb-0 small text-secondary">Destino</label></div>
        </div>
        <div v-for="(tf, idx) in formHotelLocal.tarifas" :key="idx" class="row g-1 mb-1">
            <div class="col-3">
                <select class="form-select form-select-sm" v-model="tf.tipo_habitacion">
                    <option value="simple">Simple</option>
                    <option value="matrimonial">Matrimonial</option>
                    <option value="doble">Doble</option>
                    <option value="triple">Triple</option>
                    <option value="familiar">Familiar</option>
                </select>
            </div>
            <div class="col-2">
                <input type="number" class="form-control form-control-sm" placeholder="Costo" v-model.number="tf.precio_costo">
            </div>
            <div class="col-2">
                <input type="number" class="form-control form-control-sm" placeholder="Venta" v-model.number="tf.precio_venta">
            </div>
            <div class="col-3">
                <select class="form-select form-select-sm" v-model="tf.tip_afe_igv"
                    @change="tf.destino_tributario = destinoParaIgvAgencia(tf.tip_afe_igv, tf.destino_tributario)">
                    <option value="10">Gravado</option>
                    <option value="20">Exonerado</option>
                    <option value="30">Inafecto</option>
                </select>
            </div>
            <div class="col-2">
                <select class="form-select form-select-sm" v-model="tf.destino_tributario" :disabled="tf.tip_afe_igv === IGV_EXONERADO">
                    <option value="amazonia">Amazonía</option>
                    <option value="nacional">Nacional</option>
                    <option value="extranjero">Extranjero</option>
                </select>
            </div>
        </div>
        <button class="btn btn-sm btn-outline-secondary mb-1" @click="formHotelLocal.tarifas.push(tarifaHotelLocalVacia())">+ tipo de habitación</button>
        <button class="btn btn-sm btn-primary w-100" @click="guardarHotelLocal" :disabled="guardandoHotelLocal">
            <span v-if="guardandoHotelLocal" class="spinner-border spinner-border-sm me-1"></span>Guardar hotel
        </button>
    </div>
</template>

<script setup lang="ts">
/*
 * Biblioteca local del drawer: chips de tipo, filtros, grid de servicios y paquetes, paginado y el
 * formulario de hotel no registrado. El v-if="modo === 'local'" quedó en el tag (cadena con
 * BibliotecaGuia y ComparadorMayoristas). Todo llega por inject desde cotizador/editar.vue (T-105).
 */
import DestinoTreeSelect from '@/components/AgenciaViajes/DestinoTreeSelect.vue';
import { IGV_EXONERADO, destinoParaIgvAgencia } from '@/helpers/tratamientoTributarioAgencia';
import ProveedorBuscador from '@/components/AgenciaViajes/ProveedorBuscador.vue';
import { descripcionDestinoServicio } from '@/helpers/agencia-viajes/cotizador/presentacionItem';
import {
    CLAVE_HOTELES_LOCALES, CLAVE_BIBLIOTECA, CLAVE_ITEMS_SUELTOS, CLAVE_PAGINA, inyectar,
} from '@/composables/agencia-viajes/cotizador/claves';

const {
    formHotelLocal, grupoHotelAbiertoDiaActivo, guardandoHotelLocal, guardarHotelLocal,
    mostrarFormHotelLocal, tarifaHotelLocalVacia,
} = inyectar(CLAVE_HOTELES_LOCALES);
const {
    bibliotecaPaginaActual, bibliotecaSearch, bibliotecaTotalPaginas, cargandoBiblioteca,
    cargandoFilaBibliotecaId, chipActivo, chipMasActivo, chipsFijos, chipsProveedores,
    etiquetaCategoriaPaquete, filasBibliotecaPagina, filtroDestinoId, filtroDestinoTexto, filtroProveedorId,
    filtroServicioId, modoToursNuevo, mostrarMasChips, onBibliotecaSearch, onClicFilaBiblioteca,
    seleccionarChip, serviciosFiltro,
} = inyectar(CLAVE_BIBLIOTECA);
const { mostrarFormManual } = inyectar(CLAVE_ITEMS_SUELTOS);
const { tipoHotelId } = inyectar(CLAVE_PAGINA);
</script>

<style scoped>
.lib-item:hover { background: #eef2ff; border-color: #6366f1 !important; }

.biblioteca-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.5rem;
}
@media (max-width: 767px) {
    .biblioteca-grid { grid-template-columns: 1fr; }
}
</style>
