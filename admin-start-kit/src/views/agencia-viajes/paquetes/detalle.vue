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
            <div class="card-header bg-white border-bottom py-2 d-flex justify-content-between align-items-center">
                <span class="fw-semibold text-dark small"><i class="fas fa-id-card me-1"></i>Datos generales</span>
                <button v-if="!editandoDatos" class="btn btn-sm btn-outline-secondary" @click="iniciarEdicionDatos">
                    <i class="fas fa-pen me-1"></i>Editar
                </button>
            </div>
            <div class="card-body">
                <!-- Modo lectura -->
                <div v-if="!editandoDatos" class="row g-3 small">
                    <div class="col-md-4"><strong>Destino:</strong> {{ paquete.destino_atractivo?.nombre ?? '—' }}</div>
                    <div class="col-md-4"><strong>Duración:</strong> {{ paquete.duracion_horas }} h</div>
                    <div class="col-md-4"><strong>Horario:</strong> {{ paquete.hora_salida ?? '—' }} — {{ paquete.hora_retorno ?? '—' }}</div>
                    <div class="col-md-8"><strong>Lugar de recojo:</strong> {{ paquete.lugar_recojo ?? '—' }}</div>
                    <div class="col-md-4" v-if="!esCombo"><strong>Precio desde:</strong> {{ paquete.precio_venta_final != null ? `S/ ${Number(paquete.precio_venta_final).toFixed(2)}` : '—' }}</div>
                    <div class="col-md-4"><strong>Ajuste de redondeo:</strong> {{ paquete.ajuste_redondeo != null ? `${Number(paquete.ajuste_redondeo) >= 0 ? '+' : ''}S/ ${Number(paquete.ajuste_redondeo).toFixed(2)}` : '—' }}</div>
                    <div class="col-12" v-if="paquete.descripcion"><strong>Descripción:</strong> <span v-html="paquete.descripcion"></span></div>
                    <div class="col-md-6" v-if="paquete.no_incluye"><strong>No incluye:</strong> <span v-html="paquete.no_incluye"></span></div>
                    <div class="col-md-6" v-if="paquete.recomendaciones"><strong>Recomendaciones:</strong> <span v-html="paquete.recomendaciones"></span></div>
                    <div class="col-12" v-if="paquete.vuelo_incluido">
                        <strong>Vuelo:</strong> {{ paquete.vuelo_aerolinea ?? '—' }} — {{ paquete.vuelo_detalle ?? '' }}
                    </div>
                    <div class="col-md-4"><strong>Vigencia:</strong> {{ paquete.vigencia_desde ? formatFecha(paquete.vigencia_desde) : 'sin inicio' }} — {{ paquete.vigencia_hasta ? formatFecha(paquete.vigencia_hasta) : 'indefinida' }}</div>
                    <div class="col-md-4"><strong>Publicado web:</strong> {{ paquete.publicado_web ? 'Sí' : 'No' }}</div>
                </div>

                <!-- Modo edición -->
                <div v-else class="row g-3">
                    <div class="col-12 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Código</label>
                        <input type="text" class="form-control form-control-sm" v-model="formDatos.codigo" placeholder="Ej. PDKM-CZ">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Categoría *</label>
                        <select class="form-select form-select-sm" v-model="formDatos.categoria">
                            <option value="local">Local</option>
                            <option value="nacional">Nacional</option>
                            <option value="internacional">Internacional</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Nombre *</label>
                        <input type="text" class="form-control form-control-sm" v-model="formDatos.nombre" placeholder="Ej. Full Day Alto Mayo">
                    </div>
                    <div class="col-12">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Descripción</label>
                        <RichTextEditor v-model="formDatos.descripcion" />
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Destino / Atractivo principal *</label>
                        <DestinoTreeSelect v-model="formDatos.destino_atractivo_id" nivel-max="lugar" />
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Duración (horas) *</label>
                        <input type="number" min="1" class="form-control form-control-sm" v-model.number="formDatos.duracion_horas">
                    </div>
                    <div class="col-6 col-md-3" v-if="!esCombo">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Precio venta (desde)</label>
                        <input type="number" step="0.01" min="0" class="form-control form-control-sm" v-model.number="formDatos.precio_venta_final" placeholder="Se resuelve solo con los hoteles">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Ajuste de redondeo (S/)</label>
                        <input type="number" step="0.01" class="form-control form-control-sm" v-model.number="formDatos.ajuste_redondeo" placeholder="Ej. 6.34 para redondear hacia arriba">
                        <div class="form-text small">Corrige la suma de ítems al total real que se cobra. Positivo o negativo. Se refleja como línea aparte en la cotización.</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Hora de salida</label>
                        <input type="time" class="form-control form-control-sm" v-model="formDatos.hora_salida">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Hora de retorno</label>
                        <input type="time" class="form-control form-control-sm" v-model="formDatos.hora_retorno">
                    </div>
                    <div class="col-12">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Lugar de recojo</label>
                        <input type="text" class="form-control form-control-sm" v-model="formDatos.lugar_recojo" placeholder="Ej. Hoteles ubicados dentro de la ciudad">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label mb-1 small fw-semibold text-secondary">No incluye</label>
                        <RichTextEditor v-model="formDatos.no_incluye" />
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Recomendaciones</label>
                        <RichTextEditor v-model="formDatos.recomendaciones" />
                    </div>
                    <div class="col-12">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="vueloIncluidoDatos" v-model="formDatos.vuelo_incluido">
                            <label class="form-check-label small" for="vueloIncluidoDatos">Incluye vuelo</label>
                        </div>
                        <div v-if="formDatos.vuelo_incluido" class="row g-3">
                            <div class="col-12 col-md-4">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Aerolínea</label>
                                <input type="text" class="form-control form-control-sm" v-model="formDatos.vuelo_aerolinea">
                            </div>
                            <div class="col-12 col-md-8">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Detalle (tramos, fechas, equipaje...)</label>
                                <input type="text" class="form-control form-control-sm" v-model="formDatos.vuelo_detalle">
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Vigente desde</label>
                        <input type="date" class="form-control form-control-sm" v-model="formDatos.vigencia_desde">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Vigente hasta</label>
                        <input type="date" class="form-control form-control-sm" v-model="formDatos.vigencia_hasta">
                    </div>
                    <div class="col-12 col-md-6 d-flex align-items-center">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="publicadoWebDatos" v-model="formDatos.publicado_web">
                            <label class="form-check-label small text-muted" for="publicadoWebDatos">Publicado en portal web (sin efecto todavía)</label>
                        </div>
                    </div>
                    <div class="col-12 d-flex justify-content-end gap-2 mt-2">
                        <button class="btn btn-sm btn-outline-secondary" @click="cancelarEdicionDatos" :disabled="guardandoDatos">Cancelar</button>
                        <button class="btn btn-sm btn-primary" @click="guardarDatos" :disabled="guardandoDatos">
                            <span v-if="guardandoDatos" class="spinner-border spinner-border-sm me-1"></span>Guardar cambios
                        </button>
                    </div>
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

                <!-- Ajuste de redondeo (Datos tab) — línea aparte del total
                     "venta neta" de arriba, para que el vendedor entienda que
                     son dos números distintos: venta neta es la rentabilidad
                     real (post-descuento), total final es lo que realmente
                     se cobra/se suma en la cotización. -->
                <div v-if="preview.ajusteRedondeo !== null" class="d-flex justify-content-between align-items-center border-top pt-3 mb-3">
                    <span class="text-muted small">
                        Ajuste de redondeo
                        <span class="text-muted fst-italic">(editable en el tab Datos)</span>
                    </span>
                    <span class="fw-semibold" :class="preview.ajusteRedondeo >= 0 ? 'text-success' : 'text-danger'">
                        {{ preview.ajusteRedondeo >= 0 ? '+' : '' }}S/ {{ preview.ajusteRedondeo.toFixed(2) }}
                    </span>
                </div>
                <div v-if="preview.ajusteRedondeo !== null" class="d-flex justify-content-between align-items-center mb-4">
                    <span class="fw-semibold text-dark">Total final (lo que se cobra)</span>
                    <span class="fs-3 fw-bold text-primary">S/ {{ preview.ventaFinal.toFixed(2) }}</span>
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

        <!-- ═══ TAB: Incluye (unificado tour_simple / paquete_combo) ═══ -->
        <div v-if="tabActiva === 'incluye'" class="row g-3 av-incluye">
            <!-- Columna principal -->
            <div class="col-lg-8">
                <!-- Panel: agregar ítem -->
                <div class="card border-0 shadow-sm mb-3 av-add-panel">
                    <div class="card-header bg-white border-bottom py-2">
                        <span class="fw-semibold text-dark small"><i class="fas fa-plus-circle text-primary me-1"></i>Agregar a este {{ esCombo ? 'combo' : 'tour' }}</span>
                    </div>
                    <div class="card-body py-3">
                        <div class="av-type-tabs mb-3">
                            <button v-if="esCombo" type="button" class="av-type-tab" :class="{ active: tipoItemNuevo === 'tour' }" @click="tipoItemNuevo = 'tour'; cargarBibliotecaTours()">
                                <span class="av-type-tab-icon"><i class="fas fa-layer-group"></i></span>Tour completo
                            </button>
                            <button type="button" class="av-type-tab" :class="{ active: tipoItemNuevo === 'proveedor' }" @click="tipoItemNuevo = 'proveedor'">
                                <span class="av-type-tab-icon"><i class="fas fa-concierge-bell"></i></span>Servicio de proveedor
                            </button>
                            <button type="button" class="av-type-tab" :class="{ active: tipoItemNuevo === 'guia' }" @click="tipoItemNuevo = 'guia'">
                                <span class="av-type-tab-icon"><i class="fas fa-user-tie"></i></span>Guía de turismo
                            </button>
                        </div>

                        <!-- Buscador: tour completo (solo combo) -->
                        <div v-if="esCombo && tipoItemNuevo === 'tour'">
                            <div class="av-search mb-2">
                                <i class="fas fa-search"></i>
                                <input type="text" class="form-control form-control-sm" placeholder="Buscar tour por nombre o código..."
                                    v-model="bibliotecaTourSearch" @input="onBibliotecaTourSearch">
                            </div>
                            <div class="mb-2">
                                <DestinoTreeSelect v-model="filtroDestinoTourId" nivel-max="lugar" placeholder="Filtrar por zona o destino..." />
                            </div>
                            <TransitionGroup tag="div" name="av-fade" class="av-lib-grid">
                                <div v-for="t in bibliotecaTours" :key="'tour-' + t.id" class="av-item-card"
                                    :class="{ 'is-selected': tourSeleccionado?.id === t.id, 'is-added': idsTourHijoEnItems.has(t.id) }"
                                    @click="!idsTourHijoEnItems.has(t.id) && (tourSeleccionado = t)">
                                    <div class="av-item-icon bg-primary-subtle text-primary"><i class="fas fa-layer-group"></i></div>
                                    <div class="av-item-body">
                                        <div class="av-item-title">{{ t.nombre }}<span v-if="t.codigo" class="text-muted fw-normal"> · {{ t.codigo }}</span></div>
                                        <div class="av-item-sub">{{ etiquetaCategoria(t.categoria) }} · {{ t.items_count ?? 0 }} ítem(s)</div>
                                    </div>
                                    <div class="av-item-side">
                                        <span class="av-item-price">{{ t.precio_venta_final != null ? `S/ ${Number(t.precio_venta_final).toFixed(0)}` : '—' }}</span>
                                        <span v-if="idsTourHijoEnItems.has(t.id)" class="av-badge av-badge-added"><i class="fas fa-check"></i>Agregado</span>
                                        <span v-else-if="tourSeleccionado?.id === t.id" class="av-badge av-badge-selected"><i class="fas fa-circle-check"></i>Elegido</span>
                                    </div>
                                </div>
                            </TransitionGroup>
                            <div v-if="bibliotecaTours.length === 0" class="text-muted small text-center py-3">Sin resultados.</div>
                            <div v-if="bibliotecaTours.length > 0" class="d-flex justify-content-between align-items-center mt-2">
                                <span class="small text-muted">Mostrando {{ bibliotecaTours.length }} de {{ bibliotecaToursTotal }}</span>
                                <button v-if="bibliotecaToursHayMas" type="button" class="btn btn-sm btn-outline-secondary" @click="cargarMasBibliotecaTours" :disabled="bibliotecaToursCargandoMas">
                                    <span v-if="bibliotecaToursCargandoMas" class="spinner-border spinner-border-sm me-1"></span>Cargar más
                                </button>
                            </div>
                        </div>

                        <!-- Buscador: servicio de proveedor -->
                        <div v-else-if="tipoItemNuevo === 'proveedor'">
                            <div class="av-search mb-2">
                                <i class="fas fa-search"></i>
                                <input type="text" class="form-control form-control-sm" placeholder="Buscar servicio de proveedor..."
                                    v-model="bibliotecaSearch" @input="onBibliotecaSearch">
                            </div>
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
                            <TransitionGroup tag="div" name="av-fade" class="av-lib-grid">
                                <div v-for="t in bibliotecaTarifas" :key="'prov-' + t.id" class="av-item-card"
                                    :class="{ 'is-selected': proveedorTarifaSeleccionada?.id === t.id, 'is-added': idsProveedorTarifaEnItems.has(t.id) }"
                                    @click="!idsProveedorTarifaEnItems.has(t.id) && (proveedorTarifaSeleccionada = t)">
                                    <div class="av-item-icon bg-info-subtle text-info"><i class="fas fa-concierge-bell"></i></div>
                                    <div class="av-item-body">
                                        <div class="av-item-title">
                                            {{ t.proveedor_servicio?.proveedor?.razon_social }}
                                            <span v-if="t.proveedor_servicio?.proveedor?.es_referencial" class="av-badge av-badge-muted">Referencial</span>
                                        </div>
                                        <div class="av-item-sub">{{ descripcionDestinoServicio(t.proveedor_servicio?.destino_servicio) }}<span v-if="t.tipo_habitacion"> · {{ t.tipo_habitacion }}</span></div>
                                        <div class="av-item-tags"><span class="av-badge av-badge-muted">{{ t.tipo_tarifa }} · {{ t.modalidad }}</span></div>
                                    </div>
                                    <div class="av-item-side">
                                        <span class="av-item-price">{{ t.moneda }} {{ Number(t.precio_venta_adulto).toFixed(2) }}</span>
                                        <span class="av-item-cost">costo {{ t.moneda }} {{ Number(t.precio_costo).toFixed(2) }}</span>
                                        <span v-if="idsProveedorTarifaEnItems.has(t.id)" class="av-badge av-badge-added"><i class="fas fa-check"></i>Agregado</span>
                                        <span v-else-if="proveedorTarifaSeleccionada?.id === t.id" class="av-badge av-badge-selected"><i class="fas fa-circle-check"></i>Elegido</span>
                                    </div>
                                </div>
                            </TransitionGroup>
                            <div v-if="bibliotecaTarifas.length === 0" class="text-muted small text-center py-3">Sin resultados.</div>
                            <div v-if="bibliotecaTarifas.length > 0" class="d-flex justify-content-between align-items-center mt-2">
                                <span class="small text-muted">Mostrando {{ bibliotecaTarifas.length }} de {{ bibliotecaTarifasTotal }}</span>
                                <button v-if="bibliotecaTarifasHayMas" type="button" class="btn btn-sm btn-outline-secondary" @click="cargarMasBiblioteca" :disabled="bibliotecaTarifasCargandoMas">
                                    <span v-if="bibliotecaTarifasCargandoMas" class="spinner-border spinner-border-sm me-1"></span>Cargar más
                                </button>
                            </div>
                        </div>

                        <!-- Buscador: guía de turismo -->
                        <div v-else>
                            <select class="form-select form-select-sm mb-2" v-model="guiaSeleccionadaId" @change="cargarTarifasGuia">
                                <option :value="null">— Elegí un guía —</option>
                                <option v-for="g in guias" :key="g.id" :value="g.id">{{ g.nombre }}{{ g.es_referencial ? ' (Referencial)' : '' }}</option>
                            </select>
                            <TransitionGroup tag="div" name="av-fade" class="av-lib-grid">
                                <div v-for="t in tarifasGuia" :key="'guia-' + t.id" class="av-item-card"
                                    :class="{ 'is-selected': guiaTarifaSeleccionada?.id === t.id, 'is-added': idsGuiaTarifaEnItems.has(t.id) }"
                                    @click="!idsGuiaTarifaEnItems.has(t.id) && (guiaTarifaSeleccionada = t)">
                                    <div class="av-item-icon bg-warning-subtle text-warning"><i class="fas fa-user-tie"></i></div>
                                    <div class="av-item-body">
                                        <div class="av-item-title">{{ t.destino?.nombre }}</div>
                                        <div class="av-item-sub">{{ t.modalidad === 'dia_local' ? 'Día local' : 'Grupo multidía' }}</div>
                                    </div>
                                    <div class="av-item-side">
                                        <span class="av-item-price">{{ t.moneda }} {{ t.costo_diario }}</span>
                                        <span v-if="idsGuiaTarifaEnItems.has(t.id)" class="av-badge av-badge-added"><i class="fas fa-check"></i>Agregado</span>
                                        <span v-else-if="guiaTarifaSeleccionada?.id === t.id" class="av-badge av-badge-selected"><i class="fas fa-circle-check"></i>Elegido</span>
                                    </div>
                                </div>
                            </TransitionGroup>
                            <div v-if="guiaSeleccionadaId && tarifasGuia.length === 0" class="text-muted small text-center py-3">Este guía no tiene tarifas cargadas.</div>
                        </div>

                        <!-- Barra de selección + confirmación de alta -->
                        <Transition name="av-fade">
                            <div v-if="proveedorTarifaSeleccionada || guiaTarifaSeleccionada || tourSeleccionado" class="av-selection-bar mt-3">
                                <div class="av-selection-info">
                                    <i class="fas fa-circle-check text-success me-2"></i>
                                    <span v-if="proveedorTarifaSeleccionada">{{ proveedorTarifaSeleccionada.proveedor_servicio?.proveedor?.razon_social }}</span>
                                    <span v-else-if="guiaTarifaSeleccionada">Guía — {{ guiaTarifaSeleccionada.destino?.nombre }}</span>
                                    <span v-else-if="tourSeleccionado">{{ tourSeleccionado.nombre }}</span>
                                </div>
                                <div class="d-flex align-items-end gap-2">
                                    <div class="d-flex flex-column" style="width:84px">
                                        <label class="mb-0" style="font-size:.65rem;color:#868e96">{{ tipoItemNuevo === 'tour' ? 'Día' : 'Orden' }}</label>
                                        <input type="number" min="0" class="form-control form-control-sm" v-model.number="ordenItemNuevo">
                                    </div>
                                    <button class="btn btn-primary btn-sm" @click="agregarItem" :disabled="agregandoItem">
                                        <span v-if="agregandoItem" class="spinner-border spinner-border-sm me-1"></span>
                                        <i v-else class="fas fa-plus me-1"></i>Agregar
                                    </button>
                                    <button class="btn btn-outline-secondary btn-sm" title="Cancelar selección"
                                        @click="proveedorTarifaSeleccionada = null; guiaTarifaSeleccionada = null; tourSeleccionado = null">
                                        <i class="fas fa-xmark"></i>
                                    </button>
                                </div>
                            </div>
                        </Transition>
                    </div>
                </div>

                <!-- Ítems incluidos: tour_simple -->
                <template v-if="!esCombo">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white border-bottom py-2 d-flex justify-content-between align-items-center">
                            <span class="fw-semibold text-dark small"><i class="fas fa-list-check text-primary me-1"></i>Ítems incluidos</span>
                            <span class="badge bg-light text-dark border">{{ items.length }}</span>
                        </div>
                        <div class="card-body">
                            <div v-if="itemsProveedor.length" class="av-group mb-3">
                                <div class="av-group-title">Servicios de proveedor</div>
                                <TransitionGroup tag="div" name="av-fade" class="av-included-grid">
                                    <div v-for="item in itemsProveedor" :key="item.id" class="av-item-card av-item-card--flat">
                                        <div class="av-item-icon bg-info-subtle text-info"><i class="fas fa-concierge-bell"></i></div>
                                        <div class="av-item-body">
                                            <div class="av-item-title">
                                                {{ item.proveedor_tarifa!.proveedor_servicio?.proveedor?.razon_social }}
                                                <span v-if="item.proveedor_tarifa!.proveedor_servicio?.proveedor?.es_referencial" class="av-badge av-badge-muted">Referencial</span>
                                            </div>
                                            <div class="av-item-sub">{{ descripcionDestinoServicio(item.proveedor_tarifa!.proveedor_servicio?.destino_servicio) }}<span v-if="item.proveedor_tarifa!.tipo_habitacion"> · {{ item.proveedor_tarifa!.tipo_habitacion }}</span></div>
                                            <div class="av-item-tags"><span class="av-badge av-badge-muted">{{ item.proveedor_tarifa!.tipo_tarifa }} · {{ item.proveedor_tarifa!.modalidad }}</span></div>
                                        </div>
                                        <div class="av-item-side">
                                            <span class="av-item-price">{{ monedaItem(item) }} {{ ventaItem(item).toFixed(2) }}</span>
                                            <span class="av-item-cost">costo {{ monedaItem(item) }} {{ costoItem(item).toFixed(2) }}</span>
                                            <button type="button" class="btn btn-sm btn-link text-danger p-0 mt-1" @click="quitarItem(item)" :disabled="eliminandoItemId === item.id">
                                                <span v-if="eliminandoItemId === item.id" class="spinner-border spinner-border-sm"></span>
                                                <i v-else class="fas fa-trash-can"></i>
                                            </button>
                                        </div>
                                    </div>
                                </TransitionGroup>
                            </div>

                            <div v-if="itemsGuia.length" class="av-group">
                                <div class="av-group-title">Guías de turismo</div>
                                <TransitionGroup tag="div" name="av-fade" class="av-included-grid">
                                    <div v-for="item in itemsGuia" :key="item.id" class="av-item-card av-item-card--flat">
                                        <div class="av-item-icon bg-warning-subtle text-warning"><i class="fas fa-user-tie"></i></div>
                                        <div class="av-item-body">
                                            <div class="av-item-title">
                                                Guía: {{ item.guia_tarifa!.guia?.nombre }}
                                                <span v-if="item.guia_tarifa!.guia?.es_referencial" class="av-badge av-badge-muted">Referencial</span>
                                            </div>
                                            <div class="av-item-sub">{{ item.guia_tarifa!.destino?.nombre }}</div>
                                        </div>
                                        <div class="av-item-side">
                                            <span class="av-item-price">{{ monedaItem(item) }} {{ ventaItem(item).toFixed(2) }}</span>
                                            <span class="av-item-cost">costo {{ monedaItem(item) }} {{ costoItem(item).toFixed(2) }}</span>
                                            <button type="button" class="btn btn-sm btn-link text-danger p-0 mt-1" @click="quitarItem(item)" :disabled="eliminandoItemId === item.id">
                                                <span v-if="eliminandoItemId === item.id" class="spinner-border spinner-border-sm"></span>
                                                <i v-else class="fas fa-trash-can"></i>
                                            </button>
                                        </div>
                                    </div>
                                </TransitionGroup>
                            </div>

                            <div v-if="items.length === 0" class="text-muted fst-italic text-center py-4">
                                Este paquete/tour todavía no tiene ítems incluidos.
                            </div>
                        </div>
                    </div>
                </template>

                <!-- Ítems incluidos: paquete_combo -->
                <template v-else>
                    <TransitionGroup tag="div" name="av-fade" class="d-flex flex-column gap-2 mb-2">
                        <div v-for="grupo in itemsPorTourAgrupados" :key="grupo.item.id" class="card border-0 shadow-sm av-tour-card">
                            <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-2" style="cursor:pointer" @click="toggleExpandido(grupo.item.paquete_plantilla_hijo_id!)">
                                <span class="fw-semibold text-dark small d-flex align-items-center gap-2">
                                    <i class="fas fa-chevron-right text-muted av-chevron" :class="{ 'fa-rotate-90': expandidos.has(grupo.item.paquete_plantilla_hijo_id!) }" style="font-size:10px"></i>
                                    <span class="av-item-icon bg-primary-subtle text-primary" style="width:28px;height:28px;min-width:28px"><i class="fas fa-layer-group" style="font-size:.7rem"></i></span>
                                    Día {{ grupo.item.orden ?? '—' }}: {{ grupo.item.paquete_plantilla_hijo?.nombre }}
                                </span>
                                <span class="d-flex align-items-center gap-2">
                                    <span class="badge bg-light text-dark border">{{ grupo.item.paquete_plantilla_hijo?.precio_venta_final != null ? `S/ ${Number(grupo.item.paquete_plantilla_hijo.precio_venta_final).toFixed(0)}` : '—' }}</span>
                                    <button type="button" class="btn btn-sm btn-link text-danger p-0" @click.stop="quitarTourHijo(grupo.item)" :disabled="eliminandoItemId === grupo.item.id">
                                        <span v-if="eliminandoItemId === grupo.item.id" class="spinner-border spinner-border-sm"></span>
                                        <i v-else class="fas fa-trash-can"></i>
                                    </button>
                                </span>
                            </div>
                            <Transition name="av-expand">
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
                            </Transition>
                        </div>
                    </TransitionGroup>

                    <div v-if="itemsSueltos.length" class="card border-0 shadow-sm">
                        <div class="card-header bg-white border-bottom py-2"><span class="fw-semibold text-dark small">Ítems sueltos</span></div>
                        <div class="card-body">
                            <TransitionGroup tag="div" name="av-fade" class="av-included-grid">
                                <div v-for="item in itemsSueltos" :key="item.id" class="av-item-card av-item-card--flat">
                                    <div class="av-item-icon" :class="item.proveedor_tarifa ? 'bg-info-subtle text-info' : 'bg-warning-subtle text-warning'">
                                        <i class="fas" :class="item.proveedor_tarifa ? 'fa-concierge-bell' : 'fa-user-tie'"></i>
                                    </div>
                                    <div class="av-item-body">
                                        <template v-if="item.proveedor_tarifa">
                                            <div class="av-item-title">
                                                {{ item.proveedor_tarifa.proveedor_servicio?.proveedor?.razon_social }}
                                                <span v-if="item.proveedor_tarifa.proveedor_servicio?.proveedor?.es_referencial" class="av-badge av-badge-muted">Referencial</span>
                                            </div>
                                            <div class="av-item-sub">{{ descripcionDestinoServicio(item.proveedor_tarifa.proveedor_servicio?.destino_servicio) }}<span v-if="item.proveedor_tarifa.tipo_habitacion"> · {{ item.proveedor_tarifa.tipo_habitacion }}</span></div>
                                            <div class="av-item-tags"><span class="av-badge av-badge-muted">{{ item.proveedor_tarifa.tipo_tarifa }} · {{ item.proveedor_tarifa.modalidad }}</span></div>
                                        </template>
                                        <template v-else-if="item.guia_tarifa">
                                            <div class="av-item-title">
                                                Guía: {{ item.guia_tarifa.guia?.nombre }}
                                                <span v-if="item.guia_tarifa.guia?.es_referencial" class="av-badge av-badge-muted">Referencial</span>
                                            </div>
                                            <div class="av-item-sub">{{ item.guia_tarifa.destino?.nombre }}</div>
                                        </template>
                                    </div>
                                    <div class="av-item-side">
                                        <span class="av-item-price">{{ monedaItem(item) }} {{ ventaItem(item).toFixed(2) }}</span>
                                        <span class="av-item-cost">costo {{ monedaItem(item) }} {{ costoItem(item).toFixed(2) }}</span>
                                        <button type="button" class="btn btn-sm btn-link text-danger p-0 mt-1" @click="quitarItem(item)" :disabled="eliminandoItemId === item.id">
                                            <span v-if="eliminandoItemId === item.id" class="spinner-border spinner-border-sm"></span>
                                            <i v-else class="fas fa-trash-can"></i>
                                        </button>
                                    </div>
                                </div>
                            </TransitionGroup>
                        </div>
                    </div>

                    <div v-if="itemsPorTourAgrupados.length === 0 && itemsSueltos.length === 0" class="text-muted fst-italic text-center py-4">
                        Este combo todavía no tiene tours ni ítems incluidos.
                    </div>
                </template>
            </div>

            <!-- Columna sticky: resumen de precio -->
            <div class="col-lg-4">
                <div class="av-summary-sticky">
                    <!-- Resumen: tour_simple -->
                    <div v-if="!esCombo" class="card border-0 shadow-sm">
                        <div class="card-header bg-white border-bottom py-2">
                            <span class="fw-semibold text-dark small"><i class="fas fa-coins text-primary me-1"></i>Resumen</span>
                        </div>
                        <div class="card-body">
                            <template v-if="items.length">
                                <div class="d-flex justify-content-between small mb-2">
                                    <span class="text-muted">Costo total</span><span class="fw-semibold">S/ {{ totalesIncluye.costoTotal.toFixed(2) }}</span>
                                </div>
                                <div class="d-flex justify-content-between small mb-2">
                                    <span class="text-muted">Venta total</span><span class="fw-semibold">S/ {{ totalesIncluye.ventaTotal.toFixed(2) }}</span>
                                </div>
                                <div v-if="totalesIncluye.ajusteRedondeo !== null" class="d-flex justify-content-between small mb-2">
                                    <span class="text-muted">Ajuste de redondeo <span class="fst-italic">(tab Datos)</span></span>
                                    <span class="fw-semibold" :class="totalesIncluye.ajusteRedondeo >= 0 ? 'text-success' : 'text-danger'">
                                        {{ totalesIncluye.ajusteRedondeo >= 0 ? '+' : '' }}S/ {{ totalesIncluye.ajusteRedondeo.toFixed(2) }}
                                    </span>
                                </div>
                                <div v-if="totalesIncluye.ajusteRedondeo !== null" class="d-flex justify-content-between align-items-center small mb-2">
                                    <span class="text-muted fw-semibold">Total final (lo que se cobra)</span><span class="fw-bold text-primary">S/ {{ totalesIncluye.ventaFinal.toFixed(2) }}</span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center border-top pt-2 mb-2">
                                    <span class="text-muted small">Margen</span>
                                    <span class="fs-5 fw-bold" :class="totalesIncluye.margenResultantePct >= margenMinimoAceptablePct ? 'text-success' : 'text-danger'">
                                        {{ totalesIncluye.margenResultantePct.toFixed(1) }}%
                                    </span>
                                </div>
                                <div v-if="desglosePorCategoria.length" class="border-top pt-2">
                                    <div class="d-flex justify-content-between small mb-1" v-for="cat in desglosePorCategoria" :key="cat.categoria"
                                        :class="cat.categoria === 'Sin categoría' ? 'text-warning-emphasis' : 'text-muted'">
                                        <span>{{ cat.categoria }}</span><span>S/ {{ cat.venta.toFixed(2) }}</span>
                                    </div>
                                    <div v-if="hayItemsSinCategoria" class="small text-muted fst-italic mt-1">
                                        <i class="fas fa-circle-info me-1"></i>Asignale un tipo a tus servicios en
                                        <router-link to="/agencia-viajes/destinos">Destinos → Servicios asociados</router-link>
                                        para un desglose más preciso.
                                    </div>
                                </div>
                                <div v-if="diferenciaVentaFinal !== null" class="alert alert-warning small mt-3 mb-0 py-2">
                                    <i class="fas fa-triangle-exclamation me-1"></i>
                                    El "Precio venta (desde)" de catálogo (S/ {{ Number(paquete!.precio_venta_final).toFixed(2) }}) no coincide con la
                                    suma de ítems (diferencia: S/ {{ diferenciaVentaFinal.toFixed(2) }}). Es solo informativo — no afecta el total real
                                    de la cotización. Si querés cobrar un número redondo, usá "Ajuste de redondeo" en el tab Datos.
                                </div>
                            </template>
                            <div v-else class="text-muted small text-center py-3 fst-italic">
                                Agregá ítems para ver el resumen de costos y margen.
                            </div>
                        </div>
                    </div>

                    <!-- Resumen: paquete_combo -->
                    <div v-else class="card border-0 shadow-sm">
                        <div class="card-header bg-white border-bottom py-2">
                            <span class="fw-semibold text-dark small"><i class="fas fa-coins text-primary me-1"></i>Precio del combo</span>
                        </div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between small mb-2">
                                <span class="text-muted">Costo total</span><span class="fw-semibold">S/ {{ preview.costoTotal.toFixed(2) }}</span>
                            </div>
                            <div class="d-flex justify-content-between small mb-2">
                                <span class="text-muted">Venta bruta</span><span class="fw-semibold">S/ {{ preview.ventaBruta.toFixed(2) }}</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center border-top pt-2 mb-1">
                                <span class="text-muted small">Venta neta</span>
                                <span class="fs-5 fw-bold text-primary">S/ {{ preview.ventaNeta.toFixed(2) }}</span>
                            </div>
                            <div v-if="preview.ajusteRedondeo !== null" class="d-flex justify-content-between small mb-1">
                                <span class="text-muted">Ajuste de redondeo</span>
                                <span class="fw-semibold" :class="preview.ajusteRedondeo >= 0 ? 'text-success' : 'text-danger'">
                                    {{ preview.ajusteRedondeo >= 0 ? '+' : '' }}S/ {{ preview.ajusteRedondeo.toFixed(2) }}
                                </span>
                            </div>
                            <div v-if="preview.ajusteRedondeo !== null" class="d-flex justify-content-between align-items-center mb-2">
                                <span class="text-muted small fw-semibold">Total final</span>
                                <span class="fs-5 fw-bold text-primary">S/ {{ preview.ventaFinal.toFixed(2) }}</span>
                            </div>
                            <div v-if="preview.margenResultante !== null" class="small mb-2">
                                Margen: <strong :class="margenOk ? 'text-success' : 'text-danger'">{{ preview.margenResultante.toFixed(2) }}%</strong>
                            </div>

                            <div class="d-flex gap-3 small text-muted border-top pt-2 mb-2">
                                <span><i class="fas fa-layer-group me-1"></i>{{ itemsPorTourAgrupados.length }} tour(s)</span>
                                <span v-if="itemsSueltos.length"><i class="fas fa-puzzle-piece me-1"></i>{{ itemsSueltos.length }} suelto(s)</span>
                            </div>

                            <template v-if="combo">
                                <div v-if="combo.precio_calculado.componentes_inactivos.length" class="alert alert-warning small py-2 mb-2">
                                    <i class="fas fa-triangle-exclamation me-1"></i>{{ combo.precio_calculado.componentes_inactivos.length }} tour(s) desactivado(s), fuera del total.
                                </div>
                                <div v-if="combo.precio_calculado.componentes_sin_incluye.length" class="alert alert-warning small py-2 mb-2">
                                    <i class="fas fa-triangle-exclamation me-1"></i>{{ combo.precio_calculado.componentes_sin_incluye.length }} tour(s) sin costo cargado.
                                </div>
                                <div v-if="combo.precio_calculado.componentes_sin_itinerario.length" class="alert alert-warning small py-2 mb-2">
                                    <i class="fas fa-triangle-exclamation me-1"></i>{{ combo.precio_calculado.componentes_sin_itinerario.length }} tour(s) sin itinerario cargado.
                                </div>
                            </template>

                            <button class="btn btn-sm btn-outline-primary w-100 mt-1" @click="tabActiva = 'datos'">
                                Editar descuento y margen <i class="fas fa-arrow-right ms-1"></i>
                            </button>
                        </div>
                    </div>
                </div>
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
import RichTextEditor from '@/components/RichTextEditor.vue';
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

