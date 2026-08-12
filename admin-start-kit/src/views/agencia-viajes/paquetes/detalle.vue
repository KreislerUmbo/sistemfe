<template>
    <DefaultLayout>
        <div v-if="cargandoPagina" class="text-center py-5">
            <span class="spinner-border text-primary"></span>
        </div>

        <template v-else>
        <div v-if="paquete" class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas me-2 text-primary" :class="esCombo ? 'fa-layers' : 'fa-suitcase-rolling'"></i>
                    {{ paquete.nombre }}
                    <span class="badge ms-2" :class="esCombo ? 'bg-primary-subtle text-primary border border-primary-subtle' : 'bg-info-subtle text-info border border-info-subtle'">
                        {{ esCombo ? 'Paquete combo' : 'Tour simple' }}
                    </span>
                    <span v-if="!paquete.activo" class="badge bg-secondary-subtle text-secondary border ms-1">Inactivo</span>
                </h5>
                <small class="text-muted">{{ paquete.codigo ?? 'sin código' }} · {{ etiquetaCategoria(paquete.categoria) }}</small>
            </div>
            <div class="d-flex gap-2">
                <button class="btn" :class="paquete.activo ? 'btn-outline-danger' : 'btn-outline-success'" @click="toggleActivo" :disabled="cambiandoEstado">
                    <span v-if="cambiandoEstado" class="spinner-border spinner-border-sm me-2"></span>
                    <i v-else class="fas me-2" :class="paquete.activo ? 'fa-ban' : 'fa-check-circle'"></i>{{ paquete.activo ? 'Desactivar' : 'Activar' }}
                </button>
                <router-link :to="`/agencia-viajes/paquetes/${paquete.id}/editar`" class="btn btn-outline-secondary">
                    <i class="fas fa-pen me-2"></i>Editar
                </router-link>
                <button class="btn btn-outline-secondary" @click="duplicar" :disabled="duplicando">
                    <span v-if="duplicando" class="spinner-border spinner-border-sm me-2"></span>
                    <i v-else class="fas fa-copy me-2"></i>Duplicar
                </button>
                <router-link to="/agencia-viajes/paquetes" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Volver
                </router-link>
            </div>
        </div>

        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <a class="nav-link" :class="{ active: tabActiva === 'datos' }" href="#" @click.prevent="tabActiva = 'datos'">
                    <i class="fas fa-id-card me-1"></i>Datos
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" :class="{ active: tabActiva === 'itinerario' }" href="#" @click.prevent="tabActiva = 'itinerario'">
                    <i class="fas fa-route me-1"></i>Itinerario
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" :class="{ active: tabActiva === 'incluye' }" href="#" @click.prevent="tabActiva = 'incluye'">
                    <i class="fas fa-list-check me-1"></i>Incluye
                </a>
            </li>
        </ul>

        <!-- ═══ TAB: Datos ═══ -->
        <div v-if="tabActiva === 'datos' && paquete" class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="row g-3 small">
                    <div class="col-md-4"><strong>Destino:</strong> {{ paquete.destino_atractivo?.nombre ?? '—' }}</div>
                    <div class="col-md-4"><strong>Duración:</strong> {{ paquete.duracion_horas }} h</div>
                    <div class="col-md-4"><strong>Horario:</strong> {{ paquete.hora_salida ?? '—' }} — {{ paquete.hora_retorno ?? '—' }}</div>
                    <div class="col-md-8"><strong>Lugar de recojo:</strong> {{ paquete.lugar_recojo ?? '—' }}</div>
                    <div class="col-md-4" v-if="!esCombo"><strong>Precio desde:</strong> {{ paquete.precio_venta_final != null ? `S/ ${Number(paquete.precio_venta_final).toFixed(2)}` : '—' }}</div>
                    <!-- descripcion/no_incluye/recomendaciones vienen en HTML (RichTextEditor,
                         ver paquetes/form.vue) — v-html a propósito, el contenido solo lo
                         genera este mismo editor, no llega de terceros. -->
                    <!-- TODO Sesión 11k: cuando este tab pase a editable in-situ, estos 3
                         campos deben usar RichTextEditor.vue (no volver a un <textarea>). -->
                    <div class="col-12" v-if="paquete.descripcion"><strong>Descripción:</strong> <span v-html="paquete.descripcion"></span></div>
                    <div class="col-md-6" v-if="paquete.no_incluye"><strong>No incluye:</strong> <span v-html="paquete.no_incluye"></span></div>
                    <div class="col-md-6" v-if="paquete.recomendaciones"><strong>Recomendaciones:</strong> <span v-html="paquete.recomendaciones"></span></div>
                    <div class="col-12" v-if="paquete.vuelo_incluido">
                        <strong>Vuelo:</strong> {{ paquete.vuelo_aerolinea ?? '—' }} — {{ paquete.vuelo_detalle ?? '' }}
                    </div>
                    <div class="col-md-4"><strong>Vigencia:</strong> {{ paquete.vigencia_desde ? formatFecha(paquete.vigencia_desde) : 'sin inicio' }} — {{ paquete.vigencia_hasta ? formatFecha(paquete.vigencia_hasta) : 'indefinida' }}</div>
                    <div class="col-md-4"><strong>Publicado web:</strong> {{ paquete.publicado_web ? 'Sí' : 'No' }}</div>
                </div>
            </div>
        </div>

        <!-- ═══ Precio del combo (solo paquete_combo) ═══ -->
        <div v-if="tabActiva === 'datos' && esCombo && combo" class="card border-0 shadow-sm mt-3">
            <div class="card-header bg-white border-bottom py-2">
                <span class="fw-semibold text-dark small"><i class="fas fa-coins text-primary me-1"></i>Precio del combo</span>
            </div>
            <div class="card-body">
                <div v-if="combo.precio_calculado.componentes_inactivos.length" class="alert alert-warning small mb-3">
                    <i class="fas fa-triangle-exclamation me-1"></i>
                    {{ combo.precio_calculado.componentes_inactivos.length }} tour(s) incluido(s) están desactivados y no se cuentan en este total:
                    {{ combo.precio_calculado.componentes_inactivos.map(c => c.nombre).join(', ') }}
                </div>
                <div v-if="combo.precio_calculado.componentes_sin_incluye.length" class="alert alert-warning small mb-3">
                    <i class="fas fa-triangle-exclamation me-1"></i>
                    {{ combo.precio_calculado.componentes_sin_incluye.length }} tour(s) sin costo cargado (Incluye vacío) — no van a sumar precio ni aparecer en las cotizaciones:
                    {{ combo.precio_calculado.componentes_sin_incluye.map(c => c.nombre).join(', ') }}
                </div>
                <div v-if="combo.precio_calculado.componentes_sin_itinerario.length" class="alert alert-warning small mb-3">
                    <i class="fas fa-triangle-exclamation me-1"></i>
                    {{ combo.precio_calculado.componentes_sin_itinerario.length }} tour(s) sin itinerario cargado — el día va a aparecer sin descripción en el PDF/cotización:
                    {{ combo.precio_calculado.componentes_sin_itinerario.map(c => c.nombre).join(', ') }}
                </div>

                <div class="row text-center g-3 mb-4">
                    <div class="col-4">
                        <div class="small text-muted mb-1">Costo total</div>
                        <div class="fs-4 fw-semibold text-dark">S/ {{ preview.costoTotal.toFixed(2) }}</div>
                    </div>
                    <div class="col-4">
                        <div class="small text-muted mb-1">Venta bruta</div>
                        <div class="fs-4 fw-semibold text-dark">S/ {{ preview.ventaBruta.toFixed(2) }}</div>
                    </div>
                    <div class="col-4">
                        <div class="small text-muted mb-1">Venta neta</div>
                        <div class="fs-2 fw-bold text-primary">S/ {{ preview.ventaNeta.toFixed(2) }}</div>
                    </div>
                </div>

                <div class="row g-3 align-items-end">
                    <div class="col-12 col-md-4">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Tipo de descuento</label>
                        <div class="btn-group btn-group-sm d-flex">
                            <button type="button" class="btn" :class="descuentoTipoLocal === 'porcentaje' ? 'btn-primary' : 'btn-outline-secondary'" @click="descuentoTipoLocal = 'porcentaje'">%</button>
                            <button type="button" class="btn" :class="descuentoTipoLocal === 'monto' ? 'btn-primary' : 'btn-outline-secondary'" @click="descuentoTipoLocal = 'monto'">S/ fijo</button>
                            <button type="button" class="btn" :class="!descuentoTipoLocal ? 'btn-primary' : 'btn-outline-secondary'" @click="descuentoTipoLocal = null; descuentoValorLocal = null; guardarPrecioCombo()">Sin descuento</button>
                        </div>
                    </div>
                    <div class="col-6 col-md-3" v-if="descuentoTipoLocal">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Valor del descuento</label>
                        <input type="number" step="0.01" min="0" class="form-control form-control-sm" v-model.number="descuentoValorLocal" @blur="guardarPrecioCombo">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Margen mínimo protegido (%)</label>
                        <input type="number" step="0.01" min="0" class="form-control form-control-sm" v-model.number="margenMinimoLocal" @blur="guardarPrecioCombo" placeholder="Opcional">
                    </div>
                    <div class="col-12 col-md-2">
                        <button class="btn btn-primary btn-sm w-100" @click="guardarPrecioCombo" :disabled="guardandoPrecio">
                            <span v-if="guardandoPrecio" class="spinner-border spinner-border-sm me-1"></span>Guardar
                        </button>
                    </div>
                </div>

                <div class="mt-3 small" v-if="preview.margenResultante !== null">
                    Margen resultante:
                    <strong :class="margenOk ? 'text-success' : 'text-danger'">{{ preview.margenResultante.toFixed(2) }}%</strong>
                    <span v-if="margenMinimoLocal != null" class="text-muted"> (mínimo configurado: {{ Number(margenMinimoLocal).toFixed(2) }}%)</span>
                    <i class="fas ms-1" :class="margenOk ? 'fa-circle-check text-success' : 'fa-circle-exclamation text-danger'"></i>
                </div>
            </div>
        </div>

        <!-- ═══ TAB: Itinerario ═══ -->
        <div v-if="tabActiva === 'itinerario' && !esCombo">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2">
                    <span class="fw-semibold text-dark small">Agregar paso</span>
                </div>
                <div class="card-body py-3">
                    <div class="row g-2 align-items-end">
                        <div class="col-6 col-md-2">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Día</label>
                            <input type="number" min="1" class="form-control form-control-sm" v-model.number="formPaso.dia_relativo">
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Hora</label>
                            <input type="time" class="form-control form-control-sm" v-model="formPaso.hora">
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Orden</label>
                            <input type="number" min="0" class="form-control form-control-sm" v-model.number="formPaso.orden" placeholder="si no hay hora">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Destino/Atractivo</label>
                            <DestinoTreeSelect v-model="formPaso.destino_atractivo_id" placeholder="Opcional..." />
                        </div>
                    </div>
                    <div class="row g-2 align-items-end mt-1">
                        <div class="col-12">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Descripción *</label>
                            <textarea rows="2" class="form-control form-control-sm" v-model="formPaso.descripcion" placeholder="Ej. Visita orquideario"></textarea>
                        </div>
                    </div>
                    <button class="btn btn-primary btn-sm mt-2" @click="agregarPaso" :disabled="agregandoPaso">
                        <span v-if="agregandoPaso" class="spinner-border spinner-border-sm me-1"></span>
                        <i v-else class="fas fa-plus me-1"></i>Agregar paso
                    </button>
                </div>
            </div>

            <div v-for="(pasos, dia) in itinerarioPorDia" :key="dia" class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white border-bottom small fw-semibold">Día {{ dia }}</div>
                <ul class="list-group list-group-flush" :data-dia="dia" :ref="(el) => setItinerarioDiaRef(Number(dia), el)">
                    <li v-for="paso in pasos" :key="paso.id" class="list-group-item small" :data-paso-id="paso.id">
                        <div v-if="pasoEnEdicion?.id !== paso.id" class="d-flex justify-content-between align-items-center">
                            <span>
                                <i class="fas fa-grip-vertical grip text-muted me-2" style="cursor:grab"></i>
                                <span v-if="paso.hora" class="badge bg-light text-dark border me-2">{{ paso.hora }}</span>
                                <span v-else-if="paso.orden != null" class="badge bg-light text-dark border me-2">#{{ paso.orden }}</span>
                                {{ paso.descripcion }}
                                <span v-if="paso.destino_atractivo" class="text-muted"> — {{ paso.destino_atractivo.nombre }}</span>
                            </span>
                            <span class="d-flex align-items-center gap-3">
                                <i class="fas fa-pen text-secondary" style="cursor:pointer" @click="iniciarEdicionPaso(paso)"></i>
                                <span v-if="eliminandoPasoId === paso.id" class="spinner-border spinner-border-sm text-danger"></span>
                                <i v-else class="fas fa-trash text-danger" style="cursor:pointer" @click="quitarPaso(paso)"></i>
                            </span>
                        </div>
                        <div v-else class="py-1">
                            <div class="row g-2 align-items-end">
                                <div class="col-6 col-md-2">
                                    <label class="form-label mb-1 small fw-semibold text-secondary">Día</label>
                                    <input type="number" min="1" class="form-control form-control-sm" v-model.number="pasoEnEdicion.dia_relativo">
                                </div>
                                <div class="col-6 col-md-2">
                                    <label class="form-label mb-1 small fw-semibold text-secondary">Hora</label>
                                    <input type="time" class="form-control form-control-sm" v-model="pasoEnEdicion.hora">
                                </div>
                                <div class="col-6 col-md-2">
                                    <label class="form-label mb-1 small fw-semibold text-secondary">Orden</label>
                                    <input type="number" min="0" class="form-control form-control-sm" v-model.number="pasoEnEdicion.orden">
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label mb-1 small fw-semibold text-secondary">Destino/Atractivo</label>
                                    <DestinoTreeSelect v-model="pasoEnEdicion.destino_atractivo_id" placeholder="Opcional..." />
                                </div>
                            </div>
                            <div class="row g-2 align-items-end mt-1">
                                <div class="col-12">
                                    <label class="form-label mb-1 small fw-semibold text-secondary">Descripción *</label>
                                    <textarea rows="2" class="form-control form-control-sm" v-model="pasoEnEdicion.descripcion"></textarea>
                                </div>
                            </div>
                            <div class="mt-2 d-flex gap-2">
                                <button class="btn btn-primary btn-sm" @click="guardarEdicionPaso" :disabled="guardandoEdicionPaso">
                                    <span v-if="guardandoEdicionPaso" class="spinner-border spinner-border-sm me-1"></span>Guardar
                                </button>
                                <button class="btn btn-outline-secondary btn-sm" @click="cancelarEdicionPaso" :disabled="guardandoEdicionPaso">Cancelar</button>
                            </div>
                        </div>
                    </li>
                </ul>
            </div>
            <div v-if="itinerario.length === 0" class="text-muted fst-italic text-center py-4">
                Este paquete/tour todavía no tiene itinerario cargado.
            </div>
        </div>

        <!-- ═══ TAB: Itinerario (combo, derivado, solo lectura) ═══ -->
        <div v-if="tabActiva === 'itinerario' && esCombo">
            <div class="alert alert-info small mb-3">
                <i class="fas fa-circle-info me-1"></i>
                Este itinerario se arma automáticamente según los tours incluidos en "Incluye" — para modificarlo, editá el tour correspondiente o cambiá el orden de los tours en el combo.
            </div>

            <div v-for="(pasos, dia) in itinerarioDerivadoPorDia" :key="dia" class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white border-bottom py-2">
                    <span class="fw-semibold text-dark small">Día {{ dia }}</span>
                    <span class="text-muted small"> — {{ pasos[0]?.tour_origen_nombre }}</span>
                </div>
                <ul class="list-group list-group-flush">
                    <li v-for="(paso, idx) in pasos" :key="idx" class="list-group-item small">
                        <span v-if="paso.hora" class="badge bg-light text-dark border me-2">{{ paso.hora }}</span>
                        <span v-else-if="paso.orden != null" class="badge bg-light text-dark border me-2">#{{ paso.orden }}</span>
                        {{ paso.descripcion }}
                    </li>
                </ul>
            </div>
            <div v-if="(combo?.itinerario_derivado.length ?? 0) === 0" class="text-muted fst-italic text-center py-4">
                Ninguno de los tours incluidos tiene itinerario cargado todavía.
            </div>
        </div>

        <!-- ═══ TAB: Incluye (tour_simple) ═══ -->
        <div v-if="tabActiva === 'incluye' && !esCombo">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white border-bottom py-2">
                    <span class="fw-semibold text-dark small">Agregar ítem incluido</span>
                </div>
                <div class="card-body py-3">
                    <div class="btn-group btn-group-sm mb-2">
                        <button class="btn" :class="tipoItemNuevo === 'proveedor' ? 'btn-primary' : 'btn-outline-secondary'" @click="tipoItemNuevo = 'proveedor'">Servicio de proveedor</button>
                        <button class="btn" :class="tipoItemNuevo === 'guia' ? 'btn-primary' : 'btn-outline-secondary'" @click="tipoItemNuevo = 'guia'">Guía de turismo</button>
                    </div>

                    <div v-if="tipoItemNuevo === 'proveedor'">
                        <input type="text" class="form-control form-control-sm mb-2" placeholder="Buscar servicio de proveedor..."
                            v-model="bibliotecaSearch" @input="onBibliotecaSearch">
                        <div class="row g-2 mb-2">
                            <div class="col-12 col-md-4">
                                <DestinoTreeSelect v-model="filtroDestinoId" nivel-max="lugar" placeholder="Zona o destino..." />
                            </div>
                            <div class="col-6 col-md-4">
                                <select class="form-select form-select-sm" v-model="filtroServicioId">
                                    <option :value="null">Cualquier servicio</option>
                                    <option v-for="s in serviciosFiltro" :key="s.id" :value="s.id">{{ s.nombre }}</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-4">
                                <select class="form-select form-select-sm" v-model="filtroProveedorId">
                                    <option :value="null">Cualquier proveedor</option>
                                    <option v-for="p in proveedoresFiltro" :key="p.id" :value="p.id">{{ p.nombre_comercial ?? p.razon_social }}</option>
                                </select>
                            </div>
                        </div>
                        <div class="d-flex flex-column gap-1" style="max-height:220px;overflow-y:auto;">
                            <div v-for="t in bibliotecaTarifas" :key="t.id" class="border rounded p-2 small lib-item"
                                :class="{ 'border-primary bg-light': proveedorTarifaSeleccionada?.id === t.id, 'opacity-50': idsProveedorTarifaEnItems.has(t.id) }"
                                :style="idsProveedorTarifaEnItems.has(t.id) ? 'cursor:not-allowed' : 'cursor:pointer'"
                                @click="!idsProveedorTarifaEnItems.has(t.id) && (proveedorTarifaSeleccionada = t)">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <strong>{{ t.proveedor_servicio?.proveedor?.razon_social }}</strong>
                                        <span v-if="idsProveedorTarifaEnItems.has(t.id)" class="badge bg-secondary text-white ms-1" style="font-size:10px">Ya agregado</span>
                                        <span v-if="t.proveedor_servicio?.proveedor?.es_referencial" class="badge bg-secondary-subtle text-secondary border ms-1" style="font-size:10px">Referencial</span>
                                        <span class="text-muted"> — {{ descripcionDestinoServicio(t.proveedor_servicio?.destino_servicio) }}<span v-if="t.tipo_habitacion"> · {{ t.tipo_habitacion }}</span></span>
                                        <span class="badge bg-light text-dark border ms-1" style="font-size:10px">{{ t.tipo_tarifa }} · {{ t.modalidad }}</span>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-light text-dark border d-block">{{ t.moneda }} {{ Number(t.precio_venta_adulto).toFixed(2) }}</span>
                                        <span class="text-muted" style="font-size:10px">costo {{ t.moneda }} {{ Number(t.precio_costo).toFixed(2) }}</span>
                                    </div>
                                </div>
                            </div>
                            <div v-if="bibliotecaTarifas.length === 0" class="text-muted small text-center py-2">Sin resultados.</div>
                        </div>
                    </div>

                    <div v-else>
                        <select class="form-select form-select-sm mb-2" v-model="guiaSeleccionadaId" @change="cargarTarifasGuia">
                            <option :value="null">— Elegí un guía —</option>
                            <option v-for="g in guias" :key="g.id" :value="g.id">{{ g.nombre }}{{ g.es_referencial ? ' (Referencial)' : '' }}</option>
                        </select>
                        <div class="d-flex flex-column gap-1" style="max-height:220px;overflow-y:auto;">
                            <div v-for="t in tarifasGuia" :key="t.id" class="border rounded p-2 small lib-item"
                                :class="{ 'border-primary bg-light': guiaTarifaSeleccionada?.id === t.id, 'opacity-50': idsGuiaTarifaEnItems.has(t.id) }"
                                :style="idsGuiaTarifaEnItems.has(t.id) ? 'cursor:not-allowed' : 'cursor:pointer'"
                                @click="!idsGuiaTarifaEnItems.has(t.id) && (guiaTarifaSeleccionada = t)">
                                {{ t.destino?.nombre }} — {{ t.modalidad === 'dia_local' ? 'Día local' : 'Grupo multidía' }} ({{ t.moneda }} {{ t.costo_diario }})
                                <span v-if="idsGuiaTarifaEnItems.has(t.id)" class="badge bg-secondary text-white ms-1" style="font-size:10px">Ya agregado</span>
                            </div>
                            <div v-if="guiaSeleccionadaId && tarifasGuia.length === 0" class="text-muted small text-center py-2">Este guía no tiene tarifas cargadas.</div>
                        </div>
                    </div>

                    <div class="row g-2 align-items-end mt-2">
                        <div class="col-6 col-md-3">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Orden</label>
                            <input type="number" min="0" class="form-control form-control-sm" v-model.number="ordenItemNuevo">
                        </div>
                        <div class="col-6 col-md-3">
                            <button class="btn btn-primary btn-sm w-100" @click="agregarItem" :disabled="agregandoItem || (!proveedorTarifaSeleccionada && !guiaTarifaSeleccionada)">
                                <span v-if="agregandoItem" class="spinner-border spinner-border-sm me-1"></span>
                                <i v-else class="fas fa-plus me-1"></i>Agregar
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══ Resumen (zona separada de la búsqueda) ═══ -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom py-2">
                    <span class="fw-semibold text-dark small"><i class="fas fa-list-check text-primary me-1"></i>Ítems incluidos</span>
                </div>
                <ul class="list-group list-group-flush">
                    <li v-for="item in items" :key="item.id" class="list-group-item d-flex justify-content-between align-items-center small">
                        <span v-if="item.proveedor_tarifa">
                            <i class="fas fa-concierge-bell text-primary me-1"></i>
                            {{ item.proveedor_tarifa.proveedor_servicio?.proveedor?.razon_social }}
                            <span v-if="item.proveedor_tarifa.proveedor_servicio?.proveedor?.es_referencial" class="badge bg-secondary-subtle text-secondary border ms-1" style="font-size:10px">Referencial</span>
                            <span class="text-muted"> — {{ descripcionDestinoServicio(item.proveedor_tarifa.proveedor_servicio?.destino_servicio) }}<span v-if="item.proveedor_tarifa.tipo_habitacion"> · {{ item.proveedor_tarifa.tipo_habitacion }}</span></span>
                            <span class="badge bg-light text-dark border ms-1" style="font-size:10px">{{ item.proveedor_tarifa.tipo_tarifa }} · {{ item.proveedor_tarifa.modalidad }}</span>
                        </span>
                        <span v-else-if="item.guia_tarifa">
                            <i class="fas fa-user-tie text-primary me-1"></i>
                            Guía: {{ item.guia_tarifa.guia?.nombre }}
                            <span v-if="item.guia_tarifa.guia?.es_referencial" class="badge bg-secondary-subtle text-secondary border ms-1" style="font-size:10px">Referencial</span>
                            <span class="text-muted"> — {{ item.guia_tarifa.destino?.nombre }}</span>
                        </span>
                        <span class="d-flex align-items-center gap-3">
                            <span class="text-end">
                                <span class="badge bg-light text-dark border d-block">{{ monedaItem(item) }} {{ ventaItem(item).toFixed(2) }}</span>
                                <span class="text-muted" style="font-size:10px">costo {{ monedaItem(item) }} {{ costoItem(item).toFixed(2) }}</span>
                            </span>
                            <span v-if="eliminandoItemId === item.id" class="spinner-border spinner-border-sm text-danger"></span>
                            <i v-else class="fas fa-times text-danger" style="cursor:pointer" @click="quitarItem(item)"></i>
                        </span>
                    </li>
                    <li v-if="items.length === 0" class="list-group-item text-muted fst-italic text-center py-4">
                        Este paquete/tour todavía no tiene ítems incluidos.
                    </li>
                </ul>
            </div>

            <div class="card border-0 shadow-sm mt-3" v-if="items.length">
                <div class="card-header bg-white border-bottom py-2">
                    <span class="fw-semibold text-dark small"><i class="fas fa-coins text-primary me-1"></i>Totales</span>
                </div>
                <div class="card-body">
                    <div class="row text-center g-3">
                        <div class="col-4">
                            <div class="small text-muted mb-1">Costo total</div>
                            <div class="fs-5 fw-semibold text-dark">S/ {{ totalesIncluye.costoTotal.toFixed(2) }}</div>
                        </div>
                        <div class="col-4">
                            <div class="small text-muted mb-1">Venta total</div>
                            <div class="fs-5 fw-semibold text-dark">S/ {{ totalesIncluye.ventaTotal.toFixed(2) }}</div>
                        </div>
                        <div class="col-4">
                            <div class="small text-muted mb-1">Margen resultante</div>
                            <div class="fs-5 fw-bold" :class="totalesIncluye.margenResultantePct >= margenMinimoAceptablePct ? 'text-success' : 'text-danger'">
                                {{ totalesIncluye.margenResultantePct.toFixed(1) }}%
                            </div>
                        </div>
                    </div>
                    <div v-if="desglosePorCategoria.length" class="row g-2 mt-1 border-top pt-3">
                        <div class="col-6 col-md-3" v-for="cat in desglosePorCategoria" :key="cat.categoria">
                            <div class="small text-muted mb-1">{{ cat.categoria }}</div>
                            <div class="small fw-semibold text-dark">S/ {{ cat.venta.toFixed(2) }} <span class="text-muted fw-normal">(costo {{ cat.costo.toFixed(2) }})</span></div>
                        </div>
                    </div>
                    <div v-if="diferenciaVentaFinal !== null" class="alert alert-warning small mt-3 mb-0">
                        <i class="fas fa-triangle-exclamation me-1"></i>
                        El "Precio venta (desde)" del tour (S/ {{ Number(paquete!.precio_venta_final).toFixed(2) }})
                        no coincide con la suma de los ítems (S/ {{ totalesIncluye.ventaTotal.toFixed(2) }}).
                        Diferencia: S/ {{ diferenciaVentaFinal.toFixed(2) }}.
                        Si es intencional (descuento de paquete), podés ignorar este aviso.
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ TAB: Incluye (paquete_combo) ═══ -->
        <div v-if="tabActiva === 'incluye' && esCombo">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white border-bottom py-2">
                    <span class="fw-semibold text-dark small">Agregar ítem incluido</span>
                </div>
                <div class="card-body py-3">
                    <div class="btn-group btn-group-sm mb-2">
                        <button class="btn" :class="tipoItemNuevo === 'tour' ? 'btn-primary' : 'btn-outline-secondary'" @click="tipoItemNuevo = 'tour'; cargarBibliotecaTours()">Tour completo</button>
                        <button class="btn" :class="tipoItemNuevo === 'proveedor' ? 'btn-primary' : 'btn-outline-secondary'" @click="tipoItemNuevo = 'proveedor'">Servicio de proveedor</button>
                        <button class="btn" :class="tipoItemNuevo === 'guia' ? 'btn-primary' : 'btn-outline-secondary'" @click="tipoItemNuevo = 'guia'">Guía de turismo</button>
                    </div>

                    <div v-if="tipoItemNuevo === 'tour'">
                        <input type="text" class="form-control form-control-sm mb-2" placeholder="Buscar tour por nombre o código..."
                            v-model="bibliotecaTourSearch" @input="onBibliotecaTourSearch">
                        <div class="d-flex flex-column gap-1" style="max-height:280px;overflow-y:auto;">
                            <div v-for="t in bibliotecaTours" :key="t.id" class="border rounded p-2 small lib-item"
                                :class="{ 'border-primary bg-light': tourSeleccionado?.id === t.id, 'opacity-50': idsTourHijoEnItems.has(t.id) }"
                                :style="idsTourHijoEnItems.has(t.id) ? 'cursor:not-allowed' : 'cursor:pointer'"
                                @click="!idsTourHijoEnItems.has(t.id) && (tourSeleccionado = t)">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <strong>{{ t.nombre }}</strong>
                                        <span v-if="idsTourHijoEnItems.has(t.id)" class="badge bg-secondary text-white ms-1" style="font-size:10px">Ya agregado</span>
                                        <span v-if="t.codigo" class="text-muted"> · {{ t.codigo }}</span>
                                        <div class="text-muted">{{ etiquetaCategoria(t.categoria) }} · {{ tourItemCounts[t.id] ?? 0 }} ítem(s) incluido(s)</div>
                                    </div>
                                    <span class="badge bg-light text-dark border">{{ t.precio_venta_final != null ? `S/ ${Number(t.precio_venta_final).toFixed(0)}` : '—' }}</span>
                                </div>
                            </div>
                            <div v-if="bibliotecaTours.length === 0" class="text-muted small text-center py-2">Sin resultados.</div>
                        </div>
                    </div>

                    <div v-else-if="tipoItemNuevo === 'proveedor'">
                        <input type="text" class="form-control form-control-sm mb-2" placeholder="Buscar servicio de proveedor..."
                            v-model="bibliotecaSearch" @input="onBibliotecaSearch">
                        <div class="row g-2 mb-2">
                            <div class="col-12 col-md-4">
                                <DestinoTreeSelect v-model="filtroDestinoId" nivel-max="lugar" placeholder="Zona o destino..." />
                            </div>
                            <div class="col-6 col-md-4">
                                <select class="form-select form-select-sm" v-model="filtroServicioId">
                                    <option :value="null">Cualquier servicio</option>
                                    <option v-for="s in serviciosFiltro" :key="s.id" :value="s.id">{{ s.nombre }}</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-4">
                                <select class="form-select form-select-sm" v-model="filtroProveedorId">
                                    <option :value="null">Cualquier proveedor</option>
                                    <option v-for="p in proveedoresFiltro" :key="p.id" :value="p.id">{{ p.nombre_comercial ?? p.razon_social }}</option>
                                </select>
                            </div>
                        </div>
                        <div class="d-flex flex-column gap-1" style="max-height:220px;overflow-y:auto;">
                            <div v-for="t in bibliotecaTarifas" :key="t.id" class="border rounded p-2 small lib-item"
                                :class="{ 'border-primary bg-light': proveedorTarifaSeleccionada?.id === t.id, 'opacity-50': idsProveedorTarifaEnItems.has(t.id) }"
                                :style="idsProveedorTarifaEnItems.has(t.id) ? 'cursor:not-allowed' : 'cursor:pointer'"
                                @click="!idsProveedorTarifaEnItems.has(t.id) && (proveedorTarifaSeleccionada = t)">
                                <strong>{{ t.proveedor_servicio?.proveedor?.razon_social }}</strong>
                                <span v-if="idsProveedorTarifaEnItems.has(t.id)" class="badge bg-secondary text-white ms-1" style="font-size:10px">Ya agregado</span>
                                <span v-if="t.proveedor_servicio?.proveedor?.es_referencial" class="badge bg-secondary-subtle text-secondary border ms-1" style="font-size:10px">Referencial</span>
                                <span class="text-muted"> — {{ t.proveedor_servicio?.destino_servicio?.servicio?.nombre }}<span v-if="t.tipo_habitacion"> · {{ t.tipo_habitacion }}</span></span>
                            </div>
                            <div v-if="bibliotecaTarifas.length === 0" class="text-muted small text-center py-2">Sin resultados.</div>
                        </div>
                    </div>

                    <div v-else>
                        <select class="form-select form-select-sm mb-2" v-model="guiaSeleccionadaId" @change="cargarTarifasGuia">
                            <option :value="null">— Elegí un guía —</option>
                            <option v-for="g in guias" :key="g.id" :value="g.id">{{ g.nombre }}{{ g.es_referencial ? ' (Referencial)' : '' }}</option>
                        </select>
                        <div class="d-flex flex-column gap-1" style="max-height:220px;overflow-y:auto;">
                            <div v-for="t in tarifasGuia" :key="t.id" class="border rounded p-2 small lib-item"
                                :class="{ 'border-primary bg-light': guiaTarifaSeleccionada?.id === t.id, 'opacity-50': idsGuiaTarifaEnItems.has(t.id) }"
                                :style="idsGuiaTarifaEnItems.has(t.id) ? 'cursor:not-allowed' : 'cursor:pointer'"
                                @click="!idsGuiaTarifaEnItems.has(t.id) && (guiaTarifaSeleccionada = t)">
                                {{ t.destino?.nombre }} — {{ t.modalidad === 'dia_local' ? 'Día local' : 'Grupo multidía' }} ({{ t.moneda }} {{ t.costo_diario }})
                                <span v-if="idsGuiaTarifaEnItems.has(t.id)" class="badge bg-secondary text-white ms-1" style="font-size:10px">Ya agregado</span>
                            </div>
                            <div v-if="guiaSeleccionadaId && tarifasGuia.length === 0" class="text-muted small text-center py-2">Este guía no tiene tarifas cargadas.</div>
                        </div>
                    </div>

                    <div class="row g-2 align-items-end mt-2">
                        <div class="col-6 col-md-3">
                            <label class="form-label mb-1 small fw-semibold text-secondary">{{ tipoItemNuevo === 'tour' ? 'Día del combo' : 'Orden' }}</label>
                            <input type="number" min="0" class="form-control form-control-sm" v-model.number="ordenItemNuevo">
                        </div>
                        <div class="col-6 col-md-3">
                            <button class="btn btn-primary btn-sm w-100" @click="agregarItem" :disabled="agregandoItem || (!proveedorTarifaSeleccionada && !guiaTarifaSeleccionada && !tourSeleccionado)">
                                <span v-if="agregandoItem" class="spinner-border spinner-border-sm me-1"></span>
                                <i v-else class="fas fa-plus me-1"></i>Agregar
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tours incluidos, agrupados en acordeón -->
            <div v-for="grupo in itemsPorTourAgrupados" :key="grupo.item.id" class="card border-0 shadow-sm mb-2">
                <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-2" style="cursor:pointer" @click="toggleExpandido(grupo.item.paquete_plantilla_hijo_id!)">
                    <span class="fw-semibold text-dark small">
                        <i class="fas fa-chevron-right me-2 text-muted" :class="{ 'fa-rotate-90': expandidos.has(grupo.item.paquete_plantilla_hijo_id!) }" style="font-size:10px"></i>
                        Día {{ grupo.item.orden ?? '—' }}: {{ grupo.item.paquete_plantilla_hijo?.nombre }}
                    </span>
                    <span class="d-flex align-items-center gap-2">
                        <span class="badge bg-light text-dark border">{{ grupo.item.paquete_plantilla_hijo?.precio_venta_final != null ? `S/ ${Number(grupo.item.paquete_plantilla_hijo.precio_venta_final).toFixed(0)}` : '—' }}</span>
                        <span v-if="eliminandoItemId === grupo.item.id" class="spinner-border spinner-border-sm text-danger"></span>
                        <i v-else class="fas fa-times text-danger" style="cursor:pointer" @click.stop="quitarTourHijo(grupo.item)"></i>
                    </span>
                </div>
                <div v-if="expandidos.has(grupo.item.paquete_plantilla_hijo_id!)" class="card-body py-2">
                    <div class="text-muted small fst-italic mb-2">Solo lectura — para editar estos ítems, entrá al tour.</div>
                    <ul class="list-group list-group-flush">
                        <li v-for="sub in (tourSubItemsCache[grupo.item.paquete_plantilla_hijo_id!] ?? [])" :key="sub.id" class="list-group-item small px-0">
                            <span v-if="sub.proveedor_tarifa">
                                <i class="fas fa-concierge-bell text-primary me-1"></i>{{ sub.proveedor_tarifa.proveedor_servicio?.proveedor?.razon_social }}
                            </span>
                            <span v-else-if="sub.guia_tarifa">
                                <i class="fas fa-user-tie text-primary me-1"></i>Guía: {{ sub.guia_tarifa.guia?.nombre }}
                            </span>
                        </li>
                        <li v-if="(tourSubItemsCache[grupo.item.paquete_plantilla_hijo_id!] ?? []).length === 0" class="list-group-item small px-0 text-muted fst-italic">
                            Este tour todavía no tiene ítems cargados.
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Ítems sueltos (proveedor/guía directos sobre el combo) -->
            <div v-if="itemsSueltos.length" class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom py-2"><span class="fw-semibold text-dark small">Ítems sueltos</span></div>
                <ul class="list-group list-group-flush">
                    <li v-for="item in itemsSueltos" :key="item.id" class="list-group-item d-flex justify-content-between align-items-center small">
                        <span v-if="item.proveedor_tarifa">
                            <i class="fas fa-concierge-bell text-primary me-1"></i>
                            {{ item.proveedor_tarifa.proveedor_servicio?.proveedor?.razon_social }}
                            <span v-if="item.proveedor_tarifa.proveedor_servicio?.proveedor?.es_referencial" class="badge bg-secondary-subtle text-secondary border ms-1" style="font-size:10px">Referencial</span>
                        </span>
                        <span v-else-if="item.guia_tarifa">
                            <i class="fas fa-user-tie text-primary me-1"></i>Guía: {{ item.guia_tarifa.guia?.nombre }}
                            <span v-if="item.guia_tarifa.guia?.es_referencial" class="badge bg-secondary-subtle text-secondary border ms-1" style="font-size:10px">Referencial</span>
                        </span>
                        <span v-if="eliminandoItemId === item.id" class="spinner-border spinner-border-sm text-danger"></span>
                        <i v-else class="fas fa-times text-danger" style="cursor:pointer" @click="quitarItem(item)"></i>
                    </li>
                </ul>
            </div>

            <div v-if="itemsPorTourAgrupados.length === 0 && itemsSueltos.length === 0" class="text-muted fst-italic text-center py-4">
                Este combo todavía no tiene tours ni ítems incluidos.
            </div>
        </div>
        </template>
    </DefaultLayout>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, nextTick, watch } from 'vue';
