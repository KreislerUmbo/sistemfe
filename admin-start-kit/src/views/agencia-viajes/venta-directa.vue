<template>
    <DefaultLayout>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-bolt me-2 text-primary"></i>
                    Venta directa
                </h5>
                <small class="text-muted">Arma la reserva en un solo paso, con uno o varios servicios, sin pasar por el cotizador completo</small>
            </div>
            <router-link to="/agencia-viajes/reservas" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Volver
            </router-link>
        </div>

        <div class="row g-3 align-items-start">
            <!-- ── Columna principal ─────────────────────────────────── -->
            <div class="col-12 col-lg-8">
                <!-- 1 · Cliente y viaje -->
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white border-bottom py-2">
                        <span class="fw-semibold text-dark"><span class="badge bg-primary me-2">1</span>Cliente y viaje</span>
                    </div>
                    <div class="card-body py-3">
                        <div class="row g-3">
                            <div class="col-12 col-md-6 position-relative">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Cliente</label>
                                <div class="input-group input-group-sm">
                                    <input type="text" class="form-control" v-model="clientSearchText"
                                        placeholder="Buscar por DNI, RUC o nombre..." @input="onClientSearchInput"
                                        @focus="showClientSuggestions = true" @blur="onClientSearchBlur" autocomplete="off">
                                    <button v-if="clienteSeleccionado" class="btn btn-outline-danger" type="button" @click="limpiarCliente">
                                        <i class="fas fa-times"></i>
                                    </button>
                                    <button v-else class="btn btn-success" type="button" @click="showQuickClientModal = true" title="Registrar cliente nuevo">
                                        <i class="fas fa-user-plus"></i>
                                    </button>
                                </div>
                                <div v-if="showClientSuggestions && clientSuggestions.length > 0 && !clienteSeleccionado"
                                    class="list-group mt-1 position-absolute"
                                    style="max-height:220px;overflow-y:auto;z-index:1050;width:calc(100% - 2px);box-shadow:0 4px 8px rgba(0,0,0,.1)">
                                    <button type="button" class="list-group-item list-group-item-action" v-for="c in clientSuggestions" :key="c.id"
                                        @mousedown.prevent="seleccionarCliente(c)">
                                        <div class="d-flex justify-content-between">
                                            <span>{{ c.full_name }}</span>
                                            <small class="text-muted">{{ c.n_document }}</small>
                                        </div>
                                    </button>
                                </div>
                                <div v-if="clienteSeleccionado" class="small text-success mt-1">
                                    <i class="fas fa-circle-check me-1"></i>{{ clienteSeleccionado.full_name }} ({{ clienteSeleccionado.n_document }})
                                </div>
                                <!-- T-046 (maqueta): el cliente persona natural viaja de primer pasajero. -->
                                <div v-if="datosClientePasajero" class="form-text">
                                    <template v-if="clienteViaja">
                                        Persona natural: viaja como primer pasajero.
                                        <button type="button" class="btn btn-link btn-sm p-0 align-baseline" @click="clienteViaja = false">No viaja</button>
                                    </template>
                                    <template v-else>
                                        El cliente no viaja.
                                        <button type="button" class="btn btn-link btn-sm p-0 align-baseline" @click="clienteViaja = true">Agregarlo como pasajero</button>
                                    </template>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Destino</label>
                                <DestinoTreeSelect v-model="destinoId" @update:label="destinoTexto = $event" />
                            </div>
                            <div class="col-12 col-md-7">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Fecha de inicio</label>
                                <div class="d-flex gap-2">
                                    <CampoFecha v-model="fechaInicio" />
                                    <button type="button" class="btn btn-sm btn-outline-secondary text-nowrap" @click="fechaInicio = hoyPeru()">Hoy</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary text-nowrap" @click="fechaInicio = sumarDiasISO(hoyPeru(), 1)">Mañana</button>
                                </div>
                            </div>
                            <div class="col-12 col-md-5">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Moneda de la venta</label>
                                <select class="form-select form-select-sm" v-model="moneda">
                                    <option value="PEN">Soles</option>
                                    <option value="USD">Dólares</option>
                                </select>
                                <div class="form-text">Todos los servicios tienen que estar en esta moneda.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 2 · Pasajeros (T-046): por cantidad, con residencia; nombres opcionales -->
                <div class="card border-0 shadow-sm mb-3">
                    <!-- Maqueta aprobada «Venta directa — rediseño B» (05-nov): resumen en la cabecera y
                         «Agregar nombres» a la derecha; tres tarjetas con sus contadores. -->
                    <div class="card-header bg-white border-bottom py-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-2">
                            <span class="fw-semibold text-dark"><span class="badge bg-primary me-2">2</span>Pasajeros</span>
                            <span class="badge rounded-pill bg-light text-secondary border fw-normal">{{ resumenPax || 'Sin pasajeros' }}</span>
                        </div>
                        <button type="button" class="btn btn-sm btn-link p-0 fw-semibold text-decoration-none" @click="mostrarNombres = !mostrarNombres">
                            {{ mostrarNombres ? 'Ocultar nombres' : 'Agregar nombres (opcional)' }}
                        </button>
                    </div>
                    <div class="card-body py-3">
                        <div class="row g-2">
                            <div v-for="t in TARJETAS" :key="t.tipo" class="col-12 col-md-4">
                                <div class="border rounded p-2 h-100 d-flex flex-column gap-2">
                                    <div class="d-flex align-items-baseline justify-content-between gap-2">
                                        <span class="fw-semibold small">{{ t.etiqueta }}</span>
                                        <span class="text-muted" style="font-size:.75rem">{{ rangoGrupo(t.tipo) }}</span>
                                    </div>
                                    <div v-for="g in t.grupos" :key="g.residencia" class="d-flex align-items-center justify-content-between gap-2">
                                        <span class="small text-secondary">{{ g.etiqueta }}</span>
                                        <div class="d-flex align-items-center gap-1">
                                            <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="cantidadGrupo(g) === 0" :aria-label="`${t.etiqueta} ${g.etiqueta}: uno menos`" @click="restarPax(g)"><i class="fas fa-minus"></i></button>
                                            <span class="fw-semibold text-center" style="min-width:1.75rem">{{ cantidadGrupo(g) }}</span>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" :aria-label="`${t.etiqueta} ${g.etiqueta}: uno más`" @click="sumarPax(g)"><i class="fas fa-plus"></i></button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div v-if="pax.length === 0" class="text-muted small fst-italic mt-2">Agrega al menos un pasajero.</div>

                        <div v-if="mostrarNombres && pax.length > 0" class="mt-3 d-flex flex-column gap-2">
                            <div class="small text-muted">Opcional. Lo que no llenes se completa después en la reserva.</div>
                            <div v-for="f in paxOrdenados" :key="f.uid" class="row g-2 align-items-center">
                                <div class="col-12 col-md-3 small text-secondary">{{ etiquetaFila(f) }}</div>
                                <div class="col">
                                    <input type="text" class="form-control form-control-sm" maxlength="250" placeholder="Nombre (opcional)"
                                        :aria-label="`Nombre de ${etiquetaFila(f)}`"
                                        :value="valorNombre(f)" @input="f.nombre = ($event.target as HTMLInputElement).value">
                                </div>
                                <div class="col-5 col-md-3">
                                    <input type="text" class="form-control form-control-sm" maxlength="50" placeholder="Documento"
                                        :aria-label="`Documento de ${etiquetaFila(f)}`"
                                        :value="valorDocumento(f)" @input="f.documento = ($event.target as HTMLInputElement).value">
                                </div>
                                <!-- Edad exacta de un niño (la tarea lo permite); el resto usa la edad de su tipo. -->
                                <div v-if="f.tipo === 'nino'" class="col-auto">
                                    <input type="number" class="form-control form-control-sm" style="width:70px"
                                        v-model.number="f.edad" :min="umbrales ? umbrales.infante_max + 1 : 0" :max="umbrales?.nino_max ?? 17"
                                        title="Edad" aria-label="Edad del niño" @change="ajustarEdad(f)">
                                </div>
                                <div v-if="esFilaCliente(f)" class="col-auto">
                                    <button type="button" class="btn btn-sm btn-outline-danger" title="El cliente no viaja" aria-label="Quitar al cliente de los pasajeros" @click="clienteViaja = false">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 3 · Servicios -->
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white border-bottom py-2">
                        <span class="fw-semibold text-dark"><span class="badge bg-primary me-2">3</span>Servicios</span>
                    </div>
                    <div class="card-body py-3">
                        <div v-if="lineas.length === 0 && !catalogoAbierto" class="text-muted small fst-italic mb-2">Agrega al menos un servicio.</div>

                        <div class="d-flex flex-column gap-3 mb-3">
                            <div v-for="(l, i) in lineas" :key="l.uid" :ref="(el) => guardarRefLinea(l.uid, el)"
                                class="border rounded p-3 linea-servicio" :class="{ 'linea-resaltada': resaltadoUid === l.uid }">
                                <div class="d-flex align-items-start gap-2 flex-wrap">
                                    <div class="flex-grow-1">
                                        <span class="badge me-2" :class="etiquetaTipo(l).clase">{{ etiquetaTipo(l).texto }}</span>
                                        <span class="fw-semibold">{{ nombreLinea(l) }}</span>
                                        <div v-if="l.origen === 'proveedor'" class="small text-muted">
                                            <template v-if="l.tarifa!.tipo_habitacion">Habitación {{ l.tarifa!.tipo_habitacion }}</template>
                                            <template v-else>{{ proveedorDe(l) }}</template>
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <div class="fw-semibold">{{ textoTotalLinea(l, i) }}</div>
                                        <span v-if="afectacionDe(i)" class="badge" :class="afectacionDe(i)!.clase">{{ afectacionDe(i)!.texto }}</span>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-danger" @click="quitarLinea(i)" title="Quitar este servicio">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>

                                <div class="row g-2 mt-1">
                                    <div class="col-12 col-md-5">
                                        <label class="form-label small text-secondary mb-1">Fecha del servicio</label>
                                        <select class="form-select form-select-sm" v-model.number="l.dia">
                                            <option v-for="d in DIAS" :key="d" :value="d">{{ etiquetaDia(d) }}</option>
                                        </select>
                                    </div>

                                    <!-- Hotel: noches -->
                                    <template v-if="esHotel(l)">
                                        <div class="col-6 col-md-3">
                                            <label class="form-label small text-secondary mb-1">Noches</label>
                                            <input type="number" min="1" class="form-control form-control-sm" v-model.number="l.cantidad">
                                        </div>
                                        <div class="col-6 col-md-4 d-flex align-items-end">
                                            <span class="small text-muted pb-1">
                                                <template v-if="Number.isInteger(l.cantidad) && l.cantidad >= 1">Sale el {{ formatDiaCorto(sumarDiasISO(fechaInicio, l.dia - 1 + l.cantidad)) }}</template>
                                            </span>
                                        </div>
                                    </template>

                                    <!-- Tarifa que no es hotel: por persona / por unidad -->
                                    <div v-else-if="l.origen === 'proveedor'" class="col-12">
                                        <label class="form-label small text-secondary mb-1">¿Cómo se cobra? <span class="text-danger">*</span></label>
                                        <div v-if="!lineaPrevia(i)" class="small text-muted">
                                            <span v-if="motivoSinCalculo" class="text-warning-emphasis"><i class="fas fa-circle-info me-1"></i>{{ motivoSinCalculo }}</span>
                                            <span v-else-if="!vistaPreviaError"><span class="spinner-border spinner-border-sm me-1"></span>Calculando…</span>
                                        </div>
                                        <div v-else class="row g-2">
                                            <div class="col-12 col-md-6">
                                                <label class="border rounded p-2 d-block h-100" :class="{ 'border-primary bg-primary-subtle': l.modoPrecio === 'por_persona' }" style="cursor:pointer">
                                                    <input type="radio" class="form-check-input me-2" value="por_persona" v-model="l.modoPrecio">
                                                    <span class="fw-semibold">Por persona ({{ pax.length }} pasajero{{ pax.length === 1 ? '' : 's' }}): {{ totalesAlDia ? dinero(lineaPrevia(i)!.por_persona!.total) : '…' }}</span>
                                                    <div class="small text-muted mt-1">{{ totalesAlDia ? textoDesglose(lineaPrevia(i)!) : 'Calculando…' }}</div>
                                                </label>
                                            </div>
                                            <div class="col-12 col-md-6">
                                                <label class="border rounded p-2 d-block h-100" :class="{ 'border-primary bg-primary-subtle': l.modoPrecio === 'tarifa_fija' }" style="cursor:pointer">
                                                    <input type="radio" class="form-check-input me-2" value="tarifa_fija" v-model="l.modoPrecio">
                                                    <span class="fw-semibold">Por unidad (cantidad {{ l.cantidad }}): {{ totalesAlDia ? dinero(lineaPrevia(i)!.tarifa_fija!.total) : '…' }}</span>
                                                    <div class="d-flex align-items-center gap-2 small text-muted mt-1">
                                                        {{ dinero(lineaPrevia(i)!.tarifa_fija!.precio_unitario) }} ×
                                                        <input type="number" min="1" class="form-control form-control-sm" style="max-width:80px" v-model.number="l.cantidad"
                                                            @focus="l.modoPrecio = 'tarifa_fija'" aria-label="Cantidad">
                                                    </div>
                                                </label>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Concepto libre -->
                                    <template v-else>
                                        <div class="col-12">
                                            <label class="form-label small text-secondary mb-1">Descripción</label>
                                            <input type="text" class="form-control form-control-sm" v-model="l.descripcion" placeholder="Ej. Cena show">
                                        </div>
                                        <div class="col-6 col-md-4">
                                            <label class="form-label small text-secondary mb-1">Precio unitario ({{ simbolo }})</label>
                                            <input type="number" min="0" step="0.01" class="form-control form-control-sm" v-model.number="l.precio">
                                        </div>
                                        <div class="col-6 col-md-4">
                                            <label class="form-label small text-secondary mb-1">Cantidad</label>
                                            <input type="number" min="1" class="form-control form-control-sm" v-model.number="l.cantidad">
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <label class="form-label small text-secondary mb-1">Costo unitario ({{ simbolo }})</label>
                                            <input type="number" min="0" step="0.01" class="form-control form-control-sm" v-model.number="l.costo">
                                            <div class="form-text">0 si no tiene.</div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>

                        <!-- Catálogo, en el mismo lugar -->
                        <div v-if="catalogoAbierto" class="border rounded p-3 bg-light">
                            <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn" :class="pestana === 'proveedor' ? 'btn-primary' : 'btn-outline-primary'" @click="pestana = 'proveedor'">Tarifas de proveedor</button>
                                    <button type="button" class="btn" :class="pestana === 'libre' ? 'btn-primary' : 'btn-outline-primary'" @click="pestana = 'libre'">Concepto libre</button>
                                </div>
                                <button v-if="lineas.length > 0" type="button" class="btn btn-sm btn-outline-secondary ms-auto" @click="catalogoAbierto = false">Cancelar</button>
                            </div>

                            <div v-if="pestana === 'proveedor'">
                                <div class="row g-2 mb-2">
                                    <div class="col-12 col-md-6">
                                        <!-- Filtro propio del catálogo: cambiarlo no toca el destino de la reserva. -->
                                        <DestinoTreeSelect v-model="filtroDestinoId" @update:label="filtroDestinoTexto = $event"
                                            placeholder="Todos los destinos" />
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <input type="text" class="form-control form-control-sm" v-model="busqueda" @input="onBusqueda"
                                            placeholder="Buscar por proveedor o servicio (opcional)">
                                    </div>
                                </div>
                                <div class="small text-muted mb-2">
                                    <template v-if="filtroDestinoId">Tarifas de «{{ filtroDestinoTexto }}» y sus lugares.</template>
                                    <template v-else>Tarifas de todos los destinos.</template>
                                </div>
                                <div v-if="cargandoCatalogo" class="small text-muted"><span class="spinner-border spinner-border-sm me-1"></span>Cargando…</div>
                                <div v-else-if="errorCatalogo" class="alert alert-warning py-2 px-3 small mb-0">{{ errorCatalogo }}</div>
                                <div v-else-if="catalogo.length === 0" class="small text-muted fst-italic">No hay tarifas para este filtro.</div>
                                <div v-else class="list-group" style="max-height:320px;overflow-y:auto">
                                    <div v-for="t in catalogo" :key="t.id" class="list-group-item d-flex align-items-center gap-2">
                                        <div class="flex-grow-1 small">
                                            <div class="fw-semibold">
                                                {{ nombreTarifa(t) }}
                                                <span v-if="t.tipo_habitacion" class="badge bg-info-subtle text-info-emphasis ms-1">Hotel · {{ t.tipo_habitacion }}</span>
                                            </div>
                                            <div v-if="!t.tipo_habitacion" class="text-muted">
                                                {{ t.proveedor_servicio?.proveedor?.razon_social }}<span v-if="t.proveedor_servicio?.proveedor?.es_referencial"> (Referencial)</span>
                                            </div>
                                        </div>
                                        <div class="text-end small fw-semibold text-nowrap">{{ precioCatalogo(t) }}</div>
                                        <button type="button" class="btn btn-sm text-nowrap"
                                            :class="t.moneda === moneda ? 'btn-outline-primary' : 'btn-outline-secondary'"
                                            :disabled="t.moneda !== moneda" @click="agregarTarifa(t)">
                                            <template v-if="t.moneda === moneda">Agregar</template>
                                            <template v-else>Está en {{ NOMBRE_MONEDA[t.moneda] }}</template>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div v-else>
                                <p class="small text-muted mb-2">Un servicio que no está en el catálogo: pones la descripción, el precio y el costo. Se cobra en {{ NOMBRE_MONEDA[moneda] }}.</p>
                                <button type="button" class="btn btn-sm btn-primary" @click="agregarLibre"><i class="fas fa-plus me-1"></i>Agregar concepto libre</button>
                            </div>
                        </div>

                        <button v-else type="button" class="btn btn-sm btn-outline-primary" @click="abrirCatalogo">
                            <i class="fas fa-plus me-1"></i>Agregar servicio
                        </button>
                    </div>
                </div>
            </div>

            <!-- ── Resumen ───────────────────────────────────────────── -->
            <div class="col-12 col-lg-4">
                <div class="card border-0 shadow-sm resumen-fijo">
                    <div class="card-header bg-white border-bottom py-2">
                        <span class="fw-semibold text-dark">Resumen</span>
                    </div>
                    <div class="card-body py-3 small">
                        <div class="mb-2"><span class="text-muted">Cliente:</span> {{ clienteSeleccionado?.full_name ?? '—' }}</div>
                        <div class="mb-2">
                            <span class="text-muted">Destino:</span> {{ destinoTexto || '—' }}
                            <div v-if="rangoFechas" class="text-muted">{{ rangoFechas }}</div>
                        </div>
                        <div class="mb-3"><span class="text-muted">Pasajeros:</span> {{ resumenPax || '—' }}</div>

                        <div v-if="lineas.length > 0" class="border-top pt-2 mb-2">
                            <div v-for="(l, i) in lineas" :key="l.uid" class="d-flex justify-content-between gap-2 py-1">
                                <span class="text-truncate">{{ nombreLinea(l) }}</span>
                                <span class="text-nowrap">{{ textoTotalLinea(l, i, true) }}</span>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between border-top pt-2 mb-3 fs-6 fw-bold">
                            <span>Total</span>
                            <span>{{ totalGeneralTexto }}</span>
                        </div>

                        <div v-if="faltante" class="alert alert-warning py-2 px-3 mb-2"><i class="fas fa-circle-info me-1"></i>{{ faltante }}</div>
                        <template v-else>
                            <div v-if="avisoTributario" class="alert alert-warning py-2 px-3 mb-2">
                                <i class="fas fa-triangle-exclamation me-1"></i>{{ avisoTributario }}
                                <div class="mt-1">Puedes usar «Crear» y facturar en dos comprobantes desde la reserva, o quitar una línea.</div>
                            </div>
                            <div v-else class="alert alert-success py-2 px-3 mb-2"><i class="fas fa-circle-check me-1"></i>Listo</div>
                        </template>

                        <button class="btn btn-primary fw-semibold w-100" @click="crear" :disabled="creando || !!faltante">
                            <span v-if="creando" class="spinner-border spinner-border-sm me-2"></span>
                            <i v-else class="fas fa-check me-2"></i>
                            Crear venta directa<template v-if="totalBoton"> · {{ totalBoton }}</template>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" tabindex="-1" :class="{ show: showQuickClientModal, 'd-block': showQuickClientModal }"
            style="background:rgba(0,0,0,.5)" v-if="showQuickClientModal">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title">Registrar Cliente Rápido</h6>
                        <button class="btn-close" @click="showQuickClientModal = false"></button>
                    </div>
                    <div class="modal-body">
                        <ClientFormQuick :initial-data="null" @saved="onClientCreated" @cancel="showQuickClientModal = false" />
                    </div>
                </div>
            </div>
        </div>
    </DefaultLayout>