// Sesión 11p — edición in-situ del tab "Datos". Mismo criterio que
// guardarPrecioCombo()/toggleActivo() más abajo en este archivo: en
// guardarDatos() se manda {...paquete.value, ...formDatos.value} contra
// el mismo endpoint de actualizar(), nunca un PATCH parcial (el backend
// exige nombre/categoria/destino_atractivo_id/duracion_horas en cada
// request). descuento_tipo/descuento_valor/margen_minimo_pct/tipo/activo
// quedan FUERA a propósito — ver nota de diseño arriba del archivo.
const editandoDatos = ref(false);
const guardandoDatos = ref(false);
const formDatos = ref<{
    codigo: string | null; categoria: string; nombre: string; descripcion: string | null;
    destino_atractivo_id: number | null; duracion_horas: number; precio_venta_final: number | null;
    ajuste_redondeo: number | null;
    hora_salida: string | null; hora_retorno: string | null; lugar_recojo: string | null;
    no_incluye: string | null; recomendaciones: string | null;
    vuelo_incluido: boolean; vuelo_aerolinea: string | null; vuelo_detalle: string | null;
    vigencia_desde: string | null; vigencia_hasta: string | null; publicado_web: boolean;
}>({
    codigo: null, categoria: 'local', nombre: '', descripcion: null,
    destino_atractivo_id: null, duracion_horas: 1, precio_venta_final: null,
    ajuste_redondeo: null,
    hora_salida: null, hora_retorno: null, lugar_recojo: null,
    no_incluye: null, recomendaciones: null,
    vuelo_incluido: false, vuelo_aerolinea: null, vuelo_detalle: null,
    vigencia_desde: null, vigencia_hasta: null, publicado_web: false,
});