import Sortable from 'sortablejs';
import { useRoute, useRouter } from 'vue-router';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import DestinoTreeSelect from '@/components/AgenciaViajes/DestinoTreeSelect.vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { paquetePlantillaService } from '@/services/admin/paquetePlantillaService';
import { proveedorService, proveedorTipoService } from '@/services/admin/proveedorService';
import { guiaService } from '@/services/admin/guiaService';
import { configuracionAgenciaService } from '@/services/admin/configuracionAgenciaService';
import { servicioService } from '@/services/admin/servicioService';
import { formatFecha } from '@/helpers/fecha';
import type {
    PaquetePlantilla, PaquetePlantillaItem, TourItinerarioItem,
    ProveedorTarifa, Proveedor, Guia, GuiaTarifa, ComboDatos, ComboItinerarioPaso, PaquetePlantillaResumen,
    DestinoServicio, ConfiguracionAgencia, Servicio, ProveedorTipo,
} from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const route = useRoute();
const router = useRouter();
const paqueteId = computed(() => Number(route.params.id));

const cargandoPagina = ref(true);
const paquete = ref<PaquetePlantilla | null>(null);
const combo = ref<ComboDatos | null>(null);
const esCombo = computed(() => paquete.value?.tipo === 'paquete_combo');
const tabActiva = ref<'datos' | 'itinerario' | 'incluye'>('datos');

