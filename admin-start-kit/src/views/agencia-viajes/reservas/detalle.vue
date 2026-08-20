<template>
    <DefaultLayout>
        <div v-if="reserva && cabecera" class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div>
                <h5 class="fw-bold mb-0">
                    <i class="fas fa-suitcase-rolling me-2 text-primary"></i>Reserva {{ cabecera.codigo_cotizacion }}
                    <span class="badge ms-2" :class="reserva.estado === 'activa' ? 'bg-success' : 'bg-danger'">
                        {{ reserva.estado === 'activa' ? 'Activa' : 'Cancelada' }}
                    </span>
                    <span v-if="reserva.estado === 'activa' && (reserva.pasajeros?.length ?? 0) > 0" class="badge ms-2" :class="pasajerosPendientesFacturar.length === 0 ? 'bg-success' : 'bg-warning text-dark'">
                        {{ pasajerosPendientesFacturar.length === 0 ? 'Facturación completa' : `Falta facturar a ${pasajerosPendientesFacturar.length} pasajero(s)` }}
                    </span>
                </h5>
                <small class="text-muted">
                    {{ cabecera.cliente?.full_name }} · {{ cabecera.destino }} ·
                    {{ formatFecha(cabecera.fecha_viaje_desde) }} — {{ formatFecha(cabecera.fecha_viaje_hasta) }}
                </small>
            </div>
            <div class="d-flex gap-2">
                <router-link to="/agencia-viajes/reservas" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Volver
                </router-link>
                <button v-if="mostrarBotonesFacturar" class="btn btn-primary btn-sm" @click="abrirModalFacturarSimple">
                    <i class="fas fa-file-invoice me-1"></i>Facturar
                </button>
                <button v-if="mostrarBotonesFacturar" class="btn btn-outline-primary btn-sm" @click="abrirModalFacturarEspecial">
                    <i class="fas fa-layer-group me-1"></i>Facturación especial
                </button>
                <button v-if="mostrarMarcarFacturacionExterna" class="btn btn-outline-secondary btn-sm" @click="abrirModalFacturacionExterna">
                    <i class="fas fa-external-link-alt me-1"></i>Marcar facturación externa
                </button>
                <button v-if="reserva.estado === 'activa'" class="btn btn-outline-primary btn-sm" @click="abrirModalReprogramar">
                    <i class="fas fa-calendar-days me-1"></i>Reprogramar viaje
                </button>
                <button v-if="reserva.estado === 'activa'" class="btn btn-outline-danger btn-sm" @click="mostrarModalCancelar = true">
                    <i class="fas fa-ban me-1"></i>Cancelar reserva
                </button>
            </div>
        </div>

        <div v-if="reserva?.facturacion_externa" class="alert alert-secondary d-flex justify-content-between align-items-center mb-3 py-2">
            <span>
                <i class="fas fa-external-link-alt me-2"></i>Esta reserva se factura afuera de la plataforma.
                <template v-if="reserva.referencia_externa"> Referencia: {{ reserva.referencia_externa }}.</template>
                <template v-if="reserva.fecha_facturacion_externa"> Fecha: {{ formatFecha(reserva.fecha_facturacion_externa) }}.</template>
            </span>
            <button v-if="facturacionExternaEditable" class="btn btn-sm btn-outline-secondary" @click="abrirModalFacturacionExterna">
                Editar
            </button>
        </div>

        <div v-if="reserva && (itemsPendientesSincronizar?.length ?? 0) > 0" class="alert alert-warning d-flex justify-content-between align-items-center mb-3">
            <span><i class="fas fa-triangle-exclamation me-2"></i>Hay {{ itemsPendientesSincronizar.length }} servicio(s) nuevo(s) en la cotización sin reflejar en esta reserva: {{ itemsPendientesSincronizar.map(i => i.nombre).join(', ') }}.</span>
            <button class="btn btn-sm btn-warning" :disabled="sincronizando" @click="sincronizarItems">
                <span v-if="sincronizando" class="spinner-border spinner-border-sm me-1"></span>Sincronizar
            </button>
        </div>

        <div v-if="itemsNoTocadosReprogramacion.length > 0" class="alert alert-warning d-flex justify-content-between align-items-start mb-3">
            <span>
                <i class="fas fa-triangle-exclamation me-2"></i>
                Reserva reprogramada. {{ itemsNoTocadosReprogramacion.length }} ítem(s) quedaron con su fecha
                de antes — revísalos y corrígelos a mano si hace falta:
                <span v-for="(it, idx) in itemsNoTocadosReprogramacion" :key="it.reserva_item_id">
                    {{ it.nombre }} ({{ formatFecha(it.fecha) }},
                    {{ it.motivo === 'manual' ? 'editado a mano antes' : 'sin datos para recalcular solo' }})<span v-if="idx < itemsNoTocadosReprogramacion.length - 1">, </span>
                </span>.
            </span>
            <button class="btn-close" @click="itemsNoTocadosReprogramacion = []"></button>
        </div>

        <div v-if="reserva" class="row g-3">
            <div class="col-12 col-lg-8">
                <ul class="nav nav-pills mb-3">
                    <li class="nav-item">
                        <button class="nav-link" :class="{ active: tab === 'pax' }" @click="tab = 'pax'">
                            Pasajeros <span class="badge bg-warning text-dark ms-1">{{ pasajerosIncompletos }}</span>
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" :class="{ active: tab === 'items' }" @click="tab = 'items'">
                            Ítems <span class="badge bg-warning text-dark ms-1">{{ itemsSinAsignar }}</span>
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" :class="{ active: tab === 'asignacion' }" @click="cambiarAAsignacion">
                            Asignación pasajero↔ítem
                        </button>
                    </li>
                </ul>

                <!-- TAB PASAJEROS -->
                <div v-if="tab === 'pax'" class="d-flex flex-column gap-2">
                    <div v-for="p in reserva.pasajeros" :key="p.id" class="card border-0 shadow-sm"
                        :style="{ borderLeft: '4px solid ' + (esPasajeroCompleto(p) ? '#22c55e' : '#f59e0b') }">
                        <div class="card-body py-2">
                            <div class="d-flex justify-content-between align-items-center" style="cursor:pointer" @click="toggleFormPax(p.id)">
                                <span class="d-flex align-items-center gap-2">
                                    <span class="rounded-circle d-inline-flex align-items-center justify-content-center fw-semibold"
                                        style="width:34px;height:34px;background:#e0e7ff;color:#4338ca;font-size:13px;flex-shrink:0">
                                        {{ iniciales(p.nombre) }}
                                    </span>
                                    <span>
                                        <span class="badge bg-light text-dark border me-2">{{ etiquetaTipoPax(p.tipo_pax) }}</span>
                                        {{ p.nombre || '—' }}
                                        <span v-if="!p.nombre" class="text-muted fst-italic">Sin datos todavía</span>
                                    </span>
                                </span>
                                <span class="badge" :class="esPasajeroCompleto(p) ? 'bg-success' : 'bg-warning text-dark'">
                                    {{ esPasajeroCompleto(p) ? 'Completo' : 'Incompleto' }}
                                </span>
                            </div>

                            <div v-if="formPaxAbierto === p.id" class="mt-3 border-top pt-3">
                                <div class="row g-2 mb-2 position-relative">
                                    <div class="col-md-6">
                                        <label class="form-label small text-secondary mb-1">Buscar pasajero (DNI o nombre)</label>
                                        <div class="input-group input-group-sm">
                                            <input type="text" class="form-control form-control-sm" placeholder="Buscar por DNI o nombre..."
                                                v-model="catalogoSearch[p.id]" @input="onBuscarCatalogo(p.id)" autocomplete="off">
                                            <span v-if="buscandoDni === p.id" class="input-group-text bg-white">
                                                <span class="spinner-border spinner-border-sm text-primary"></span>
                                            </span>
                                        </div>
                                        <div v-if="(catalogoResultados[p.id]?.length ?? 0) > 0"
                                            class="list-group position-absolute w-100 shadow-sm" style="z-index:10;max-width:60%">
                                            <button type="button" class="list-group-item list-group-item-action py-2 text-start"
                                                v-for="c in catalogoResultados[p.id]" :key="c.id" @click="autocompletarDesdeCatalogo(p, c)">
                                                <div class="small fw-semibold">{{ c.nombre }}</div>
                                                <div class="small text-muted">{{ c.documentos?.[0]?.numero_documento ?? 'sin documento' }}</div>
                                            </button>
                                        </div>
                                        <small class="text-muted">Si escribís un DNI de 8 dígitos y no está en el sistema, se busca solo en RENIEC.</small>
                                    </div>
                                </div>
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <label class="form-label small text-secondary mb-1">Nombre completo</label>
                                        <input type="text" class="form-control form-control-sm" v-model="p.nombre">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small text-secondary mb-1">Documento</label>
                                        <input type="text" class="form-control form-control-sm" v-model="p.documento">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small text-secondary mb-1">Nacionalidad</label>
                                        <input type="text" class="form-control form-control-sm" v-model="p.nacionalidad">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small text-secondary mb-1">Alimentación especial</label>
                                        <input type="text" class="form-control form-control-sm" placeholder="Ninguna" v-model="p.alimentacion_especial">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small text-secondary mb-1">Discapacidad (detalle)</label>
                                        <input type="text" class="form-control form-control-sm" placeholder="Ninguna" v-model="p.discapacidad">
                                    </div>
                                    <div class="col-12">
                                        <small class="text-muted d-block mb-2"><i class="fas fa-plane me-1"></i>Completar solo si el pasajero ya tiene su propio vuelo comprado por su cuenta (no es el pasaje que vende la agencia).</small>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small text-secondary mb-1">Vuelo ida — aerolínea</label>
                                        <input type="text" class="form-control form-control-sm" placeholder="Ej. LA2203" v-model="p.vuelo_aerolinea_ida">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small text-secondary mb-1">Vuelo ida — fecha</label>
                                        <input type="date" class="form-control form-control-sm" v-model="p.vuelo_fecha_ida">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small text-secondary mb-1">Vuelo ida — hora</label>
                                        <input type="time" class="form-control form-control-sm" placeholder="18:45" v-model="p.vuelo_hora_ida">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small text-secondary mb-1">Vuelo vuelta — aerolínea</label>
                                        <input type="text" class="form-control form-control-sm" placeholder="Ej. LA2204" v-model="p.vuelo_aerolinea_vuelta">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small text-secondary mb-1">Vuelo vuelta — fecha</label>
                                        <input type="date" class="form-control form-control-sm" v-model="p.vuelo_fecha_vuelta">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small text-secondary mb-1">Vuelo vuelta — hora</label>
                                        <input type="time" class="form-control form-control-sm" placeholder="18:45" v-model="p.vuelo_hora_vuelta">
                                    </div>
                                </div>
                                <button class="btn btn-primary btn-sm mt-2" :disabled="guardandoPax === p.id" @click="guardarPax(p)">
                                    <span v-if="guardandoPax === p.id" class="spinner-border spinner-border-sm me-1"></span>
                                    <i v-else class="fas fa-check me-1"></i>Guardar
                                </button>
                            </div>
                        </div>
                    </div>
                    <div v-if="(reserva.pasajeros?.length ?? 0) === 0" class="text-muted text-center py-4">Sin pasajeros.</div>
                </div>

                <!-- TAB ITEMS -->
                <div v-if="tab === 'items'" class="d-flex flex-column gap-2">
                    <div v-for="it in reserva.items" :key="it.id" class="card border-0 shadow-sm">
                        <div class="card-body py-2">
                            <div class="mb-2">
                                <strong>{{ nombreItem(it) }}</strong>
                                <span v-if="it.fecha_origen === 'manual'" class="badge bg-light text-dark border ms-2" title="Esta fecha fue editada a mano — una reprogramación de la reserva no la mueve sola">
                                    <i class="fas fa-hand me-1"></i>Fecha manual
                                </span>
                                <span v-if="itemsFacturadosIds.includes(it.id)" class="badge bg-success-subtle text-success border ms-2">
                                    <i class="fas fa-file-invoice me-1"></i>Facturado
                                </span>
                            </div>
                            <div v-if="destinoItem(it)" class="small text-muted mb-2"><i class="fas fa-map-marker-alt me-1"></i>{{ destinoItem(it) }}</div>
                            <div class="row g-2">
                                <div class="col-md-3" v-if="it.alternativa_item?.origen_tipo === 'proveedor'">
                                    <label class="form-label small text-secondary mb-1">Proveedor</label>
                                    <div v-if="proveedorBuscando !== it.id" class="d-flex align-items-center justify-content-between border rounded px-2 py-1 bg-white"
                                        style="cursor:pointer;min-height:31px" @click="proveedorBuscando = it.id; proveedorSearch[it.id] = ''">
                                        <span class="small" :class="{ 'text-muted fst-italic': !nombreProveedorActual(it) }">{{ nombreProveedorActual(it) ?? 'Sin asignar' }}</span>
                                        <i class="fas fa-pen text-muted small"></i>
                                    </div>
                                    <div v-else class="position-relative">
                                        <input type="text" class="form-control form-control-sm" placeholder="Buscar proveedor..." v-model="proveedorSearch[it.id]"
                                            @blur="cerrarBusquedaProveedor(it)" autofocus>
                                        <div class="list-group position-absolute w-100 shadow-sm" style="z-index:10;max-height:220px;overflow-y:auto">
                                            <button type="button" class="list-group-item list-group-item-action py-1 small text-muted" @mousedown.prevent="elegirProveedor(it, null)">
                                                Sin asignar
                                            </button>
                                            <button type="button" class="list-group-item list-group-item-action py-1 small" v-for="t in proveedoresFiltrados(it)" :key="t.id" @mousedown.prevent="elegirProveedor(it, t.id)">
                                                {{ t.proveedor_servicio?.proveedor?.razon_social ?? ('Tarifa #' + t.id) }}{{ t.proveedor_servicio?.proveedor?.es_referencial ? ' (Referencial)' : '' }}
                                                <span class="text-muted">— {{ t.tipo_tarifa }} · {{ t.modalidad }} · {{ t.moneda }} {{ Number(t.precio_venta_adulto).toFixed(0) }}</span>
                                            </button>
                                            <div v-if="proveedoresFiltrados(it).length === 0" class="list-group-item small text-muted py-1">Sin resultados</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3" v-if="it.alternativa_item?.origen_tipo === 'guia'">
                                    <label class="form-label small text-secondary mb-1">Guía</label>
                                    <div v-if="it.salida_operativa_id" class="small">
                                        <span :class="it.salida_operativa?.guia ? '' : 'text-muted fst-italic'">{{ it.salida_operativa?.guia?.nombre ?? 'Sin asignar' }}</span>
                                        <router-link :to="`/agencia-viajes/salidas/${it.salida_operativa_id}`" class="d-block text-decoration-none" style="font-size:11px">
                                            <i class="fas fa-link me-1"></i>Vía salida operativa
                                        </router-link>
                                    </div>
                                    <select v-else class="form-select form-select-sm" v-model="it.guia_id" @change="guardarItem(it)">
                                        <option :value="null">Sin asignar</option>
                                        <option v-for="g in guias" :key="g.id" :value="g.id">{{ g.nombre }}{{ g.es_referencial ? ' (Referencial)' : '' }}</option>
                                    </select>
                                </div>
                                <div :class="tieneAsignacionAplicable(it) ? 'col-md-3' : 'col-md-6'">
                                    <label class="form-label small text-secondary mb-1">Fecha del servicio</label>
                                    <div v-if="it.salida_operativa_id" class="small">
                                        <span>{{ formatFecha(it.fecha) }}</span>
                                        <router-link :to="`/agencia-viajes/salidas/${it.salida_operativa_id}`" class="d-block text-decoration-none" style="font-size:11px">
                                            <i class="fas fa-link me-1"></i>Vía salida operativa
                                        </router-link>
                                    </div>
                                    <input v-else type="date" class="form-control form-control-sm" v-model="it.fecha" @change="guardarItem(it)">
                                </div>
                                <div :class="tieneAsignacionAplicable(it) ? 'col-md-3' : 'col-md-6'">
                                    <label class="form-label small text-secondary mb-1">Hora</label>
                                    <input type="time" class="form-control form-control-sm" v-model="it.hora" @change="guardarItem(it)">
                                </div>
                            </div>
                            <p v-if="tieneAsignacionAplicable(it) && !it.proveedor_tarifa_id && !it.guia_id" class="small mt-2 mb-0" style="color:#adb5bd;font-style:italic">
                                <i class="fas fa-triangle-exclamation me-1"></i>Sin asignar todavía — no bloquea el resto de la reserva
                            </p>
                        </div>
                    </div>
                    <div v-if="(reserva.items?.length ?? 0) === 0" class="text-muted text-center py-4">Sin ítems.</div>
                </div>

                <!-- TAB ASIGNACION -->
                <div v-if="tab === 'asignacion'" class="d-flex flex-column gap-3">
                    <div v-for="it in reserva.items" :key="it.id" class="card border-0 shadow-sm">
                        <div class="card-body py-2">
                            <p class="fw-semibold mb-2">{{ nombreItem(it) }}</p>
                            <div class="d-flex flex-wrap gap-3">
                                <label v-for="p in reserva.pasajeros" :key="p.id" class="small d-flex align-items-center gap-1" style="cursor:pointer">
                                    <input type="checkbox" :checked="estaAsignado(it.id, p.id)" @change="toggleAsignacion(it, p)">
                                    {{ p.nombre || ('Pasajero ' + p.id) }} <span class="text-muted">({{ etiquetaTipoPax(p.tipo_pax) }})</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div v-if="(reserva.items?.length ?? 0) === 0" class="text-muted text-center py-4">Sin ítems.</div>
                </div>
            </div>

            <div class="col-12 col-lg-4">
                <div class="card border-0 shadow-sm" style="position:sticky;top:1rem">
                    <div class="card-body">
                        <p class="fw-bold mb-0">Resumen de la reserva</p>
                        <p class="small text-muted mb-3">
                            {{ reserva.pasajeros?.length ?? 0 }} pasajero(s) ·
                            {{ formatFecha(cabecera?.fecha_viaje_desde) }} — {{ formatFecha(cabecera?.fecha_viaje_hasta) }}
                        </p>

                        <div class="d-flex flex-column gap-2 small mb-3">
                            <div v-for="r in resumen" :key="r.reserva_item_id" class="d-flex justify-content-between">
                                <span>{{ r.nombre }}<span v-if="r.fecha" class="text-muted"> — {{ formatFecha(r.fecha) }}</span></span>
                                <span class="fw-semibold">{{ moneda }} {{ Number(r.total_convertido).toFixed(2) }}</span>
                            </div>
                        </div>

                        <hr>
                        <div class="d-flex justify-content-between align-items-baseline mb-3">
                            <span class="fw-semibold">TOTAL</span>
                            <span class="fs-4 fw-semibold">{{ moneda }} {{ Number(total).toFixed(2) }}</span>
                        </div>

                        <hr>
                        <p class="small fw-semibold text-secondary mb-2">Progreso operativo</p>
                        <div class="d-flex justify-content-between align-items-center small mb-1">
                            <span><i class="fas fa-user-check me-1 text-muted"></i>Pasajeros completos</span>
                            <span class="fw-semibold">{{ pasajerosCompletos }} / {{ reserva.pasajeros?.length ?? 0 }}</span>
                        </div>
                        <div class="progress mb-2" style="height:6px">
                            <div class="progress-bar bg-success" :style="{ width: pctPax + '%' }"></div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center small mb-1">
                            <span><i class="fas fa-clipboard-check me-1 text-muted"></i>Ítems con proveedor/guía</span>
                            <span class="fw-semibold">{{ itemsAsignados }} / {{ itemsAsignables.length }}</span>
                        </div>
                        <div class="progress" style="height:6px">
                            <div class="progress-bar bg-info" :style="{ width: pctItems + '%' }"></div>
                        </div>

                        <p class="small text-muted mt-3 mb-0">
                            <i class="fas fa-circle-info me-1"></i>Saldo pendiente de cobro no entra en esta pantalla — el total es el cerrado al aceptar la alternativa, no se recalcula acá.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div v-else class="text-center text-muted py-5">Cargando reserva...</div>

        <!-- Modal cancelar -->
        <div class="modal fade" tabindex="-1" :class="{ show: mostrarModalCancelar, 'd-block': mostrarModalCancelar }"
            style="background:rgba(0,0,0,.5)" v-if="mostrarModalCancelar">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold">Cancelar reserva</h6>
                        <button class="btn-close" @click="mostrarModalCancelar = false"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label small fw-semibold text-secondary">Motivo</label>
                        <select class="form-select form-select-sm" v-model="motivoCancelacion">
                            <option value="voluntaria">Voluntaria</option>
                            <option value="fuerza_mayor">Fuerza mayor</option>
                            <option value="clima">Clima</option>
                            <option value="falta_pago_cuotas">Falta de pago de cuotas</option>
                        </select>
                        <p class="small text-muted mt-2 mb-0"><i class="fas fa-info-circle me-1"></i>El cálculo de reembolso no entra en esta pantalla todavía.</p>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-outline-secondary btn-sm" @click="mostrarModalCancelar = false">Volver</button>
                        <button class="btn btn-danger btn-sm" :disabled="cancelando" @click="confirmarCancelacion">Confirmar cancelación</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal reprogramar (Fase 2 del fix Cotización↔Reserva, 2026-08-19) -->
        <div class="modal fade" tabindex="-1" :class="{ show: mostrarModalReprogramar, 'd-block': mostrarModalReprogramar }"
            style="background:rgba(0,0,0,.5)" v-if="mostrarModalReprogramar">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold">Reprogramar viaje</h6>
                        <button class="btn-close" @click="mostrarModalReprogramar = false"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="form-label small fw-semibold text-secondary">Fecha desde</label>
                                <input type="date" class="form-control form-control-sm" v-model="reprogramarForm.fecha_viaje_desde">
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-semibold text-secondary">Fecha hasta</label>
                                <input type="date" class="form-control form-control-sm" v-model="reprogramarForm.fecha_viaje_hasta">
                            </div>
                        </div>
                        <label class="form-label small fw-semibold text-secondary">Motivo</label>
                        <textarea class="form-control form-control-sm" rows="2" v-model="reprogramarForm.motivo" placeholder="Ej. Cliente pidió correr el viaje 15 días por motivos laborales"></textarea>
                        <p class="small text-muted mt-2 mb-0">
                            <i class="fas fa-info-circle me-1"></i>Los ítems con fecha editada a mano
                            (<span class="badge bg-light text-dark border">Fecha manual</span>) no se mueven —
                            hay que revisarlos aparte. La cotización original no se toca.
                        </p>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-outline-secondary btn-sm" @click="mostrarModalReprogramar = false">Volver</button>
                        <button class="btn btn-primary btn-sm" :disabled="reprogramando || !reprogramarForm.fecha_viaje_desde || !reprogramarForm.motivo"
                            @click="confirmarReprogramacion">
                            <span v-if="reprogramando" class="spinner-border spinner-border-sm me-1"></span>Confirmar reprogramación
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal facturar — facturación múltiple por grupo de pasajeros
             (2026-08-20, sobre la base de la Fase A del 2026-08-19). Cada
             pasajero pertenece a un único comprobante: se elige a quién se
             factura en esta pasada, con su propio cliente/texto, y el
             modal se puede repetir para los pasajeros que van quedando. -->
        <div class="modal fade" tabindex="-1" :class="{ show: mostrarModalFacturarEspecial, 'd-block': mostrarModalFacturarEspecial }"
            style="background:rgba(0,0,0,.5)" v-if="mostrarModalFacturarEspecial">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold">Facturación especial</h6>
                        <button class="btn-close" @click="mostrarModalFacturarEspecial = false"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label small fw-semibold text-secondary">¿A quién le facturamos en esta pasada?</label>
                        <p class="small text-muted mb-2">Marca uno o varios pasajeros — cada grupo genera su propio comprobante, con su propio cliente. Puedes repetir esto después para el resto.</p>
                        <div class="d-flex flex-column gap-1 mb-3" style="max-height:160px;overflow-y:auto">
                            <label v-for="p in pasajerosPendientesFacturar" :key="p.id" class="small d-flex align-items-center border rounded px-2 py-1" style="cursor:pointer">
                                <input type="checkbox" class="form-check-input me-2" v-model="facturarEspecialForm.pasajero_ids" :value="p.id">
                                {{ nombrePasajero(p) }}
                            </label>
                        </div>

                        <div v-if="cargandoPreviewFacturaEspecial" class="small text-muted mb-2">
                            <span class="spinner-border spinner-border-sm me-1"></span>Calculando...
                        </div>

                        <template v-else-if="previewFacturaEspecial">
                            <!-- Ítems compartidos (ej. habitación doble) que no entran todavía
                                 porque falta incluir a otro pasajero que también los usa. -->
                            <div v-if="previewFacturaEspecial.items_pendientes_por_pasajero_faltante.length > 0" class="alert alert-secondary small py-2 mb-2">
                                <i class="fas fa-circle-info me-1"></i>
                                {{ previewFacturaEspecial.items_pendientes_por_pasajero_faltante.length }} ítem(s) compartido(s) quedan pendientes porque los comparte otro pasajero que no está en esta selección:
                                {{ previewFacturaEspecial.items_pendientes_por_pasajero_faltante.map(it => it.nombre).join(', ') }}.
                            </div>

                            <!-- Ítems sin ningún pasajero asignado (el caso más común hoy:
                                 ajustes, extras) — el vendedor decide a mano si van en este
                                 comprobante. -->
                            <div v-if="previewFacturaEspecial.items_sin_asignar_disponibles.length > 0" class="mb-2">
                                <label class="form-label small fw-semibold text-secondary">Otros ítems sin pasajero asignado (agrégalos si corresponden acá)</label>
                                <div class="d-flex flex-column gap-1" style="max-height:120px;overflow-y:auto">
                                    <label v-for="it in previewFacturaEspecial.items_sin_asignar_disponibles" :key="it.reserva_item_id" class="small d-flex justify-content-between align-items-center border rounded px-2 py-1" style="cursor:pointer">
                                        <span>
                                            <input type="checkbox" class="form-check-input me-2" v-model="facturarEspecialForm.reserva_item_ids_manual" :value="it.reserva_item_id">
                                            {{ it.nombre }}
                                        </span>
                                        <span class="fw-semibold">{{ moneda }} {{ Number(it.total).toFixed(2) }}</span>
                                    </label>
                                </div>
                            </div>

                            <!-- Guardia tributario: este subgrupo mezcla tratamientos distintos,
                                 no se puede emitir un solo comprobante — se avisa acá y se oculta
                                 el resto del formulario. -->
                            <div v-if="previewFacturaEspecial.bloqueado_tributario" class="alert alert-danger mb-0">
                                <i class="fas fa-triangle-exclamation me-2"></i>
                                <strong>No se puede facturar este grupo así todavía.</strong>
                                <p class="mb-0 mt-1">{{ previewFacturaEspecial.motivo }}</p>
                            </div>

                            <template v-else>
                                <div class="row g-2 mb-2">
                                    <div class="col-6">
                                        <label class="form-label small fw-semibold text-secondary">Tipo de comprobante</label>
                                        <select class="form-select form-select-sm" v-model="facturarEspecialForm.tipo_comprobante_codigo">
                                            <option value="01">Factura</option>
                                            <option value="03">Boleta</option>
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label small fw-semibold text-secondary">Total de este comprobante</label>
                                        <input type="text" class="form-control form-control-sm fw-bold" disabled :value="`${moneda} ${Number(previewFacturaEspecial.total ?? 0).toFixed(2)}`">
                                    </div>
                                </div>

                                <div class="mb-2">
                                    <label class="form-label small fw-semibold text-secondary">Cliente para este comprobante</label>
                                    <div v-if="clienteSeleccionado" class="d-flex justify-content-between align-items-center border rounded px-2 py-1 small">
                                        <span>{{ clienteSeleccionado.full_name }} — {{ clienteSeleccionado.n_document }}</span>
                                        <button type="button" class="btn btn-sm btn-link text-danger p-0" @click="clienteSeleccionado = null">Cambiar</button>
                                    </div>
                                    <template v-else>
                                        <input type="text" class="form-control form-control-sm" placeholder="Buscar cliente por nombre o documento..."
                                            v-model="busquedaCliente" @input="onBuscarCliente">
                                        <div v-if="resultadosCliente.length > 0" class="border rounded mt-1" style="max-height:140px;overflow-y:auto">
                                            <button v-for="c in resultadosCliente" :key="c.id" type="button"
                                                class="dropdown-item small w-100 text-start" @click="seleccionarCliente(c)">
                                                {{ c.full_name }} — {{ c.n_document }}
                                            </button>
                                        </div>
                                    </template>
                                </div>

                                <div class="mb-2">
                                    <label class="form-label small fw-semibold text-secondary">Texto para este comprobante (opcional)</label>
                                    <textarea class="form-control form-control-sm" rows="2"
                                        placeholder="Ej: Servicio de movilización y hospedaje — comisión de servicio"
                                        v-model="facturarEspecialForm.texto_personalizado"></textarea>
                                </div>

                                <p class="small text-muted mb-0">
                                    <i class="fas fa-info-circle me-1"></i>La venta se crea pendiente de cobro. Cobrarla y
                                    enviarla a SUNAT se hace después, desde la pantalla normal de ventas.
                                </p>
                            </template>
                        </template>

                        <p v-else-if="facturarEspecialForm.pasajero_ids.length === 0" class="small text-muted mb-0">
                            Marca al menos un pasajero para ver la propuesta de comprobante.
                        </p>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-outline-secondary btn-sm" @click="mostrarModalFacturarEspecial = false">Cerrar</button>
                        <button v-if="previewFacturaEspecial && !previewFacturaEspecial.bloqueado_tributario" class="btn btn-primary btn-sm"
                            :disabled="facturando || cargandoPreviewFacturaEspecial || facturarEspecialForm.pasajero_ids.length === 0 || !clienteSeleccionado"
                            @click="confirmarFacturacionEspecial">
                            <span v-if="facturando" class="spinner-border spinner-border-sm me-1"></span>Facturar este grupo
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal facturar simple (2026-08-20) — camino de todos los días:
             un solo responsable de pago, cubre a TODOS los pasajeros y
             ítems pendientes en un único comprobante, sin pedir selección
             manual. Usa el cliente de la cotización directamente. Mismo
             backend (preparar-factura/facturar) que Facturación especial —
             solo arma el payload distinto: pasajero_ids = todos los
             pendientes, reserva_item_ids_manual = todo el pool sin asignar. -->
        <div class="modal fade" tabindex="-1" :class="{ show: mostrarModalFacturarSimple, 'd-block': mostrarModalFacturarSimple }"
            style="background:rgba(0,0,0,.5)" v-if="mostrarModalFacturarSimple">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold">Facturar reserva</h6>
                        <button class="btn-close" @click="mostrarModalFacturarSimple = false"></button>
                    </div>
                    <div class="modal-body">
                        <div v-if="cargandoFacturarSimple" class="small text-muted mb-2">
                            <span class="spinner-border spinner-border-sm me-1"></span>Calculando...
                        </div>

                        <template v-else-if="previewFacturaSimple">
                            <div v-if="previewFacturaSimple.bloqueado_tributario" class="alert alert-danger mb-0">
                                <i class="fas fa-triangle-exclamation me-2"></i>
                                <strong>No se puede facturar toda la reserva en un solo comprobante.</strong>
                                <p class="mb-1 mt-1">{{ previewFacturaSimple.motivo }}</p>
                                <p class="mb-0 small">Usa <strong>Facturación especial</strong> para separar los grupos con distinto tratamiento tributario.</p>
                            </div>

                            <template v-else>
                                <p class="small text-muted mb-2">
                                    Se factura de una vez a {{ pasajerosPendientesFacturar.length }} pasajero(s) pendiente(s), a nombre de
                                    <strong>{{ cabecera?.cliente?.full_name ?? 'el cliente de la cotización' }}</strong>.
                                    ¿Necesitas dividirlo en varios comprobantes o cambiar el cliente? Usa <strong>Facturación especial</strong>.
                                </p>
                                <div class="row g-2 mb-2">
                                    <div class="col-6">
                                        <label class="form-label small fw-semibold text-secondary">Tipo de comprobante</label>
                                        <select class="form-select form-select-sm" v-model="facturarSimpleForm.tipo_comprobante_codigo">
                                            <option value="01">Factura</option>
                                            <option value="03">Boleta</option>
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label small fw-semibold text-secondary">Total a facturar</label>
                                        <input type="text" class="form-control form-control-sm fw-bold" disabled :value="`${moneda} ${Number(previewFacturaSimple.total ?? 0).toFixed(2)}`">
                                    </div>
                                </div>
                                <p class="small text-muted mb-0">
                                    <i class="fas fa-info-circle me-1"></i>La venta se crea pendiente de cobro. Cobrarla y
                                    enviarla a SUNAT se hace después, desde la pantalla normal de ventas.
                                </p>
                            </template>
                        </template>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-outline-secondary btn-sm" @click="mostrarModalFacturarSimple = false">Cerrar</button>
                        <button v-if="previewFacturaSimple && !previewFacturaSimple.bloqueado_tributario" class="btn btn-primary btn-sm"
                            :disabled="facturando || cargandoFacturarSimple"
                            @click="confirmarFacturacionSimple">
                            <span v-if="facturando" class="spinner-border spinner-border-sm me-1"></span>Facturar
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Facturación externa por tenant + por reserva -->
        <div class="modal fade" tabindex="-1" :class="{ show: mostrarModalFacturacionExterna, 'd-block': mostrarModalFacturacionExterna }"
            style="background:rgba(0,0,0,.5)" v-if="mostrarModalFacturacionExterna">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold">Facturación externa</h6>
                        <button class="btn-close" @click="mostrarModalFacturacionExterna = false"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-muted">
                            Esta reserva se factura fuera de la plataforma — esto es solo una anotación de
                            referencia, no valida nada contra ningún sistema externo.
                        </p>
                        <div class="mb-2">
                            <label class="form-label small">Referencia externa (opcional)</label>
                            <input v-model="formFacturacionExterna.referencia_externa" type="text" class="form-control form-control-sm">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small">Fecha de facturación externa (opcional)</label>
                            <input v-model="formFacturacionExterna.fecha_facturacion_externa" type="date" class="form-control form-control-sm">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button v-if="reserva?.facturacion_externa" class="btn btn-outline-danger btn-sm" :disabled="guardandoFacturacionExterna" @click="guardarFacturacionExterna(false)">
                            Desmarcar
                        </button>
                        <button class="btn btn-outline-secondary btn-sm" @click="mostrarModalFacturacionExterna = false">Cerrar</button>
                        <button class="btn btn-primary btn-sm" :disabled="guardandoFacturacionExterna" @click="guardarFacturacionExterna(true)">
                            <span v-if="guardandoFacturacionExterna" class="spinner-border spinner-border-sm me-1"></span>Guardar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </DefaultLayout>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import httpClient from '@/helpers/http-client';