</template>

<script setup lang="ts">
import CampoFecha from '@/components/CampoFecha.vue';
import DestinoTreeSelect from '@/components/AgenciaViajes/DestinoTreeSelect.vue';
import { ref, computed, watch, nextTick, onMounted } from 'vue';
import { hoyPeru, sumarDiasISO, formatDiaCorto } from '@/helpers/fecha';
import { useRouter } from 'vue-router';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import httpClient from '@/helpers/http-client';
import ClientFormQuick from '@/components/Sales/ClientFormQuick.vue';
import { proveedorService } from '@/services/admin/proveedorService';
import {
    ventaDirectaService,
    type LineaVentaDirecta,
    type MonedaVenta,
    type TipoPaxVistaPrevia,
    type VistaPreviaLinea,
    type VistaPreviaVentaDirecta,
} from '@/services/admin/ventaDirectaService';
import type { Client } from '@/types/clients';
import type { ProveedorTarifa } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const router = useRouter();

const NOMBRE_MONEDA: Record<MonedaVenta, string> = { PEN: 'soles', USD: 'dólares' };
const DIAS = Array.from({ length: 14 }, (_, i) => i + 1);

// ── Cliente ────────────────────────────────────────────────────────────
const clientSearchText = ref<string>('');
const clientSuggestions = ref<Client[]>([]);
const clienteSeleccionado = ref<Client | null>(null);
const showClientSuggestions = ref<boolean>(false);
const showQuickClientModal = ref<boolean>(false);
let clientSearchTimeout: any = null;