const etiquetaCategoria = (c: string) => ({ local: 'Local', nacional: 'Nacional', internacional: 'Internacional' } as Record<string, string>)[c] ?? c;

// Fix 1 (pantalla "Incluye") — destino/atractivo antes del nombre del
// servicio, para diferenciar tarifas del mismo proveedor/servicio genérico
// que solo se distinguían por precio. `destino_atractivo` puede venir null
// (no todo servicio está atado a un destino) — en ese caso solo el nombre
// del servicio, sin romper el render.
const descripcionDestinoServicio = (ds?: DestinoServicio) => {
    const destino = ds?.destino_atractivo?.nombre;
    const servicio = ds?.servicio?.nombre ?? '';
    return destino ? `${destino} · ${servicio}` : servicio;
};

const cargarPaquete = async () => {
    const res = await paquetePlantillaService.obtener(paqueteId.value);
    paquete.value = res.paquete_plantilla;
    combo.value = res.combo;
    if (combo.value) {
        descuentoTipoLocal.value = paquete.value.descuento_tipo ?? null;
        descuentoValorLocal.value = paquete.value.descuento_valor ?? null;
        margenMinimoLocal.value = paquete.value.margen_minimo_pct ?? null;
    }
};

// ── Activar/Desactivar (punto 6 del diseño) ──────────────────────────
const cambiandoEstado = ref(false);