import { reservaService } from '@/services/admin/reservaService';
import { reservaFacturacionService, type PrepararFacturaResponse } from '@/services/admin/reservaFacturacionService';
import { reservaPasajeroService } from '@/services/admin/reservaPasajeroService';
import { reservaItemService } from '@/services/admin/reservaItemService';
import { proveedorService } from '@/services/admin/proveedorService';
import { guiaService } from '@/services/admin/guiaService';
import { useToast } from '@/composables/useToast';
import { formatFecha } from '@/helpers/fecha';
import type {
    Reserva, ReservaPasajero, ReservaItem, ReservaResumenItem, ReservaCabecera,
    PasajeroCatalogo, ProveedorTarifa, Guia, MotivoCancelacion,
} from '@/types/agencia-viajes';

const route = useRoute();
const reservaId = Number(route.params.id);
const toast = useToast();

const reserva = ref<Reserva | null>(null);
const resumen = ref<ReservaResumenItem[]>([]);
const total = ref<number>(0);
const moneda = ref<'PEN' | 'USD'>('PEN');
const cabecera = ref<ReservaCabecera | null>(null);
const itemsPendientesSincronizar = ref<Array<{ id: number; nombre: string }>>([]);
const sincronizando = ref(false);
const itemsNoTocadosReprogramacion = ref<Array<{ reserva_item_id: number; nombre: string; fecha: string | null; motivo: 'manual' | 'sin_dia_referencial' }>>([]);
const itemsFacturadosIds = ref<number[]>([]);
// Facturación múltiple por grupo de pasajeros (2026-08-20): pasajeros ya
// cubiertos por ALGUNA ReservaVenta — cada pasajero pertenece a un único
// comprobante, no se vuelve a ofrecer una vez facturado.
const pasajerosFacturadosIds = ref<number[]>([]);

