<template>
    <DefaultLayout>
        <div v-if="reserva && cabecera" class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div>
                <h5 class="fw-bold mb-0">
                    <i class="fas fa-suitcase-rolling me-2 text-primary"></i>Reserva {{ cabecera.codigo }}
                    <span class="badge ms-2" :class="reserva.estado === 'activa' ? 'bg-success' : 'bg-danger'">
                        {{ reserva.estado === 'activa' ? 'Activa' : 'Cancelada' }}
                    </span>
                    <span v-if="reserva.estado === 'activa' && (reserva.pasajeros?.length ?? 0) > 0" class="badge ms-2" :class="pasajerosPendientesFacturar.length === 0 ? 'bg-success' : 'bg-warning text-dark'">
                        Pasajeros: {{ (reserva.pasajeros?.length ?? 0) - pasajerosPendientesFacturar.length }}/{{ reserva.pasajeros?.length ?? 0 }} facturados
                    </span>
                    <span v-if="reserva.estado === 'activa'" class="badge ms-2" :class="itemsPendientesDeFacturarCount === 0 ? 'bg-success' : 'bg-warning text-dark'">
                        {{ itemsPendientesDeFacturarCount === 0 ? 'Servicios: todos facturados' : `Servicios: ${itemsPendientesDeFacturarCount} pendiente(s)` }}
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
                <router-link v-if="reserva.alternativa?.cotizacion_id" :to="`/agencia-viajes/cotizador/${reserva.alternativa.cotizacion_id}`"
                    class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-file-lines me-1"></i>Ver cotización
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
                <button v-if="reserva.estado === 'activa'" class="btn btn-outline-success btn-sm" @click="abrirModalAnticipo">
                    <i class="fas fa-hand-holding-usd me-1"></i>Cobrar anticipo
                </button>
                <button v-if="reserva.estado === 'activa'" class="btn btn-outline-primary btn-sm" @click="abrirModalReprogramar">
                    <i class="fas fa-calendar-days me-1"></i>Reprogramar viaje
                </button>
                <button v-if="mostrarBotonReasignarMayorista" class="btn btn-outline-primary btn-sm" @click="abrirModalReasignarMayorista">
                    <i class="fas fa-right-left me-1"></i>Reasignar mayorista
                </button>
                <button v-if="mostrarBotonReasignarHotel" class="btn btn-outline-primary btn-sm" @click="abrirModalReasignarHotel">
                    <i class="fas fa-bed me-1"></i>Reasignar hotel
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
                Reserva reprogramada. {{ itemsNoTocadosReprogramacion.length }} servicio(s) quedaron con su fecha
                de antes — revísalos y corrígelos a mano si hace falta:
                <span v-for="(it, idx) in itemsNoTocadosReprogramacion" :key="it.reserva_item_id">
                    {{ it.nombre }} ({{ formatFecha(it.fecha) }},
                    {{ it.motivo === 'manual' ? 'editado a mano antes' : 'sin datos para recalcular solo' }})<span v-if="idx < itemsNoTocadosReprogramacion.length - 1">, </span>
                </span>.
            </span>
            <button class="btn-close" @click="itemsNoTocadosReprogramacion = []"></button>
        </div>

        <!-- Anticipos recibidos (Tier 0 — conexión Adelantos↔Reservas,
             2026-08-21): visible solo si el cliente ya pagó algo hacia esta
             reserva. El comprobante propio de cada anticipo se maneja desde
             el módulo de Adelantos; acá solo se ve el resumen y se puede
             desasociar mientras no se haya aplicado a ninguna venta. -->
        <div v-if="anticipos.length > 0" class="card border-0 shadow-sm mb-3">
            <div class="card-body py-2">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="fw-semibold mb-0"><i class="fas fa-hand-holding-usd me-1 text-success"></i>Anticipos recibidos</h6>
                    <span class="small text-muted">Disponible para aplicar: {{ moneda }} {{ totalAnticiposDisponibles.toFixed(2) }}</span>
                </div>
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Fecha</th>
                            <th class="text-end">Monto</th>
                            <th class="text-end">Disponible</th>
                            <th>Estado SUNAT</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in anticipos" :key="a.id">
                            <td>{{ formatFecha(a.fecha_asignacion) }}</td>
                            <td class="text-end">{{ moneda }} {{ a.monto.toFixed(2) }}</td>
                            <td class="text-end">{{ moneda }} {{ a.disponible.toFixed(2) }}</td>
                            <td>
                                <span class="badge" :class="a.comprobante_enviado ? 'bg-success' : 'bg-warning text-dark'">
                                    {{ a.comprobante_enviado ? 'Enviado a SUNAT' : 'Registrado, sin enviar' }}
                                </span>
                            </td>
                            <td class="text-end">
                                <button v-if="a.disponible === a.monto" type="button" class="btn btn-sm btn-link text-danger p-0"
                                    title="Quitar de esta reserva" @click="quitarAnticipo(a)">
                                    <i class="fas fa-times"></i>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Comprobantes emitidos (2026-09-22) — todas las Sale reales
             generadas contra esta reserva. Puede haber varias (facturación
             múltiple por sub-grupo de pasajeros/cliente). estado_sunat
             distingue un borrador nunca enviado o rechazado de uno
             realmente aceptado (ver EstadoFacturacionReserva). -->
        <div v-if="comprobantes.length > 0" class="card border-0 shadow-sm mb-3">
            <div class="card-body py-2">
                <h6 class="fw-semibold mb-2"><i class="fas fa-file-invoice me-1 text-primary"></i>Comprobantes emitidos</h6>
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Comprobante</th>
                            <th>Fecha</th>
                            <th class="text-end">Total</th>
                            <th>Estado SUNAT</th>
                            <th class="text-center">Cubre</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in comprobantes" :key="c.reserva_venta_id">
                            <td class="fw-semibold">
                                {{ c.n_operacion ?? (c.serie ? `${c.serie}-${String(c.correlativo).padStart(8, '0')}` : 'Sin serie') }}
                            </td>
                            <td>{{ formatFecha(c.fecha) }}</td>
                            <td class="text-end">{{ c.moneda }} {{ c.total.toFixed(2) }}</td>
                            <td>
                                <span class="badge" :class="badgeEstadoSunat(c.estado_sunat).clase" :title="c.sunat_error_message ?? ''">
                                    {{ badgeEstadoSunat(c.estado_sunat).texto }}
                                </span>
                            </td>
                            <td class="text-center small text-muted">{{ c.cantidad_items }} servicio(s) · {{ c.cantidad_pasajeros }} pax</td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-link p-0" title="Ver / imprimir comprobante"
                                    @click="imprimirComprobante(c.sale_id)">
                                    <i class="fas fa-print"></i>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
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
                            Servicios <span class="badge bg-warning text-dark ms-1">{{ itemsSinAsignar }}</span>
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" :class="{ active: tab === 'asignacion' }" @click="cambiarAAsignacion">
                            Pasajeros por servicio
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
                                <span class="d-flex align-items-center gap-2">
                                    <span class="badge" :class="esPasajeroCompleto(p) ? 'bg-success' : 'bg-warning text-dark'">
                                        {{ esPasajeroCompleto(p) ? 'Completo' : 'Incompleto' }}
                                    </span>
                                    <button v-if="reserva.estado === 'activa' && (reserva.pasajeros?.length ?? 0) > 1" type="button"
                                        class="btn btn-sm btn-link text-danger p-0" title="Quitar este pasajero de la reserva"
                                        :disabled="eliminandoPasajeroId === p.id" @click.stop="quitarPasajero(p)">
                                        <span v-if="eliminandoPasajeroId === p.id" class="spinner-border spinner-border-sm"></span>
                                        <i v-else class="fas fa-trash-can"></i>
                                    </button>
                                </span>
                            </div>

                            <!-- fieldset :disabled — deshabilita nativamente todos los inputs/
                                 botones anidados cuando la reserva no está activa, sin tener
                                 que atar :disabled campo por campo (mismo criterio que el
                                 backend, ver ReservaPasajeroController::update()). -->
                            <fieldset v-if="formPaxAbierto === p.id" class="mt-3 border-top pt-3" :disabled="reserva.estado !== 'activa'">
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

                                <!-- Vuelo con la AGENCIA — distinto del bloque de arriba (por cuenta
                                     propia). Un bloque por cada pasaje aéreo real de la reserva,
                                     siempre visible para todos los pasajeros (no depende del checkbox
                                     del tab Asignación — ver corrección 2026-08-27 en el backend). -->
                                <div v-if="itemsVueloAgencia.length > 0" class="border-top pt-3 mt-3">
                                    <small class="text-muted d-block mb-2"><i class="fas fa-plane-departure me-1"></i>Vuelo(s) vendido(s) por la agencia.</small>
                                    <div v-for="it in itemsVueloAgencia" :key="it.id" class="mb-3">
                                        <p class="small fw-semibold mb-2">
                                            {{ nombreItem(it) }}
                                            <span v-if="itemsFacturadosIds.includes(it.id)" class="badge bg-success-subtle text-success border ms-2">Facturado</span>
                                        </p>
                                        <div class="row g-2">
                                            <div class="col-md-4">
                                                <label class="form-label small text-secondary mb-1">Aerolínea confirmada</label>
                                                <input type="text" class="form-control form-control-sm" :placeholder="aerolineaCotizada(it) ?? 'Sin definir'" v-model="vueloAgenciaForm(it, p).vuelo_aerolinea_confirmada">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label small text-secondary mb-1">Vuelo ida — número</label>
                                                <input type="text" class="form-control form-control-sm" placeholder="Ej. LA2050" v-model="vueloAgenciaForm(it, p).vuelo_numero_ida">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label small text-secondary mb-1">Ida — fecha</label>
                                                <input type="date" class="form-control form-control-sm" v-model="vueloAgenciaForm(it, p).vuelo_fecha_ida">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label small text-secondary mb-1">Ida — hora</label>
                                                <input type="time" class="form-control form-control-sm" v-model="vueloAgenciaForm(it, p).vuelo_hora_ida">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label small text-secondary mb-1">Vuelo vuelta — número</label>
                                                <input type="text" class="form-control form-control-sm" placeholder="Ej. LA2051" v-model="vueloAgenciaForm(it, p).vuelo_numero_vuelta">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label small text-secondary mb-1">Vuelta — fecha</label>
                                                <input type="date" class="form-control form-control-sm" v-model="vueloAgenciaForm(it, p).vuelo_fecha_vuelta">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label small text-secondary mb-1">Vuelta — hora</label>
                                                <input type="time" class="form-control form-control-sm" v-model="vueloAgenciaForm(it, p).vuelo_hora_vuelta">
                                            </div>
                                        </div>
                                        <button class="btn btn-outline-primary btn-sm mt-2" :disabled="guardandoVueloAgenciaKey === claveVuelo(it, p)" @click="guardarVueloAgencia(it, p)">
                                            <span v-if="guardandoVueloAgenciaKey === claveVuelo(it, p)" class="spinner-border spinner-border-sm me-1"></span>
                                            <i v-else class="fas fa-check me-1"></i>Guardar vuelo
                                        </button>
                                    </div>
                                </div>

                                <button class="btn btn-primary btn-sm mt-2" :disabled="guardandoPax === p.id" @click="guardarPax(p)">
                                    <span v-if="guardandoPax === p.id" class="spinner-border spinner-border-sm me-1"></span>
                                    <i v-else class="fas fa-check me-1"></i>Guardar
                                </button>
                            </fieldset>
                        </div>
                    </div>
                    <div v-if="(reserva.pasajeros?.length ?? 0) === 0" class="text-muted text-center py-4">Sin pasajeros.</div>
                </div>

                <!-- TAB ITEMS -->
                <div v-if="tab === 'items'" class="d-flex flex-column gap-2">
                    <div v-for="it in reserva.items" :key="it.id" class="card border-0 shadow-sm">
                        <div class="card-body py-2">
                            <div class="mb-2 d-flex justify-content-between align-items-start">
                                <div>
                                    <strong>{{ nombreItem(it) }}</strong>
                                    <span v-if="it.fecha_origen === 'manual'" class="badge bg-light text-dark border ms-2" title="Esta fecha fue editada a mano — una reprogramación de la reserva no la mueve sola">
                                        <i class="fas fa-hand me-1"></i>Fecha manual
                                    </span>
                                    <span v-if="itemsFacturadosIds.includes(it.id)" class="badge bg-success-subtle text-success border ms-2">
                                        <i class="fas fa-file-invoice me-1"></i>Facturado
                                    </span>
                                    <span v-if="(it.veces_reasignado_mayorista ?? 0) > 0" class="badge bg-info-subtle text-info-emphasis border ms-2"
                                        :title="it.motivo_reasignacion_mayorista ?? ''">
                                        <i class="fas fa-right-left me-1"></i>Mayorista reasignado {{ it.veces_reasignado_mayorista }}x
                                    </span>
                                    <!-- 2026-09-23 — antes esto solo se descubría al chocar con el
                                         bloqueo de "mezcla tributaria" al intentar facturar. -->
                                    <span class="badge ms-2" :class="badgeTratamientoTributario(it).clase" title="Tratamiento tributario de este servicio">
                                        <i class="fas fa-file-invoice-dollar me-1"></i>{{ badgeTratamientoTributario(it).texto }}
                                    </span>
                                    <!-- Caso 3 Amazonía (2026-09-24) — un servicio con destino≠nacional
                                         no se puede facturar hasta que alguien lo confirme a mano
                                         (ver overrideTratamientoTributario()). -->
                                    <span v-if="requiereConfirmacionTributaria(it)" class="badge bg-warning-subtle text-warning-emphasis border ms-2">
                                        Sin confirmar
                                    </span>
                                    <button v-if="reserva.estado === 'activa' && requiereConfirmacionTributaria(it)" type="button"
                                        class="btn btn-sm btn-link p-0 ms-2" @click="abrirModalConfirmarTributario([it])">
                                        Confirmar tratamiento tributario
                                    </button>
                                </div>
                                <button v-if="reserva.estado === 'activa' && !itemsFacturadosIds.includes(it.id)" type="button"
                                    class="btn btn-sm btn-link text-danger p-0" title="Quitar este servicio de la reserva"
                                    :disabled="eliminandoItemId === it.id" @click="quitarItem(it)">
                                    <span v-if="eliminandoItemId === it.id" class="spinner-border spinner-border-sm"></span>
                                    <i v-else class="fas fa-trash-can"></i>
                                </button>
                            </div>
                            <div v-if="destinoItem(it)" class="small text-muted mb-2"><i class="fas fa-map-marker-alt me-1"></i>{{ destinoItem(it) }}</div>
                            <!-- Vuelo vendido por la agencia: solo lectura acá, se edita por
                                 pasajero en el tab Pasajeros (decidido con el usuario — es la
                                 pregunta que se hace el vendedor por persona, no por ítem). -->
                            <div v-if="it.alternativa_item?.origen_tipo === 'pasaje_aereo'" class="small mb-2">
                                <span class="badge" :class="resumenVueloAgencia(it).total > 0 && resumenVueloAgencia(it).confirmados === resumenVueloAgencia(it).total ? 'bg-success-subtle text-success border' : 'bg-warning-subtle text-warning-emphasis border'">
                                    <i class="fas fa-plane-departure me-1"></i>{{ resumenVueloAgencia(it).confirmados }}/{{ resumenVueloAgencia(it).total }} pasajeros con vuelo confirmado
                                </span>
                                <span class="text-muted ms-1">— editar desde el tab Pasajeros</span>
                            </div>
                            <!-- fieldset :disabled — mismo criterio que el tab de pasajeros:
                                 con la reserva no activa, nada de esto es editable (backend
                                 ya lo rechaza, ver ReservaItemController::update()). El
                                 buscador de proveedor no es un <select> nativo, se guarda
                                 aparte con reserva.estado === 'activa' en su propio @click. -->
                            <fieldset class="row g-2" :disabled="reserva.estado !== 'activa'">
                                <div class="col-md-3" v-if="it.alternativa_item?.origen_tipo === 'proveedor'">
                                    <label class="form-label small text-secondary mb-1">Proveedor</label>
                                    <div v-if="proveedorBuscando !== it.id" class="d-flex align-items-center justify-content-between border rounded px-2 py-1 bg-white"
                                        style="cursor:pointer;min-height:31px" @click="abrirBusquedaProveedor(it)">
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
                                                {{ t.proveedor_servicio?.proveedor?.nombre_comercial || t.proveedor_servicio?.proveedor?.razon_social || ('Tarifa #' + t.id) }}{{ t.proveedor_servicio?.proveedor?.es_referencial ? ' (Referencial)' : '' }}
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
                            </fieldset>
                            <p v-if="tieneAsignacionAplicable(it) && !it.proveedor_tarifa_id && !it.guia_id" class="small mt-2 mb-0" style="color:#adb5bd;font-style:italic">
                                <i class="fas fa-triangle-exclamation me-1"></i>Sin asignar todavía — no bloquea el resto de la reserva
                            </p>
                        </div>
                    </div>
                    <div v-if="(reserva.items?.length ?? 0) === 0" class="text-muted text-center py-4">Sin servicios.</div>
                </div>

                <!-- TAB ASIGNACION -->
                <div v-if="tab === 'asignacion'" class="d-flex flex-column gap-3">
                    <div v-for="it in reserva.items" :key="it.id" class="card border-0 shadow-sm">
                        <div class="card-body py-2">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <div>
                                    <p class="fw-semibold mb-0">{{ nombreItem(it) }}</p>
                                    <div v-if="destinoItem(it)" class="small text-muted"><i class="fas fa-map-marker-alt me-1"></i>{{ destinoItem(it) }}</div>
                                </div>
                                <!-- "Marcar/desmarcar todos" — con reservas grupales grandes (10+
                                     pasajeros) tildar uno por uno era tedioso, hallazgo de la
                                     auditoría de UX del módulo. Sin endpoint masivo en el backend,
                                     se resuelve en el frontend con un Promise.all (mismo criterio ya
                                     usado en cotizador/editar.vue::eliminarBloque()). -->
                                <button v-if="reserva.estado === 'activa' && (reserva.pasajeros?.length ?? 0) > 1" type="button"
                                    class="btn btn-sm btn-link p-0" :disabled="asignacionMasivaEnProceso.has(it.id)"
                                    @click="toggleAsignacionMasiva(it)">
                                    <span v-if="asignacionMasivaEnProceso.has(it.id)" class="spinner-border spinner-border-sm me-1"></span>
                                    {{ todosAsignados(it) ? 'Desmarcar todos' : 'Marcar todos' }}
                                </button>
                            </div>
                            <fieldset class="d-flex flex-wrap gap-3" :disabled="reserva.estado !== 'activa' || asignacionMasivaEnProceso.has(it.id)">
                                <label v-for="p in reserva.pasajeros" :key="p.id" class="small d-flex align-items-center gap-1" style="cursor:pointer">
                                    <input type="checkbox" :checked="estaAsignado(it.id, p.id)" :disabled="asignacionEnProceso.has(claveAsignacion(it, p))" @change="toggleAsignacion(it, p)">
                                    {{ p.nombre || ('Pasajero ' + p.id) }} <span class="text-muted">({{ etiquetaTipoPax(p.tipo_pax) }})</span>
                                </label>
                            </fieldset>
                        </div>
                    </div>
                    <div v-if="(reserva.items?.length ?? 0) === 0" class="text-muted text-center py-4">Sin servicios.</div>
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

                        <div class="d-flex flex-column gap-3 mb-3">
                            <div v-for="bloque in resumenAgrupado" :key="bloque.tourOrigenId ?? 'sueltos'">
                                <p v-if="bloque.tourNombre" class="small fw-semibold text-secondary mb-1">
                                    <i class="fas fa-route me-1"></i>{{ bloque.tourNombre }}
                                </p>
                                <div class="d-flex flex-column gap-2 small">
                                    <div v-for="r in bloque.items" :key="r.reserva_item_id" class="d-flex justify-content-between">
                                        <span>{{ r.nombre }}<span v-if="r.fecha" class="text-muted"> — {{ formatFecha(r.fecha) }}</span></span>
                                        <span class="fw-semibold">{{ moneda }} {{ Number(r.total_convertido).toFixed(2) }}</span>
                                    </div>
                                </div>
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
                            <span><i class="fas fa-clipboard-check me-1 text-muted"></i>Servicios con proveedor/guía</span>
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
                        <div v-if="totalAnticiposDisponibles > 0" class="alert alert-warning small mt-2 mb-0 py-2">
                            <i class="fas fa-triangle-exclamation me-1"></i>
                            Esta reserva tiene <strong>{{ moneda }} {{ totalAnticiposDisponibles.toFixed(2) }}</strong> en
                            anticipos cobrados sin aplicar a ninguna venta. Si cancelás igual, quedarán disponibles en el
                            módulo de Adelantos — revisá si corresponde reembolsarlos.
                        </div>
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
                            <i class="fas fa-info-circle me-1"></i>Los servicios con fecha editada a mano
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

        <!-- Modal reasignar mayorista (Sesión 12h, auditoria-arquitectonica-
             agencia-viajes.md §9.2) — mismo componente/estilo que
             "Reprogramar viaje". NUNCA ajusta precio_venta_snapshot del
             cliente, solo referencia de quién opera el destino. -->
        <div class="modal fade" tabindex="-1" :class="{ show: mostrarModalReasignarMayorista, 'd-block': mostrarModalReasignarMayorista }"
            style="background:rgba(0,0,0,.5)" v-if="mostrarModalReasignarMayorista">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold">Reasignar mayorista</h6>
                        <button class="btn-close" @click="mostrarModalReasignarMayorista = false"></button>
                    </div>
                    <div class="modal-body">
                        <div v-if="mayoristasActualesDistintos.length > 1" class="mb-3">
                            <label class="form-label small fw-semibold text-secondary">Mayorista a reasignar</label>
                            <select class="form-select form-select-sm" v-model.number="reasignarForm.opcion_mayorista_actual_id" @change="onCambiarMayoristaActual">
                                <option v-for="m in mayoristasActualesDistintos" :key="m.id" :value="m.id">{{ m.nombre }} ({{ m.itemsCount }} servicio{{ m.itemsCount === 1 ? '' : 's' }})</option>
                            </select>
                        </div>

                        <div class="card bg-light border-0 p-2 mb-3 small">
                            <span class="text-muted">Mayorista actual</span>
                            <strong>{{ mayoristaActualNombre }}</strong>
                        </div>

                        <label class="form-label small fw-semibold text-secondary">Servicios afectados</label>
                        <div class="d-flex flex-column gap-1 mb-3">
                            <label v-for="it in itemsDelMayoristaActual" :key="it.id" class="small d-flex align-items-center border rounded px-2 py-1" style="cursor:pointer">
                                <input type="checkbox" class="form-check-input me-2" v-model="reasignarForm.reserva_item_ids" :value="it.id">
                                {{ nombreItem(it) }}
                            </label>
                        </div>

                        <label class="form-label small fw-semibold text-secondary">Nueva opción de mayorista</label>
                        <select class="form-select form-select-sm mb-2" v-model.number="reasignarForm.nueva_opcion_mayorista_id" @change="reasignarForm.nueva_opcion_hotel_tarifa_id = null">
                            <option :value="null" disabled>Elegí una opción...</option>
                            <option v-for="op in opcionesMayoristaCandidatas" :key="op.id" :value="op.id">
                                {{ op.proveedor?.nombre_comercial || op.proveedor?.razon_social }} ({{ op.estado }})
                            </option>
                        </select>
                        <p v-if="!cargandoOpcionesMayorista && opcionesMayoristaCandidatas.length === 0" class="small text-muted">
                            No hay otra opción de mayorista candidata en este destino todavía — agregala desde la cotización antes de reasignar.
                        </p>

                        <template v-if="reasignarForm.nueva_opcion_mayorista_id">
                            <label class="form-label small fw-semibold text-secondary">Tarifa de habitación <span class="text-muted fw-normal">(opcional — solo para ver la diferencia de costo)</span></label>
                            <select class="form-select form-select-sm mb-3" v-model.number="reasignarForm.nueva_opcion_hotel_tarifa_id">
                                <option :value="null">Sin especificar</option>
                                <option v-for="t in tarifasDeLaNuevaOpcion" :key="t.id" :value="t.id">
                                    {{ t.opcion_hotel_nombre }} · {{ t.tipo_habitacion }} — {{ mayoristaActualMoneda }} {{ Number(t.precio_costo).toFixed(2) }}
                                </option>
                            </select>
                        </template>

                        <label class="form-label small fw-semibold text-secondary">Motivo</label>
                        <textarea class="form-control form-control-sm" rows="2" v-model="reasignarForm.motivo" placeholder="Ej. Mayorista A no confirmó cupo para las fechas del viaje"></textarea>

                        <div class="card border-0 p-2 mt-3 small" :class="diferenciaCostoPreview.clase">
                            <div class="d-flex justify-content-between">
                                <span>Costo anterior</span>
                                <span>{{ mayoristaActualMoneda }} {{ diferenciaCostoPreview.costoAnterior.toFixed(2) }}</span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Costo nuevo</span>
                                <span>{{ diferenciaCostoPreview.costoNuevo !== null ? `${mayoristaActualMoneda} ${diferenciaCostoPreview.costoNuevo.toFixed(2)}` : 'Elegí una tarifa para verlo' }}</span>
                            </div>
                            <p class="mb-0 mt-1" style="font-size:11px">
                                <i class="fas fa-info-circle me-1"></i>El precio que ve el cliente NO cambia automáticamente. Si el negocio decide ajustarlo, es una acción manual aparte desde Facturación.
                            </p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-outline-secondary btn-sm" @click="mostrarModalReasignarMayorista = false">Volver</button>
                        <button class="btn btn-primary btn-sm"
                            :disabled="reasignandoMayorista || !reasignarForm.nueva_opcion_mayorista_id || !reasignarForm.motivo || reasignarForm.reserva_item_ids.length === 0"
                            @click="confirmarReasignarMayorista">
                            <span v-if="reasignandoMayorista" class="spinner-border spinner-border-sm me-1"></span>Confirmar reasignación
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal reasignar hotel (18-sep-2026) — espejo de "Reasignar
             mayorista", para el hotel Local/Nacional (catálogo real o
             ad-hoc). Deja cruzar entre catálogo y ad-hoc: el caso real que
             lo motivó fue justo notar que un hotel ad-hoc ya cargado en la
             cotización en realidad estaba en el catálogo de Proveedores
             (o viceversa). NUNCA toca precio_venta_snapshot del cliente. -->
        <div class="modal fade" tabindex="-1" :class="{ show: mostrarModalReasignarHotel, 'd-block': mostrarModalReasignarHotel }"
            style="background:rgba(0,0,0,.5)" v-if="mostrarModalReasignarHotel">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold">Reasignar hotel</h6>
                        <button class="btn-close" @click="mostrarModalReasignarHotel = false"></button>
                    </div>
                    <div class="modal-body">
                        <div v-if="hotelesActualesDistintos.length > 1" class="mb-3">
                            <label class="form-label small fw-semibold text-secondary">Hotel a reasignar</label>
                            <select class="form-select form-select-sm" v-model="reasignarHotelForm.clave_hotel_actual" @change="onCambiarHotelActual">
                                <option v-for="h in hotelesActualesDistintos" :key="h.clave" :value="h.clave">{{ h.nombre }} ({{ h.itemsCount }} servicio{{ h.itemsCount === 1 ? '' : 's' }})</option>
                            </select>
                        </div>

                        <div class="card bg-light border-0 p-2 mb-3 small">
                            <span class="text-muted">Hotel actual</span>
                            <strong>{{ hotelActualNombre }}</strong>
                        </div>

                        <label class="form-label small fw-semibold text-secondary">Servicios afectados</label>
                        <div class="d-flex flex-column gap-1 mb-3">
                            <label v-for="it in itemsDelHotelActual" :key="it.id" class="small d-flex align-items-center border rounded px-2 py-1" style="cursor:pointer">
                                <input type="checkbox" class="form-check-input me-2" v-model="reasignarHotelForm.reserva_item_ids" :value="it.id">
                                {{ nombreItem(it) }}
                            </label>
                        </div>

                        <ul class="nav nav-tabs nav-tabs-sm small mb-2">
                            <li class="nav-item">
                                <a class="nav-link" :class="{ active: origenNuevoHotel === 'catalogo' }" href="#" @click.prevent="origenNuevoHotel = 'catalogo'">Del catálogo</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" :class="{ active: origenNuevoHotel === 'adhoc' }" href="#" @click.prevent="origenNuevoHotel = 'adhoc'">Ad-hoc de esta reserva</a>
                            </li>
                        </ul>

                        <template v-if="origenNuevoHotel === 'catalogo'">
                            <input type="text" class="form-control form-control-sm mb-2" placeholder="Buscar hotel por nombre..." v-model="buscarHotelCatalogo" @input="buscarTarifasHotelCatalogo">
                            <div v-if="buscandoTarifasCatalogo" class="small text-muted mb-2"><span class="spinner-border spinner-border-sm me-1"></span>Buscando...</div>
                            <select v-else class="form-select form-select-sm mb-3" v-model.number="reasignarHotelForm.nueva_proveedor_tarifa_id" @change="reasignarHotelForm.nuevo_opcion_hotel_tarifa_id = null">
                                <option :value="null" disabled>Elegí una tarifa...</option>
                                <option v-for="t in tarifasHotelCatalogoCandidatas" :key="t.id" :value="t.id">
                                    {{ (t.proveedor_servicio?.proveedor?.nombre_comercial || t.proveedor_servicio?.proveedor?.razon_social) ?? 'Hotel' }} · {{ t.tipo_habitacion }} — {{ t.moneda }} {{ Number(t.precio_costo).toFixed(2) }}
                                </option>
                            </select>
                            <p v-if="!buscandoTarifasCatalogo && buscarHotelCatalogo && tarifasHotelCatalogoCandidatas.length === 0" class="small text-muted">Sin resultados para "{{ buscarHotelCatalogo }}".</p>
                        </template>
                        <template v-else>
                            <select class="form-select form-select-sm mb-3" v-model.number="reasignarHotelForm.nuevo_opcion_hotel_tarifa_id" @change="reasignarHotelForm.nueva_proveedor_tarifa_id = null">
                                <option :value="null" disabled>Elegí una tarifa...</option>
                                <option v-for="t in tarifasHotelAdhocCandidatas" :key="t.id" :value="t.id">
                                    {{ t.opcion_hotel?.nombre_hotel ?? 'Hotel' }} · {{ t.tipo_habitacion }} — {{ Number(t.precio_costo).toFixed(2) }}
                                </option>
                            </select>
                            <p v-if="tarifasHotelAdhocCandidatas.length === 0" class="small text-muted">No hay otro hotel ad-hoc cargado en esta reserva todavía.</p>
                        </template>

                        <label class="form-label small fw-semibold text-secondary">Motivo</label>
                        <textarea class="form-control form-control-sm" rows="2" v-model="reasignarHotelForm.motivo" placeholder="Ej. El hotel se quedó sin cupo para esas fechas"></textarea>

                        <div class="card border-0 p-2 mt-3 small" :class="diferenciaCostoHotelPreview.clase">
                            <div class="d-flex justify-content-between">
                                <span>Costo anterior</span>
                                <span>{{ diferenciaCostoHotelPreview.costoAnterior.toFixed(2) }}</span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Costo nuevo</span>
                                <span>{{ diferenciaCostoHotelPreview.costoNuevo !== null ? diferenciaCostoHotelPreview.costoNuevo.toFixed(2) : 'Elegí una tarifa para verlo' }}</span>
                            </div>
                            <p class="mb-0 mt-1" style="font-size:11px">
                                <i class="fas fa-info-circle me-1"></i>El precio que ve el cliente NO cambia automáticamente. Si el negocio decide ajustarlo, es una acción manual aparte desde Facturación.
                            </p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-outline-secondary btn-sm" @click="mostrarModalReasignarHotel = false">Volver</button>
                        <button class="btn btn-primary btn-sm"
                            :disabled="reasignandoHotel || (!reasignarHotelForm.nueva_proveedor_tarifa_id && !reasignarHotelForm.nuevo_opcion_hotel_tarifa_id) || !reasignarHotelForm.motivo || reasignarHotelForm.reserva_item_ids.length === 0"
                            @click="confirmarReasignarHotel">
                            <span v-if="reasignandoHotel" class="spinner-border spinner-border-sm me-1"></span>Confirmar reasignación
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal confirmar tratamiento tributario — Caso 3 Amazonía
             (2026-09-24). Un servicio con destino≠nacional no se puede
             facturar hasta que un humano lo confirme a mano, con motivo
             obligatorio (auditable) — ver overrideTratamientoTributario()
             en el backend. -->
        <div class="modal fade" tabindex="-1" :class="{ show: mostrarModalConfirmarTributario, 'd-block': mostrarModalConfirmarTributario }"
            style="background:rgba(0,0,0,.5)" v-if="mostrarModalConfirmarTributario">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold">Confirmar tratamiento tributario</h6>
                        <button class="btn-close" @click="mostrarModalConfirmarTributario = false"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-muted">
                            Un servicio con tratamiento ≠ nacional (ej. Amazonía) no se puede facturar hasta confirmarlo
                            a mano — esto queda registrado con el motivo, no se puede deshacer sin dejar rastro.
                        </p>
                        <label class="form-label small fw-semibold text-secondary">Servicios a confirmar</label>
                        <ul class="mb-3 small ps-3">
                            <li v-for="it in confirmarTributarioForm.items" :key="it.id">{{ nombreItem(it) }}</li>
                        </ul>
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="form-label small fw-semibold text-secondary">Destino tributario</label>
                                <select class="form-select form-select-sm" v-model="confirmarTributarioForm.destino_tributario">
                                    <option value="amazonia">Amazonía</option>
                                    <option value="nacional">Nacional</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-semibold text-secondary">Tratamiento IGV</label>
                                <select class="form-select form-select-sm" v-model="confirmarTributarioForm.tip_afe_igv">
                                    <option value="20">Exonerado</option>
                                    <option value="30">Inafecto</option>
                                    <option value="10">Gravado (IGV 18%)</option>
                                </select>
                            </div>
                        </div>
                        <label class="form-label small fw-semibold text-secondary">Motivo (obligatorio)</label>
                        <textarea class="form-control form-control-sm" rows="2" v-model="confirmarTributarioForm.motivo"
                            placeholder="Ej. Servicio confirmado en zona Amazonía por el operador, exonerado según Ley 27037."></textarea>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-outline-secondary btn-sm" @click="mostrarModalConfirmarTributario = false">Cancelar</button>
                        <button class="btn btn-primary btn-sm" :disabled="confirmandoTributario || !confirmarTributarioForm.motivo"
                            @click="confirmarTratamientoTributario">
                            <span v-if="confirmandoTributario" class="spinner-border spinner-border-sm me-1"></span>Confirmar
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
                                {{ previewFacturaEspecial.items_pendientes_por_pasajero_faltante.length }} servicio(s) compartido(s) quedan pendientes porque los comparte otro pasajero que no está en esta selección:
                                {{ previewFacturaEspecial.items_pendientes_por_pasajero_faltante.map(it => it.nombre).join(', ') }}.
                            </div>

                            <!-- Ítems sin ningún pasajero asignado (el caso más común hoy:
                                 ajustes, extras) — el vendedor decide a mano si van en este
                                 comprobante. -->
                            <div v-if="previewFacturaEspecial.items_sin_asignar_disponibles.length > 0" class="mb-2">
                                <label class="form-label small fw-semibold text-secondary">Otros servicios sin pasajero asignado (agrégalos si corresponden acá)</label>
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

                            <!-- Anticipos disponibles (Tier 0 — conexión Adelantos↔Reservas):
                                 un anticipo es de la reserva completa, no de un pasajero puntual —
                                 no se reparte solo entre sub-facturas, el vendedor elige a mano. -->
                            <div v-if="!previewFacturaEspecial.bloqueado_tributario && (previewFacturaEspecial.anticipos_disponibles?.length ?? 0) > 0" class="mb-2">
                                <label class="form-label small fw-semibold text-secondary">Aplicar anticipos ya cobrados a este comprobante</label>
                                <table class="table table-sm table-bordered align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width:36px;"></th>
                                            <th>Anticipo</th>
                                            <th class="text-end" style="width:120px;">Disponible</th>
                                            <th style="width:130px;">Monto a aplicar</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="a in anticiposEspecialSeleccionables" :key="a.id">
                                            <td>
                                                <input type="checkbox" v-model="a.seleccionado" @change="onAnticipoEspecialToggle(a)">
                                            </td>
                                            <td>Anticipo #{{ a.advance_id }}</td>
                                            <td class="text-end">{{ a.moneda === 'USD' ? 'US$' : 'S/' }} {{ a.disponible.toFixed(2) }}</td>
                                            <td>
                                                <input type="number" class="form-control form-control-sm" min="0" :max="a.disponible" step="0.01"
                                                    v-model.number="a.monto_aplicado" :disabled="!a.seleccionado">
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Moneda del comprobante (2026-09-24, pedido del usuario: "tengo
                                 mi cotización en dólares, pero el cliente pide que le facture en
                                 soles") — SIEMPRE visible, mismo criterio que sucursal/tributario
                                 de acá abajo. Por defecto factura en la moneda de la cotización
                                 (sin override); cambiarla exige tipo de cambio + motivo, ver
                                 ReservaFacturacionController::resolverMonedaFacturacion(). -->
                            <div class="mb-2">
                                <label class="form-label small fw-semibold text-secondary">
                                    <i class="fas fa-money-bill-wave me-1"></i>Moneda del comprobante
                                </label>
                                <select class="form-select form-select-sm" v-model="monedaFacturacionSeleccionada">
                                    <option value="PEN">Soles (PEN)</option>
                                    <option value="USD">Dólares (USD)</option>
                                </select>
                                <small class="text-muted d-block mt-1">Cotización en {{ moneda }}.</small>
                            </div>
                            <div v-if="requiereOverrideMoneda" class="row g-2 mb-2">
                                <div class="col-6">
                                    <label class="form-label small fw-semibold text-secondary">
                                        Tipo de cambio ({{ moneda }} → {{ monedaFacturacionSeleccionada }})
                                    </label>
                                    <input type="number" step="0.0001" min="0.0001" class="form-control form-control-sm"
                                        v-model.number="tipoCambioFacturacion" placeholder="Ej: 3.75">
                                    <small v-if="previewFacturaEspecial.tipo_cambio_sugerido" class="text-muted">
                                        Sugerido SUNAT del día: {{ previewFacturaEspecial.tipo_cambio_sugerido }}
                                    </small>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-semibold text-secondary">Motivo del cambio de moneda</label>
                                    <input type="text" class="form-control form-control-sm" v-model="motivoCambioMonedaFacturacion"
                                        placeholder="Ej: cliente pidió el comprobante en soles">
                                </div>
                            </div>

                            <!-- Sucursal (2026-09-24, pedido del usuario) — SIEMPRE visible,
                                 bloqueado_tributario o no, para que se vea aunque el bloqueo
                                 dispare (bug real: antes vivía solo dentro del v-else de abajo,
                                 así que una reserva que mezcla tratamientos nunca la mostraba en
                                 "Facturar" simple — solo era visible si el subgrupo elegido daba
                                 OK). Solo visible con permiso can_switch_branch, mismo criterio
                                 que sale/register.vue. La serie real se elige sola por
                                 sucursal+tipo+moneda (SerieComprobanteService), esto solo deja
                                 elegir DESDE QUÉ sucursal se emite. -->
                            <div v-if="puedeCambiarSucursal" class="mb-2">
                                <label class="form-label small fw-semibold text-secondary">
                                    <i class="fas fa-store me-1"></i>Sucursal (define la serie según moneda)
                                </label>
                                <select class="form-select form-select-sm" v-model.number="branchIdFacturacion">
                                    <option v-for="b in sucursales" :key="b.id" :value="b.id">{{ b.name }}</option>
                                </select>
                            </div>

                            <!-- Serie visible (2026-09-24, pedido del usuario: "debe aparecer
                                 de manera visible la serie") — antes solo se veía la sucursal, el
                                 vendedor no sabía si iba a salir F001/B001/F002 hasta confirmar.
                                 Solo lectura (resolverParaUsuario() no reserva ningún
                                 correlativo), se recalcula solo con sucursal/tipo/moneda. -->
                            <div v-if="previewFacturaEspecial.serie_resuelta" class="mb-2 small">
                                <span class="text-muted">Serie a emitir:</span>
                                <span class="badge bg-light text-dark border ms-1">{{ previewFacturaEspecial.serie_resuelta }}</span>
                            </div>

                            <!-- Pedido del usuario (2026-09-24): "debo poder ver lo que he
                                 seleccionado si fue gravado o exonerado" — siempre visible,
                                 mismo criterio que la sucursal de arriba. -->
                            <div v-if="(previewFacturaEspecial.items_por_destino_tributario?.length ?? 0) > 0" class="mb-2 small">
                                <span class="text-muted d-block mb-1">Tratamiento tributario de lo seleccionado:</span>
                                <span v-for="it in previewFacturaEspecial.items_por_destino_tributario" :key="it.reserva_item_id"
                                    class="badge bg-light text-dark border me-1 mb-1">
                                    {{ it.nombre }}: {{ etiquetaTipAfeIgv(it.tip_afe_igv) }}
                                </span>
                            </div>

                            <!-- Guardia tributario: este subgrupo mezcla tratamientos distintos,
                                 no se puede emitir un solo comprobante — se avisa acá y se oculta
                                 el resto del formulario. -->
                            <div v-if="previewFacturaEspecial.bloqueado_tributario" class="alert alert-danger mb-0">
                                <i class="fas fa-triangle-exclamation me-2"></i>
                                <strong>No se puede facturar este grupo así todavía.</strong>
                                <p class="mb-1 mt-1">{{ previewFacturaEspecial.motivo }}</p>
                                <!-- 2026-09-23 — antes solo se sabía QUÉ valores distintos había
                                     (ej. "nacional" y "amazonia"), nunca cuál servicio era cuál. -->
                                <ul v-if="(previewFacturaEspecial.items_por_destino_tributario?.length ?? 0) > 0" class="mb-0 small ps-3">
                                    <li v-for="it in previewFacturaEspecial.items_por_destino_tributario" :key="it.reserva_item_id">
                                        {{ it.nombre }} — <span class="fw-semibold">{{ it.destino_tributario }}</span>
                                    </li>
                                </ul>
                                <!-- Caso 3 Amazonía (2026-09-24) — CTA directo, sin tener que ir a
                                     buscar el servicio en el tab "Servicios". -->
                                <button v-if="(previewFacturaEspecial.items_sin_confirmar_ids?.length ?? 0) > 0" type="button"
                                    class="btn btn-sm btn-outline-danger mt-2"
                                    @click="abrirModalConfirmarTributario(itemsPorIds(previewFacturaEspecial.items_sin_confirmar_ids ?? []))">
                                    Confirmar tratamiento tributario
                                </button>
                            </div>

                            <template v-else>
                                <div class="row g-2 mb-2">
                                    <div class="col-6">
                                        <label class="form-label small fw-semibold text-secondary">Tipo de comprobante</label>
                                        <select class="form-select form-select-sm" v-model="facturarEspecialForm.tipo_comprobante_codigo">
                                            <option value="01" :disabled="!clienteEspecialTieneRuc">Factura</option>
                                            <option value="03">Boleta</option>
                                        </select>
                                        <small v-if="!clienteEspecialTieneRuc" class="text-muted">El cliente elegido no tiene RUC — solo puede recibir boleta.</small>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label small fw-semibold text-secondary">Total de este comprobante</label>
                                        <input v-if="!requiereOverrideMoneda || previewFacturaEspecial.tipo_cambio_aplicado" type="text"
                                            class="form-control form-control-sm fw-bold" disabled
                                            :value="`${monedaFacturacionSeleccionada} ${Number(previewFacturaEspecial.total ?? 0).toFixed(2)}`">
                                        <input v-else type="text" class="form-control form-control-sm fw-bold text-danger" disabled
                                            value="Ingresa un tipo de cambio para calcular el total">
                                    </div>
                                </div>

                                <div class="mb-2">
                                    <label class="form-label small fw-semibold text-secondary">Cliente para este comprobante</label>
                                    <div v-if="clienteSeleccionado" class="d-flex justify-content-between align-items-center border rounded px-2 py-1 small">
                                        <span>{{ clienteSeleccionado.full_name }} — {{ clienteSeleccionado.n_document }}</span>
                                        <button type="button" class="btn btn-sm btn-link text-danger p-0" @click="clienteSeleccionado = null">Cambiar</button>
                                    </div>
                                    <template v-else>
                                        <div class="input-group input-group-sm">
                                            <input type="text" class="form-control" placeholder="Buscar cliente por nombre o documento..."
                                                v-model="busquedaCliente" @input="onBuscarCliente">
                                            <!-- Bug real (2026-09-24): este buscador solo consultaba
                                                 clientes YA registrados (clients?search=) — si la
                                                 empresa era nueva, no había ningún camino sin salir
                                                 del modal. Mismo componente que ya usa Venta Directa
                                                 (ClientFormQuick, con búsqueda de RUC vía apisperu). -->
                                            <button type="button" class="btn btn-outline-primary" @click="abrirModalClienteRapido">
                                                <i class="fas fa-plus me-1"></i>Nuevo
                                            </button>
                                        </div>
                                        <div v-if="resultadosCliente.length > 0" class="border rounded mt-1" style="max-height:140px;overflow-y:auto">
                                            <button v-for="c in resultadosCliente" :key="c.id" type="button"
                                                class="dropdown-item small w-100 text-start" @click="seleccionarCliente(c)">
                                                {{ c.full_name }} — {{ c.n_document }}
                                            </button>
                                        </div>
                                        <small v-else-if="busquedaCliente.trim().length >= 2" class="text-muted d-block mt-1">
                                            Sin resultados entre los clientes ya registrados — usa "Nuevo" para buscar por RUC/DNI o registrarlo a mano.
                                        </small>
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
                            <span v-if="facturando" class="spinner-border spinner-border-sm me-1"></span>Emitir comprobante de este grupo
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Registrar cliente rápido (2026-09-24) — mismo componente que ya
             usa Venta Directa (ClientFormQuick: búsqueda de RUC/DNI vía
             apisperu + alta manual). Bug real: "Facturación especial" solo
             dejaba elegir entre clientes YA registrados, sin ningún camino
             para una empresa nueva. -->
        <div class="modal fade" tabindex="-1" :class="{ show: mostrarModalClienteRapido, 'd-block': mostrarModalClienteRapido }"
            style="background:rgba(0,0,0,.5)" v-if="mostrarModalClienteRapido">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold">Registrar cliente rápido</h6>
                        <button class="btn-close" @click="mostrarModalClienteRapido = false"></button>
                    </div>
                    <div class="modal-body">
                        <ClientFormQuick :initial-data="clienteRapidoInitialData" @saved="onClienteRapidoCreado" @cancel="mostrarModalClienteRapido = false" />
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
                            <!-- Moneda del comprobante (2026-09-24) — mismo bloque que
                                 "Facturación especial", ver ese modal para el detalle del
                                 porqué. -->
                            <div class="mb-2">
                                <label class="form-label small fw-semibold text-secondary">
                                    <i class="fas fa-money-bill-wave me-1"></i>Moneda del comprobante
                                </label>
                                <select class="form-select form-select-sm" v-model="monedaFacturacionSeleccionada">
                                    <option value="PEN">Soles (PEN)</option>
                                    <option value="USD">Dólares (USD)</option>
                                </select>
                                <small class="text-muted d-block mt-1">Cotización en {{ moneda }}.</small>
                            </div>
                            <div v-if="requiereOverrideMoneda" class="row g-2 mb-2">
                                <div class="col-6">
                                    <label class="form-label small fw-semibold text-secondary">
                                        Tipo de cambio ({{ moneda }} → {{ monedaFacturacionSeleccionada }})
                                    </label>
                                    <input type="number" step="0.0001" min="0.0001" class="form-control form-control-sm"
                                        v-model.number="tipoCambioFacturacion" placeholder="Ej: 3.75">
                                    <small v-if="previewFacturaSimple.tipo_cambio_sugerido" class="text-muted">
                                        Sugerido SUNAT del día: {{ previewFacturaSimple.tipo_cambio_sugerido }}
                                    </small>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-semibold text-secondary">Motivo del cambio de moneda</label>
                                    <input type="text" class="form-control form-control-sm" v-model="motivoCambioMonedaFacturacion"
                                        placeholder="Ej: cliente pidió el comprobante en soles">
                                </div>
                            </div>

                            <!-- Sucursal + tratamiento tributario (2026-09-24) — SIEMPRE
                                 visibles, bloqueado_tributario o no. Bug real corregido: antes
                                 vivían solo dentro del v-else de abajo, así que una reserva que
                                 mezcla tratamientos (caso real, ver bloqueo abajo) nunca los
                                 mostraba en "Facturar" simple. -->
                            <div v-if="puedeCambiarSucursal" class="mb-2">
                                <label class="form-label small fw-semibold text-secondary">
                                    <i class="fas fa-store me-1"></i>Sucursal (define la serie según moneda)
                                </label>
                                <select class="form-select form-select-sm" v-model.number="branchIdFacturacion">
                                    <option v-for="b in sucursales" :key="b.id" :value="b.id">{{ b.name }}</option>
                                </select>
                            </div>
                            <!-- Serie visible (2026-09-24) — mismo bloque que "Facturación
                                 especial", ver ese modal para el detalle del porqué. -->
                            <div v-if="previewFacturaSimple.serie_resuelta" class="mb-2 small">
                                <span class="text-muted">Serie a emitir:</span>
                                <span class="badge bg-light text-dark border ms-1">{{ previewFacturaSimple.serie_resuelta }}</span>
                            </div>
                            <div v-if="(previewFacturaSimple.items_por_destino_tributario?.length ?? 0) > 0" class="mb-2 small">
                                <span class="text-muted d-block mb-1">Tratamiento tributario de lo seleccionado:</span>
                                <span v-for="it in previewFacturaSimple.items_por_destino_tributario" :key="it.reserva_item_id"
                                    class="badge bg-light text-dark border me-1 mb-1">
                                    {{ it.nombre }}: {{ etiquetaTipAfeIgv(it.tip_afe_igv) }}
                                </span>
                            </div>

                            <div v-if="previewFacturaSimple.bloqueado_tributario" class="alert alert-danger mb-0">
                                <i class="fas fa-triangle-exclamation me-2"></i>
                                <strong>No se puede facturar toda la reserva en un solo comprobante.</strong>
                                <p class="mb-1 mt-1">{{ previewFacturaSimple.motivo }}</p>
                                <ul v-if="(previewFacturaSimple.items_por_destino_tributario?.length ?? 0) > 0" class="mb-1 small ps-3">
                                    <li v-for="it in previewFacturaSimple.items_por_destino_tributario" :key="it.reserva_item_id">
                                        {{ it.nombre }} — <span class="fw-semibold">{{ it.destino_tributario }}</span>
                                    </li>
                                </ul>
                                <button v-if="(previewFacturaSimple.items_sin_confirmar_ids?.length ?? 0) > 0" type="button"
                                    class="btn btn-sm btn-outline-danger mb-2"
                                    @click="abrirModalConfirmarTributario(itemsPorIds(previewFacturaSimple.items_sin_confirmar_ids ?? []))">
                                    Confirmar tratamiento tributario
                                </button>
                                <p class="mb-0 small">Usa <strong>Facturación especial</strong> para separar los grupos con distinto tratamiento tributario.</p>
                            </div>

                            <template v-else>
                                <p class="small text-muted mb-2">
                                    <template v-if="pasajerosPendientesFacturar.length > 0">
                                        Se factura de una vez a {{ pasajerosPendientesFacturar.length }} pasajero(s) pendiente(s)
                                    </template>
                                    <template v-else>
                                        Se facturan {{ itemsPendientesDeFacturarCount }} ítem(s) sueltos pendientes (todos los pasajeros ya fueron facturados)
                                    </template>
                                    , a nombre de
                                    <strong>{{ cabecera?.cliente?.full_name ?? 'el cliente de la cotización' }}</strong>.
                                    ¿Necesitas dividirlo en varios comprobantes o cambiar el cliente? Usa <strong>Facturación especial</strong>.
                                </p>
                                <div class="row g-2 mb-2">
                                    <div class="col-6">
                                        <label class="form-label small fw-semibold text-secondary">Tipo de comprobante</label>
                                        <select class="form-select form-select-sm" v-model="facturarSimpleForm.tipo_comprobante_codigo">
                                            <option value="01" :disabled="!clienteSimpleTieneRuc">Factura</option>
                                            <option value="03">Boleta</option>
                                        </select>
                                        <small v-if="!clienteSimpleTieneRuc" class="text-muted">Este cliente no tiene RUC — solo puede recibir boleta.</small>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label small fw-semibold text-secondary">Total a facturar</label>
                                        <input v-if="!requiereOverrideMoneda || previewFacturaSimple.tipo_cambio_aplicado" type="text"
                                            class="form-control form-control-sm fw-bold" disabled
                                            :value="`${monedaFacturacionSeleccionada} ${Number(previewFacturaSimple.total ?? 0).toFixed(2)}`">
                                        <input v-else type="text" class="form-control form-control-sm fw-bold text-danger" disabled
                                            value="Ingresa un tipo de cambio para calcular el total">
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
                            <span v-if="facturando" class="spinner-border spinner-border-sm me-1"></span>Emitir comprobante
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

        <!-- Cobrar anticipo (Tier 0 — conexión Adelantos↔Reservas, 2026-08-21) —
             cliente fijo (el de la cotización), moneda fija (la de la
             reserva), no editables acá: evita de raíz el guard de moneda
             distinta que ya blinda la aplicación de adelantos. -->
        <div class="modal fade" tabindex="-1" :class="{ show: mostrarModalAnticipo, 'd-block': mostrarModalAnticipo }"
            style="background:rgba(0,0,0,.5)" v-if="mostrarModalAnticipo">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold">Cobrar anticipo</h6>
                        <button class="btn-close" @click="mostrarModalAnticipo = false"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-muted">
                            Genera su propio comprobante SUNAT a nombre de {{ cabecera?.cliente?.full_name }}
                            ({{ moneda }}) — enviarlo a SUNAT se hace después, desde el módulo de Adelantos.
                        </p>
                        <div class="mb-2">
                            <label class="form-label small fw-semibold">Monto recibido</label>
                            <input type="number" class="form-control form-control-sm" min="0.01" step="0.01" v-model.number="formAnticipo.monto">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-semibold">Medio de pago</label>
                            <select class="form-select form-select-sm" v-model="formAnticipo.medio_pago">
                                <option v-for="pm in paymentMethods" :key="pm.id" :value="pm.code">{{ pm.name }}</option>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-semibold">Tratamiento tributario</label>
                            <select class="form-select form-select-sm" v-model="formAnticipo.tip_afe_igv">
                                <option value="10">Gravado (IGV 18%)</option>
                                <option value="20">Exonerado</option>
                                <option value="30">Inafecto</option>
                            </select>
                        </div>
                        <div class="mb-0">
                            <label class="form-label small fw-semibold">Notas (opcional)</label>
                            <textarea class="form-control form-control-sm" rows="2" v-model="formAnticipo.notas"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-outline-secondary btn-sm" @click="mostrarModalAnticipo = false">Cerrar</button>
                        <button class="btn btn-primary btn-sm" :disabled="guardandoAnticipo || formAnticipo.monto <= 0 || !formAnticipo.medio_pago"
                            @click="guardarAnticipo">
                            <span v-if="guardandoAnticipo" class="spinner-border spinner-border-sm me-1"></span>Registrar anticipo
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </DefaultLayout>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import httpClient from '@/helpers/http-client';
import { useAuthStore } from '@/stores/auth';
import type { Branch } from '@/types/cash-session';
import ClientFormQuick from '@/components/Sales/ClientFormQuick.vue';
import { reservaService } from '@/services/admin/reservaService';
import { reservaFacturacionService, type PrepararFacturaResponse, type AnticipoDisponiblePreview } from '@/services/admin/reservaFacturacionService';
import { reservaAnticipoService } from '@/services/admin/reservaAnticipoService';
import { reservaPasajeroService } from '@/services/admin/reservaPasajeroService';
import { reservaItemService, type ActualizarVueloPayload } from '@/services/admin/reservaItemService';
import { proveedorService } from '@/services/admin/proveedorService';
import { guiaService } from '@/services/admin/guiaService';
import { opcionMayoristaService } from '@/services/admin/opcionMayoristaService';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { useToast } from '@/composables/useToast';
import { formatFecha } from '@/helpers/fecha';
import { imprimirComprobante } from '@/composables/usePrintComprobante';
import type {
    Reserva, ReservaPasajero, ReservaItem, ReservaResumenItem, ReservaCabecera,
    PasajeroCatalogo, ProveedorTarifa, Guia, MotivoCancelacion, AnticipoReserva, OpcionMayorista, OpcionHotelTarifa,
    ComprobanteReserva,
} from '@/types/agencia-viajes';
import type { PaymentMethod, PaymentMethods } from '@/types/cash';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const route = useRoute();
const reservaId = Number(route.params.id);
const toast = useToast();
const authStore = useAuthStore();

// Sucursal/serie al facturar (2026-09-24, pedido del usuario: "la agencia
// factura en dólares y en soles... debo poder elegir la serie") — mismo
// patrón exacto que sale/register.vue: solo visible con can_switch_branch,
// la serie real se resuelve sola por sucursal+tipo+moneda en el backend
// (SerieComprobanteService), esto solo deja elegir DESDE QUÉ sucursal.
const puedeCambiarSucursal = computed(() => authStore.isPermitedRoute('can_switch_branch'));
const sucursales = ref<Branch[]>([]);
const branchIdFacturacion = ref<number | undefined>(undefined);

const cargarSucursalesSiCorresponde = async () => {
    if (!puedeCambiarSucursal.value || sucursales.value.length > 0) return;
    try {
        const res = await httpClient.get<{ branches: Branch[] }>('branches?active=1');
        sucursales.value = res.data.branches;
        branchIdFacturacion.value = sucursales.value[0]?.id;
    } catch {
        // No bloquea el resto del modal — sin sucursales cargadas, el
        // selector queda vacío y el backend simplemente usa la sucursal
        // propia del usuario (branch_id nunca viaja).
    }
};

// Facturar en moneda distinta a la de la cotización (2026-09-24 — caso
// real: cotización en USD, cliente pide el comprobante en soles). Un solo
// set de refs para los dos modales de facturar (Simple/Especial) — nunca
// están abiertos a la vez, y ambos se resetean al abrir. Ver
// ReservaFacturacionController::resolverMonedaFacturacion() — el tipo de
// cambio sugerido de SUNAT es editable, nunca se aplica sin que el
// vendedor lo confirme (motivo obligatorio en el backend).
const monedaFacturacionSeleccionada = ref<'PEN' | 'USD'>('PEN');
const tipoCambioFacturacion = ref<number | null>(null);
const motivoCambioMonedaFacturacion = ref('');
const requiereOverrideMoneda = computed(
    () => monedaFacturacionSeleccionada.value !== moneda.value
);

const resetearOverrideMoneda = () => {
    monedaFacturacionSeleccionada.value = moneda.value;
    tipoCambioFacturacion.value = null;
    motivoCambioMonedaFacturacion.value = '';
};

const reserva = ref<Reserva | null>(null);
const resumen = ref<ReservaResumenItem[]>([]);

// Auditoría de UX 2026-08-27 — el resumen salía en el orden de carga de la
// relación, no agrupado por tour/día como ya hace el cotizador. Agrupa por
// tour_origen_id (mismo criterio visual que cotizador/editar.vue), ordena
// cada bloque por fecha, y los bloques entre sí por su fecha más temprana
// — así el panel se lee cronológicamente aunque los ítems no hayan llegado
// en ese orden desde el backend. Los ítems sin tour_origen_id (manuales,
// pasajes sueltos, etc.) quedan en un único bloque final sin encabezado.
type ResumenBloque = { tourOrigenId: number | null; tourNombre: string | null; items: ReservaResumenItem[] };
const resumenAgrupado = computed<ResumenBloque[]>(() => {
    const bloques = new Map<number, ResumenBloque>();
    const sueltos: ReservaResumenItem[] = [];

    for (const r of resumen.value) {
        if (r.tour_origen_id) {
            if (!bloques.has(r.tour_origen_id)) {
                bloques.set(r.tour_origen_id, { tourOrigenId: r.tour_origen_id, tourNombre: r.tour_origen_nombre ?? null, items: [] });
            }
            bloques.get(r.tour_origen_id)!.items.push(r);
        } else {
            sueltos.push(r);
        }
    }

    const porFecha = (a: ReservaResumenItem, b: ReservaResumenItem) => (a.fecha ?? '').localeCompare(b.fecha ?? '');

    const gruposConTour = Array.from(bloques.values())
        .map((b) => ({ ...b, items: [...b.items].sort(porFecha) }))
        .sort((a, b) => porFecha(a.items[0], b.items[0]));

    sueltos.sort(porFecha);
    const grupoSuelto: ResumenBloque[] = sueltos.length > 0 ? [{ tourOrigenId: null, tourNombre: null, items: sueltos }] : [];

    return [...gruposConTour, ...grupoSuelto];
});

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

// Tier 0 — conexión Adelantos↔Reservas (hallazgo de auditoría del módulo
// Adelantos, 2026-08-21): anticipos ya pagados hacia esta reserva.
const anticipos = ref<AnticipoReserva[]>([]);
const totalAnticiposDisponibles = ref(0);
const comprobantes = ref<ComprobanteReserva[]>([]);

// Mismo criterio que badgeFacturacion() en reservas/index.vue (no
// compartido en un helper común a propósito — son 2 conceptos vecinos
// pero distintos: acá es el estado de UN comprobante puntual, allá el
// agregado de toda la reserva).
const badgeEstadoSunat = (estado: ComprobanteReserva['estado_sunat']): { texto: string; clase: string } => {
    switch (estado) {
        case 'aceptado':
            return { texto: 'Aceptado', clase: 'bg-success' };
        case 'rechazado':
            return { texto: 'Rechazado', clase: 'bg-danger' };
        default:
            return { texto: 'Sin enviar', clase: 'bg-secondary' };
    }
};

const tab = ref<'pax' | 'items' | 'asignacion'>('pax');

// Cuántos reserva_items reales todavía no están cubiertos por ninguna
// venta — hallazgo 2026-08-24: antes "Facturación completa" solo miraba
// pasajeros, así que un ítem compartido o "sin asignar" podía quedar
// pendiente para siempre con el badge en verde y ambos botones ocultos.
const itemsPendientesDeFacturarCount = ref(0);

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
// facturacion_externa=true (ver template). El botón sigue disponible
// mientras queden PASAJEROS o ÍTEMS pendientes — antes solo miraba
// pasajeros, dejando ítems sueltos sin ningún camino para facturarlos.
const mostrarBotonesFacturar = computed(() =>
    reserva.value?.estado === 'activa'
    && !reserva.value?.facturacion_externa
    && facturacionHabilitadaTenant.value
    && (pasajerosPendientesFacturar.value.length > 0 || itemsPendientesDeFacturarCount.value > 0)
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
    // vuelo_pasajeros (vuelo de agencia) NO necesita el mismo truncado: su
    // modelo (ReservaItemVueloPasajero) a propósito no tiene cast 'date' en
    // vuelo_fecha_ida/vuelta (mismo criterio que ReservaPasajero.vuelo_fecha_
    // ida/vuelta), así que ya llega como 'YYYY-MM-DD' plano desde Postgres.
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
    itemsPendientesDeFacturarCount.value = res.items_pendientes_de_facturar_count ?? 0;
    facturacionHabilitadaTenant.value = res.facturacion_habilitada_tenant ?? false;
    facturacionExternaEditable.value = res.facturacion_externa_editable ?? false;
    anticipos.value = res.anticipos ?? [];
    totalAnticiposDisponibles.value = res.total_anticipos_disponibles ?? 0;
    comprobantes.value = res.comprobantes ?? [];
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

// ── Vuelo con la agencia ────────────────────────────────────────────
// Distinto del bloque de arriba (vuelo por cuenta propia, campo directo de
// ReservaPasajero) — este vive en su propia tabla (ReservaItemVueloPasajero,
// corrección 2026-08-27 tras un bug real: la primera versión lo guardaba en
// reserva_item_pasajero, la MISMA fila que edita el checkbox del tab
// Asignación — desmarcar un pasajero ahí borraba el vuelo ya cargado). Se
// aplica a TODOS los pasajeros de la reserva por igual, sin depender de
// ningún checkbox/vínculo. Se muestra en el tab Pasajeros (no en Ítems, que
// solo da un resumen de solo lectura) porque es la pregunta que se hace el
// vendedor por persona, no por servicio — decidido con el usuario.
const itemsVueloAgencia = computed(() =>
    (reserva.value?.items ?? []).filter((it) => it.alternativa_item?.origen_tipo === 'pasaje_aereo')
);

const aerolineaCotizada = (it: ReservaItem) => it.alternativa_item?.cotizacion_pasaje_aereo?.aerolinea ?? null;

const claveVuelo = (it: ReservaItem, p: ReservaPasajero) => `${it.id}_${p.id}`;
const vueloAgenciaForms = reactive<Record<string, ActualizarVueloPayload>>({});

// Lazy: se inicializa una sola vez por (ítem, pasajero) desde la fila ya
// cargada en la reserva (o vacío si todavía no se guardó nada) — no se
// reinicializa en cada render, así v-model no pierde lo que el usuario
// está tipeando.
const vueloAgenciaForm = (it: ReservaItem, p: ReservaPasajero): ActualizarVueloPayload => {
    const key = claveVuelo(it, p);
    if (!vueloAgenciaForms[key]) {
        const existente = it.vuelo_pasajeros?.find((vp) => vp.reserva_pasajero_id === p.id);
        vueloAgenciaForms[key] = {
            vuelo_numero_ida: existente?.vuelo_numero_ida ?? null,
            vuelo_fecha_ida: existente?.vuelo_fecha_ida ?? null,
            vuelo_hora_ida: existente?.vuelo_hora_ida ?? null,
            vuelo_numero_vuelta: existente?.vuelo_numero_vuelta ?? null,
            vuelo_fecha_vuelta: existente?.vuelo_fecha_vuelta ?? null,
            vuelo_hora_vuelta: existente?.vuelo_hora_vuelta ?? null,
            vuelo_aerolinea_confirmada: existente?.vuelo_aerolinea_confirmada ?? null,
        };
    }
    return vueloAgenciaForms[key];
};

const guardandoVueloAgenciaKey = ref<string | null>(null);
const guardarVueloAgencia = async (it: ReservaItem, p: ReservaPasajero) => {
    const key = claveVuelo(it, p);
    guardandoVueloAgenciaKey.value = key;
    try {
        await reservaItemService.actualizarVuelo(it.id, p.id, vueloAgenciaForm(it, p));
        toast.success('Vuelo actualizado');
        await cargarReserva();
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo actualizar el vuelo');
    } finally {
        guardandoVueloAgenciaKey.value = null;
    }
};

// ── Ítems ────────────────────────────────────────────────────────────
const bibliotecaTarifas = ref<ProveedorTarifa[]>([]);
const guias = ref<Guia[]>([]);

const proveedorBuscando = ref<number | null>(null); // id del reserva_item en modo búsqueda
const proveedorSearch = ref<Record<number, string>>({});

// Auditoría de UX 2026-08-27: nombre_comercial ya viaja en el JSON
// (Proveedor lo tiene desde su creación), pero acá solo se mostraba/
// buscaba por razon_social — en la práctica al negocio se lo conoce por su
// nombre comercial, no por la razón social. nombre_comercial es prioridad
// si existe, con fallback a razon_social (mismo criterio que ya usa
// ProveedorTarifaController::index() para su propio buscador server-side).
const nombreProveedorActual = (it: ReservaItem) => {
    const t = bibliotecaTarifas.value.find((x) => x.id === it.proveedor_tarifa_id);
    const p = t?.proveedor_servicio?.proveedor;
    return p ? (p.nombre_comercial || p.razon_social) : null;
};

const proveedoresFiltrados = (it: ReservaItem) => {
    const servicioId = it.alternativa_item?.proveedor_tarifa?.proveedor_servicio?.destino_servicio?.servicio_id;
    const q = (proveedorSearch.value[it.id] ?? '').trim().toLowerCase();

    return bibliotecaTarifas.value
        .filter((t) => !servicioId || t.proveedor_servicio?.destino_servicio?.servicio_id === servicioId)
        .filter((t) => {
            if (!q) return true;
            const p = t.proveedor_servicio?.proveedor;
            return (p?.razon_social ?? '').toLowerCase().includes(q) || (p?.nombre_comercial ?? '').toLowerCase().includes(q);
        })
        .slice(0, 30);
};

// Reserva no activa: mismo guard que el backend (ReservaItemController::
// update()) — el buscador de proveedor no es un <select> nativo, así que
// no queda cubierto por el <fieldset :disabled> del resto del bloque.
const abrirBusquedaProveedor = (it: ReservaItem) => {
    if (reserva.value?.estado !== 'activa') return;
    proveedorBuscando.value = it.id;
    proveedorSearch.value[it.id] = '';
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

// nombre_comercial con fallback a razon_social — mismo criterio que
// nombreProveedorActual()/proveedoresFiltrados() arriba. Solo para esta
// pantalla (tab Ítems/Asignación); el nombre que sale en el comprobante
// SUNAT sigue siendo razón social vía ReservaController::resolverNombreItem()
// en el backend, sin tocar — ahí sí importa la identidad fiscal, acá es
// solo para que el vendedor reconozca al negocio más rápido.
const nombreItem = (it: ReservaItem) => {
    const item = it.alternativa_item;
    if (!item) return 'Servicio';
    if (item.origen_tipo === 'manual') return item.descripcion_manual ?? 'Servicio manual';
    if (item.origen_tipo === 'pasaje_aereo') return item.cotizacion_pasaje_aereo?.aerolinea ?? 'Pasaje aéreo';
    if (item.origen_tipo === 'mayorista') {
        // Sesión 12h — it.opcion_mayorista es quién opera REALMENTE hoy
        // (puede diferir del original de la alternativa si ya se
        // reasignó); fallback al de la alternativa para reservas creadas
        // antes de 12h (columna nueva, sin backfill retroactivo).
        const p = it.opcion_mayorista?.proveedor ?? item.opcion_mayorista?.proveedor;
        return (p ? (p.nombre_comercial || p.razon_social) : null) ?? 'Paquete mayorista';
    }
    // Sesión M2 — mismo bug que ResourceController::resolverNombreItem()
    // tenía en el backend antes de centralizarse: esta pantalla nunca
    // tuvo rama para origen_tipo=guia, caía al genérico 'Servicio'.
    if (item.origen_tipo === 'guia') {
        return 'Guía de turismo' + (item.guia_tarifa?.guia?.nombre ? ` — ${item.guia_tarifa.guia.nombre}` : '');
    }
    // Reasignar hotel (18-sep-2026) — mismo bug que mayorista tuvo antes
    // de 12h: leer el hotel SOLO de item (alternativa_item original)
    // seguía mostrando el hotel viejo después de reasignar, porque
    // ReservaController::reasignarHotel() reescribe it.proveedor_tarifa_id/
    // it.opcion_hotel_tarifa_id, nunca los del alternativa_item. it.* tiene
    // que revisarse PRIMERO — un fallback en "o" (como tenía el caso
    // opcion_hotel_tarifa de acá abajo) no alcanza: tras cruzar de catálogo
    // a ad-hoc, item.proveedor_tarifa sigue teniendo tipo_habitacion (no
    // cambia nunca), así que un "||" seguiría entrando a esta rama con el
    // hotel viejo en vez de cruzar al bloque de opcion_hotel_tarifa. Recién
    // si it. no tiene NINGUNO de los 2 (reserva vieja, de antes de que
    // reserva_items espejara estas columnas) se cae al original.
    if (it.proveedor_tarifa?.tipo_habitacion) {
        const p = it.proveedor_tarifa.proveedor_servicio?.proveedor;
        const proveedor = (p ? (p.nombre_comercial || p.razon_social) : null) ?? 'Hotel';
        return `${proveedor} · ${it.proveedor_tarifa.tipo_habitacion}`;
    }
    if (it.opcion_hotel_tarifa) {
        return `${it.opcion_hotel_tarifa.opcion_hotel?.nombre_hotel ?? 'Hotel'} · ${it.opcion_hotel_tarifa.tipo_habitacion}`;
    }
    if (item.proveedor_tarifa?.tipo_habitacion) {
        const p = item.proveedor_tarifa.proveedor_servicio?.proveedor;
        const proveedor = (p ? (p.nombre_comercial || p.razon_social) : null) ?? 'Hotel';
        return `${proveedor} · ${item.proveedor_tarifa.tipo_habitacion}`;
    }
    if (item.opcion_hotel_tarifa) {
        return `${item.opcion_hotel_tarifa.opcion_hotel?.nombre_hotel ?? 'Hotel'} · ${item.opcion_hotel_tarifa.tipo_habitacion}`;
    }
    return item.proveedor_tarifa?.proveedor_servicio?.destino_servicio?.servicio?.nombre ?? 'Servicio';
};

const destinoItem = (it: ReservaItem) => it.alternativa_item?.proveedor_tarifa?.proveedor_servicio?.destino_servicio?.destino_atractivo?.nombre ?? null;

// Tratamiento tributario por servicio (hallazgo de auditoría 2026-09-23):
// antes esto no se veía en ningún lado del detalle de la reserva — solo se
// descubría al chocar con el bloqueo de "mezcla tributaria" al intentar
// facturar. Mismo criterio de resolución que
// ReservaFacturacionController::resolverDestinoTributario()/
// resolverTipAfeIgvItem() en el backend (fuente de verdad) — esto solo lo
// refleja para el badge, no decide nada.
const destinoTributarioItem = (it: ReservaItem): string =>
    it.destino_tributario ?? it.proveedor_tarifa?.destino_tributario ?? 'nacional';

const tipAfeIgvItem = (it: ReservaItem): string => {
    if (it.alternativa_item?.origen_tipo === 'pasaje_aereo' && it.alternativa_item.cotizacion_pasaje_aereo?.tip_afe_igv) {
        return it.alternativa_item.cotizacion_pasaje_aereo.tip_afe_igv;
    }
    return it.tip_afe_igv ?? it.proveedor_tarifa?.tip_afe_igv ?? '10';
};

// Caso 3 Amazonía (2026-09-24) — mismo criterio que
// ReservaFacturacionController::detectarMezclaTributaria(): solo
// 'amazonia' se puede confirmar acá (extranjero sigue bloqueado sin
// excepción, no tiene sentido ofrecer "confirmar" para ese caso).
const requiereConfirmacionTributaria = (it: ReservaItem): boolean =>
    destinoTributarioItem(it) === 'amazonia' && !it.motivo_override_tributario;

const itemsPorIds = (ids: number[]): ReservaItem[] =>
    (reserva.value?.items ?? []).filter((it) => ids.includes(it.id));

// Compartido con el resumen "Tratamiento tributario de este comprobante" de
// los modales de facturar (simple/especial) — mismo criterio de etiquetas.
const etiquetaTipAfeIgv = (codigo: string): string => {
    if (codigo === '20') return 'Exonerado';
    if (codigo === '30') return 'Inafecto';
    return 'Gravado';
};

const badgeTratamientoTributario = (it: ReservaItem): { texto: string; clase: string } => {
    const destino = destinoTributarioItem(it);
    if (destino !== 'nacional') {
        return {
            texto: destino === 'amazonia' ? 'Amazonía (exonerado)' : destino,
            clase: 'bg-warning-subtle text-warning-emphasis border',
        };
    }

    const etiqueta = etiquetaTipAfeIgv(tipAfeIgvItem(it));
    return etiqueta === 'Gravado'
        ? { texto: etiqueta, clase: 'bg-light text-dark border' }
        : { texto: etiqueta, clase: 'bg-info-subtle text-info-emphasis border' };
};

// Resumen de solo lectura para el tab Ítems (auditoría de UX/funcionalidad
// 2026-08-27) — la edición real del vuelo de agencia vive en el tab
// Pasajeros (ver itemsVueloAgencia() más abajo), acá solo se cuenta cuántos
// de los pasajeros aplicables ya tienen al menos un número de vuelo cargado.
const resumenVueloAgencia = (it: ReservaItem) => {
    const total = reserva.value?.pasajeros?.length ?? 0;
    const confirmados = (it.vuelo_pasajeros ?? []).filter((vp) => vp.vuelo_numero_ida || vp.vuelo_numero_vuelta).length;
    return { confirmados, total };
};

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
        toast.success('Servicio actualizado');
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo actualizar el servicio');
    }
};

// Quitar ítem/pasajero (Fase D del backend, conectado recién en la
// auditoría 2026-08-27 — DELETE reserva-items/{id} y
// DELETE reserva-pasajeros/{id} existían sin ningún botón en el frontend).
// Recarga la reserva completa tras borrar: a diferencia de guardarItem()
// (edición local sin recarga), quitar un ítem/pasajero mueve totales,
// pendientes de facturar y el resumen entero — mismo criterio de recarga
// completa que ya usa sincronizarItems().
const eliminandoItemId = ref<number | null>(null);
const quitarItem = async (it: ReservaItem) => {
    const confirmacion = await (Swal as TVueSwalInstance).fire({
        title: `¿Quitar "${nombreItem(it)}" de la reserva?`,
        text: 'Esta acción no se puede deshacer.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sí, quitar',
        cancelButtonText: 'Cancelar',
    });
    if (!confirmacion.isConfirmed) return;

    eliminandoItemId.value = it.id;
    try {
        await reservaItemService.eliminar(it.id);
        toast.success('Servicio quitado de la reserva');
        await cargarReserva();
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo quitar el servicio');
    } finally {
        eliminandoItemId.value = null;
    }
};

const eliminandoPasajeroId = ref<number | null>(null);
const quitarPasajero = async (p: ReservaPasajero) => {
    const confirmacion = await (Swal as TVueSwalInstance).fire({
        title: `¿Quitar a ${p.nombre || 'este pasajero'} de la reserva?`,
        text: 'Esta acción no se puede deshacer.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sí, quitar',
        cancelButtonText: 'Cancelar',
    });
    if (!confirmacion.isConfirmed) return;

    eliminandoPasajeroId.value = p.id;
    try {
        await reservaPasajeroService.eliminar(p.id);
        toast.success('Pasajero quitado de la reserva');
        await cargarReserva();
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo quitar el pasajero');
    } finally {
        eliminandoPasajeroId.value = null;
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

// Bug real encontrado en pruebas (2026-08-27): sin este guard, un segundo
// toggle sobre el MISMO checkbox antes de que el primero termine (doble
// clic, o clic mientras la request anterior sigue en vuelo) lee el mismo
// asignacionesPorItem desactualizado, intenta el mismo DELETE dos veces, y
// el segundo revienta 404 ("No query results ... ReservaItemPasajero N")
// porque el primero ya la borró. asignacionEnProceso bloquea el checkbox
// mientras su propia request está pendiente — un Set reactivo (Vue 3 lo
// trackea nativo dentro de un ref()).
const asignacionEnProceso = ref<Set<string>>(new Set());
const claveAsignacion = (it: ReservaItem, p: ReservaPasajero) => `${it.id}_${p.id}`;

const toggleAsignacion = async (it: ReservaItem, p: ReservaPasajero) => {
    const key = claveAsignacion(it, p);
    if (asignacionEnProceso.value.has(key)) return;
    asignacionEnProceso.value.add(key);

    const asignacion = (asignacionesPorItem.value[it.id] ?? []).find((a) => a.reserva_pasajero_id === p.id);
    try {
        if (asignacion) {
            await reservaItemService.quitarPasajero(asignacion.id);
        } else {
            await reservaItemService.asignarPasajero(it.id, p.id);
        }
    } catch (error: any) {
        // 404 acá significa "ya estaba en el estado que querías" (otro
        // toggle/pestaña ya lo resolvió) — no es un error real para el
        // usuario, se resincroniza abajo igual. Cualquier otro código sí se
        // avisa.
        if (error.response?.status !== 404) {
            toast.error(error.response?.data?.message ?? 'No se pudo actualizar la asignación');
        }
    } finally {
        try {
            const res = await reservaItemService.listarPasajerosAsignados(it.id);
            asignacionesPorItem.value[it.id] = res.reserva_item_pasajeros.map((a) => ({ id: a.id, reserva_pasajero_id: a.reserva_pasajero_id }));
        } finally {
            asignacionEnProceso.value.delete(key);
        }
    }
};

// "Marcar/desmarcar todos" — hallazgo de la auditoría de UX: con una reserva
// grupal grande (10+ pasajeros) tildar uno por uno era tedioso. Sin
// endpoint masivo en el backend, se resuelve acá con un Promise.all sobre
// los mismos store()/destroy() que ya usa toggleAsignacion() — mientras
// corre, se deshabilita todo el fieldset del ítem (incluidos los checkboxes
// individuales) para no pisarse con asignacionEnProceso.
const todosAsignados = (it: ReservaItem) => (reserva.value?.pasajeros ?? []).every((p) => estaAsignado(it.id, p.id));

const asignacionMasivaEnProceso = ref<Set<number>>(new Set());
const toggleAsignacionMasiva = async (it: ReservaItem) => {
    if (asignacionMasivaEnProceso.value.has(it.id)) return;
    asignacionMasivaEnProceso.value.add(it.id);

    const marcarTodos = !todosAsignados(it);
    const pasajeros = reserva.value?.pasajeros ?? [];

    try {
        await Promise.all(pasajeros.map(async (p) => {
            const yaAsignado = estaAsignado(it.id, p.id);
            if (marcarTodos && !yaAsignado) {
                await reservaItemService.asignarPasajero(it.id, p.id);
            } else if (!marcarTodos && yaAsignado) {
                const asignacion = (asignacionesPorItem.value[it.id] ?? []).find((a) => a.reserva_pasajero_id === p.id);
                if (asignacion) await reservaItemService.quitarPasajero(asignacion.id);
            }
        }));
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo actualizar la asignación de todos los pasajeros');
    } finally {
        const res = await reservaItemService.listarPasajerosAsignados(it.id);
        asignacionesPorItem.value[it.id] = res.reserva_item_pasajeros.map((a) => ({ id: a.id, reserva_pasajero_id: a.reserva_pasajero_id }));
        asignacionMasivaEnProceso.value.delete(it.id);
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

// ── Reasignar mayorista (Sesión 12h, auditoria-arquitectonica-agencia-
// viajes.md §9.2) — si el mayorista elegido no puede honrar precio/cupo
// después de aceptada la reserva. NUNCA toca precio_venta_snapshot del
// cliente, ver docblock del backend (ReservaController::reasignarMayorista()).
const mostrarModalReasignarMayorista = ref(false);
const reasignandoMayorista = ref(false);
const cargandoOpcionesMayorista = ref(false);
const opcionesMayoristaTodas = ref<OpcionMayorista[]>([]);
const reasignarForm = ref<{
    opcion_mayorista_actual_id: number | null;
    reserva_item_ids: number[];
    nueva_opcion_mayorista_id: number | null;
    nueva_opcion_hotel_tarifa_id: number | null;
    motivo: string;
}>({ opcion_mayorista_actual_id: null, reserva_item_ids: [], nueva_opcion_mayorista_id: null, nueva_opcion_hotel_tarifa_id: null, motivo: '' });

// Ítems origen_tipo=mayorista con un mayorista real asignado, excluyendo
// los ya facturados (el backend los rechaza igual — mejor no ofrecerlos
// en el checklist que dejar que el submit falle).
const itemsConMayoristaActivos = computed(() =>
    (reserva.value?.items ?? []).filter((it) => it.opcion_mayorista_id && !itemsFacturadosIds.value.includes(it.id))
);

const mostrarBotonReasignarMayorista = computed(() => reserva.value?.estado === 'activa' && itemsConMayoristaActivos.value.length > 0);

// Casi siempre hay un único mayorista actual en toda la reserva — el
// select de arriba del modal solo se muestra si de verdad hay más de uno
// (ej. 2 destinos internacionales del mismo viaje con mayoristas
// distintos).
const mayoristasActualesDistintos = computed(() => {
    const grupos = new Map<number, { id: number; nombre: string; itemsCount: number }>();
    for (const it of itemsConMayoristaActivos.value) {
        const id = it.opcion_mayorista_id as number;
        if (!grupos.has(id)) {
            const p = it.opcion_mayorista?.proveedor;
            grupos.set(id, { id, nombre: (p ? (p.nombre_comercial || p.razon_social) : null) ?? 'Paquete mayorista', itemsCount: 0 });
        }
        grupos.get(id)!.itemsCount++;
    }
    return Array.from(grupos.values());
});

const itemsDelMayoristaActual = computed(() =>
    itemsConMayoristaActivos.value.filter((it) => it.opcion_mayorista_id === reasignarForm.value.opcion_mayorista_actual_id)
);

const opcionActualObj = computed(() => itemsDelMayoristaActual.value[0]?.opcion_mayorista ?? null);
const mayoristaActualNombre = computed(() => mayoristasActualesDistintos.value.find((m) => m.id === reasignarForm.value.opcion_mayorista_actual_id)?.nombre ?? '—');
const mayoristaActualMoneda = computed(() => opcionActualObj.value?.moneda ?? 'USD');

const opcionesMayoristaCandidatas = computed(() =>
    opcionesMayoristaTodas.value.filter((op) =>
        op.id !== reasignarForm.value.opcion_mayorista_actual_id &&
        op.alternativa_destino_id === opcionActualObj.value?.alternativa_destino_id
    )
);

const tarifasDeLaNuevaOpcion = computed(() => {
    const opcion = opcionesMayoristaTodas.value.find((op) => op.id === reasignarForm.value.nueva_opcion_mayorista_id);
    return (opcion?.opciones_hotel ?? []).flatMap((hotel) =>
        (hotel.opciones_hotel_tarifas ?? []).map((t) => ({ ...t, opcion_hotel_nombre: hotel.nombre_hotel }))
    );
});

const diferenciaCostoPreview = computed(() => {
    const items = itemsDelMayoristaActual.value.filter((it) => reasignarForm.value.reserva_item_ids.includes(it.id));
    const costoAnterior = items.reduce((sum, it) => sum + Number(it.alternativa_item?.costo_snapshot ?? 0), 0);
    const tarifa = tarifasDeLaNuevaOpcion.value.find((t) => t.id === reasignarForm.value.nueva_opcion_hotel_tarifa_id);
    const costoNuevo = tarifa ? Number(tarifa.precio_costo) * items.length : null;
    let clase = '';
    if (costoNuevo !== null) clase = costoNuevo > costoAnterior ? 'bg-danger-subtle' : costoNuevo < costoAnterior ? 'bg-success-subtle' : 'bg-light';
    return { costoAnterior, costoNuevo, clase };
});

const onCambiarMayoristaActual = () => {
    reasignarForm.value.reserva_item_ids = itemsDelMayoristaActual.value.map((it) => it.id);
    reasignarForm.value.nueva_opcion_mayorista_id = null;
    reasignarForm.value.nueva_opcion_hotel_tarifa_id = null;
};

const abrirModalReasignarMayorista = async () => {
    if (!reserva.value?.alternativa_id) return;
    reasignarForm.value = {
        opcion_mayorista_actual_id: mayoristasActualesDistintos.value[0]?.id ?? null,
        reserva_item_ids: [], nueva_opcion_mayorista_id: null, nueva_opcion_hotel_tarifa_id: null, motivo: '',
    };
    onCambiarMayoristaActual();
    mostrarModalReasignarMayorista.value = true;

    cargandoOpcionesMayorista.value = true;
    try {
        const res = await opcionMayoristaService.listar(reserva.value.alternativa_id);
        opcionesMayoristaTodas.value = res.opciones_mayorista ?? [];
    } catch {
        toast.error('No se pudieron cargar las opciones de mayorista disponibles');
    } finally {
        cargandoOpcionesMayorista.value = false;
    }
};

const confirmarReasignarMayorista = async () => {
    if (!reserva.value || !reasignarForm.value.nueva_opcion_mayorista_id) return;
    reasignandoMayorista.value = true;
    try {
        const res = await reservaService.reasignarMayorista(reserva.value.id, {
            reserva_item_ids: reasignarForm.value.reserva_item_ids,
            nueva_opcion_mayorista_id: reasignarForm.value.nueva_opcion_mayorista_id,
            nueva_opcion_hotel_tarifa_id: reasignarForm.value.nueva_opcion_hotel_tarifa_id,
            motivo: reasignarForm.value.motivo,
        });
        reserva.value = res.reserva;
        resumen.value = res.resumen;
        total.value = res.total;
        cabecera.value = res.cabecera;
        mostrarModalReasignarMayorista.value = false;
        const diffTexto = res.costo_nuevo !== null
            ? ` Costo: ${mayoristaActualMoneda.value} ${res.costo_anterior.toFixed(2)} → ${mayoristaActualMoneda.value} ${res.costo_nuevo.toFixed(2)}.`
            : '';
        toast.success(res.message + diffTexto);
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo reasignar el mayorista');
    } finally {
        reasignandoMayorista.value = false;
    }
};

// ── Reasignar hotel (18-sep-2026) — espejo de "Reasignar mayorista",
// para el hotel Local/Nacional (catálogo real o ad-hoc). A diferencia de
// mayorista, el hotel actual se identifica leyendo directamente los
// campos PROPIOS del reserva_item (it.proveedor_tarifa_id/
// it.opcion_hotel_tarifa_id) — es exactamente lo que el backend usa para
// el guard "mismo hotel actual" (ReservaController::reasignarHotel()),
// así que el frontend tiene que agrupar con el mismo criterio para no
// ofrecer una combinación que el backend rechazaría después.
const mostrarModalReasignarHotel = ref(false);
const reasignandoHotel = ref(false);
const origenNuevoHotel = ref<'catalogo' | 'adhoc'>('catalogo');
const buscarHotelCatalogo = ref('');
const buscandoTarifasCatalogo = ref(false);
const tarifasHotelCatalogoCandidatas = ref<ProveedorTarifa[]>([]);
let debounceBuscarHotelCatalogo: ReturnType<typeof setTimeout> | null = null;
const reasignarHotelForm = ref<{
    clave_hotel_actual: string;
    reserva_item_ids: number[];
    nueva_proveedor_tarifa_id: number | null;
    nuevo_opcion_hotel_tarifa_id: number | null;
    motivo: string;
}>({ clave_hotel_actual: '', reserva_item_ids: [], nueva_proveedor_tarifa_id: null, nuevo_opcion_hotel_tarifa_id: null, motivo: '' });

const claveHotelActual = (it: ReservaItem) => (it.proveedor_tarifa_id ? `proveedor:${it.proveedor_tarifa_id}` : `adhoc:${it.opcion_hotel_tarifa_id}`);

const itemsConHotelActivos = computed(() =>
    (reserva.value?.items ?? []).filter((it) =>
        (it.proveedor_tarifa?.tipo_habitacion || it.opcion_hotel_tarifa) && !itemsFacturadosIds.value.includes(it.id)
    )
);

const mostrarBotonReasignarHotel = computed(() => reserva.value?.estado === 'activa' && itemsConHotelActivos.value.length > 0);

// Casi siempre hay un único hotel actual en toda la reserva — mismo
// criterio que mayoristasActualesDistintos.
const hotelesActualesDistintos = computed(() => {
    const grupos = new Map<string, { clave: string; nombre: string; itemsCount: number }>();
    for (const it of itemsConHotelActivos.value) {
        const clave = claveHotelActual(it);
        if (!grupos.has(clave)) grupos.set(clave, { clave, nombre: nombreItem(it), itemsCount: 0 });
        grupos.get(clave)!.itemsCount++;
    }
    return Array.from(grupos.values());
});

const itemsDelHotelActual = computed(() => itemsConHotelActivos.value.filter((it) => claveHotelActual(it) === reasignarHotelForm.value.clave_hotel_actual));
const hotelActualNombre = computed(() => hotelesActualesDistintos.value.find((h) => h.clave === reasignarHotelForm.value.clave_hotel_actual)?.nombre ?? '—');

// Ad-hoc: candidatas son las tarifas ad-hoc YA usadas en algún otro ítem
// de esta misma reserva (no hay un "catálogo" de ad-hoc para buscar —
// nacen sueltas por cotización) — excluye el hotel actual y duplicados.
const tarifasHotelAdhocCandidatas = computed(() => {
    const claveActual = reasignarHotelForm.value.clave_hotel_actual;
    const vistos = new Set<number>();
    const lista: OpcionHotelTarifa[] = [];
    for (const it of reserva.value?.items ?? []) {
        const t = it.opcion_hotel_tarifa;
        if (t && !vistos.has(t.id) && claveHotelActual(it) !== claveActual) {
            vistos.add(t.id);
            lista.push(t);
        }
    }
    return lista;
});

const diferenciaCostoHotelPreview = computed(() => {
    const items = itemsDelHotelActual.value.filter((it) => reasignarHotelForm.value.reserva_item_ids.includes(it.id));
    const costoAnterior = items.reduce((sum, it) => sum + Number(it.alternativa_item?.costo_snapshot ?? 0), 0);
    let costoNuevo: number | null = null;
    if (reasignarHotelForm.value.nueva_proveedor_tarifa_id) {
        const t = tarifasHotelCatalogoCandidatas.value.find((tt) => tt.id === reasignarHotelForm.value.nueva_proveedor_tarifa_id);
        costoNuevo = t ? Number(t.precio_costo) * items.length : null;
    } else if (reasignarHotelForm.value.nuevo_opcion_hotel_tarifa_id) {
        const t = tarifasHotelAdhocCandidatas.value.find((tt) => tt.id === reasignarHotelForm.value.nuevo_opcion_hotel_tarifa_id);
        costoNuevo = t ? Number(t.precio_costo) * items.length : null;
    }
    let clase = '';
    if (costoNuevo !== null) clase = costoNuevo > costoAnterior ? 'bg-danger-subtle' : costoNuevo < costoAnterior ? 'bg-success-subtle' : 'bg-light';
    return { costoAnterior, costoNuevo, clase };
});

const onCambiarHotelActual = () => {
    reasignarHotelForm.value.reserva_item_ids = itemsDelHotelActual.value.map((it) => it.id);
    reasignarHotelForm.value.nueva_proveedor_tarifa_id = null;
    reasignarHotelForm.value.nuevo_opcion_hotel_tarifa_id = null;
};

const abrirModalReasignarHotel = () => {
    reasignarHotelForm.value = {
        clave_hotel_actual: hotelesActualesDistintos.value[0]?.clave ?? '',
        reserva_item_ids: [], nueva_proveedor_tarifa_id: null, nuevo_opcion_hotel_tarifa_id: null, motivo: '',
    };
    onCambiarHotelActual();
    origenNuevoHotel.value = 'catalogo';
    buscarHotelCatalogo.value = '';
    tarifasHotelCatalogoCandidatas.value = [];
    mostrarModalReasignarHotel.value = true;
};

const buscarTarifasHotelCatalogo = () => {
    if (debounceBuscarHotelCatalogo) clearTimeout(debounceBuscarHotelCatalogo);
    if (!buscarHotelCatalogo.value.trim()) {
        tarifasHotelCatalogoCandidatas.value = [];
        return;
    }
    debounceBuscarHotelCatalogo = setTimeout(async () => {
        buscandoTarifasCatalogo.value = true;
        try {
            const res = await proveedorService.biblioteca({ search: buscarHotelCatalogo.value, per_page: 30 });
            tarifasHotelCatalogoCandidatas.value = (res.proveedor_tarifas ?? []).filter((t) => !!t.tipo_habitacion);
        } catch {
            toast.error('No se pudo buscar en el catálogo de hoteles');
        } finally {
            buscandoTarifasCatalogo.value = false;
        }
    }, 350);
};

const confirmarReasignarHotel = async () => {
    if (!reserva.value) return;
    reasignandoHotel.value = true;
    try {
        const res = await reservaService.reasignarHotel(reserva.value.id, {
            reserva_item_ids: reasignarHotelForm.value.reserva_item_ids,
            nueva_proveedor_tarifa_id: reasignarHotelForm.value.nueva_proveedor_tarifa_id,
            nuevo_opcion_hotel_tarifa_id: reasignarHotelForm.value.nuevo_opcion_hotel_tarifa_id,
            motivo: reasignarHotelForm.value.motivo,
        });
        reserva.value = res.reserva;
        resumen.value = res.resumen;
        total.value = res.total;
        cabecera.value = res.cabecera;
        mostrarModalReasignarHotel.value = false;
        const diffTexto = res.costo_nuevo !== null ? ` Costo: ${res.costo_anterior.toFixed(2)} → ${res.costo_nuevo.toFixed(2)}.` : '';
        toast.success(res.message + diffTexto);
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo reasignar el hotel');
    } finally {
        reasignandoHotel.value = false;
    }
};

// ── Confirmar tratamiento tributario — Caso 3 Amazonía (2026-09-24) ──
const mostrarModalConfirmarTributario = ref(false);
const confirmandoTributario = ref(false);
const confirmarTributarioForm = ref<{
    items: ReservaItem[];
    destino_tributario: 'amazonia' | 'nacional' | 'extranjero';
    tip_afe_igv: '10' | '20' | '30';
    motivo: string;
}>({ items: [], destino_tributario: 'amazonia', tip_afe_igv: '20', motivo: '' });

const abrirModalConfirmarTributario = (items: ReservaItem[]) => {
    confirmarTributarioForm.value = {
        items,
        destino_tributario: (destinoTributarioItem(items[0]) as 'amazonia' | 'nacional' | 'extranjero') ?? 'amazonia',
        tip_afe_igv: (tipAfeIgvItem(items[0]) as '10' | '20' | '30') ?? '20',
        motivo: '',
    };
    mostrarModalConfirmarTributario.value = true;
};

const confirmarTratamientoTributario = async () => {
    if (!reserva.value) return;
    confirmandoTributario.value = true;
    try {
        const res = await reservaService.overrideTratamientoTributario(reserva.value.id, {
            reserva_item_ids: confirmarTributarioForm.value.items.map((it) => it.id),
            destino_tributario: confirmarTributarioForm.value.destino_tributario,
            tip_afe_igv: confirmarTributarioForm.value.tip_afe_igv,
            motivo: confirmarTributarioForm.value.motivo,
        });
        reserva.value = res.reserva;
        resumen.value = res.resumen;
        total.value = res.total;
        cabecera.value = res.cabecera;
        mostrarModalConfirmarTributario.value = false;
        toast.success(res.message);
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo confirmar el tratamiento tributario');
    } finally {
        confirmandoTributario.value = false;
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

// Tier 0 — conexión Adelantos↔Reservas: picker de anticipos disponibles
// para esta sub-factura, mismo patrón (checkbox + monto editable) que ya
// usa sale/register.vue para aplicar adelantos en el checkout normal. Un
// anticipo es de la reserva completa, no de un pasajero puntual — no se
// reparte automáticamente entre sub-facturas sin adivinar (mismo criterio
// ya usado con ítems compartidos), el vendedor elige a mano.
type AnticipoCheckoutItem = AnticipoDisponiblePreview & { seleccionado: boolean; monto_aplicado: number };
const anticiposEspecialSeleccionables = ref<AnticipoCheckoutItem[]>([]);

const onAnticipoEspecialToggle = (a: AnticipoCheckoutItem) => {
    a.monto_aplicado = a.seleccionado ? a.disponible : 0;
};

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
            facturarEspecialForm.value.reserva_item_ids_manual,
            {
                monedaFacturacion: monedaFacturacionSeleccionada.value,
                tipoCambioConversion: tipoCambioFacturacion.value,
                branchId: branchIdFacturacion.value,
                tipoComprobanteCodigo: facturarEspecialForm.value.tipo_comprobante_codigo
            }
        );
        // Sugerencia SUNAT del día — solo prellena si el vendedor todavía
        // no escribió nada a mano (nunca pisa un valor ya editado).
        if (requiereOverrideMoneda.value && tipoCambioFacturacion.value === null && previewFacturaEspecial.value.tipo_cambio_sugerido) {
            tipoCambioFacturacion.value = previewFacturaEspecial.value.tipo_cambio_sugerido;
        }
        // Reconstruye el picker preservando lo ya tildado por advance_id —
        // el preview se re-dispara con cada cambio de pasajeros/ítems.
        const previas = new Map(anticiposEspecialSeleccionables.value.map((a) => [a.advance_id, a]));
        anticiposEspecialSeleccionables.value = (previewFacturaEspecial.value?.anticipos_disponibles ?? []).map((a) => {
            const previa = previas.get(a.advance_id);
            return { ...a, seleccionado: previa?.seleccionado ?? false, monto_aplicado: previa?.monto_aplicado ?? 0 };
        });
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

// Cambiar de moneda o editar el tipo de cambio recalcula el total
// convertido — cualquiera de los dos modales que esté abierto (nunca los
// dos a la vez).
const refrescarPreviewFacturacionAbierta = () => {
    if (mostrarModalFacturarEspecial.value) refrescarPreviewFacturaEspecial();
    else if (mostrarModalFacturarSimple.value) refrescarPreviewFacturaSimple();
};

// Cambiar de moneda (select) es una sola acción discreta — refresca al
// toque. Editar el tipo de cambio es un <input type="number"> que dispara
// un evento por cada tecla — sin debounce, cada dígito escrito disparaba
// un preview nuevo (bug real reportado: "me refresca cada vez que escribo
// un número"). Mismo patrón que clienteSearchTimeout (onBuscarCliente) más
// abajo en este archivo.
watch(monedaFacturacionSeleccionada, refrescarPreviewFacturacionAbierta);

let tipoCambioFacturacionTimeout: ReturnType<typeof setTimeout> | undefined;
watch(tipoCambioFacturacion, () => {
    clearTimeout(tipoCambioFacturacionTimeout);
    tipoCambioFacturacionTimeout = setTimeout(refrescarPreviewFacturacionAbierta, 500);
});

// Serie visible (2026-09-24) — cambiar de sucursal o de tipo de
// comprobante (Boleta/Factura) también cambia qué serie se resuelve.
// facturarSimpleForm todavía no existe en este punto del script (se
// declara más abajo) — su propio watch vive junto a su declaración, ver
// más abajo, para evitar "Cannot access before initialization" (watch()
// evalúa el getter una vez de inmediato al registrarse).
watch(branchIdFacturacion, refrescarPreviewFacturacionAbierta);
watch(() => facturarEspecialForm.value.tipo_comprobante_codigo, () => {
    if (mostrarModalFacturarEspecial.value) refrescarPreviewFacturaEspecial();
});

// ── Buscador de cliente (mismo endpoint que usa el formulario de Ventas,
// `clients?search=`) — simplificado: solo busca y elige entre clientes ya
// existentes, no crea uno nuevo (si no existe, se crea desde la pantalla
// de Clientes primero). ──
type ClienteBusqueda = { id: number; full_name: string; n_document: string; cod_tipo_doc_sunat?: string };
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

// Cliente nuevo sin salir del modal (2026-09-24) — mismo componente que ya
// usa Venta Directa. Si lo que se tipeó en el buscador parece un RUC (11
// dígitos), se precarga en el form rápido para no reescribirlo dos veces.
const mostrarModalClienteRapido = ref(false);
const clienteRapidoInitialData = ref<{ type_document: string; n_document: string } | null>(null);

const abrirModalClienteRapido = () => {
    const query = busquedaCliente.value.trim();
    clienteRapidoInitialData.value = /^\d{11}$/.test(query)
        ? { type_document: 'RUC', n_document: query }
        : null;
    mostrarModalClienteRapido.value = true;
};

const onClienteRapidoCreado = (cliente: ClienteBusqueda) => {
    seleccionarCliente(cliente);
    mostrarModalClienteRapido.value = false;
};

// Factura ('01') exige RUC (cod_tipo_doc_sunat='6', Catálogo 06 SUNAT) —
// el backend ya lo valida y rechaza con 422 (2026-08-24), esto solo evita
// que el vendedor llegue a intentarlo. Corre cada vez que cambia el
// cliente elegido, no solo al abrir el modal.
const clienteEspecialTieneRuc = computed(() => clienteSeleccionado.value?.cod_tipo_doc_sunat === '6');
watch(clienteSeleccionado, (cliente) => {
    if (cliente && cliente.cod_tipo_doc_sunat !== '6' && facturarEspecialForm.value.tipo_comprobante_codigo === '01') {
        facturarEspecialForm.value.tipo_comprobante_codigo = '03';
    }
});

const abrirModalFacturarEspecial = () => {
    facturarEspecialForm.value = {
        pasajero_ids: pasajerosPendientesFacturar.value.map((p) => p.id),
        reserva_item_ids_manual: [],
        tipo_comprobante_codigo: '01',
        texto_personalizado: '',
    };
    previewFacturaEspecial.value = null;
    anticiposEspecialSeleccionables.value = [];
    clienteSeleccionado.value = null;
    busquedaCliente.value = '';
    resultadosCliente.value = [];
    resetearOverrideMoneda();
    mostrarModalFacturarEspecial.value = true;
    refrescarPreviewFacturaEspecial();
    cargarSucursalesSiCorresponde();
};

const confirmarFacturacionEspecial = async () => {
    if (!reserva.value || !clienteSeleccionado.value) return;
    if (requiereOverrideMoneda.value && (!tipoCambioFacturacion.value || !motivoCambioMonedaFacturacion.value.trim())) {
        toast.error('Indica el tipo de cambio y el motivo para facturar en una moneda distinta a la de la cotización.');
        return;
    }
    facturando.value = true;
    try {
        const anticiposAplicados = anticiposEspecialSeleccionables.value
            .filter((a) => a.seleccionado && a.monto_aplicado > 0)
            .map((a) => ({ advance_id: a.advance_id, amount: a.monto_aplicado }));

        const res = await reservaFacturacionService.facturar(reserva.value.id, {
            pasajero_ids: facturarEspecialForm.value.pasajero_ids,
            reserva_item_ids_manual: facturarEspecialForm.value.reserva_item_ids_manual,
            tipo_comprobante_codigo: facturarEspecialForm.value.tipo_comprobante_codigo,
            client_id: clienteSeleccionado.value.id,
            texto_personalizado: facturarEspecialForm.value.texto_personalizado || null,
            advance_applications: anticiposAplicados.length > 0 ? anticiposAplicados : undefined,
            branch_id: puedeCambiarSucursal.value ? branchIdFacturacion.value : undefined,
            moneda_facturacion: requiereOverrideMoneda.value ? monedaFacturacionSeleccionada.value : undefined,
            tipo_cambio_conversion: requiereOverrideMoneda.value ? tipoCambioFacturacion.value : undefined,
            motivo_cambio_moneda: requiereOverrideMoneda.value ? motivoCambioMonedaFacturacion.value.trim() : undefined,
        });
        toast.success(`${res.message} (serie ${res.serie})`);
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
            anticiposEspecialSeleccionables.value = [];
            clienteSeleccionado.value = null;
            resetearOverrideMoneda();
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

// Serie visible (2026-09-24) — cambiar Boleta/Factura cambia qué serie se
// resuelve. Ver el watch gemelo de facturarEspecialForm más arriba.
watch(() => facturarSimpleForm.value.tipo_comprobante_codigo, () => {
    if (mostrarModalFacturarSimple.value) refrescarPreviewFacturaSimple();
});

// Cuando ya no queda ningún pasajero pendiente pero sí ítems sueltos sin
// cubrir (ver itemsPendientesDeFacturarCount), el backend acepta
// re-enviar pasajero_ids ya facturados SOLO para arrastrar esos ítems
// (resolverSeleccion() los ignora para armar líneas, los usa nada más
// para no violar el "pasajero_ids requerido, mínimo 1") — sin esto, un
// ítem compartido/sin asignar que quedó huérfano después de facturar a
// todos los pasajeros nunca tendría forma de facturarse.
const pasajeroIdsParaFacturarSimple = () => {
    const pendientes = pasajerosPendientesFacturar.value.map((p) => p.id);
    if (pendientes.length > 0) return pendientes;
    return (reserva.value?.pasajeros ?? []).map((p) => p.id);
};

// Mismo criterio que clienteEspecialTieneRuc: "Facturar simple" usa
// siempre el cliente fijo de la cotización, sin buscador — si no tiene
// RUC, arranca directo en boleta.
const clienteSimpleTieneRuc = computed(() => cabecera.value?.cliente?.cod_tipo_doc_sunat === '6');

const refrescarPreviewFacturaSimple = async () => {
    if (!reserva.value) return;
    cargandoFacturarSimple.value = true;
    try {
        const pasajeroIds = pasajeroIdsParaFacturarSimple();
        const opciones = {
            monedaFacturacion: monedaFacturacionSeleccionada.value,
            tipoCambioConversion: tipoCambioFacturacion.value,
            branchId: branchIdFacturacion.value,
            tipoComprobanteCodigo: facturarSimpleForm.value.tipo_comprobante_codigo
        };
        // Primer preview: descubre qué ítems sin asignar hay disponibles.
        const primerPreview = await reservaFacturacionService.prepararFactura(reserva.value.id, pasajeroIds, [], opciones);
        const idsSinAsignar = (primerPreview.items_sin_asignar_disponibles ?? []).map((it) => it.reserva_item_id);
        // Segundo preview: los agrega todos, para que el total refleje
        // realmente "toda la reserva pendiente" — el modo simple no
        // pregunta cuáles, se asume que todos van en este único
        // comprobante.
        previewFacturaSimple.value = idsSinAsignar.length > 0
            ? await reservaFacturacionService.prepararFactura(reserva.value.id, pasajeroIds, idsSinAsignar, opciones)
            : primerPreview;
        if (requiereOverrideMoneda.value && tipoCambioFacturacion.value === null && previewFacturaSimple.value.tipo_cambio_sugerido) {
            tipoCambioFacturacion.value = previewFacturaSimple.value.tipo_cambio_sugerido;
        }
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo calcular la vista previa de la factura');
        mostrarModalFacturarSimple.value = false;
    } finally {
        cargandoFacturarSimple.value = false;
    }
};

const abrirModalFacturarSimple = async () => {
    if (!reserva.value) return;
    previewFacturaSimple.value = null;
    facturarSimpleForm.value = { tipo_comprobante_codigo: clienteSimpleTieneRuc.value ? '01' : '03' };
    resetearOverrideMoneda();
    mostrarModalFacturarSimple.value = true;
    cargarSucursalesSiCorresponde();
    await refrescarPreviewFacturaSimple();
};

const confirmarFacturacionSimple = async () => {
    if (!reserva.value || !cabecera.value?.cliente?.id) return;
    if (requiereOverrideMoneda.value && (!tipoCambioFacturacion.value || !motivoCambioMonedaFacturacion.value.trim())) {
        toast.error('Indica el tipo de cambio y el motivo para facturar en una moneda distinta a la de la cotización.');
        return;
    }
    facturando.value = true;
    try {
        const pasajeroIds = pasajeroIdsParaFacturarSimple();
        const idsSinAsignar = (previewFacturaSimple.value?.items_sin_asignar_disponibles ?? []).map((it) => it.reserva_item_id);
        const res = await reservaFacturacionService.facturar(reserva.value.id, {
            pasajero_ids: pasajeroIds,
            reserva_item_ids_manual: idsSinAsignar,
            tipo_comprobante_codigo: facturarSimpleForm.value.tipo_comprobante_codigo,
            client_id: cabecera.value.cliente.id,
            branch_id: puedeCambiarSucursal.value ? branchIdFacturacion.value : undefined,
            moneda_facturacion: requiereOverrideMoneda.value ? monedaFacturacionSeleccionada.value : undefined,
            tipo_cambio_conversion: requiereOverrideMoneda.value ? tipoCambioFacturacion.value : undefined,
            motivo_cambio_moneda: requiereOverrideMoneda.value ? motivoCambioMonedaFacturacion.value.trim() : undefined,
        });
        mostrarModalFacturarSimple.value = false;
        toast.success(`${res.message} (serie ${res.serie})`);
        await cargarReserva();
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo facturar la reserva');
    } finally {
        facturando.value = false;
    }
};

// ── Cobrar anticipo (Tier 0 — conexión Adelantos↔Reservas, 2026-08-21) ──
// Punto de entrada elegido con el usuario: cobrar desde la propia pantalla
// de la reserva (no desde el módulo genérico de Adelantos con etiquetado
// posterior) — reduce el riesgo de anticipos huérfanos sin asignar.
const mostrarModalAnticipo = ref(false);
const guardandoAnticipo = ref(false);
const paymentMethods = ref<PaymentMethod[]>([]);
const formAnticipo = ref<{ monto: number; medio_pago: string; tip_afe_igv: '10' | '20' | '30'; notas: string }>({
    monto: 0, medio_pago: '', tip_afe_igv: '10', notas: '',
});

const cargarPaymentMethods = async () => {
    try {
        const res: { data: PaymentMethods } = await httpClient.get('payment-methods?active=1');
        paymentMethods.value = res.data.payment_methods;
    } catch {
        // Silencioso — un fallo acá no debe bloquear la carga de la reserva.
    }
};

const abrirModalAnticipo = () => {
    formAnticipo.value = { monto: 0, medio_pago: paymentMethods.value[0]?.code ?? '', tip_afe_igv: '10', notas: '' };
    mostrarModalAnticipo.value = true;
};

const guardarAnticipo = async () => {
    if (!reserva.value) return;
    guardandoAnticipo.value = true;
    try {
        const res = await reservaAnticipoService.crear(reserva.value.id, {
            monto: formAnticipo.value.monto,
            medio_pago: formAnticipo.value.medio_pago,
            tip_afe_igv: formAnticipo.value.tip_afe_igv,
            notas: formAnticipo.value.notas || null,
        });
        toast.success(res.message);
        mostrarModalAnticipo.value = false;
        await cargarReserva();
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo registrar el anticipo');
    } finally {
        guardandoAnticipo.value = false;
    }
};

const quitarAnticipo = async (anticipo: AnticipoReserva) => {
    if (!confirm('¿Quitar este anticipo de la reserva? El dinero no se toca, solo deja de estar asociado a este viaje.')) return;
    try {
        const res = await reservaAnticipoService.eliminar(anticipo.id);
        toast.success(res.message);
        await cargarReserva();
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo quitar el anticipo');
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
    const [bib, gs] = await Promise.all([
        proveedorService.biblioteca(),
        guiaService.listar({ page: 1 }),
        cargarPaymentMethods(),
    ]);
    bibliotecaTarifas.value = bib.proveedor_tarifas;
    guias.value = gs.guias ?? [];
});
</script>