// ── Viaje ──────────────────────────────────────────────────────────────
const destinoId = ref<number | null>(null);
const destinoTexto = ref<string>('');
const fechaInicio = ref<string>(hoyPeru()); // T-045: obligatoria, por defecto hoy en Perú
const moneda = ref<MonedaVenta>('PEN');
const simbolo = computed(() => (moneda.value === 'USD' ? 'US$' : 'S/'));
const creando = ref<boolean>(false);

// ── Pasajeros (T-046) ──────────────────────────────────────────────────
// Se piden por cantidad; cada contador crea o quita filas. La fila es la unidad que viaja al
// backend ({edad, residencia} y, si se escribieron, nombre y documento).
type TipoPax = TipoPaxVistaPrevia;
type Residencia = 'nacional' | 'extranjero';
type FilaPax = {
    uid: number;
    tipo: TipoPax;
    residencia: Residencia;
    edad: number;
    // null = el vendedor no lo tocó (en la fila del cliente se muestra el dato del cliente).
    nombre: string | null;
    documento: string | null;
};

// Una tarjeta por tipo, con un contador por residencia (maqueta aprobada). Los infantes no se
// separan por residencia: no pagan.
type Grupo = { tipo: TipoPax; residencia: Residencia; etiqueta: string };
const TARJETAS: Array<{ tipo: TipoPax; etiqueta: string; grupos: Grupo[] }> = [
    { tipo: 'adulto', etiqueta: 'Adultos', grupos: [
        { tipo: 'adulto', residencia: 'nacional', etiqueta: 'Nacionales' },
        { tipo: 'adulto', residencia: 'extranjero', etiqueta: 'Extranjeros' },
    ] },
    { tipo: 'nino', etiqueta: 'Niños', grupos: [
        { tipo: 'nino', residencia: 'nacional', etiqueta: 'Nacionales' },
        { tipo: 'nino', residencia: 'extranjero', etiqueta: 'Extranjeros' },
    ] },
    { tipo: 'infante', etiqueta: 'Infantes', grupos: [
        { tipo: 'infante', residencia: 'nacional', etiqueta: 'Infantes' },
    ] },
];
const ORDEN_TIPOS: TipoPax[] = ['adulto', 'nino', 'infante'];
const ORDEN_RESIDENCIAS: Residencia[] = ['nacional', 'extranjero'];