// Facturación externa por tenant + por reserva (PEGAR-EN-CLAUDE-CODE-
// facturacion-externa-tenant.md, 2026-08-20): flag del TENANT (controla si
// se ofrecen los botones Facturar) y si la reserva todavía puede editar su
// propio facturacion_externa (sin ninguna venta asociada).
const facturacionHabilitadaTenant = ref(false);
const facturacionExternaEditable = ref(false);

const tab = ref<'pax' | 'items' | 'asignacion'>('pax');

// Pasajeros que todavía no fueron cubiertos por ningún comprobante —
// candidatos a incluir en la próxima pasada de facturación.
const pasajerosPendientesFacturar = computed(() =>
    (reserva.value?.pasajeros ?? []).filter((p) => !pasajerosFacturadosIds.value.includes(p.id))
);

// Las 3 condiciones del brief (§3.3), cada una independiente — sin lógica
// especial por caso: Facturar solo si el tenant tiene el flag Y no está
// marcada como externa; "Marcar facturación externa" siempre que no esté
// marcada y sea editable (cubre tanto tenant=false como la excepción con
// tenant=true); el banner de datos externos se muestra solo cuando
// facturacion_externa=true (ver template).
const mostrarBotonesFacturar = computed(() =>
    reserva.value?.estado === 'activa'
    && !reserva.value?.facturacion_externa
    && facturacionHabilitadaTenant.value
    && pasajerosPendientesFacturar.value.length > 0
);
const mostrarMarcarFacturacionExterna = computed(() =>
    reserva.value?.estado === 'activa'
    && !reserva.value?.facturacion_externa
    && facturacionExternaEditable.value
);

