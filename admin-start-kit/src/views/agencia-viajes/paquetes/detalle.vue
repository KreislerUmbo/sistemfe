<template>
    <DefaultLayout>
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
                <button class="btn" :class="paquete.activo ? 'btn-outline-danger' : 'btn-outline-success'" @click="toggleActivo">
                    <i class="fas me-2" :class="paquete.activo ? 'fa-ban' : 'fa-check-circle'"></i>{{ paquete.activo ? 'Desactivar' : 'Activar' }}
                </button>
                <router-link :to="`/agencia-viajes/paquetes/${paquete.id}/editar`" class="btn btn-outline-secondary">
                    <i class="fas fa-pen me-2"></i>Editar
                </router-link>
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
            <li class="nav-item">
                <a class="nav-link" :class="{ active: tabActiva === 'hoteles' }" href="#" @click.prevent="tabActiva = 'hoteles'">
                    <i class="fas fa-bed me-1"></i>Hoteles
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
                    <div class="col-12" v-if="paquete.descripcion"><strong>Descripción:</strong> {{ paquete.descripcion }}</div>
                    <div class="col-md-6" v-if="paquete.no_incluye"><strong>No incluye:</strong> {{ paquete.no_incluye }}</div>
                    <div class="col-md-6" v-if="paquete.recomendaciones"><strong>Recomendaciones:</strong> {{ paquete.recomendaciones }}</div>
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
                        <div class="col-12 col-md-3">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Descripción *</label>
                            <input type="text" class="form-control form-control-sm" v-model="formPaso.descripcion" placeholder="Ej. Visita orquideario">
                        </div>
                    </div>
                    <button class="btn btn-primary btn-sm mt-2" @click="agregarPaso">
                        <i class="fas fa-plus me-1"></i>Agregar paso
                    </button>
                </div>
            </div>

            <div v-for="(pasos, dia) in itinerarioPorDia" :key="dia" class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white border-bottom small fw-semibold">Día {{ dia }}</div>
                <ul class="list-group list-group-flush">
                    <li v-for="paso in pasos" :key="paso.id" class="list-group-item d-flex justify-content-between align-items-center small">
                        <span>
                            <span v-if="paso.hora" class="badge bg-light text-dark border me-2">{{ paso.hora }}</span>
                            <span v-else-if="paso.orden != null" class="badge bg-light text-dark border me-2">#{{ paso.orden }}</span>
                            {{ paso.descripcion }}
                            <span v-if="paso.destino_atractivo" class="text-muted"> — {{ paso.destino_atractivo.nombre }}</span>
                        </span>
                        <i class="fas fa-times text-danger" style="cursor:pointer" @click="quitarPaso(paso)"></i>
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
                        <div class="d-flex flex-column gap-1" style="max-height:220px;overflow-y:auto;">
                            <div v-for="t in bibliotecaTarifas" :key="t.id" class="border rounded p-2 small lib-item"
                                :class="{ 'border-primary bg-light': proveedorTarifaSeleccionada?.id === t.id }"
                                style="cursor:pointer" @click="proveedorTarifaSeleccionada = t">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <strong>{{ t.proveedor_servicio?.proveedor?.razon_social }}</strong>
                                        <span v-if="t.proveedor_servicio?.proveedor?.es_referencial" class="badge bg-secondary-subtle text-secondary border ms-1" style="font-size:10px">Referencial</span>
                                        <span class="text-muted"> — {{ t.proveedor_servicio?.destino_servicio?.servicio?.nombre }}<span v-if="t.tipo_habitacion"> · {{ t.tipo_habitacion }}</span></span>
                                    </div>
                                    <span class="badge bg-light text-dark border">{{ t.moneda }} {{ Number(t.precio_venta_adulto).toFixed(2) }}</span>
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
                                :class="{ 'border-primary bg-light': guiaTarifaSeleccionada?.id === t.id }"
                                style="cursor:pointer" @click="guiaTarifaSeleccionada = t">
                                {{ t.destino?.nombre }} — {{ t.modalidad === 'dia_local' ? 'Día local' : 'Grupo multidía' }} ({{ t.moneda }} {{ t.costo_diario }})
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
                            <button class="btn btn-primary btn-sm w-100" @click="agregarItem" :disabled="!proveedorTarifaSeleccionada && !guiaTarifaSeleccionada">
                                <i class="fas fa-plus me-1"></i>Agregar
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <ul class="list-group list-group-flush">
                    <li v-for="item in items" :key="item.id" class="list-group-item d-flex justify-content-between align-items-center small">
                        <span v-if="item.proveedor_tarifa">
                            <i class="fas fa-concierge-bell text-primary me-1"></i>
                            {{ item.proveedor_tarifa.proveedor_servicio?.proveedor?.razon_social }}
                            <span v-if="item.proveedor_tarifa.proveedor_servicio?.proveedor?.es_referencial" class="badge bg-secondary-subtle text-secondary border ms-1" style="font-size:10px">Referencial</span>
                            <span class="text-muted"> — {{ item.proveedor_tarifa.proveedor_servicio?.destino_servicio?.servicio?.nombre }}<span v-if="item.proveedor_tarifa.tipo_habitacion"> · {{ item.proveedor_tarifa.tipo_habitacion }}</span></span>
                        </span>
                        <span v-else-if="item.guia_tarifa">
                            <i class="fas fa-user-tie text-primary me-1"></i>
                            Guía: {{ item.guia_tarifa.guia?.nombre }}
                            <span v-if="item.guia_tarifa.guia?.es_referencial" class="badge bg-secondary-subtle text-secondary border ms-1" style="font-size:10px">Referencial</span>
                            <span class="text-muted"> — {{ item.guia_tarifa.destino?.nombre }}</span>
                        </span>
                        <span class="d-flex align-items-center gap-3">
                            <span class="badge bg-light text-dark border">{{ monedaItem(item) }} {{ ventaItem(item).toFixed(2) }}</span>
                            <i class="fas fa-times text-danger" style="cursor:pointer" @click="quitarItem(item)"></i>
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
                            <div class="fs-5 fw-bold" :class="totalesIncluye.margenResultantePct >= MARGEN_MINIMO_ACEPTABLE_PCT ? 'text-success' : 'text-danger'">
                                {{ totalesIncluye.margenResultantePct.toFixed(1) }}%
                            </div>
                        </div>
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
                                :class="{ 'border-primary bg-light': tourSeleccionado?.id === t.id }"
                                style="cursor:pointer" @click="tourSeleccionado = t">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <strong>{{ t.nombre }}</strong>
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
                        <div class="d-flex flex-column gap-1" style="max-height:220px;overflow-y:auto;">
                            <div v-for="t in bibliotecaTarifas" :key="t.id" class="border rounded p-2 small lib-item"
                                :class="{ 'border-primary bg-light': proveedorTarifaSeleccionada?.id === t.id }"
                                style="cursor:pointer" @click="proveedorTarifaSeleccionada = t">
                                <strong>{{ t.proveedor_servicio?.proveedor?.razon_social }}</strong>
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
                                :class="{ 'border-primary bg-light': guiaTarifaSeleccionada?.id === t.id }"
                                style="cursor:pointer" @click="guiaTarifaSeleccionada = t">
                                {{ t.destino?.nombre }} — {{ t.modalidad === 'dia_local' ? 'Día local' : 'Grupo multidía' }} ({{ t.moneda }} {{ t.costo_diario }})
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
                            <button class="btn btn-primary btn-sm w-100" @click="agregarItem" :disabled="!proveedorTarifaSeleccionada && !guiaTarifaSeleccionada && !tourSeleccionado">
                                <i class="fas fa-plus me-1"></i>Agregar
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
                        <i class="fas fa-times text-danger" style="cursor:pointer" @click.stop="quitarTourHijo(grupo.item)"></i>
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
                        <i class="fas fa-times text-danger" style="cursor:pointer" @click="quitarItem(item)"></i>
                    </li>
                </ul>
            </div>

            <div v-if="itemsPorTourAgrupados.length === 0 && itemsSueltos.length === 0" class="text-muted fst-italic text-center py-4">
                Este combo todavía no tiene tours ni ítems incluidos.
            </div>
        </div>

        <!-- ═══ TAB: Hoteles ═══ -->
        <div v-if="tabActiva === 'hoteles'">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white border-bottom py-2">
                    <span class="fw-semibold text-dark small">Agregar hotel</span>
                </div>
                <div class="card-body py-3">
                    <label class="form-label mb-1 small text-secondary">Nombre del hotel</label>
                    <input type="text" class="form-control form-control-sm mb-2" placeholder="Nombre del hotel" v-model="formHotel.nombre_hotel">
                    <div class="row g-2 mb-2">
                        <div class="col-6 col-md-4">
                            <label class="form-label mb-1 small text-secondary">Estrellas</label>
                            <input type="number" min="1" max="5" class="form-control form-control-sm" placeholder="Estrellas" v-model.number="formHotel.categoria_estrellas">
                        </div>
                        <div class="col-6 col-md-4">
                            <label class="form-label mb-1 small text-secondary">Moneda</label>
                            <select class="form-select form-select-sm" v-model="formHotel.moneda">
                                <option value="PEN">PEN</option>
                                <option value="USD">USD</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label mb-1 small text-secondary">Proveedor</label>
                            <select class="form-select form-select-sm" v-model="formHotel.proveedor_id" @change="onCambiarProveedorHotel">
                                <option :value="null">Hotel manual/referencial (sin proveedor)</option>
                                <option v-for="p in proveedoresHotel" :key="p.id" :value="p.id">{{ p.nombre_comercial ?? p.razon_social }}</option>
                            </select>
                        </div>
                    </div>
                    <!-- Encabezados de columna — cumplen el rol de label para cada fila
                         de tarifas sin repetir el texto en cada una (mismo criterio que
                         un <th>, ver misma estructura de columnas que la fila de abajo). -->
                    <div class="row g-1 mb-1">
                        <div class="col-3"><label class="form-label mb-0 small text-secondary">Tipo de habitación</label></div>
                        <div class="col-3" v-if="formHotel.proveedor_id"><label class="form-label mb-0 small text-secondary">Tarifa registrada</label></div>
                        <div :class="formHotel.proveedor_id ? 'col-2' : 'col-3'"><label class="form-label mb-0 small text-secondary">Costo</label></div>
                        <div :class="formHotel.proveedor_id ? 'col-2' : 'col-3'"><label class="form-label mb-0 small text-secondary">Venta</label></div>
                        <div class="col-1"></div>
                    </div>
                    <div v-for="(tf, idx) in formHotel.tarifas" :key="idx" class="row g-1 mb-1 align-items-center">
                        <div class="col-3">
                            <select class="form-select form-select-sm" v-model="tf.tipo_habitacion" @change="tf.proveedor_tarifa_id = null">
                                <option value="simple">Simple</option>
                                <option value="matrimonial">Matrimonial</option>
                                <option value="doble">Doble</option>
                                <option value="triple">Triple</option>
                                <option value="familiar">Familiar</option>
                            </select>
                        </div>
                        <div class="col-3" v-if="formHotel.proveedor_id">
                            <select class="form-select form-select-sm" :value="tf.proveedor_tarifa_id ?? ''" @change="onElegirTarifaRegistrada(tf, $event)">
                                <option value="">Manual</option>
                                <option v-for="t in tarifasHotelParaTipo(tf.tipo_habitacion)" :key="t.id" :value="t.id">
                                    Tarifa registrada ({{ t.precio_venta_adulto }})
                                </option>
                            </select>
                        </div>
                        <div :class="formHotel.proveedor_id ? 'col-2' : 'col-3'">
                            <input type="number" class="form-control form-control-sm" placeholder="Costo"
                                v-model.number="tf.precio_costo" :readonly="!!tf.proveedor_tarifa_id">
                        </div>
                        <div :class="formHotel.proveedor_id ? 'col-2' : 'col-3'">
                            <input type="number" class="form-control form-control-sm" placeholder="Venta"
                                v-model.number="tf.precio_venta" :readonly="!!tf.proveedor_tarifa_id">
                        </div>
                        <div class="col-1">
                            <button class="btn btn-sm btn-outline-danger" @click="formHotel.tarifas.splice(idx, 1)" :disabled="formHotel.tarifas.length === 1">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    <button class="btn btn-sm btn-outline-secondary mb-2" @click="formHotel.tarifas.push({ tipo_habitacion: 'doble', precio_costo: 0, precio_venta: 0, proveedor_tarifa_id: null })">
                        + tipo de habitación
                    </button>
                    <div>
                        <button class="btn btn-primary btn-sm" @click="guardarHotel">Guardar hotel</button>
                    </div>
                </div>
            </div>

            <div v-for="hotel in hoteles" :key="hotel.id" class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-2">
                    <span class="fw-semibold text-dark small">
                        <i class="fas fa-hotel me-1 text-primary"></i>{{ hotel.nombre_hotel }}
                        <span v-if="hotel.categoria_estrellas" class="text-warning ms-1">
                            <i v-for="n in hotel.categoria_estrellas" :key="n" class="fas fa-star" style="font-size:10px"></i>
                        </span>
                        <span class="text-muted ms-1">({{ hotel.moneda }})</span>
                    </span>
                    <i class="fas fa-times text-danger" style="cursor:pointer" @click="quitarHotel(hotel)"></i>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr class="small text-secondary text-uppercase">
                                <th class="ps-3">Habitación</th>
                                <th class="text-end">Costo</th>
                                <th class="text-end pe-3">Venta</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="tarifa in hotel.opciones_hotel_tarifas" :key="tarifa.id" class="small">
                                <td class="ps-3 text-capitalize">
                                    {{ tarifa.tipo_habitacion }}
                                    <i v-if="tarifa.proveedor_tarifa_id" class="fas fa-link text-primary ms-1" style="font-size:10px" title="Tarifa registrada de un proveedor"></i>
                                </td>
                                <td class="text-end">{{ tarifa.precio_costo }}</td>
                                <td class="text-end pe-3">{{ tarifa.precio_venta }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div v-if="hoteles.length === 0" class="text-muted fst-italic text-center py-4">
                Este paquete/tour todavía no tiene hoteles cargados.
            </div>
        </div>
    </DefaultLayout>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import DestinoTreeSelect from '@/components/AgenciaViajes/DestinoTreeSelect.vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { paquetePlantillaService } from '@/services/admin/paquetePlantillaService';
import { proveedorService, proveedorTipoService } from '@/services/admin/proveedorService';
import { guiaService } from '@/services/admin/guiaService';
import { formatFecha } from '@/helpers/fecha';
import type {
    PaquetePlantilla, PaquetePlantillaItem, TourItinerarioItem, OpcionHotel,
    ProveedorTarifa, Proveedor, Guia, GuiaTarifa, ComboDatos, ComboItinerarioPaso, PaquetePlantillaResumen,
} from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const route = useRoute();
const paqueteId = computed(() => Number(route.params.id));

const paquete = ref<PaquetePlantilla | null>(null);
const combo = ref<ComboDatos | null>(null);
const esCombo = computed(() => paquete.value?.tipo === 'paquete_combo');
const tabActiva = ref<'datos' | 'itinerario' | 'incluye' | 'hoteles'>('datos');

const etiquetaCategoria = (c: string) => ({ local: 'Local', nacional: 'Nacional', internacional: 'Internacional' } as Record<string, string>)[c] ?? c;

const cargarPaquete = async () => {
    const res = await paquetePlantillaService.obtener(paqueteId.value);
    paquete.value = res.paquete_plantilla;
    hoteles.value = res.opciones_hotel;
    combo.value = res.combo;
    if (combo.value) {
        descuentoTipoLocal.value = paquete.value.descuento_tipo ?? null;
        descuentoValorLocal.value = paquete.value.descuento_valor ?? null;
        margenMinimoLocal.value = paquete.value.margen_minimo_pct ?? null;
    }
};

// ── Activar/Desactivar (punto 6 del diseño) ──────────────────────────
const toggleActivo = async () => {
    if (!paquete.value) return;

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
};

const agregarPaso = async () => {
    if (!formPaso.value.descripcion.trim()) {
        (Swal as TVueSwalInstance).fire('Error', 'La descripción del paso es obligatoria.', 'error');
        return;
    }
    try {
        await paquetePlantillaService.agregarPasoItinerario(paqueteId.value, formPaso.value);
        formPaso.value = { dia_relativo: formPaso.value.dia_relativo, hora: null, orden: null, destino_atractivo_id: null, descripcion: '' };
        await cargarItinerario();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo agregar', 'error');
    }
};

const quitarPaso = async (paso: TourItinerarioItem) => {
    await paquetePlantillaService.quitarPasoItinerario(paso.id);
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

const onBibliotecaSearch = () => {
    clearTimeout(bibliotecaTimeout);
    bibliotecaTimeout = setTimeout(cargarBiblioteca, 300);
};

const cargarBiblioteca = async () => {
    const res = await proveedorService.biblioteca(bibliotecaSearch.value || undefined);
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

// ── Totales de "Incluye" (tour_simple) — costo/venta/margen, en vivo ──
const MARGEN_MINIMO_ACEPTABLE_PCT = 20;

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

const agregarItem = async () => {
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
    }
};

const quitarItem = async (item: PaquetePlantillaItem) => {
    await paquetePlantillaService.quitarItem(item.id);
    await cargarItems();
    await cargarPaquete();
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

// ── Hoteles ───────────────────────────────────────────────────────────
const hoteles = ref<OpcionHotel[]>([]);
const formHotel = ref<{
    nombre_hotel: string; categoria_estrellas: number | null; proveedor_id: number | null; moneda: 'PEN' | 'USD';
    tarifas: Array<{ tipo_habitacion: string; precio_costo: number; precio_venta: number; proveedor_tarifa_id?: number | null }>;
}>({
    nombre_hotel: '', categoria_estrellas: null, proveedor_id: null, moneda: 'PEN',
    tarifas: [{ tipo_habitacion: 'doble', precio_costo: 0, precio_venta: 0, proveedor_tarifa_id: null }],
});

// Sesión 11k, Fix 9 — proveedores tipo Hotel (para "usar tarifa registrada")
// + sus tarifas Hotel una vez elegido uno.
const proveedoresHotel = ref<Proveedor[]>([]);
const tarifasHotelProveedorSeleccionado = ref<ProveedorTarifa[]>([]);

const onCambiarProveedorHotel = async () => {
    formHotel.value.tarifas.forEach((tf) => { tf.proveedor_tarifa_id = null; });
    tarifasHotelProveedorSeleccionado.value = formHotel.value.proveedor_id
        ? (await proveedorService.tarifasHotel(formHotel.value.proveedor_id)).proveedor_tarifas
        : [];
};

const tarifasHotelParaTipo = (tipoHabitacion: string) => {
    return tarifasHotelProveedorSeleccionado.value.filter((t) => t.tipo_habitacion === tipoHabitacion);
};

const onElegirTarifaRegistrada = (tf: { precio_costo: number; precio_venta: number; proveedor_tarifa_id?: number | null }, event: Event) => {
    const id = Number((event.target as HTMLSelectElement).value) || null;
    tf.proveedor_tarifa_id = id;
    const tarifa = tarifasHotelProveedorSeleccionado.value.find((t) => t.id === id);
    if (tarifa) {
        tf.precio_costo = Number(tarifa.precio_costo);
        tf.precio_venta = Number(tarifa.precio_venta_adulto);
    }
};

const guardarHotel = async () => {
    if (!formHotel.value.nombre_hotel.trim()) {
        (Swal as TVueSwalInstance).fire('Error', 'El nombre del hotel es obligatorio.', 'error');
        return;
    }
    try {
        await paquetePlantillaService.agregarHotel(paqueteId.value, formHotel.value);
        formHotel.value = {
            nombre_hotel: '', categoria_estrellas: null, proveedor_id: null, moneda: 'PEN',
            tarifas: [{ tipo_habitacion: 'doble', precio_costo: 0, precio_venta: 0, proveedor_tarifa_id: null }],
        };
        tarifasHotelProveedorSeleccionado.value = [];
        await cargarPaquete();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo guardar', 'error');
    }
};

const quitarHotel = async (hotel: OpcionHotel) => {
    await paquetePlantillaService.quitarHotel(hotel.id);
    await cargarPaquete();
};

onMounted(async () => {
    await cargarPaquete();
    await cargarItinerario();
    await cargarItems();
    await cargarBiblioteca();

    const res = await guiaService.listar({});
    guias.value = res.guias ?? [];

    // Sesión 11k, Fix 9 — proveedores tipo Hotel, para "usar tarifa registrada".
    // slug='alojamiento-hoteles' es el slug REAL (confirmado contra datos
    // reales) — 'hotel' no matchea nada (ver mismo hallazgo documentado en
    // ProveedorController::tarifasHotel(), backend).
    const tipos = await proveedorTipoService.listar();
    const tipoHotel = tipos.proveedor_tipos.find((t) => t.slug === 'alojamiento-hoteles');
    if (tipoHotel) {
        const resProveedores = await proveedorService.listar({ tipo_id: tipoHotel.id, estado: true });
        proveedoresHotel.value = resProveedores.proveedores ?? [];
    }
});
</script>