const iniciarEdicionDatos = () => {
    if (!paquete.value) return;
    formDatos.value = {
        codigo: paquete.value.codigo ?? null,
        categoria: paquete.value.categoria,
        nombre: paquete.value.nombre,
        descripcion: paquete.value.descripcion ?? null,
        destino_atractivo_id: paquete.value.destino_atractivo_id ?? null,
        duracion_horas: paquete.value.duracion_horas,
        precio_venta_final: paquete.value.precio_venta_final ?? null,
        ajuste_redondeo: paquete.value.ajuste_redondeo ?? null,
        // Mismo motivo que iniciarEdicionPaso() más abajo: hora_salida/
        // hora_retorno vienen HH:MM:SS (columna TIME de Postgres), pero
        // <input type="time">/date_format:H:i del backend solo aceptan
        // HH:MM — sin truncar acá, guardar sin tocar el horario 422ea.
        hora_salida: paquete.value.hora_salida ? paquete.value.hora_salida.substring(0, 5) : null,
        hora_retorno: paquete.value.hora_retorno ? paquete.value.hora_retorno.substring(0, 5) : null,
        lugar_recojo: paquete.value.lugar_recojo ?? null,
        no_incluye: paquete.value.no_incluye ?? null,
        recomendaciones: paquete.value.recomendaciones ?? null,
        vuelo_incluido: paquete.value.vuelo_incluido ?? false,
        vuelo_aerolinea: paquete.value.vuelo_aerolinea ?? null,
        vuelo_detalle: paquete.value.vuelo_detalle ?? null,
        vigencia_desde: paquete.value.vigencia_desde ?? null,
        vigencia_hasta: paquete.value.vigencia_hasta ?? null,
        publicado_web: paquete.value.publicado_web ?? false,
    };
    editandoDatos.value = true;
};