const cargarReserva = async () => {
    const res = await reservaService.obtener(reservaId);
    // El backend devuelve fecha como timestamp ISO completo (cast 'date' de
    // Eloquent, mismo patrón ya documentado para fecha_viaje_desde/hasta) —
    // <input type="date"> exige exactamente YYYY-MM-DD o queda vacío en
    // pantalla aunque el modelo sí tenga el valor. Antes de esta sesión
    // 'fecha' siempre nacía null y se tipeaba a mano (nunca disparaba el
    // problema); ahora que ReservaController la auto-completa, truncar acá
    // es necesario para que se vea.
    res.reserva.items?.forEach((it) => {
        if (it.fecha) it.fecha = it.fecha.substring(0, 10);
    });
    reserva.value = res.reserva;
    resumen.value = res.resumen;
    total.value = res.total;
    moneda.value = res.moneda;
    cabecera.value = res.cabecera;
    itemsPendientesSincronizar.value = res.items_pendientes_sincronizar ?? [];
    itemsFacturadosIds.value = res.items_facturados_ids ?? [];
    pasajerosFacturadosIds.value = res.pasajeros_facturados_ids ?? [];
    facturacionHabilitadaTenant.value = res.facturacion_habilitada_tenant ?? false;
    facturacionExternaEditable.value = res.facturacion_externa_editable ?? false;
};