const toggleActivo = async () => {
    if (!paquete.value) return;
    cambiandoEstado.value = true;
    try {
        if (paquete.value.activo) {
            await desactivar(false);
        } else {
            try {
                await paquetePlantillaService.actualizar(paquete.value.id, { ...paquete.value, activo: true });
                await cargarPaquete();
            } catch (error: any) {
                (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo activar', 'error');
            }
        }
    } finally {
        cambiandoEstado.value = false;
    }
};

const desactivar = async (forzar: boolean) => {
    if (!paquete.value) return;
    try {
        await paquetePlantillaService.actualizar(paquete.value.id, { ...paquete.value, activo: false, forzar_desactivacion: forzar });
        await cargarPaquete();
        if (forzar) (Swal as TVueSwalInstance).fire('Listo', 'Tour desactivado. Los combos afectados excluyen su costo/venta del total.', 'success');
    } catch (error: any) {
        const combosAfectados: PaquetePlantillaResumen[] | undefined = error.response?.data?.combos_afectados;
        if (error.response?.status === 422 && combosAfectados?.length) {
            const lista = combosAfectados.map(c => `<li><a href="/agencia-viajes/paquetes/${c.id}" target="_blank">${c.nombre}${c.codigo ? ` (${c.codigo})` : ''}</a></li>`).join('');
            const result = await (Swal as TVueSwalInstance).fire({
                title: 'Este tour está incluido en combos activos',
                html: `<p class="text-start mb-1">No se puede desactivar en silencio — está incluido en:</p><ul class="text-start">${lista}</ul>`,
                icon: 'warning',
                showCancelButton: true,
                cancelButtonText: 'Cancelar',
                confirmButtonText: 'Desactivar de todos modos',
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                reverseButtons: true,
                focusCancel: true,
            } as any);
            if ((result as any).isConfirmed) {
                await desactivar(true);
            }
        } else {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo desactivar', 'error');
        }
    }
};

// ── Duplicar (Sesión 11m) ─────────────────────────────────────────────
const duplicando = ref(false);

const duplicar = async () => {
    if (!paquete.value) return;
    const result = await (Swal as TVueSwalInstance).fire({
        title: 'Confirmar duplicación',
        text: `¿Duplicar "${paquete.value.nombre}"? Se creará una copia inactiva para que la revises antes de publicarla.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Sí, duplicar',
    });
    if (!(result as any).isConfirmed) return;
    duplicando.value = true;
    try {
        const res = await paquetePlantillaService.duplicar(paquete.value.id);
        router.push(`/agencia-viajes/paquetes/${res.paquete_plantilla.id}`);
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo duplicar', 'error');
    } finally {
        duplicando.value = false;
    }
};

// ── Precio del combo: preview en vivo + guardado (punto 4 del diseño) ─
const descuentoTipoLocal = ref<'porcentaje' | 'monto' | null>(null);
const descuentoValorLocal = ref<number | null>(null);
const margenMinimoLocal = ref<number | null>(null);
const guardandoPrecio = ref(false);

// Réplica exacta de PriceEngineService::calcularCombo()/aplicarDescuento()
// (backend) — costo_total_combo/venta_bruta_combo no cambian con este
// formulario (dependen de los tours incluidos, no del descuento), así que
// alcanza con recalcular la parte de descuento/margen en el cliente, sin
// round-trip al server en cada tecla.
const preview = computed(() => {
    const costoTotal = combo.value?.precio_calculado.costo_total_combo ?? 0;
    const ventaBruta = combo.value?.precio_calculado.venta_bruta_combo ?? 0;

    let ventaNeta = ventaBruta;
    if (descuentoTipoLocal.value && descuentoValorLocal.value != null) {
        const descuento = descuentoTipoLocal.value === 'monto' ? descuentoValorLocal.value : ventaBruta * (descuentoValorLocal.value / 100);
        ventaNeta = Math.round((ventaBruta - descuento) * 100) / 100;
    }

    const margenResultante = costoTotal > 0 ? Math.round(((ventaNeta - costoTotal) / costoTotal) * 100 * 100) / 100 : null;

    return { costoTotal, ventaBruta, ventaNeta, margenResultante };
});

const margenOk = computed(() => {
    if (preview.value.margenResultante === null || margenMinimoLocal.value == null) return true;
    return preview.value.margenResultante >= margenMinimoLocal.value - 0.005;
});

const guardarPrecioCombo = async () => {
    if (!paquete.value) return;
    guardandoPrecio.value = true;
    try {
        await paquetePlantillaService.actualizar(paquete.value.id, {
            ...paquete.value,
            descuento_tipo: descuentoTipoLocal.value,
            descuento_valor: descuentoValorLocal.value,
            margen_minimo_pct: margenMinimoLocal.value,
        });
        await cargarPaquete();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo guardar el precio', 'error');
    } finally {
        guardandoPrecio.value = false;
    }
};

// ── Itinerario (tour_simple) ──────────────────────────────────────────
const itinerario = ref<TourItinerarioItem[]>([]);
const formPaso = ref<{ dia_relativo: number; hora: string | null; orden: number | null; destino_atractivo_id: number | null; descripcion: string }>({
    dia_relativo: 1, hora: null, orden: null, destino_atractivo_id: null, descripcion: '',
});

const itinerarioPorDia = computed(() => {
    const grupos: Record<number, TourItinerarioItem[]> = {};
    for (const paso of itinerario.value) {
        (grupos[paso.dia_relativo] ??= []).push(paso);
    }
    return grupos;
});

const cargarItinerario = async () => {
    if (esCombo.value) return;
    const res = await paquetePlantillaService.listarItinerario(paqueteId.value);
    itinerario.value = res.tour_itinerario_items;
    await nextTick();
    inicializarSortableItinerario();
};

const agregandoPaso = ref(false);

const agregarPaso = async () => {
    if (!formPaso.value.descripcion.trim()) {
        (Swal as TVueSwalInstance).fire('Error', 'La descripción del paso es obligatoria.', 'error');
        return;
    }
    agregandoPaso.value = true;
    try {
        await paquetePlantillaService.agregarPasoItinerario(paqueteId.value, formPaso.value);
        formPaso.value = { dia_relativo: formPaso.value.dia_relativo, hora: null, orden: null, destino_atractivo_id: null, descripcion: '' };
        await cargarItinerario();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo agregar', 'error');
    } finally {
        agregandoPaso.value = false;
    }
};

const eliminandoPasoId = ref<number | null>(null);

const quitarPaso = async (paso: TourItinerarioItem) => {
    const result = await (Swal as TVueSwalInstance).fire({
        title: 'Eliminar paso de itinerario',
        text: `¿Eliminar "${paso.descripcion}"?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar',
    });
    if (!(result as any).isConfirmed) return;
    eliminandoPasoId.value = paso.id;
    try {
        await paquetePlantillaService.quitarPasoItinerario(paso.id);
        await cargarItinerario();
    } finally {
        eliminandoPasoId.value = null;
    }
};

// ── Edición inline de un paso (Sesión 11l v2) ────────────────────────
const pasoEnEdicion = ref<(Omit<TourItinerarioItem, 'destino_atractivo_id'> & { destino_atractivo_id: number | null }) | null>(null);

const iniciarEdicionPaso = (paso: TourItinerarioItem) => {
    // El backend devuelve `hora` como HH:MM:SS (columna TIME de Postgres),
    // pero <input type="time"> sin `step` y el validador H:i del backend
    // solo aceptan HH:MM — si el usuario guarda sin tocar este campo, el
    // valor con segundos viaja intacto y el 422 revienta en un campo que
    // ni siquiera editó. Se normaliza acá, no en agregarPaso (ese arranca
    // vacío y solo recibe HH:MM directo del input).
    pasoEnEdicion.value = {
        ...paso,
        hora: paso.hora ? paso.hora.substring(0, 5) : null,
        destino_atractivo_id: paso.destino_atractivo_id ?? null,
    };
};

const cancelarEdicionPaso = () => {
    pasoEnEdicion.value = null;
};

const guardandoEdicionPaso = ref(false);

const guardarEdicionPaso = async () => {
    if (!pasoEnEdicion.value) return;
    if (!pasoEnEdicion.value.descripcion.trim()) {
        (Swal as TVueSwalInstance).fire('Error', 'La descripción del paso es obligatoria.', 'error');
        return;
    }
    guardandoEdicionPaso.value = true;
    try {
        await paquetePlantillaService.actualizarPasoItinerario(pasoEnEdicion.value.id, {
            dia_relativo: pasoEnEdicion.value.dia_relativo,
            hora: pasoEnEdicion.value.hora || null,
            orden: pasoEnEdicion.value.orden,
            destino_atractivo_id: pasoEnEdicion.value.destino_atractivo_id,
            descripcion: pasoEnEdicion.value.descripcion,
        });
        pasoEnEdicion.value = null;
        await cargarItinerario();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo guardar', 'error');
    } finally {
        guardandoEdicionPaso.value = false;
    }
};

// ── Drag&drop del itinerario (Sesión 11l v2) — un Sortable por día,
// mismo `group` para poder arrastrar un paso de un día a otro. Los
// refs se reconstruyen en cada carga (las claves de itinerarioPorDia
// cambian si se agrega/quita el último paso de un día), por eso se
// reinicializan explícitamente después de cada cargarItinerario().
const itinerarioDiaRefs = ref<Record<number, HTMLElement>>({});
let itinerarioSortables: Sortable[] = [];

const setItinerarioDiaRef = (dia: number, el: unknown) => {
    if (el instanceof HTMLElement) {
        itinerarioDiaRefs.value[dia] = el;
    } else {
        delete itinerarioDiaRefs.value[dia];
    }
};

const inicializarSortableItinerario = () => {
    itinerarioSortables.forEach((s) => s.destroy());
    itinerarioSortables = [];
    for (const el of Object.values(itinerarioDiaRefs.value)) {
        itinerarioSortables.push(Sortable.create(el, {
            group: 'itinerario',
            handle: '.grip',
            animation: 150,
            forceFallback: true,
            onEnd: onItinerarioDragEnd,
        }));
    }
};

const onItinerarioDragEnd = async (evt: Sortable.SortableEvent) => {
    const listasAfectadas = new Set([evt.from, evt.to].filter(Boolean) as HTMLElement[]);
    const itemsActualizados: { id: number; dia_relativo: number; orden: number }[] = [];
    for (const lista of listasAfectadas) {
        const dia = Number(lista.dataset.dia);
        Array.from(lista.children).forEach((li, idx) => {
            const id = Number((li as HTMLElement).dataset.pasoId);
            if (id) itemsActualizados.push({ id, dia_relativo: dia, orden: idx });
        });
    }
    try {
        await paquetePlantillaService.reordenarItinerario(paqueteId.value, itemsActualizados);
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo reordenar', 'error');
    }
    await cargarItinerario();
};

// ── Itinerario derivado (paquete_combo, solo lectura) ────────────────
const itinerarioDerivadoPorDia = computed(() => {
    const grupos: Record<number, ComboItinerarioPaso[]> = {};
    for (const paso of combo.value?.itinerario_derivado ?? []) {
        (grupos[paso.dia_relativo] ??= []).push(paso);
    }
    return grupos;
});

// ── Incluye (items) ──────────────────────────────────────────────────
const items = ref<PaquetePlantillaItem[]>([]);
const tipoItemNuevo = ref<'proveedor' | 'guia' | 'tour'>('proveedor');
const ordenItemNuevo = ref<number | null>(null);

const bibliotecaSearch = ref('');
const bibliotecaTarifas = ref<ProveedorTarifa[]>([]);
const proveedorTarifaSeleccionada = ref<ProveedorTarifa | null>(null);
let bibliotecaTimeout: any = null;

// Sesión 11l v2 — filtros de zona/servicio/proveedor de la biblioteca del
// tab Incluye, combinables entre sí y con bibliotecaSearch.
const filtroDestinoId = ref<number | null>(null);
const filtroServicioId = ref<number | null>(null);
const filtroProveedorId = ref<number | null>(null);
const serviciosFiltro = ref<Servicio[]>([]);
const proveedoresFiltro = ref<Proveedor[]>([]);

const onBibliotecaSearch = () => {
    clearTimeout(bibliotecaTimeout);
    bibliotecaTimeout = setTimeout(cargarBiblioteca, 300);
};

watch([filtroDestinoId, filtroServicioId, filtroProveedorId], onBibliotecaSearch);

const cargarBiblioteca = async () => {
    const res = await proveedorService.biblioteca({
        search: bibliotecaSearch.value || undefined,
        destino_atractivo_id: filtroDestinoId.value ?? undefined,
        servicio_id: filtroServicioId.value ?? undefined,
        proveedor_id: filtroProveedorId.value ?? undefined,
    });
    bibliotecaTarifas.value = res.proveedor_tarifas;
};

const guias = ref<Guia[]>([]);
const guiaSeleccionadaId = ref<number | null>(null);
const tarifasGuia = ref<GuiaTarifa[]>([]);
const guiaTarifaSeleccionada = ref<GuiaTarifa | null>(null);

const cargarTarifasGuia = async () => {
    guiaTarifaSeleccionada.value = null;
    tarifasGuia.value = [];
    if (!guiaSeleccionadaId.value) return;
    const res = await guiaService.obtener(guiaSeleccionadaId.value);
    tarifasGuia.value = res.guia.guia_tarifas ?? [];
};

// ── Biblioteca de tours (solo paquete_combo) ─────────────────────────
const bibliotecaTourSearch = ref('');
const bibliotecaTours = ref<PaquetePlantilla[]>([]);
const tourSeleccionado = ref<PaquetePlantilla | null>(null);
// Cantidad de ítems por tour — el listado GET /paquetes-plantilla no la trae
// (no se tocó el backend en esta sesión, es frontend-only), se resuelve acá
// con una llamada liviana por tour visible, reusando el endpoint existente.
const tourItemCounts = ref<Record<number, number>>({});
let bibliotecaTourTimeout: any = null;

const onBibliotecaTourSearch = () => {
    clearTimeout(bibliotecaTourTimeout);
    bibliotecaTourTimeout = setTimeout(cargarBibliotecaTours, 300);
};

const cargarBibliotecaTours = async () => {
    const res = await paquetePlantillaService.listar({ tipo: 'tour_simple', activo: true, search: bibliotecaTourSearch.value || undefined });
    bibliotecaTours.value = (res.paquetes_plantilla as PaquetePlantilla[]).filter(t => t.id !== paqueteId.value);
    for (const t of bibliotecaTours.value) {
        if (tourItemCounts.value[t.id] !== undefined) continue;
        paquetePlantillaService.listarItems(t.id).then(r => {
            tourItemCounts.value[t.id] = r.paquete_plantilla_items.length;
        });
    }
};

const cargarItems = async () => {
    const res = await paquetePlantillaService.listarItems(paqueteId.value);
    items.value = res.paquete_plantilla_items;
};

// Sesión 11n — sets de ids ya incluidos en items.value, recalculados
// automáticamente (computed) cada vez que items cambia (agregar/quitar).
// Usados para deshabilitar en la biblioteca lo que ya está agregado a
// ESTE paquete/tour — el backend valida por paquete_plantilla_id, no
// global, así que esto no debe cruzarse con otros tours/combos.
const idsProveedorTarifaEnItems = computed(() =>
    new Set(items.value.map(i => i.proveedor_tarifa_id).filter((id): id is number => id != null))
);
const idsGuiaTarifaEnItems = computed(() =>
    new Set(items.value.map(i => i.guia_tarifa_id).filter((id): id is number => id != null))
);
const idsTourHijoEnItems = computed(() =>
    new Set(items.value.map(i => i.paquete_plantilla_hijo_id).filter((id): id is number => id != null))
);

// ── Totales de "Incluye" (tour_simple) — costo/venta/margen, en vivo ──
// Fix 3 — margen mínimo aceptable configurable por agencia (antes
// hardcodeado acá), mismo patrón que proveedores/detalle.vue y
// cotizador/editar.vue. Fallback de 20 solo mientras la config está en
// vuelo (no bloquea el render).
const configAgencia = ref<ConfiguracionAgencia | null>(null);
const margenMinimoAceptablePct = computed(() => configAgencia.value?.margen_minimo_aceptable_pct ?? 20);

const ventaGuiaTarifa = (gt: GuiaTarifa): number =>
    gt.tipo_margen === 'porcentaje' ? gt.costo_diario * (1 + gt.margen_valor / 100) : gt.costo_diario + gt.margen_valor;

const costoItem = (item: PaquetePlantillaItem): number => {
    if (item.proveedor_tarifa) return Number(item.proveedor_tarifa.precio_costo);
    if (item.guia_tarifa) return Number(item.guia_tarifa.costo_diario);
    return 0;
};
const ventaItem = (item: PaquetePlantillaItem): number => {
    if (item.proveedor_tarifa) return Number(item.proveedor_tarifa.precio_venta_adulto);
    if (item.guia_tarifa) return ventaGuiaTarifa(item.guia_tarifa);
    return 0;
};
const monedaItem = (item: PaquetePlantillaItem): string =>
    item.proveedor_tarifa?.moneda ?? item.guia_tarifa?.moneda ?? 'PEN';

const totalesIncluye = computed(() => {
    const costoTotal = items.value.reduce((acc, item) => acc + costoItem(item), 0);
    const ventaTotal = items.value.reduce((acc, item) => acc + ventaItem(item), 0);
    const margenResultantePct = costoTotal > 0 ? ((ventaTotal - costoTotal) / costoTotal) * 100 : 0;
    return { costoTotal, ventaTotal, margenResultantePct };
});

// Desglose por categoría (Sesión 11l v2) — mismo catálogo proveedor_tipos
// ya cargado para "usar tarifa registrada" de hoteles (ver onMounted).
// Guía no tiene proveedor_tipo (no es un ProveedorServicio), por eso usa
// una categoría fija en vez de resolverla contra el catálogo.
const proveedorTipos = ref<ProveedorTipo[]>([]);

const categoriaItem = (item: PaquetePlantillaItem): string => {
    if (item.guia_tarifa) return 'Guía';
    const tipoProveedorId = item.proveedor_tarifa?.proveedor_servicio?.destino_servicio?.servicio?.tipo_proveedor_id;
    const tipo = proveedorTipos.value.find((t) => t.id === tipoProveedorId);
    return tipo?.nombre ?? 'Otros';
};

const desglosePorCategoria = computed(() => {
    const acumulado: Record<string, { categoria: string; costo: number; venta: number }> = {};
    for (const item of items.value) {
        const categoria = categoriaItem(item);
        const fila = (acumulado[categoria] ??= { categoria, costo: 0, venta: 0 });
        fila.costo += costoItem(item);
        fila.venta += ventaItem(item);
    }
    return Object.values(acumulado);
});

// Fix 4 — advertencia (no bloqueo) cuando el "Precio venta (desde)" manual
// del tab "Datos" diverge de la suma calculada de ítems del tab "Incluye".
// Puede ser intencional (descuento de paquete) — solo informa.
const diferenciaVentaFinal = computed(() => {
    if (!paquete.value || paquete.value.precio_venta_final == null) return null;
    const diff = Number(paquete.value.precio_venta_final) - totalesIncluye.value.ventaTotal;
    return Math.abs(diff) < 0.01 ? null : diff;
});

const agregandoItem = ref(false);

const agregarItem = async () => {
    agregandoItem.value = true;
    try {
        await paquetePlantillaService.agregarItem(paqueteId.value, {
            proveedor_tarifa_id: proveedorTarifaSeleccionada.value?.id ?? undefined,
            guia_tarifa_id: guiaTarifaSeleccionada.value?.id ?? undefined,
            paquete_plantilla_hijo_id: tourSeleccionado.value?.id ?? undefined,
            orden: ordenItemNuevo.value ?? undefined,
        });
        proveedorTarifaSeleccionada.value = null;
        guiaTarifaSeleccionada.value = null;
        tourSeleccionado.value = null;
        ordenItemNuevo.value = null;
        await cargarItems();
        await cargarPaquete();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo agregar', 'error');
    } finally {
        agregandoItem.value = false;
    }
};

const eliminandoItemId = ref<number | null>(null);

const quitarItem = async (item: PaquetePlantillaItem) => {
    eliminandoItemId.value = item.id;
    try {
        await paquetePlantillaService.quitarItem(item.id);
        await cargarItems();
        await cargarPaquete();
    } finally {
        eliminandoItemId.value = null;
    }
};

const quitarTourHijo = async (item: PaquetePlantillaItem) => {
    const result = await (Swal as TVueSwalInstance).fire({
        title: 'Quitar tour del combo',
        text: `Se quita "${item.paquete_plantilla_hijo?.nombre}" y TODOS sus ítems del combo al explotarse después. ¿Continuar?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Sí, quitar',
    });
    if (!(result as any).isConfirmed) return;
    await quitarItem(item);
};

// Agrupación visual por tour incluido (acordeón) + ítems sueltos —
// punto 2 del diseño.
const itemsPorTourAgrupados = computed(() => {
    return items.value
        .filter(i => i.paquete_plantilla_hijo_id != null)
        .sort((a, b) => (a.orden ?? 0) - (b.orden ?? 0))
        .map(item => ({ item }));
});

const itemsSueltos = computed(() => items.value.filter(i => i.paquete_plantilla_hijo_id == null));

const expandidos = ref<Set<number>>(new Set());
const tourSubItemsCache = ref<Record<number, PaquetePlantillaItem[]>>({});

const toggleExpandido = async (tourHijoId: number) => {
    if (expandidos.value.has(tourHijoId)) {
        expandidos.value.delete(tourHijoId);
        return;
    }
    expandidos.value.add(tourHijoId);
    if (!tourSubItemsCache.value[tourHijoId]) {
        const res = await paquetePlantillaService.listarItems(tourHijoId);
        tourSubItemsCache.value[tourHijoId] = res.paquete_plantilla_items;
    }
};

onMounted(async () => {
    try {
        // Fix 3 — carga la config de agencia en paralelo, no bloquea la carga
        // del paquete (el margen mínimo tiene fallback de 20 mientras está en vuelo).
        configuracionAgenciaService.obtener().then((res) => { configAgencia.value = res.configuracion_agencia; });

        // Sesión 11l v2 — catálogos de servicios/proveedores para los filtros
        // de la biblioteca del tab Incluye, en paralelo (no bloquean la carga).
        servicioService.listar({}).then((res) => { serviciosFiltro.value = res.servicios ?? []; });
        proveedorService.listar({ estado: true }).then((res) => { proveedoresFiltro.value = res.proveedores ?? []; });

        await cargarPaquete();
        await cargarItinerario();
        await cargarItems();
        await cargarBiblioteca();

        const res = await guiaService.listar({});
        guias.value = res.guias ?? [];

        const tipos = await proveedorTipoService.listar();
        proveedorTipos.value = tipos.proveedor_tipos;
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo cargar el paquete/tour.', 'error');
    } finally {
        cargandoPagina.value = false;
    }
});
</script>