const filas = ref<FilaPax[]>([]);
let siguienteUidPax = 1;
// Rangos de edad de la agencia: se piden al abrir la pantalla a venta-directa/umbrales-edad
// (configuracion-agencia pide un permiso que el vendedor no tiene); la vista previa los repite.
const umbrales = ref<{ infante_max: number; nino_max: number } | null>(null);
const mostrarNombres = ref<boolean>(false);
// El cliente (persona natural) viaja de primer adulto salvo que el vendedor lo quite.
const clienteViaja = ref<boolean>(true);

onMounted(async () => {
    try {
        const res = await ventaDirectaService.umbralesEdad();
        umbrales.value = res.umbrales;
    } catch {
        // Sin rangos la pantalla sigue funcionando: los trae la vista previa.
    }
});

// El orden que se manda al backend (y el de las filas de nombres): adultos nacionales, adultos
// extranjeros, niños nacionales… — estable dentro de cada grupo. El primer adulto es el cliente.
const paxOrdenados = computed<FilaPax[]>(() => ORDEN_TIPOS.flatMap((t) =>
    ORDEN_RESIDENCIAS.flatMap((r) => filas.value.filter((f) => f.tipo === t && f.residencia === r))));
// Lo que se manda a la vista previa y a crear (sin nombres: esos se agregan al crear).
const pax = computed(() => paxOrdenados.value.map((f) => ({ edad: f.edad, residencia: f.residencia })));