const sincronizarItems = async () => {
    sincronizando.value = true;
    try {
        const res = await reservaService.sincronizarItems(reservaId);
        res.reserva.items?.forEach((it) => {
            if (it.fecha) it.fecha = it.fecha.substring(0, 10);
        });
        reserva.value = res.reserva;
        resumen.value = res.resumen;
        total.value = res.total;
        itemsPendientesSincronizar.value = res.items_pendientes_sincronizar ?? [];
        toast.success(res.message);
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo sincronizar');
    } finally {
        sincronizando.value = false;
    }
};

const iniciales = (nombre?: string | null) => (nombre || '?').split(' ').map((x) => x[0]).slice(0, 2).join('').toUpperCase();
const etiquetaTipoPax = (t?: string | null) => t === 'adulto' ? 'Adulto' : t === 'nino' ? 'Niño' : t === 'infante' ? 'Infante' : '—';
const nombrePasajero = (p: ReservaPasajero) => p.nombre || `${etiquetaTipoPax(p.tipo_pax)} #${p.id} (sin datos todavía)`;

// ── Pasajeros ────────────────────────────────────────────────────────
const esPasajeroCompleto = (p: ReservaPasajero) => !!(p.nombre && p.documento);
const pasajerosCompletos = computed(() => (reserva.value?.pasajeros ?? []).filter(esPasajeroCompleto).length);
const pasajerosIncompletos = computed(() => (reserva.value?.pasajeros?.length ?? 0) - pasajerosCompletos.value);
const pctPax = computed(() => {
    const totalPax = reserva.value?.pasajeros?.length ?? 0;
    return totalPax ? Math.round((pasajerosCompletos.value / totalPax) * 100) : 0;
});