const cancelarEdicionDatos = () => {
    editandoDatos.value = false;
};

const guardarDatos = async () => {
    if (!paquete.value) return;
    if (!formDatos.value.nombre.trim()) {
        (Swal as TVueSwalInstance).fire('Error', 'El nombre es obligatorio.', 'error');
        return;
    }
    if (!formDatos.value.destino_atractivo_id) {
        (Swal as TVueSwalInstance).fire('Error', 'Seleccioná el destino/atractivo principal.', 'error');
        return;
    }
    if (!formDatos.value.duracion_horas || formDatos.value.duracion_horas < 1) {
        (Swal as TVueSwalInstance).fire('Error', 'La duración en horas es obligatoria.', 'error');
        return;
    }
    if (esCombo.value) {
        formDatos.value.precio_venta_final = null;
    }

    guardandoDatos.value = true;
    try {
        const res = await paquetePlantillaService.actualizar(paquete.value.id, {
            ...paquete.value,
            ...formDatos.value,
        });
        await cargarPaquete();
        editandoDatos.value = false;
        (Swal as TVueSwalInstance).fire('Listo', res.message, 'success');
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo guardar', 'error');
    } finally {
        guardandoDatos.value = false;
    }
};

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

    // margenResultante sigue midiéndose SOLO sobre ventaNeta, sin el ajuste
    // de redondeo — mismo criterio que PriceEngineService::calcularCombo()
    // (backend): el ajuste es cosmético sobre el total final, no una
    // decisión de rentabilidad.
    const margenResultante = costoTotal > 0 ? Math.round(((ventaNeta - costoTotal) / costoTotal) * 100 * 100) / 100 : null;

    // Fix ajuste de redondeo (2026-08-18): se edita en el tab Datos
    // (formDatos.ajuste_redondeo/guardarDatos()), NO en este formulario —
    // por eso se lee directo de paquete.value (ya guardado), a diferencia de
    // descuentoTipoLocal/descuentoValorLocal (editables en vivo acá mismo,
    // sin guardar todavía).
    const ajusteRedondeoRaw = paquete.value?.ajuste_redondeo;
    const ajusteRedondeo = ajusteRedondeoRaw != null ? Number(ajusteRedondeoRaw) : null;
    const ventaFinal = ajusteRedondeo !== null ? Math.round((ventaNeta + ajusteRedondeo) * 100) / 100 : ventaNeta;

    return { costoTotal, ventaBruta, ventaNeta, margenResultante, ajusteRedondeo, ventaFinal };
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