const cantidadGrupo = (g: { tipo: TipoPax; residencia: Residencia }) => filas.value.filter((f) => f.tipo === g.tipo && f.residencia === g.residencia).length;

const edadPorDefecto = (tipo: TipoPax): number => {
    if (tipo === 'infante') return 0;
    if (tipo === 'nino') return (umbrales.value?.infante_max ?? 2) + 1;
    return 30;
};

const sumarPax = (g: { tipo: TipoPax; residencia: Residencia }) => {
    filas.value.push({ uid: siguienteUidPax++, tipo: g.tipo, residencia: g.residencia, edad: edadPorDefecto(g.tipo), nombre: null, documento: null });
};

// Quita primero una fila sin nombre, para no perder lo que el vendedor ya escribió.
const restarPax = (g: { tipo: TipoPax; residencia: Residencia }) => {
    const delGrupo = filas.value.filter((f) => f.tipo === g.tipo && f.residencia === g.residencia);
    const sinDatos = [...delGrupo].reverse().find((f) => f.nombre === null && f.documento === null);
    const quitar = sinDatos ?? delGrupo[delGrupo.length - 1];
    if (quitar) filas.value = filas.value.filter((f) => f.uid !== quitar.uid);
};

// La edad de un niño solo se puede mover dentro de su rango (si no, el backend lo clasificaría distinto).
const ajustarEdad = (f: FilaPax) => {
    if (!umbrales.value || !Number.isInteger(f.edad)) return;
    f.edad = Math.min(umbrales.value.nino_max, Math.max(umbrales.value.infante_max + 1, f.edad));
};

// Si el vendedor agrega un niño antes de que lleguen los rangos, nace con una edad supuesta (3)
// que, con un tope de infante de 3 o más, el backend clasificaría como infante (y cobraría como
// tal). Al llegar los rangos, se lo lleva a su rango.
watch(umbrales, () => {
    filas.value.filter((f) => f.tipo === 'nino').forEach(ajustarEdad);
});

const rangoGrupo = (tipo: TipoPax): string => {
    if (!umbrales.value) return '';
    const { infante_max: inf, nino_max: nino } = umbrales.value;
    if (tipo === 'adulto') return `${nino + 1} años o más`;
    if (tipo === 'nino') return inf + 1 === nino ? `${nino} años` : `${inf + 1} a ${nino} años`;
    return `0 a ${inf} años`;
};

// «Adulto nacional», «Niño extranjero», «Infante»; la del cliente lleva «Cliente · » delante.
const etiquetaFila = (f: FilaPax) => {
    const tipo = { adulto: 'Adulto', nino: 'Niño', infante: 'Infante' }[f.tipo];
    const base = f.tipo === 'infante' ? tipo : `${tipo} ${f.residencia === 'extranjero' ? 'extranjero' : 'nacional'}`;
    return esFilaCliente(f) ? `Cliente · ${base}` : base;
};

// «3 adultos · 1 niño (1 ext.)», como la maqueta: los extranjeros se cuentan en total al final.
const resumenPax = computed<string>(() => {
    const nombres: Record<TipoPax, [string, string]> = { adulto: ['adulto', 'adultos'], nino: ['niño', 'niños'], infante: ['infante', 'infantes'] };
    const partes = ORDEN_TIPOS.map((t) => {
        const n = filas.value.filter((f) => f.tipo === t).length;
        return n === 0 ? '' : `${n} ${nombres[t][n === 1 ? 0 : 1]}`;
    }).filter(Boolean);
    const ext = filas.value.filter((f) => f.residencia === 'extranjero').length;
    return partes.length === 0 ? '' : partes.join(' · ') + (ext > 0 ? ` (${ext} ext.)` : '');
});

// El cliente como primer pasajero: solo una persona con documento real (igual que PasajeroCatalogoService).
const datosClientePasajero = computed<{ nombre: string; documento: string } | null>(() => {
    const c = clienteSeleccionado.value;
    if (!c) return null;
    const tipo = (c.type_document ?? '').trim().toUpperCase();
    const documento = (c.n_document ?? '').trim();
    const nombre = (c.full_name ?? '').trim();
    if (!['DNI', 'CE', 'PAS'].includes(tipo) || documento === '' || documento === '00000000' || nombre === '') return null;
    return { nombre, documento };
});

const filaCliente = computed<FilaPax | null>(() => (clienteViaja.value && datosClientePasajero.value ? (paxOrdenados.value.find((f) => f.tipo === 'adulto') ?? null) : null));
const esFilaCliente = (f: FilaPax) => filaCliente.value?.uid === f.uid;

const valorNombre = (f: FilaPax) => f.nombre ?? (esFilaCliente(f) ? datosClientePasajero.value!.nombre : '');
const valorDocumento = (f: FilaPax) => f.documento ?? (esFilaCliente(f) ? datosClientePasajero.value!.documento : '');

// Lo que el vendedor escribió en una fila, listo para el backend. La fila del cliente sin tocar
// (o con los datos del cliente tal cual) no manda nada: el backend la completa con su ficha del catálogo.
const datosEscritos = (f: FilaPax): { nombre?: string; documento?: string } => {
    if (f.nombre === null && f.documento === null) return {};
    const nombre = valorNombre(f).trim();
    const documento = valorDocumento(f).trim();
    if (esFilaCliente(f) && nombre === datosClientePasajero.value!.nombre && documento === datosClientePasajero.value!.documento) return {};
    return { ...(nombre ? { nombre } : {}), ...(documento ? { documento } : {}) };
};