const formPaxAbierto = ref<number | null>(null);
const toggleFormPax = (id: number) => { formPaxAbierto.value = formPaxAbierto.value === id ? null : id; };

const catalogoSearch = ref<Record<number, string>>({});
const catalogoResultados = ref<Record<number, PasajeroCatalogo[]>>({});
const buscandoDni = ref<number | null>(null); // id del pax en búsqueda RENIEC (spinner del campo unificado)
const ultimoDniAutoBuscado = ref<Record<number, string>>({}); // evita repetir la misma consulta RENIEC en cada tecla
let catalogoTimeout: any = null;

// Un solo campo de búsqueda flexible (DNI o nombre, mismo criterio que el
// usuario pidió: "muchas veces no recuerdo el DNI pero sí el nombre o
// viceversa"). Primero filtra el catálogo interno; si no hay match Y el
// texto es un DNI completo (8 dígitos), cae en cascada a RENIEC sin botón
// ni modal — no se puede buscar por nombre en RENIEC, solo por documento.
const onBuscarCatalogo = (paxId: number) => {
    clearTimeout(catalogoTimeout);
    const texto = (catalogoSearch.value[paxId] ?? '').trim();
    if (texto.length < 2) { catalogoResultados.value[paxId] = []; return; }
    catalogoTimeout = setTimeout(async () => {
        const res = await reservaPasajeroService.buscarCatalogo(texto);
        catalogoResultados.value[paxId] = res.pasajeros_catalogo;

        const esDniCompleto = /^\d{8}$/.test(texto);
        if (res.pasajeros_catalogo.length === 0 && esDniCompleto && ultimoDniAutoBuscado.value[paxId] !== texto) {
            ultimoDniAutoBuscado.value[paxId] = texto;
            await buscarDniEnReniec(paxId, texto);
        }
    }, 250); // debounce, mismo criterio que el buscador de cliente en Ventas
};

const autocompletarDesdeCatalogo = (p: ReservaPasajero, c: PasajeroCatalogo) => {
    p.nombre = c.nombre;
    p.nacionalidad = c.nacionalidad ?? p.nacionalidad;
    p.documento = c.documentos?.[0]?.numero_documento ?? p.documento;
    p.pasajero_catalogo_id = c.id;
    catalogoResultados.value[p.id] = [];
    catalogoSearch.value[p.id] = '';
    toast.success(`Datos de ${c.nombre} autocompletados desde su perfil`);
};

// ── Fallback automático a RENIEC (vía apisperu) cuando el DNI no está en el catálogo interno ──
const buscarDniEnReniec = async (paxId: number, dni: string) => {
    const p = reserva.value?.pasajeros?.find((x) => x.id === paxId);
    if (!p) return;
    buscandoDni.value = paxId;
    try {
        const res = await httpClient.get(`/search-document/dni/${dni}`);
        const nombres = res.data.success === false
            ? ''
            : `${res.data.nombres ?? ''} ${res.data.apellidoPaterno ?? ''} ${res.data.apellidoMaterno ?? ''}`.trim();
        if (nombres) {
            p.nombre = nombres;
            p.documento = dni;
            catalogoSearch.value[paxId] = '';
            catalogoResultados.value[paxId] = [];
            toast.success(`${nombres} encontrado en RENIEC y autocompletado`);
        } else {
            // DNI válido (8 dígitos) pero sin datos en RENIEC — no es un error del
            // sistema, avisa para que se complete a mano en vez de quedar en silencio.
            toast.warning(`DNI ${dni} no encontrado en RENIEC — completa el nombre manualmente`);
        }
    } catch (error) {
        // fallo de red/API — sí silencioso, es una consulta automática de fondo mientras el usuario escribe
    } finally {
        buscandoDni.value = null;
    }
};

const guardandoPax = ref<number | null>(null);
const guardarPax = async (p: ReservaPasajero) => {
    guardandoPax.value = p.id;
    try {
        const res = await reservaPasajeroService.actualizar(p.id, {
            nombre: p.nombre, documento: p.documento, nacionalidad: p.nacionalidad,
            alimentacion_especial: p.alimentacion_especial, discapacidad: p.discapacidad,
            vuelo_aerolinea_ida: p.vuelo_aerolinea_ida, vuelo_fecha_ida: p.vuelo_fecha_ida, vuelo_hora_ida: p.vuelo_hora_ida,
            vuelo_aerolinea_vuelta: p.vuelo_aerolinea_vuelta, vuelo_fecha_vuelta: p.vuelo_fecha_vuelta, vuelo_hora_vuelta: p.vuelo_hora_vuelta,
            pasajero_catalogo_id: p.pasajero_catalogo_id,
        });
        Object.assign(p, res.reserva_pasajero);
        toast.success(esPasajeroCompleto(p) ? `${p.nombre} guardado — datos completos` : 'Guardado, pero faltan datos obligatorios');
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo guardar el pasajero');
    } finally {
        guardandoPax.value = null;
    }
};

// ── Ítems ────────────────────────────────────────────────────────────
const bibliotecaTarifas = ref<ProveedorTarifa[]>([]);
const guias = ref<Guia[]>([]);

const proveedorBuscando = ref<number | null>(null); // id del reserva_item en modo búsqueda
const proveedorSearch = ref<Record<number, string>>({});

const nombreProveedorActual = (it: ReservaItem) => {
    const t = bibliotecaTarifas.value.find((x) => x.id === it.proveedor_tarifa_id);
    return t?.proveedor_servicio?.proveedor?.razon_social ?? null;
};

const proveedoresFiltrados = (it: ReservaItem) => {
    const servicioId = it.alternativa_item?.proveedor_tarifa?.proveedor_servicio?.destino_servicio?.servicio_id;
    const q = (proveedorSearch.value[it.id] ?? '').trim().toLowerCase();

    return bibliotecaTarifas.value
        .filter((t) => !servicioId || t.proveedor_servicio?.destino_servicio?.servicio_id === servicioId)
        .filter((t) => !q || (t.proveedor_servicio?.proveedor?.razon_social ?? '').toLowerCase().includes(q))
        .slice(0, 30);
};

const elegirProveedor = (it: ReservaItem, proveedorTarifaId: number | null) => {
    it.proveedor_tarifa_id = proveedorTarifaId;
    guardarItem(it);
    proveedorBuscando.value = null;
};

// @mousedown.prevent en los botones de la lista dispara la selección
// ANTES de este blur — si el blur cerrara la lista sin el delay, el
// mousedown nunca llegaría a disparar (el blur gana la carrera).
const cerrarBusquedaProveedor = (it: ReservaItem) => {
    setTimeout(() => { if (proveedorBuscando.value === it.id) proveedorBuscando.value = null; }, 200);
};

const nombreItem = (it: ReservaItem) => {
    const item = it.alternativa_item;
    if (!item) return 'Servicio';
    if (item.origen_tipo === 'manual') return item.descripcion_manual ?? 'Ítem manual';
    if (item.origen_tipo === 'pasaje_aereo') return item.cotizacion_pasaje_aereo?.aerolinea ?? 'Pasaje aéreo';
    if (item.origen_tipo === 'mayorista') return item.opcion_mayorista?.proveedor?.razon_social ?? 'Paquete mayorista';
    if (item.proveedor_tarifa?.tipo_habitacion) {
        const proveedor = item.proveedor_tarifa.proveedor_servicio?.proveedor?.razon_social ?? 'Hotel';
        return `${proveedor} · ${item.proveedor_tarifa.tipo_habitacion}`;
    }
    return item.proveedor_tarifa?.proveedor_servicio?.destino_servicio?.servicio?.nombre ?? 'Servicio';
};

const destinoItem = (it: ReservaItem) => it.alternativa_item?.proveedor_tarifa?.proveedor_servicio?.destino_servicio?.destino_atractivo?.nombre ?? null;