// Paginación de la biblioteca — antes el backend traía como mucho 100
// resultados sin avisar que había más. Ahora pagina de verdad: "cargar
// más" pide la siguiente página y la concatena; cualquier cambio de
// búsqueda/filtro vuelve a pedir desde la página 1 (reemplaza, no suma).
const bibliotecaTarifasTotal = ref(0);
const bibliotecaTarifasPage = ref(1);
const bibliotecaTarifasHayMas = ref(false);
const bibliotecaTarifasCargandoMas = ref(false);

const onBibliotecaSearch = () => {
    clearTimeout(bibliotecaTimeout);
    bibliotecaTimeout = setTimeout(() => cargarBiblioteca(1), 300);
};

watch([filtroDestinoId, filtroServicioId, filtroProveedorId], onBibliotecaSearch);

const cargarBiblioteca = async (pagina = 1) => {
    const res = await proveedorService.biblioteca({
        search: bibliotecaSearch.value || undefined,
        destino_atractivo_id: filtroDestinoId.value ?? undefined,
        servicio_id: filtroServicioId.value ?? undefined,
        proveedor_id: filtroProveedorId.value ?? undefined,
        page: pagina,
    });
    bibliotecaTarifas.value = pagina === 1 ? res.proveedor_tarifas : [...bibliotecaTarifas.value, ...res.proveedor_tarifas];
    bibliotecaTarifasTotal.value = res.total ?? bibliotecaTarifas.value.length;
    bibliotecaTarifasPage.value = res.current_page ?? pagina;
    bibliotecaTarifasHayMas.value = (res.current_page ?? pagina) < (res.last_page ?? pagina);
};