// Si el vendedor vació a mano nombre y documento de la fila del cliente, es que no viaja.
const clienteViajaEnvio = computed<boolean>(() => {
    const f = filaCliente.value;
    if (!f) return clienteViaja.value;
    const tocada = f.nombre !== null || f.documento !== null;
    return !(tocada && valorNombre(f).trim() === "" && valorDocumento(f).trim() === "");
});

const paxParaCrear = () => paxOrdenados.value.map((f) => ({ edad: f.edad, residencia: f.residencia, ...datosEscritos(f) }));

// ── Líneas ─────────────────────────────────────────────────────────────
// Una línea es un servicio de la venta. T-047 suma el origen 'tour'.
type Linea = {
    uid: number;
    origen: 'proveedor' | 'manual';
    dia: number; // día del viaje: 1 = fecha de inicio
    cantidad: number; // unidades; en un hotel, las noches
    // proveedor
    tarifa?: ProveedorTarifa;
    // T-045: sin valor por defecto; el vendedor elige viendo los dos totales.
    modoPrecio: 'por_persona' | 'tarifa_fija' | null;
    // concepto libre
    descripcion: string;
    precio: number | null;
    costo: number | null; // 0 si no tiene
};

const lineas = ref<Linea[]>([]);
let siguienteUid = 1;

const esHotel = (l: Linea) => l.origen === 'proveedor' && !!l.tarifa?.tipo_habitacion;
// Un hotel se nombra por el hotel (su servicio siempre es «Tipo de Habitación»).
const nombreTarifa = (t?: ProveedorTarifa) => (t?.tipo_habitacion ? t.proveedor_servicio?.proveedor?.razon_social : t?.proveedor_servicio?.destino_servicio?.servicio?.nombre) ?? 'Servicio';
const nombreLinea = (l: Linea) => (l.origen === 'proveedor' ? nombreTarifa(l.tarifa) : (l.descripcion.trim() || 'Concepto libre'));
const proveedorDe = (l: Linea) => l.tarifa?.proveedor_servicio?.proveedor?.razon_social ?? '';
const etiquetaTipo = (l: Linea) => esHotel(l)
    ? { texto: 'Hotel', clase: 'bg-info-subtle text-info-emphasis' }
    : l.origen === 'proveedor'
        ? { texto: 'Proveedor', clase: 'bg-primary-subtle text-primary-emphasis' }
        : { texto: 'Libre', clase: 'bg-secondary-subtle text-secondary-emphasis' };
const etiquetaDia = (d: number) => `${formatDiaCorto(sumarDiasISO(fechaInicio.value, d - 1))}${d === 1 ? ' (inicio)' : ` · día ${d}`}`;