// Sesión pendiente-11e-groundwork — Proveedor solo aplica a origen_tipo
// 'proveedor' (servicios normales + hoteles), Guía solo a 'guia' (el
// guía ya es un ítem real con costo propio, no un campo suelto).
// Manual/pasaje_aereo/mayorista no tienen ningún campo de asignación
// operativa hoy — el layout de Fecha/Hora se ajusta cuando no aplica
// ninguno de los dos.
const tieneAsignacionAplicable = (it: ReservaItem) =>
    it.alternativa_item?.origen_tipo === 'proveedor' || it.alternativa_item?.origen_tipo === 'guia';

const itemsAsignables = computed(() => (reserva.value?.items ?? []).filter(tieneAsignacionAplicable));
const itemsAsignados = computed(() => itemsAsignables.value.filter((it) =>
    it.alternativa_item?.origen_tipo === 'guia' ? !!it.guia_id : !!it.proveedor_tarifa_id
).length);
const itemsSinAsignar = computed(() => itemsAsignables.value.length - itemsAsignados.value);
const pctItems = computed(() => {
    const total = itemsAsignables.value.length;
    return total ? Math.round((itemsAsignados.value / total) * 100) : 0;
});

const guardarItem = async (it: ReservaItem) => {
    try {
        await reservaItemService.actualizar(it.id, {
            guia_id: it.guia_id ?? null,
            proveedor_tarifa_id: it.proveedor_tarifa_id ?? null,
            fecha: it.fecha ?? null,
            hora: it.hora ?? null,
        });
        toast.success('Ítem actualizado');
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo actualizar el ítem');
    }
};

// ── Asignación pasajero↔ítem ────────────────────────────────────────
const asignacionesPorItem = ref<Record<number, Array<{ id: number; reserva_pasajero_id: number }>>>({});

const cambiarAAsignacion = async () => {
    tab.value = 'asignacion';
    for (const it of reserva.value?.items ?? []) {
        const res = await reservaItemService.listarPasajerosAsignados(it.id);
        asignacionesPorItem.value[it.id] = res.reserva_item_pasajeros.map((a) => ({ id: a.id, reserva_pasajero_id: a.reserva_pasajero_id }));
    }
};

const estaAsignado = (itemId: number, pasajeroId: number) => (asignacionesPorItem.value[itemId] ?? []).some((a) => a.reserva_pasajero_id === pasajeroId);

const toggleAsignacion = async (it: ReservaItem, p: ReservaPasajero) => {
    const asignacion = (asignacionesPorItem.value[it.id] ?? []).find((a) => a.reserva_pasajero_id === p.id);
    try {
        if (asignacion) {
            await reservaItemService.quitarPasajero(asignacion.id);
        } else {
            await reservaItemService.asignarPasajero(it.id, p.id);
        }
        const res = await reservaItemService.listarPasajerosAsignados(it.id);
        asignacionesPorItem.value[it.id] = res.reserva_item_pasajeros.map((a) => ({ id: a.id, reserva_pasajero_id: a.reserva_pasajero_id }));
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo actualizar la asignación');
    }
};

// ── Cancelación ──────────────────────────────────────────────────────
const mostrarModalCancelar = ref(false);
const motivoCancelacion = ref<MotivoCancelacion>('voluntaria');
const cancelando = ref(false);

const confirmarCancelacion = async () => {
    if (!reserva.value) return;
    cancelando.value = true;
    try {
        await reservaService.cancelar(reserva.value.id, motivoCancelacion.value);
        mostrarModalCancelar.value = false;
        await cargarReserva();
        toast.success('Reserva cancelada correctamente');
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo cancelar la reserva');
    } finally {
        cancelando.value = false;
    }
};

// ── Reprogramar (Fase 2 del fix Cotización↔Reserva, 2026-08-19) ────────
const mostrarModalReprogramar = ref(false);
const reprogramando = ref(false);
const reprogramarForm = ref<{ fecha_viaje_desde: string; fecha_viaje_hasta: string; motivo: string }>({
    fecha_viaje_desde: '', fecha_viaje_hasta: '', motivo: '',
});

const abrirModalReprogramar = () => {
    reprogramarForm.value = {
        fecha_viaje_desde: cabecera.value?.fecha_viaje_desde ? cabecera.value.fecha_viaje_desde.slice(0, 10) : '',
        fecha_viaje_hasta: cabecera.value?.fecha_viaje_hasta ? cabecera.value.fecha_viaje_hasta.slice(0, 10) : '',
        motivo: '',
    };
    mostrarModalReprogramar.value = true;
};

const confirmarReprogramacion = async () => {
    if (!reserva.value) return;
    reprogramando.value = true;
    try {
        const res = await reservaService.reprogramar(reserva.value.id, {
            fecha_viaje_desde: reprogramarForm.value.fecha_viaje_desde,
            fecha_viaje_hasta: reprogramarForm.value.fecha_viaje_hasta || null,
            motivo: reprogramarForm.value.motivo,
        });
        res.reserva.items?.forEach((it) => {
            if (it.fecha) it.fecha = it.fecha.substring(0, 10);
        });
        reserva.value = res.reserva;
        resumen.value = res.resumen;
        total.value = res.total;
        cabecera.value = res.cabecera;
        itemsNoTocadosReprogramacion.value = res.items_no_tocados ?? [];
        mostrarModalReprogramar.value = false;
        toast.success(res.message);
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo reprogramar la reserva');
    } finally {
        reprogramando.value = false;
    }
};

// ── Facturar (Fase A del plan "Proceso de reserva: facturación + 3 fixes",
// 2026-08-19; facturación múltiple por grupo de pasajeros, 2026-08-20) ──
// Ya no hay un solo Sale por reserva: el vendedor elige a qué pasajeros
// factura en esta pasada (cada pasajero termina en un único comprobante),
// con su propio cliente/texto, y puede repetir el proceso para el resto
// sin cerrar el modal.
const mostrarModalFacturarEspecial = ref(false);
const facturando = ref(false);
const facturarEspecialForm = ref<{
    pasajero_ids: number[];
    reserva_item_ids_manual: number[];
    tipo_comprobante_codigo: '01' | '03';
    texto_personalizado: string;
}>({
    pasajero_ids: [], reserva_item_ids_manual: [], tipo_comprobante_codigo: '01', texto_personalizado: '',
});

// Guardia tributario (2026-08-20): antes de dejar confirmar, se consulta
// GET preparar-factura con los pasajeros/ítems marcados. Si el subgrupo
// mezcla tratamientos tributarios distintos (ej. exonerado Amazonía +
// gravado nacional), no se puede emitir un solo comprobante — se avisa
// acá, ANTES de llenar todo el formulario. El guardia real (el que de
// verdad protege) vive en el backend (POST facturar también lo revisa) —
// esto es solo para que el usuario no pierda tiempo ni se lleve una
// sorpresa al confirmar.
const previewFacturaEspecial = ref<PrepararFacturaResponse | null>(null);
const cargandoPreviewFacturaEspecial = ref(false);

const refrescarPreviewFacturaEspecial = async () => {
    if (!reserva.value || facturarEspecialForm.value.pasajero_ids.length === 0) {
        previewFacturaEspecial.value = null;
        return;
    }
    cargandoPreviewFacturaEspecial.value = true;
    try {
        previewFacturaEspecial.value = await reservaFacturacionService.prepararFactura(
            reserva.value.id,
            facturarEspecialForm.value.pasajero_ids,
            facturarEspecialForm.value.reserva_item_ids_manual
        );
        // Sugerencia de cliente (no vinculante): si el backend detectó que
        // el único pasajero seleccionado ya tiene perfil de cliente
        // propio, se lo ofrece como punto de partida — el vendedor puede
        // cambiarlo con el buscador igual.
        if (previewFacturaEspecial.value?.cliente_sugerido && !clienteSeleccionado.value) {
            clienteSeleccionado.value = previewFacturaEspecial.value.cliente_sugerido;
        }
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo calcular la vista previa de la factura');
        previewFacturaEspecial.value = null;
    } finally {
        cargandoPreviewFacturaEspecial.value = false;
    }
};

watch(() => [...facturarEspecialForm.value.pasajero_ids, ...facturarEspecialForm.value.reserva_item_ids_manual], () => {
    if (mostrarModalFacturarEspecial.value) refrescarPreviewFacturaEspecial();
});