const cargarMasBiblioteca = async () => {
    if (bibliotecaTarifasCargandoMas.value || !bibliotecaTarifasHayMas.value) return;
    bibliotecaTarifasCargandoMas.value = true;
    try {
        await cargarBiblioteca(bibliotecaTarifasPage.value + 1);
    } finally {
        bibliotecaTarifasCargandoMas.value = false;
    }
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
// Filtro por zona/destino — cada tour tiene su propio destino_atractivo_id
// (ver DestinoAtractivo::idsConDescendientes en el backend), útil cuando el
// catálogo de tours crece y abarca varias regiones o países a la vez.
// Ref propia (no comparte filtroDestinoId con el picker de "Servicio de
// proveedor") para que cambiar de tab no borre el filtro del otro.
const filtroDestinoTourId = ref<number | null>(null);

// Paginación — el backend de /paquetes-plantilla ya paginaba (15 por
// página) pero el frontend nunca pedía la página 2 en adelante, así que
// quedaba pegado a los primeros 15 tours sin importar cuántos hubiera.
// "Cargar más" pide la siguiente página y concatena, mismo patrón que la
// biblioteca de proveedores de arriba.
const bibliotecaToursTotal = ref(0);
const bibliotecaToursPage = ref(1);
const bibliotecaToursHayMas = ref(false);
const bibliotecaToursCargandoMas = ref(false);
let bibliotecaTourTimeout: any = null;

const onBibliotecaTourSearch = () => {
    clearTimeout(bibliotecaTourTimeout);
    bibliotecaTourTimeout = setTimeout(() => cargarBibliotecaTours(1), 300);
};

watch(filtroDestinoTourId, () => cargarBibliotecaTours(1));

// La cantidad de ítems por tour ahora viene directo en items_count
// (withCount('items') agregado en PaquetePlantillaController::index()) —
// antes se pedía con un GET aparte por cada tour visible (N+1), que
// además hubiera empeorado al agregar paginación acá.
const cargarBibliotecaTours = async (pagina = 1) => {
    const res = await paquetePlantillaService.listar({
        tipo: 'tour_simple', activo: true, search: bibliotecaTourSearch.value || undefined,
        destino_atractivo_id: filtroDestinoTourId.value ?? undefined,
        page: pagina,
    });
    const crudos = res.paquetes_plantilla as PaquetePlantilla[];
    const tandaTours = crudos.filter(t => t.id !== paqueteId.value);
    bibliotecaTours.value = pagina === 1 ? tandaTours : [...bibliotecaTours.value, ...tandaTours];
    bibliotecaToursTotal.value = res.total ?? bibliotecaTours.value.length;
    bibliotecaToursPage.value = pagina;
    // El backend pagina fijo a 15 por página (paginate(15)) y no devuelve
    // last_page acá — se usa el tamaño CRUDO de la página (antes de
    // filtrar el propio tour) para decidir si hay más: comparar contra el
    // total filtrado se desalinea si el tour actual cae justo en una
    // página intermedia.
    bibliotecaToursHayMas.value = crudos.length === 15;
};

const cargarMasBibliotecaTours = async () => {
    if (bibliotecaToursCargandoMas.value || !bibliotecaToursHayMas.value) return;
    bibliotecaToursCargandoMas.value = true;
    try {
        await cargarBibliotecaTours(bibliotecaToursPage.value + 1);
    } finally {
        bibliotecaToursCargandoMas.value = false;
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

// Rediseño UI "Incluye" — items agrupados por tipo para la vista de
// tarjetas (tour_simple). Se recalculan solos (computed) cada vez que
// items.value cambia, igual que los sets de ids de arriba.
const itemsProveedor = computed(() => items.value.filter(i => i.proveedor_tarifa));
const itemsGuia = computed(() => items.value.filter(i => i.guia_tarifa));

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
    // margenResultantePct sigue midiéndose SOLO sobre ventaTotal (sin el
    // ajuste), mismo criterio que la sección paquete_combo/preview — es
    // cosmético sobre el total final, no una decisión de rentabilidad.
    const margenResultantePct = costoTotal > 0 ? ((ventaTotal - costoTotal) / costoTotal) * 100 : 0;

    // Fix ajuste de redondeo (2026-08-18) — se edita en el tab Datos
    // (formDatos.ajuste_redondeo), no acá; se lee directo de paquete.value
    // ya guardado, mismo criterio que preview.ajusteRedondeo (sección combo).
    const ajusteRedondeoRaw = paquete.value?.ajuste_redondeo;
    const ajusteRedondeo = ajusteRedondeoRaw != null ? Number(ajusteRedondeoRaw) : null;
    const ventaFinal = ajusteRedondeo !== null ? Math.round((ventaTotal + ajusteRedondeo) * 100) / 100 : ventaTotal;

    return { costoTotal, ventaTotal, margenResultantePct, ajusteRedondeo, ventaFinal };
});

// Desglose por categoría (Sesión 11l v2) — mismo catálogo proveedor_tipos
// ya cargado para "usar tarifa registrada" de hoteles (ver onMounted).
// Guía no tiene proveedor_tipo (no es un ProveedorServicio), por eso usa
// una categoría fija en vez de resolverla contra el catálogo.
const proveedorTipos = ref<ProveedorTipo[]>([]);

// "Sin categoría" (antes "Otros") — la mayoría de los servicios de
// proveedor todavía no tienen tipo_proveedor_id asignado (recién se
// agregó el selector para hacerlo en Destinos → Servicios asociados,
// ver destinos/index.vue), así que ESTE bucket va a concentrar casi
// todo hasta que se clasifiquen. El nombre es deliberadamente honesto
// (no es una categoría real, es "falta clasificar") para que no se
// confunda con Venta total en el resumen.
const categoriaItem = (item: PaquetePlantillaItem): string => {
    if (item.guia_tarifa) return 'Guía';
    const tipoProveedorId = item.proveedor_tarifa?.proveedor_servicio?.destino_servicio?.servicio?.tipo_proveedor_id;
    const tipo = proveedorTipos.value.find((t) => t.id === tipoProveedorId);
    return tipo?.nombre ?? 'Sin categoría';
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

const hayItemsSinCategoria = computed(() => desglosePorCategoria.value.some((c) => c.categoria === 'Sin categoría'));

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
        servicioService.listar({ per_page: 200 }).then((res) => { serviciosFiltro.value = res.servicios ?? []; });
        proveedorService.listar({ estado: true }).then((res) => { proveedoresFiltro.value = res.proveedores ?? []; });

        // cargarPaquete() va primero porque cargarItinerario() decide qué
        // pedir según esCombo.value (derivado de paquete.value). El resto
        // de las cargas de esta pantalla son independientes entre sí, así
        // que se disparan juntas con Promise.all en vez de un await por
        // línea — mismo resultado, pero un solo "round-trip" de espera en
        // vez de 4 seguidos.
        await cargarPaquete();
        await Promise.all([
            cargarItinerario(),
            cargarItems(),
            cargarBiblioteca(),
            guiaService.listar({}).then((res) => { guias.value = res.guias ?? []; }),
            proveedorTipoService.listar().then((tipos) => { proveedorTipos.value = tipos.proveedor_tipos; }),
        ]);
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo cargar el paquete/tour.', 'error');
    } finally {
        cargandoPagina.value = false;
    }
});
</script>