const dinero = (monto: number, m: MonedaVenta = moneda.value) =>
    `${m === 'USD' ? 'US$' : 'S/'} ${monto.toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

// Resaltado de la línea recién agregada.
const resaltadoUid = ref<number | null>(null);
const refsLinea = new Map<number, HTMLElement>();
const guardarRefLinea = (uid: number, el: any) => { if (el) refsLinea.set(uid, el as HTMLElement); else refsLinea.delete(uid); };

const nuevaLinea = (parcial: Partial<Linea> & Pick<Linea, 'origen'>): Linea => ({
    uid: siguienteUid++, dia: 1, cantidad: 1, modoPrecio: null, descripcion: '', precio: null, costo: 0, ...parcial,
});

const resaltar = async (uid: number) => {
    resaltadoUid.value = uid;
    await nextTick();
    refsLinea.get(uid)?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    setTimeout(() => { if (resaltadoUid.value === uid) resaltadoUid.value = null; }, 2500);
};

const agregarLinea = (l: Linea) => {
    lineas.value.push(l);
    catalogoAbierto.value = false;
    resaltar(l.uid);
};
const agregarTarifa = (t: ProveedorTarifa) => agregarLinea(nuevaLinea({ origen: 'proveedor', tarifa: t }));
const agregarLibre = () => agregarLinea(nuevaLinea({ origen: 'manual' }));
const quitarLinea = (i: number) => { lineas.value.splice(i, 1); if (lineas.value.length === 0) abrirCatalogo(); };

// ── Catálogo (en el mismo lugar que las líneas) ────────────────────────
const catalogoAbierto = ref<boolean>(true); // sin líneas, viene abierto
const pestana = ref<'proveedor' | 'libre'>('proveedor');
const busqueda = ref('');
// Filtro del catálogo, separado del destino de la reserva: buscar en otro lugar no cambia la venta.
const filtroDestinoId = ref<number | null>(null);
const filtroDestinoTexto = ref<string>('');
const catalogo = ref<ProveedorTarifa[]>([]);
const cargandoCatalogo = ref(false);
const errorCatalogo = ref('');
let catalogoTimeout: any = null;
let catalogoSeq = 0;

// Muestra resultados sin escribir; filtra por el destino del catálogo (el backend incluye sus descendientes).
const cargarCatalogo = async () => {
    const seq = ++catalogoSeq;
    cargandoCatalogo.value = true;
    errorCatalogo.value = '';
    try {
        const res = await proveedorService.biblioteca({
            search: busqueda.value.trim() || undefined,
            destino_atractivo_id: filtroDestinoId.value ?? undefined,
        });
        if (seq === catalogoSeq) catalogo.value = res.proveedor_tarifas;
    } catch (error: any) {
        if (seq !== catalogoSeq) return;
        catalogo.value = [];
        errorCatalogo.value = error.response?.status === 403
            ? 'No tienes permiso para ver el catálogo de tarifas. Usa «Concepto libre» o pídelo al administrador.'
            : (error.response?.data?.message ?? 'No se pudo cargar el catálogo.');
    } finally {
        if (seq === catalogoSeq) cargandoCatalogo.value = false;
    }
};
const onBusqueda = () => { clearTimeout(catalogoTimeout); catalogoTimeout = setTimeout(cargarCatalogo, 250); };
// El filtro arranca en el destino de la venta: al elegirlo arriba y cada vez que se abre el catálogo.
const ponerFiltroEnDestinoDeLaVenta = () => {
    filtroDestinoId.value = destinoId.value;
    filtroDestinoTexto.value = destinoTexto.value;
};
const abrirCatalogo = () => {
    catalogoAbierto.value = true;
    pestana.value = 'proveedor';
    const cambio = filtroDestinoId.value !== destinoId.value;
    ponerFiltroEnDestinoDeLaVenta();
    if (!cambio) cargarCatalogo(); // si cambió, el watch del filtro ya recarga
};
watch(destinoId, ponerFiltroEnDestinoDeLaVenta);
watch(filtroDestinoId, () => { if (catalogoAbierto.value) cargarCatalogo(); });
onMounted(cargarCatalogo);

const precioCatalogo = (t: ProveedorTarifa) =>
    `${dinero(Number(t.precio_venta_adulto), t.moneda)}${t.tipo_habitacion ? ' / noche' : ''}`;

// ── Vista previa (T-045 + T-085) ───────────────────────────────────────
// El backend clasifica a los pasajeros por edad (ConfiguracionAgencia, que un
// vendedor no puede leer) y decide la afectación y si se podrá facturar en un
// solo comprobante. Concepto libre muestra su total local (precio × cantidad).
const vistaPrevia = ref<VistaPreviaVentaDirecta | null>(null);
const vistaPreviaError = ref<string>('');
let vistaPreviaTimeout: any = null;
let vistaPreviaSeq = 0; // descarta respuestas viejas

const cantidadValida = (n: number) => Number.isInteger(n) && n >= 1;
const paxValidos = computed(() => pax.value.length > 0 && pax.value.every((p) => Number.isInteger(p.edad) && p.edad >= 0 && p.edad <= 120));

// Por qué no se puede calcular todavía (se muestra en lugar de un spinner sin fin).
const motivoSinCalculo = computed<string>(() => {
    if (pax.value.length === 0) return 'Agrega al menos un pasajero para ver el total.';
    if (!paxValidos.value) return 'Revisa las edades de los pasajeros (de 0 a 120).';
    if (lineas.value.length === 0) return 'Agrega al menos un servicio.';
    for (const l of lineas.value) {
        if (!cantidadValida(l.cantidad)) return l.origen === 'proveedor' && esHotel(l) ? `Las noches de «${nombreLinea(l)}» deben ser 1 o más.` : `La cantidad de «${nombreLinea(l)}» debe ser 1 o más.`;
        if (l.origen === 'manual') {
            if (!l.descripcion.trim() || l.precio === null || l.precio < 0) return 'Completa la descripción y el precio del concepto libre.';
            if (l.costo === null || l.costo < 0) return `Revisa el costo de «${nombreLinea(l)}» (0 o más).`;
        }
    }
    return '';
});

// Una línea de tarifa quedó en otra moneda porque se cambió la de la venta después de agregarla.
const motivoMoneda = computed<string>(() => {
    const l = lineas.value.find((x) => x.origen === 'proveedor' && x.tarifa && x.tarifa.moneda !== moneda.value);
    return l
        ? `La venta es en ${NOMBRE_MONEDA[moneda.value]} y «${nombreLinea(l)}» está en ${NOMBRE_MONEDA[l.tarifa!.moneda]}. No se mezclan monedas: cambia la moneda de la venta o quita esa línea.`
        : '';
});

const payloadLineas = (): LineaVentaDirecta[] => lineas.value.map((l) => l.origen === 'proveedor'
    ? {
        origen_tipo: 'proveedor', dia: l.dia, proveedor_tarifa_id: l.tarifa!.id,
        modo_precio: esHotel(l) ? 'tarifa_fija' : l.modoPrecio,
        cantidad: !esHotel(l) && l.modoPrecio === 'por_persona' ? 1 : l.cantidad,
    }
    : {
        origen_tipo: 'manual', dia: l.dia, descripcion_manual: l.descripcion.trim(),
        precio_venta_snapshot: l.precio!, costo_snapshot: l.costo ?? 0, cantidad: l.cantidad,
    });

// Hay un cálculo en espera (debounce) o en vuelo: los totales en pantalla
// pueden ser de los datos anteriores.
const calculando = ref<boolean>(false);

// Los totales corresponden a lo que está en pantalla ahora mismo.
const totalesAlDia = computed<boolean>(() => !!vistaPrevia.value && !calculando.value && !motivoSinCalculo.value && !motivoMoneda.value);

const pedirVistaPrevia = () => {
    clearTimeout(vistaPreviaTimeout);
    const seq = ++vistaPreviaSeq; // invalida lo que esté en vuelo
    vistaPreviaError.value = '';
    // Con datos inválidos se conserva la vista previa anterior, pero sin
    // mostrar sus montos: totalesAlDia queda en false.
    if (motivoSinCalculo.value || motivoMoneda.value) {
        calculando.value = false;
        return;
    }
    calculando.value = true;
    const datos = { fecha_servicio: fechaInicio.value, moneda: moneda.value, pax: pax.value, lineas: payloadLineas() };
    vistaPreviaTimeout = setTimeout(async () => {
        try {
            const res = await ventaDirectaService.vistaPrevia(datos);
            if (seq === vistaPreviaSeq) {
                vistaPrevia.value = res;
                umbrales.value = res.umbrales ?? umbrales.value;
            }
        } catch (error: any) {
            if (seq !== vistaPreviaSeq) return;
            vistaPrevia.value = null;
            vistaPreviaError.value = error.response?.data?.message ?? 'No se pudo calcular el total.';
        } finally {
            if (seq === vistaPreviaSeq) calculando.value = false;
        }
    }, 300);
};

// Todo lo que cambia el cálculo en una sola firma.
watch(
    () => JSON.stringify([fechaInicio.value, moneda.value, pax.value.map((p) => [p.edad, p.residencia]), lineas.value.map((l) => [l.uid, l.dia, l.cantidad, l.modoPrecio, l.descripcion, l.precio, l.costo])]),
    pedirVistaPrevia,
);

const lineaPrevia = (i: number): VistaPreviaLinea | null => {
    const lp = vistaPrevia.value?.lineas[i];
    // Las opciones por persona/por unidad existen solo en una tarifa que no es hotel.
    return lp && lp.origen_tipo === 'proveedor' && lp.por_persona && lp.tarifa_fija ? lp : null;
};

const textoDesglose = (lp: VistaPreviaLinea) => {
    const etiquetas: Record<TipoPaxVistaPrevia, [string, string]> = { adulto: ['adulto', 'adultos'], nino: ['niño', 'niños'], infante: ['infante', 'infantes'] };
    return (Object.keys(etiquetas) as TipoPaxVistaPrevia[])
        .filter((t) => lp.por_persona!.desglose[t].cantidad > 0)
        .map((t) => {
            const { cantidad: n, precio } = lp.por_persona!.desglose[t];
            return `${n} ${etiquetas[t][n === 1 ? 0 : 1]} × ${precio}`;
        })
        .join(' + ');
};

// Total de una línea: el concepto libre se calcula acá; el resto viene del backend.
const textoTotalLinea = (l: Linea, i: number, resumen = false): string => {
    if (l.origen === 'manual') {
        if (l.precio === null || !cantidadValida(l.cantidad)) return '—';
        return dinero(Math.round(l.precio * 100) / 100 * l.cantidad);
    }
    if (!totalesAlDia.value) return vistaPrevia.value || resumen ? '…' : '';
    const total = vistaPrevia.value!.lineas[i]?.total;
    return total === null || total === undefined ? 'Por elegir' : dinero(total);
};

const ETIQUETA_AFECTACION = (lp: VistaPreviaLinea) => {
    if (lp.destino_tributario === 'amazonia') return { texto: 'Exonerado · Amazonía', clase: 'bg-success-subtle text-success-emphasis' };
    if (lp.destino_tributario === 'extranjero') return { texto: 'Exportación', clase: 'bg-warning-subtle text-warning-emphasis' };
    if (lp.tip_afe_igv === '20') return { texto: 'Exonerado', clase: 'bg-success-subtle text-success-emphasis' };
    if (lp.tip_afe_igv === '30') return { texto: 'Inafecto', clase: 'bg-secondary-subtle text-secondary-emphasis' };
    return { texto: 'Gravado', clase: 'bg-light text-dark border' };
};
const afectacionDe = (i: number) => {
    const lp = vistaPrevia.value?.lineas[i];
    return totalesAlDia.value && lp ? ETIQUETA_AFECTACION(lp) : null;
};

// ── Resumen ────────────────────────────────────────────────────────────
const rangoFechas = computed(() => {
    if (!fechaInicio.value) return '';
    const hasta = totalesAlDia.value ? vistaPrevia.value!.fecha_viaje_hasta : null;
    return hasta && hasta !== fechaInicio.value
        ? `del ${formatDiaCorto(fechaInicio.value)} al ${formatDiaCorto(hasta)}`
        : `el ${formatDiaCorto(fechaInicio.value)}`;
});

const totalGeneralTexto = computed<string>(() => {
    if (!totalesAlDia.value) return vistaPrevia.value || calculando.value ? '…' : '—';
    const total = vistaPrevia.value!.total;
    return total === null ? 'Por elegir' : dinero(total);
});
const totalBoton = computed<string>(() => (totalesAlDia.value && vistaPrevia.value!.total !== null ? dinero(vistaPrevia.value!.total) : ''));

// Lo que falta para poder crear (un solo estado, el más urgente).
const faltante = computed<string>(() => {
    if (!clienteSeleccionado.value) return 'Elige el cliente.';
    if (!destinoId.value) return 'Elige el destino.';
    if (!fechaInicio.value) return 'Indica la fecha de inicio.';
    if (motivoSinCalculo.value) return motivoSinCalculo.value;
    if (motivoMoneda.value) return motivoMoneda.value;
    if (vistaPreviaError.value) return vistaPreviaError.value;
    const sinModo = lineas.value.find((l) => l.origen === 'proveedor' && !esHotel(l) && !l.modoPrecio);
    if (sinModo) return `Elige cómo se cobra «${nombreLinea(sinModo)}»: por persona o por unidad.`;
    // Total sin confirmar: no se deja crear, para no cobrar un monto distinto al que se ve (H42).
    if (!totalesAlDia.value) return 'Calculando el total…';
    return '';
});

// Un aviso tributario no deshabilita «Crear»: la venta se crea y se factura desde la reserva.
const avisoTributario = computed<string>(() => (totalesAlDia.value && !vistaPrevia.value!.facturable.ok ? (vistaPrevia.value!.facturable.motivo ?? '') : ''));

// ── Cliente ────────────────────────────────────────────────────────────
const onClientSearchInput = () => {
    clearTimeout(clientSearchTimeout);
    clientSearchTimeout = setTimeout(buscarClientes, 300);
};

const buscarClientes = async () => {
    if (clientSearchText.value.trim().length < 2) { clientSuggestions.value = []; return; }
    const res = await httpClient.get('/clients', { params: { search: clientSearchText.value } });
    // ClientController::index() envuelve el listado en ClientCollection
    // ({ data: [...] }) — clients.data, no clients directo (mismo acceso
    // que ya usa sale/register.vue).
    clientSuggestions.value = res.data.clients?.data ?? [];
};

const seleccionarCliente = (c: Client) => {
    clienteSeleccionado.value = c;
    clienteViaja.value = true; // T-046: un cliente nuevo vuelve a proponerse como pasajero
    clientSearchText.value = c.full_name;
    showClientSuggestions.value = false;
};

const limpiarCliente = () => {
    clienteSeleccionado.value = null;
    clientSearchText.value = '';
};

const onClientSearchBlur = () => { setTimeout(() => { showClientSuggestions.value = false; }, 150); };

const onClientCreated = (client: Client) => {
    seleccionarCliente(client);
    showQuickClientModal.value = false;
};

const crear = async () => {
    if (faltante.value || !clienteSeleccionado.value || !destinoId.value) {
        (Swal as TVueSwalInstance).fire('Error', faltante.value || 'Completa los datos de la venta.', 'error');
        return;
    }

    creando.value = true;
    try {
        const res = await ventaDirectaService.crear({
            cliente_id: clienteSeleccionado.value.id,
            destino: destinoTexto.value,
            destino_atractivo_id: destinoId.value,
            fecha_servicio: fechaInicio.value,
            moneda: moneda.value,
            pax: paxParaCrear(),
            cliente_viaja: clienteViajaEnvio.value,
            lineas: payloadLineas(),
        });

        router.push(`/agencia-viajes/reservas/${res.reserva.id}`);
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo crear la venta directa', 'error');
    } finally {
        creando.value = false;
    }
};
</script>

<style scoped>
.resumen-fijo {
    position: sticky;
    top: 80px;
}

@media (max-width: 991.98px) {
    .resumen-fijo {
        position: static;
    }
}

.linea-servicio {
    transition: background-color 0.6s ease, box-shadow 0.6s ease;
}

.linea-resaltada {
    background-color: var(--bs-warning-bg-subtle);
    box-shadow: 0 0 0 2px var(--bs-warning-border-subtle);
}
</style>