// ── Buscador de cliente (mismo endpoint que usa el formulario de Ventas,
// `clients?search=`) — simplificado: solo busca y elige entre clientes ya
// existentes, no crea uno nuevo (si no existe, se crea desde la pantalla
// de Clientes primero). ──
type ClienteBusqueda = { id: number; full_name: string; n_document: string };
const clienteSeleccionado = ref<ClienteBusqueda | null>(null);
const busquedaCliente = ref('');
const resultadosCliente = ref<ClienteBusqueda[]>([]);
let clienteSearchTimeout: ReturnType<typeof setTimeout> | undefined;

const onBuscarCliente = () => {
    clearTimeout(clienteSearchTimeout);
    const query = busquedaCliente.value.trim();
    if (query.length < 2) {
        resultadosCliente.value = [];
        return;
    }
    clienteSearchTimeout = setTimeout(async () => {
        try {
            const res = await httpClient.get(`clients?search=${encodeURIComponent(query)}&take=10`);
            resultadosCliente.value = res.data.clients.data;
        } catch {
            resultadosCliente.value = [];
        }
    }, 300);
};

const seleccionarCliente = (c: ClienteBusqueda) => {
    clienteSeleccionado.value = c;
    resultadosCliente.value = [];
    busquedaCliente.value = '';
};

const abrirModalFacturarEspecial = () => {
    facturarEspecialForm.value = {
        pasajero_ids: pasajerosPendientesFacturar.value.map((p) => p.id),
        reserva_item_ids_manual: [],
        tipo_comprobante_codigo: '01',
        texto_personalizado: '',
    };
    previewFacturaEspecial.value = null;
    clienteSeleccionado.value = null;
    busquedaCliente.value = '';
    resultadosCliente.value = [];
    mostrarModalFacturarEspecial.value = true;
    refrescarPreviewFacturaEspecial();
};

const confirmarFacturacionEspecial = async () => {
    if (!reserva.value || !clienteSeleccionado.value) return;
    facturando.value = true;
    try {
        const res = await reservaFacturacionService.facturar(reserva.value.id, {
            pasajero_ids: facturarEspecialForm.value.pasajero_ids,
            reserva_item_ids_manual: facturarEspecialForm.value.reserva_item_ids_manual,
            tipo_comprobante_codigo: facturarEspecialForm.value.tipo_comprobante_codigo,
            client_id: clienteSeleccionado.value.id,
            texto_personalizado: facturarEspecialForm.value.texto_personalizado || null,
        });
        toast.success(res.message);
        // Refresca la reserva completa (marca pasajeros/ítems como
        // facturados) — la venta creada se gestiona desde su propia
        // pantalla (cobrar, enviar a SUNAT), no acá.
        await cargarReserva();
        // Iterativo: si todavía quedan pasajeros pendientes, el modal
        // sigue abierto listo para la próxima pasada; si no queda
        // ninguno, se cierra solo — evita que el vendedor tenga que
        // cerrar/reabrir para facturar al resto del grupo.
        if (pasajerosPendientesFacturar.value.length > 0) {
            facturarEspecialForm.value = {
                pasajero_ids: [], reserva_item_ids_manual: [], tipo_comprobante_codigo: '01', texto_personalizado: '',
            };
            previewFacturaEspecial.value = null;
            clienteSeleccionado.value = null;
        } else {
            mostrarModalFacturarEspecial.value = false;
        }
    } catch (error: any) {
        // El guardia tributario también puede rechazar acá (422,
        // bloqueado_tributario) si el preview no llegó a correr a tiempo
        // — mismo mensaje, nunca deja crear la venta a medias.
        toast.error(error.response?.data?.message ?? 'No se pudo facturar la reserva');
    } finally {
        facturando.value = false;
    }
};

// ── Facturar simple (2026-08-20) — camino de todos los días: un solo
// responsable de pago, cubre a TODOS los pasajeros pendientes (sin
// selección manual) usando el cliente de la cotización. Mismo backend
// que Facturación especial: arma el payload automáticamente en vez de
// pedírselo al vendedor.
const mostrarModalFacturarSimple = ref(false);
const cargandoFacturarSimple = ref(false);
const previewFacturaSimple = ref<PrepararFacturaResponse | null>(null);
const facturarSimpleForm = ref<{ tipo_comprobante_codigo: '01' | '03' }>({ tipo_comprobante_codigo: '01' });

const abrirModalFacturarSimple = async () => {
    if (!reserva.value) return;
    previewFacturaSimple.value = null;
    facturarSimpleForm.value = { tipo_comprobante_codigo: '01' };
    mostrarModalFacturarSimple.value = true;
    cargandoFacturarSimple.value = true;
    try {
        const pasajeroIds = pasajerosPendientesFacturar.value.map((p) => p.id);
        // Primer preview: descubre qué ítems sin asignar hay disponibles.
        const primerPreview = await reservaFacturacionService.prepararFactura(reserva.value.id, pasajeroIds, []);
        const idsSinAsignar = (primerPreview.items_sin_asignar_disponibles ?? []).map((it) => it.reserva_item_id);
        // Segundo preview: los agrega todos, para que el total refleje
        // realmente "toda la reserva pendiente" — el modo simple no
        // pregunta cuáles, se asume que todos van en este único
        // comprobante.
        previewFacturaSimple.value = idsSinAsignar.length > 0
            ? await reservaFacturacionService.prepararFactura(reserva.value.id, pasajeroIds, idsSinAsignar)
            : primerPreview;
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo calcular la vista previa de la factura');
        mostrarModalFacturarSimple.value = false;
    } finally {
        cargandoFacturarSimple.value = false;
    }
};

const confirmarFacturacionSimple = async () => {
    if (!reserva.value || !cabecera.value?.cliente?.id) return;
    facturando.value = true;
    try {
        const pasajeroIds = pasajerosPendientesFacturar.value.map((p) => p.id);
        const idsSinAsignar = (previewFacturaSimple.value?.items_sin_asignar_disponibles ?? []).map((it) => it.reserva_item_id);
        const res = await reservaFacturacionService.facturar(reserva.value.id, {
            pasajero_ids: pasajeroIds,
            reserva_item_ids_manual: idsSinAsignar,
            tipo_comprobante_codigo: facturarSimpleForm.value.tipo_comprobante_codigo,
            client_id: cabecera.value.cliente.id,
        });
        mostrarModalFacturarSimple.value = false;
        toast.success(res.message);
        await cargarReserva();
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo facturar la reserva');
    } finally {
        facturando.value = false;
    }
};

// ── Facturación externa por tenant + por reserva (2026-08-20) — marcar/
// desmarcar/editar la anotación de "esta reserva se factura afuera de la
// plataforma". Editable solo mientras facturacionExternaEditable (sin
// ninguna venta asociada, ver ReservaController::actualizarFacturacionExterna()).
const mostrarModalFacturacionExterna = ref(false);
const guardandoFacturacionExterna = ref(false);
const formFacturacionExterna = ref<{ referencia_externa: string; fecha_facturacion_externa: string }>({
    referencia_externa: '',
    fecha_facturacion_externa: '',
});

const abrirModalFacturacionExterna = () => {
    formFacturacionExterna.value = {
        referencia_externa: reserva.value?.referencia_externa ?? '',
        fecha_facturacion_externa: reserva.value?.fecha_facturacion_externa?.substring(0, 10) ?? '',
    };
    mostrarModalFacturacionExterna.value = true;
};

const guardarFacturacionExterna = async (marcar: boolean) => {
    guardandoFacturacionExterna.value = true;
    try {
        const res = await reservaService.actualizarFacturacionExterna(reservaId, {
            facturacion_externa: marcar,
            referencia_externa: marcar ? (formFacturacionExterna.value.referencia_externa || null) : null,
            fecha_facturacion_externa: marcar ? (formFacturacionExterna.value.fecha_facturacion_externa || null) : null,
        });
        toast.success(res.message);
        mostrarModalFacturacionExterna.value = false;
        await cargarReserva();
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo actualizar la facturación externa');
    } finally {
        guardandoFacturacionExterna.value = false;
    }
};

onMounted(async () => {
    await cargarReserva();
    const [bib, gs] = await Promise.all([proveedorService.biblioteca(), guiaService.listar({ page: 1 })]);
    bibliotecaTarifas.value = bib.proveedor_tarifas;
    guias.value = gs.guias ?? [];
});
</script>