<style scoped>
/* ═══ Rediseño tab "Incluye" — selector de tipo, tarjetas de biblioteca/
   incluidos, barra de selección y panel sticky de resumen. Nombres con
   prefijo av- (Agencia de Viajes) para no chocar con clases globales. ═══ */

.av-type-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: .5rem;
}

.av-type-tab {
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    padding: .45rem .85rem;
    border: 1px solid #dee2e6;
    border-radius: .5rem;
    background: #fff;
    font-size: .8125rem;
    font-weight: 600;
    color: #495057;
    cursor: pointer;
    transition: border-color .15s ease, color .15s ease, background-color .15s ease;
}

.av-type-tab:hover {
    border-color: var(--bs-primary, #0d6efd);
    color: var(--bs-primary, #0d6efd);
}

.av-type-tab.active {
    background: var(--bs-primary, #0d6efd);
    border-color: var(--bs-primary, #0d6efd);
    color: #fff;
}

.av-type-tab-icon {
    font-size: .8rem;
}

.av-search {
    position: relative;
}

.av-search > i {
    position: absolute;
    left: .7rem;
    top: 50%;
    transform: translateY(-50%);
    color: #adb5bd;
    font-size: .75rem;
    pointer-events: none;
}

.av-search .form-control {
    padding-left: 1.9rem;
}

.av-lib-grid,
.av-included-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(255px, 1fr));
    gap: .6rem;
    padding: 2px;
}

.av-lib-grid {
    max-height: 320px;
    overflow-y: auto;
}

.av-item-card {
    position: relative;
    display: flex;
    align-items: flex-start;
    gap: .6rem;
    border: 1px solid #e9ecef;
    border-radius: .6rem;
    padding: .6rem .7rem;
    background: #fff;
    cursor: pointer;
    transition: transform .12s ease, box-shadow .12s ease, border-color .12s ease, opacity .12s ease;
}

.av-item-card:hover:not(.is-added) {
    border-color: var(--bs-primary, #0d6efd);
    box-shadow: 0 2px 10px rgba(13, 110, 253, .12);
    transform: translateY(-1px);
}

.av-item-card.is-selected {
    border-color: var(--bs-primary, #0d6efd);
    background: #f5f9ff;
    box-shadow: 0 0 0 2px rgba(13, 110, 253, .15) inset;
}

.av-item-card.is-added {
    opacity: .55;
    cursor: not-allowed;
}

.av-item-card--flat {
    cursor: default;
}

.av-item-card--flat:hover {
    transform: none;
    box-shadow: none;
    border-color: #e9ecef;
}

.av-item-icon {
    width: 34px;
    height: 34px;
    min-width: 34px;
    border-radius: .5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: .85rem;
}

.av-item-body {
    flex: 1;
    min-width: 0;
}

.av-item-title {
    font-size: .8125rem;
    font-weight: 600;
    color: #212529;
    line-height: 1.3;
}

.av-item-sub {
    font-size: .72rem;
    color: #6c757d;
    margin-top: 1px;
}

.av-item-tags {
    margin-top: .3rem;
}

.av-item-side {
    text-align: right;
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: .25rem;
    min-width: 72px;
}

.av-item-price {
    font-size: .78rem;
    font-weight: 700;
    color: #212529;
    white-space: nowrap;
}

.av-item-cost {
    font-size: .66rem;
    color: #adb5bd;
    white-space: nowrap;
}

.av-badge {
    display: inline-flex;
    align-items: center;
    gap: .25rem;
    font-size: .62rem;
    font-weight: 700;
    padding: .15rem .4rem;
    border-radius: 1rem;
    white-space: nowrap;
}

.av-badge-added {
    background: #e9ecef;
    color: #6c757d;
}

.av-badge-selected {
    background: #d1e7ff;
    color: #0d6efd;
}

.av-badge-muted {
    background: #f1f3f5;
    color: #868e96;
    font-weight: 600;
    margin-left: .25rem;
}

.av-selection-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .75rem;
    flex-wrap: wrap;
    background: #f5f9ff;
    border: 1px solid #d1e7ff;
    border-radius: .6rem;
    padding: .6rem .8rem;
}

.av-selection-info {
    font-size: .8125rem;
    font-weight: 600;
    color: #212529;
}

.av-group-title {
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #868e96;
    margin-bottom: .5rem;
}

.av-tour-card .av-chevron {
    transition: transform .15s ease;
}

/* Panel de resumen — sticky en pantallas grandes, en flujo normal en
   mobile (evita solaparse con el contenido en pantallas angostas). */
.av-summary-sticky {
    position: sticky;
    top: 1rem;
}

@media (max-width: 991.98px) {
    .av-summary-sticky {
        position: static;
    }
}

/* Transiciones — feedback visual al agregar/quitar ítems (punto 3 del
   rediseño). av-fade cubre tarjetas de biblioteca e ítems incluidos;
   av-expand cubre el acordeón de tours del combo. */
.av-fade-enter-active,
.av-fade-leave-active {
    transition: opacity .2s ease, transform .2s ease;
}

.av-fade-enter-from {
    opacity: 0;
    transform: translateY(-6px) scale(.98);
}

.av-fade-leave-to {
    opacity: 0;
    transform: translateX(12px) scale(.98);
}

.av-fade-leave-active {
    position: absolute;
}

.av-fade-move {
    transition: transform .2s ease;
}

.av-expand-enter-active,
.av-expand-leave-active {
    transition: opacity .18s ease;
}

.av-expand-enter-from,
.av-expand-leave-to {
    opacity: 0;
}
</style>
