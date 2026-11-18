<template>
    <DefaultLayout>
        <div v-if="cargandoPagina" class="text-center py-5">
            <span class="spinner-border text-primary"></span>
        </div>

        <template v-else>
        <div v-if="cotizacion" class="mb-3">
            <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
                <div>
                    <h5 v-if="modificacionInfo" class="fw-bold mb-1">
                        <i class="fas fa-pen-to-square me-2 text-primary"></i>Modificación de la reserva {{ modificacionInfo.reserva_codigo ?? `#${modificacionInfo.reserva_id}` }}
                        <span v-if="modificacionInfo.estado !== 'abierta'" class="badge bg-secondary ms-2">{{ modificacionInfo.estado === 'aplicada' ? 'Aplicada' : 'Descartada' }}</span>
                    </h5>
                    <h5 v-else class="fw-bold mb-1"><i class="fas fa-route me-2 text-primary"></i>Cotización {{ cotizacion.codigo }}</h5>
                    <div class="fw-semibold text-dark mb-2"><i class="fas fa-user me-2 text-muted" style="font-size:12px"></i>{{ cotizacion.cliente?.full_name ?? 'Sin cliente' }}</div>
                    <!-- T-051: la próxima acción pendiente de la cotización (la lista está en el panel de la alternativa). -->
                    <div v-if="proximaAccion" class="small mb-2" :class="proximaAccion.fecha < hoyPeru() ? 'text-danger' : 'text-muted'">
                        <i class="fas fa-tasks me-1"></i>Próxima acción: {{ proximaAccion.tipo_etiqueta.toLowerCase() }} el
                        {{ formatDiaCorto(proximaAccion.fecha) }} ({{ proximaAccion.responsable.nombre.split(' ')[0] }})
                    </div>
                    <!-- T-052: cerrada como perdida (motivo y detalle como texto, nunca v-html). -->
                    <div v-if="cotizacionPerdida" class="small text-danger mb-2">
                        <i class="fas fa-circle-xmark me-1"></i>Perdida: {{ cotizacion.resultado_motivo?.nombre ?? 'sin motivo' }}<template v-if="cotizacion.resultado_detalle"> — {{ cotizacion.resultado_detalle }}</template>
                        · {{ formatFechaHora(cotizacion.perdida_en) }}<template v-if="cotizacion.perdida_por"> ({{ cotizacion.perdida_por.name }})</template>
                    </div>
                    <PreferenciasViajeroResumen :cliente-id="cotizacion.cliente_id ?? null" />
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <template v-if="puedeCambiarResultado">
                        <button v-if="cotizacionPerdida" class="btn btn-outline-primary btn-sm" :disabled="reabriendo" @click="reabrirCotizacion">
                            <span v-if="reabriendo" class="spinner-border spinner-border-sm me-1"></span>
                            <i v-else class="fas fa-rotate-left me-1"></i>Reabrir
                        </button>
                        <button v-else class="btn btn-outline-danger btn-sm" @click="cerrandoComoPerdida = true">
                            <i class="fas fa-circle-xmark me-1"></i>Cerrar como perdida
                        </button>
                    </template>
                    <router-link v-if="modificacionInfo" :to="`/agencia-viajes/reservas/${modificacionInfo.reserva_id}`" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-arrow-left me-2"></i>Volver a la reserva
                    </router-link>
                    <router-link v-else to="/agencia-viajes/cotizador" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-arrow-left me-2"></i>Volver
                    </router-link>
                </div>
            </div>
            <CerrarComoPerdidaModal v-if="cerrandoComoPerdida" :cotizacion-id="cotizacion.id" :codigo="cotizacion.codigo"
                @cerrar="cerrandoComoPerdida = false" @cerrada="onResultadoCambiado('Cotización cerrada como perdida.')" />
            <AceptarAlternativaModal v-if="aceptandoAlternativa && alternativaActiva" :alternativa-nombre="alternativaActiva.nombre"
                :procesando="marcandoAceptada" @cerrar="aceptandoAlternativa = false" @confirmar="confirmarAceptada" />
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="badge bg-light text-dark border fw-normal"><i class="fas fa-map-marker-alt me-1 text-primary"></i>{{ cotizacion.destino }}</span>
                <span class="badge bg-light text-dark border fw-normal"><i class="fas fa-calendar me-1 text-primary"></i>{{ textoFechasViaje }}</span>
                <span class="badge bg-light text-dark border fw-normal"><i class="fas fa-users me-1 text-primary"></i>{{ resumenPax }}</span>
                <i class="fas fa-pen text-primary" style="cursor:pointer;font-size:12px" title="Corregir cliente/destino/fecha" @click="abrirEdicionCabecera"></i>
                <i v-if="!hayAlternativaAceptada" class="fas fa-user-edit text-primary" style="cursor:pointer;font-size:12px" title="Agregar o quitar pasajeros" @click="abrirEdicionPasajeros"></i>
                <i v-else class="fas fa-user-lock text-muted" style="font-size:12px" title="Esta cotización ya tiene una alternativa aceptada (con reserva generada) — los pasajeros ya no se pueden modificar desde acá."></i>
            </div>
        </div>

        <!-- T-007 — modificación de reserva: aviso de edades estimadas, panel de diferencia. -->
        <div v-if="avisoEdadEstimada.length" class="alert alert-warning py-2 px-3 small mb-3 d-flex justify-content-between gap-2">
            <span>
                <i class="fas fa-triangle-exclamation me-1"></i><strong>Edad estimada:</strong>
                estos pasajeros de la reserva no tienen fecha de nacimiento; se usó una edad según su tipo. Corrígela si cambia el precio:
                <span v-for="(p, i) in avisoEdadEstimada" :key="p.reserva_pasajero_id">{{ i > 0 ? ', ' : ' ' }}{{ p.nombre || 'Pasajero' }} ({{ p.edad_estimada }} años)</span>.
            </span>
            <button class="btn-close" @click="avisoEdadEstimada = []"></button>
        </div>
        <div v-if="modificacionInfo && modificacionInfo.estado !== 'abierta'" class="alert alert-secondary py-2 small mb-3">
            Esta modificación ya está {{ modificacionInfo.estado === 'aplicada' ? 'aplicada a la reserva' : 'descartada' }}: solo se puede consultar.
        </div>
        <ModificacionDiferenciaPanel v-if="modificacionInfo?.estado === 'abierta'"
            :diferencia="modificacion.diferencia.value" :cargando="modificacion.cargando.value" :error="modificacion.error.value"
            :procesando="procesandoModificacion" :descargando-pdf="descargandoPdfModificacion"
            @refrescar="modificacion.cargar()" @pdf="descargarPdfModificacion" @descartar="descartarModificacion" @aplicar="aplicarModificacion"
            @ir-a-item="irAItem" @confirmar="confirmarRevisados" @abrir-comprobante="abrirComprobanteBloqueo"
            @abrir-penalidad="abrirPenalidad" @borrar-penalidad="borrarPenalidad" />
        <PenalidadPasajeroModal v-if="penalidadPasajero" :pasajero="penalidadPasajero" :moneda="modificacion.diferencia.value?.moneda ?? ''"
            :guardando="guardandoPenalidad" :error-mensaje="errorPenalidad" :inicial="penalidadInicial"
            @cerrar="penalidadPasajero = null" @guardar="guardarPenalidad" />

        <!-- Agregar/quitar pasajeros — gap real: el endpoint existía en el
             backend desde Sesión 7a pero nunca tuvo control en pantalla.
             Bloqueado (ícono de arriba deshabilitado) si ya hay una
             alternativa aceptada, ver hayAlternativaAceptada. -->
        <div v-if="editandoPasajeros" class="card border-0 shadow-sm mb-3">
            <div class="card-body py-3">
                <div class="d-flex gap-2 mb-3">
                    <button class="btn btn-sm btn-outline-primary" @click="agregarPaxEdicion(30)"><i class="fas fa-plus me-1"></i>Adulto</button>
                    <button class="btn btn-sm btn-outline-primary" @click="agregarPaxEdicion(8)"><i class="fas fa-plus me-1"></i>Niño</button>
                    <button class="btn btn-sm btn-outline-primary" @click="agregarPaxEdicion(1)"><i class="fas fa-plus me-1"></i>Infante</button>
                </div>
                <div v-if="formPasajeros.length === 0" class="text-muted small fst-italic mb-2">Agregá al menos un pasajero.</div>
                <div class="d-flex flex-column gap-2 mb-3">
                    <div v-for="(pax, idx) in formPasajeros" :key="pax.id ?? `nuevo-${idx}`" class="d-flex align-items-center gap-2 border rounded p-2 small">
                        <span class="badge" :class="tipoPorEdadEdicion(pax.edad).clase" style="min-width:70px">{{ tipoPorEdadEdicion(pax.edad).label }}</span>
                        <label class="text-muted mb-0">Edad</label>
                        <input type="number" class="form-control form-control-sm" style="max-width:80px" v-model.number="pax.edad" min="0" max="120">
                        <!-- Fila 13, fase 1b (§3.3): residencia, solo para el precio del tour. -->
                        <select class="form-select form-select-sm" style="max-width:130px" v-model="pax.residencia" title="Residencia (precio del tour)">
                            <option value="nacional">Nacional</option>
                            <option value="extranjero">Extranjero</option>
                        </select>
                        <span v-if="!pax.id" class="badge bg-info-subtle text-info">nuevo</span>
                        <i class="fas fa-times text-danger ms-auto" style="cursor:pointer" @click="formPasajeros.splice(idx, 1)"></i>
                    </div>
                </div>
                <div class="alert alert-warning py-2 px-3 small mb-3">
                    <i class="fas fa-triangle-exclamation me-1"></i>Si esta cotización ya tiene ítems cargados que se cobran "por persona", al guardar sus precios se recalculan automáticamente con la nueva cantidad de pasajeros — los que no se puedan recalcular solos (ej. pasajes aéreos, paquetes de mayorista) quedan avisados para revisar el precio a mano.
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-primary btn-sm" @click="guardarPasajeros" :disabled="guardandoPasajeros || formPasajeros.length === 0">
                        <span v-if="guardandoPasajeros" class="spinner-border spinner-border-sm me-1"></span>
                        <span v-else><i class="fas fa-check me-1"></i>Guardar</span>
                    </button>
                    <button class="btn btn-outline-secondary btn-sm" @click="editandoPasajeros = false">Cancelar</button>
                </div>
            </div>
        </div>

        <!-- Corregir cliente/destino/fecha (me equivoqué al crear la cotización) -->
        <div v-if="editandoCabecera" class="card border-0 shadow-sm mb-3">
            <div class="card-body py-3">
                <div class="row g-2">
                    <div class="col-12 col-md-5 position-relative">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Cliente</label>
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control" v-model="clientSearchText"
                                placeholder="Buscar por DNI, RUC o nombre..." @input="onClientSearchInput"
                                @focus="showClientSuggestions = true" @blur="onClientSearchBlur" autocomplete="off">
                            <button v-if="clienteEditado" class="btn btn-outline-primary" type="button" title="Editar datos del cliente" @click="abrirEdicionCliente">
                                <i class="fas fa-pen"></i>
                            </button>
                            <button v-if="clienteEditado" class="btn btn-outline-danger" type="button" @click="limpiarClienteEditado">
                                <i class="fas fa-times"></i>
                            </button>
                            <button v-else class="btn btn-outline-primary" type="button" @click="abrirNuevoCliente" title="Registrar cliente nuevo">
                                <i class="fas fa-user-plus"></i>
                            </button>
                        </div>
                        <div v-if="showClientSuggestions && clientSuggestions.length > 0 && !clienteEditado"
                            class="list-group mt-1 position-absolute"
                            style="max-height:220px;overflow-y:auto;z-index:1050;width:calc(100% - 2px);box-shadow:0 4px 8px rgba(0,0,0,.1)">
                            <button type="button" class="list-group-item list-group-item-action" v-for="c in clientSuggestions" :key="c.id"
                                @mousedown.prevent="seleccionarClienteEditado(c)">
                                <div class="d-flex justify-content-between">
                                    <span>{{ c.full_name }}</span>
                                    <small class="text-muted">{{ c.n_document }}</small>
                                </div>
                            </button>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Destino</label>
                        <input type="text" class="form-control form-control-sm" v-model="formCabecera.destino">
                    </div>
                    <div class="col-6 col-md-3 d-flex align-items-end gap-1">
                        <button class="btn btn-primary btn-sm w-100" @click="guardarCabecera" :disabled="guardandoCabecera">
                            <span v-if="guardandoCabecera" class="spinner-border spinner-border-sm"></span>
                            <span v-else><i class="fas fa-check me-1"></i>Guardar</span>
                        </button>
                        <button class="btn btn-outline-secondary btn-sm" @click="editandoCabecera = false"><i class="fas fa-times"></i></button>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Fecha desde</label>
                        <CampoFecha v-model="formCabecera.fecha_viaje_desde" />
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Fecha hasta</label>
                        <CampoFecha v-model="formCabecera.fecha_viaje_hasta" />
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" tabindex="-1" :class="{ show: showQuickClientModal, 'd-block': showQuickClientModal }"
            style="background:rgba(0,0,0,.5)" v-if="showQuickClientModal">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title">{{ modoEdicionCliente ? 'Editar Cliente' : 'Registrar Cliente Rápido' }}</h6>
                        <button class="btn-close" @click="showQuickClientModal = false"></button>
                    </div>
                    <div class="modal-body">
                        <ClientFormQuick :initial-data="modoEdicionCliente ? clienteEditado : null"
                            :cliente-id="modoEdicionCliente ? clienteEditado?.id ?? null : null"
                            @saved="onClientCreated" @cancel="showQuickClientModal = false" />
                    </div>
                </div>
            </div>
        </div>

        <CalculadoraTipoCambioModal v-if="mostrarCalculadoraTipoCambio && alternativaActiva"
            :moneda-cotizacion="alternativaActiva.moneda_cotizacion" :total-alternativa="totalLocal"
            @close="mostrarCalculadoraTipoCambio = false" />

        <!-- Pestañas de alternativas -->
        <div class="d-flex align-items-center gap-2 mb-3 flex-wrap" v-if="cotizacion">
            <span v-for="alt in cotizacion.alternativas" :key="alt.id" class="alt-pill position-relative badge rounded-pill px-3 py-2 d-inline-flex align-items-center gap-2"
                :class="[alt.id === alternativaActivaId ? 'bg-primary' : 'bg-light text-dark border', { 'alt-pill-reservada': alt.estado === 'aceptada' }]"
                style="cursor:pointer" @click="seleccionarAlternativa(alt.id)">
                <!-- T-080: indicador en la esquina, visible activa o no (el ✓ interno heredaba el color de la pestaña). -->
                <span v-if="alt.estado === 'aceptada'" class="badge rounded-pill bg-success position-absolute top-0 start-100 translate-middle alt-pill-marca" title="Alternativa reservada">
                    <i class="fas fa-check"></i>
                </span>
                <span v-else-if="alt.estado === 'reemplazada'" class="badge rounded-pill bg-secondary position-absolute top-0 start-100 translate-middle alt-pill-marca" title="Alternativa reemplazada por una modificación">
                    <i class="fas fa-history"></i>
                </span>
                <span>
                    {{ alt.nombre }} · {{ alt.moneda_cotizacion }} {{ Number(alt.total).toFixed(0) }}
                    <span v-if="alt.estado === 'aceptada' || alt.estado === 'reemplazada'" class="ms-1">
                        <template v-if="reservaDeAlternativa(alt)">
                            {{ alt.estado === 'reemplazada' ? 'Reemplazada' : 'Reservada' }} ·
                            <router-link :to="`/agencia-viajes/reservas/${reservaDeAlternativa(alt)!.id}`" class="text-reset fw-semibold" @click.stop>{{ reservaDeAlternativa(alt)!.codigo ?? `#${reservaDeAlternativa(alt)!.id}` }}</router-link>
                        </template>
                        <template v-else>{{ alt.estado === 'reemplazada' ? 'Reemplazada' : 'Reservada' }}</template>
                    </span>
                    <span v-else-if="alt.estado === 'descartada'" class="ms-1 opacity-50"><i class="fas fa-times-circle"></i></span>
                </span>
                <i v-if="alt.id === alternativaActivaId" class="fas fa-pen alt-pill-edit" title="Renombrar esta alternativa" @click.stop="renombrarAlternativa(alt)"></i>
                <!-- T-033: la moneda se cambia solo mientras la alternativa no tenga servicios. -->
                <select v-if="alt.id === alternativaActivaId && !modificacionInfo && !alternativaSoloLectura" class="form-select form-select-sm py-0 ps-2 pe-4 w-auto"
                    style="font-size:.75rem" :value="alt.moneda_cotizacion" :disabled="(alt.items?.length ?? 0) > 0 || cambiandoMoneda"
                    :title="(alt.items?.length ?? 0) > 0 ? 'Con servicios no se cambia la moneda: quítalos o crea otra alternativa' : 'Moneda de la alternativa'"
                    @click.stop @change="cambiarMonedaAlternativa(alt, ($event.target as HTMLSelectElement).value as 'PEN' | 'USD')">
                    <option value="PEN">PEN</option>
                    <option value="USD">USD</option>
                </select>
                <span v-if="alt.id === alternativaActivaId && eliminandoAlternativa" class="spinner-border spinner-border-sm alt-pill-delete" style="opacity:1"></span>
                <!-- Una alternativa reservada o reemplazada ya generó su reserva: el servidor no la elimina. -->
                <i v-else-if="alt.id === alternativaActivaId && !['aceptada', 'reemplazada'].includes(alt.estado)" class="fas fa-trash alt-pill-delete" title="Eliminar esta alternativa" @click.stop="eliminarAlternativa"></i>
            </span>
            <!-- Punto F — siempre visible; deshabilitado con motivo al llegar al tope,
                 en vez de desaparecer sin explicación. -->
            <span v-if="!modificacionInfo && !cotizacionPerdida" class="badge rounded-pill px-3 py-2"
                :class="alcanzoLimiteAlternativas ? 'bg-light text-muted border opacity-50' : 'bg-light text-dark border'"
                :style="alcanzoLimiteAlternativas ? 'cursor:not-allowed;border-style:dashed' : 'cursor:pointer;border-style:dashed'"
                :title="alcanzoLimiteAlternativas ? 'Máximo 5 alternativas por cotización' : ''"
                @click="!alcanzoLimiteAlternativas && (mostrarFormAlternativa = true)">
                <i class="fas fa-plus me-1"></i>Nueva
            </span>
        </div>

        <!-- Form nueva alternativa -->
        <div v-if="mostrarFormAlternativa" class="card border-0 shadow-sm mb-3">
            <div class="card-body py-2">
                <div class="row g-2 align-items-end">
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Nombre</label>
                        <input type="text" class="form-control form-control-sm" :class="{ 'is-invalid': erroresAlternativa.nombre }"
                            v-model="formAlternativa.nombre" :placeholder="nombreSugeridoAlternativa">
                        <div v-if="erroresAlternativa.nombre" class="invalid-feedback">{{ erroresAlternativa.nombre }}</div>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Moneda</label>
                        <select class="form-select form-select-sm" :class="{ 'is-invalid': erroresAlternativa.moneda_cotizacion }" v-model="formAlternativa.moneda_cotizacion">
                            <option value="PEN">Soles</option>
                            <option value="USD">Dólares</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Tipo de cambio</label>
                        <select class="form-select form-select-sm" v-model="formAlternativa.tipo_cambio_origen">
                            <option value="dia">Del día</option>
                            <option value="agencia">De la agencia</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Valor nuevo (opcional)</label>
                        <input type="number" step="0.0001" class="form-control form-control-sm" :class="{ 'is-invalid': erroresAlternativa.tipo_cambio_valor }" v-model.number="formAlternativa.tipo_cambio_valor">
                        <div v-if="erroresAlternativa.tipo_cambio_valor" class="invalid-feedback">{{ erroresAlternativa.tipo_cambio_valor }}</div>
                        <small v-if="sugerenciaTipoCambioSunat && formAlternativa.tipo_cambio_origen === 'dia'" class="text-muted d-block">
                            Sugerido SUNAT ({{ sugerenciaTipoCambioSunat.fecha }}): {{ sugerenciaTipoCambioSunat.valor }}
                        </small>
                    </div>
                    <div class="col-12 col-md-2 d-flex gap-2">
                        <button class="btn btn-primary btn-sm w-100" @click="crearAlternativa()" :disabled="creandoAlternativa">
                            <span v-if="creandoAlternativa" class="spinner-border spinner-border-sm me-1"></span>Crear
                        </button>
                        <button class="btn btn-outline-secondary btn-sm" @click="mostrarFormAlternativa = false"><i class="fas fa-times"></i></button>
                    </div>
                </div>
                <div v-if="erroresAlternativa.general" class="text-danger small mt-2">{{ erroresAlternativa.general }}</div>
            </div>
        </div>

        <!-- Sesión 12f-2, §7.1 punto 1 — chips de destino, un nivel arriba de
             las pestañas de día. Ocultos con 1 solo destino (Punto B, mismo
             criterio que mostrarTabsDia) — el "+ Agregar destino" queda
             visible igual, es el único camino para pasar a 2. -->
        <div v-if="alternativaActiva" class="d-flex align-items-center gap-2 mb-2 flex-wrap ms-2">
            <i v-if="mostrarChipsDestino" class="fas fa-angle-right text-muted" style="font-size:11px"></i>
            <template v-if="mostrarChipsDestino">
                <span v-for="d in alternativaDestinos" :key="d.id" class="destino-chip badge rounded-pill px-2 py-1 d-inline-flex align-items-center gap-2"
                    :class="destinoActivoId === d.id ? 'bg-primary' : 'bg-light text-dark border'"
                    style="cursor:pointer" @click="destinoActivoId = d.id">
                    <span style="font-size:12px"><i class="fas fa-map-marker-alt me-1"></i>{{ d.destino_texto }}</span>
                    <template v-if="destinoActivoId === d.id">
                        <i class="fas fa-pen destino-chip-action" title="Editar este destino" @click.stop="abrirFormEditarDestinoActivo"></i>
                        <i class="fas fa-trash destino-chip-action" title="Eliminar este destino" @click.stop="eliminarDestinoActivo"></i>
                    </template>
                </span>
            </template>
            <span class="badge rounded-pill px-2 py-1 bg-light text-dark border" style="cursor:pointer;border-style:dashed" @click="abrirFormDestino">
                <i class="fas fa-plus me-1"></i>Agregar destino
            </span>
        </div>

        <div v-if="mostrarFormDestino" class="card border-0 shadow-sm mb-2">
            <div class="card-body py-2">
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-md-4">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Destino</label>
                        <DestinoTreeSelect v-model="nuevoDestinoAtractivoId" @update:label="nuevoDestinoTexto = $event" />
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Fecha desde</label>
                        <CampoFecha v-model="nuevoDestinoFechaInicio" />
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Fecha hasta</label>
                        <CampoFecha v-model="nuevoDestinoFechaFin" />
                    </div>
                    <div class="col-12 col-md-2 d-flex gap-2">
                        <button class="btn btn-primary btn-sm w-100" @click="guardarNuevoDestino" :disabled="guardandoDestino || !nuevoDestinoTexto.trim()">
                            <span v-if="guardandoDestino" class="spinner-border spinner-border-sm me-1"></span>{{ destinoEnEdicionId ? 'Guardar' : 'Agregar' }}
                        </button>
                        <button class="btn btn-outline-secondary btn-sm" @click="mostrarFormDestino = false"><i class="fas fa-times"></i></button>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3" v-if="alternativaActiva">
            <!-- ═══ LIENZO ═══ -->
            <div class="col-12 col-lg-8">
                <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                    <button v-if="!alternativaSoloLectura" class="btn btn-primary btn-sm" @click="abrirDrawerBiblioteca">
                        <i class="fas fa-plus me-1"></i>Agregar servicio
                    </button>
                    <button v-if="!mostrarTabsDia && !alternativaSoloLectura" class="btn btn-link btn-sm text-decoration-none" @click="agregarDia">
                        <i class="fas fa-calendar-plus me-1"></i>Agregar otro día
                    </button>
                    <button v-if="!alternativaSoloLectura" class="btn btn-outline-secondary btn-sm ms-auto" @click="toggleFormPasajeAereo">
                        <i class="fas fa-plane me-1"></i>{{ mostrarFormPasajeAereo ? 'Cerrar' : 'Pasaje aéreo suelto' }}
                    </button>
                </div>

                <div v-if="alternativaSoloLectura && alternativaActiva" class="alert alert-info py-2 small mb-2">
                    <i class="fas fa-lock me-1"></i>Alternativa reservada: para cambiarla usa Modificar reserva
                    <template v-if="reservaDeAlternativa(alternativaActiva)">
                        — <router-link :to="`/agencia-viajes/reservas/${reservaDeAlternativa(alternativaActiva)!.id}`">{{ reservaDeAlternativa(alternativaActiva)!.codigo ?? `reserva #${reservaDeAlternativa(alternativaActiva)!.id}` }}</router-link>
                    </template>.
                </div>

                <div v-if="mostrarFormPasajeAereo" class="border rounded p-2 mb-2">
                    <div v-if="itemPasajeAereoEnEdicion && formPasajeSoloLectura" class="d-flex justify-content-end mb-1">
                        <button type="button" class="btn btn-link btn-sm p-0" @click="toggleFormPasajeAereo"><i class="fas fa-times me-1"></i>Cerrar</button>
                    </div>
                    <div v-if="!alternativaSoloLectura && avisoItemReservado(itemPasajeAereoEnEdicion)" class="alert alert-info py-2 small mb-2">
                        <i class="fas fa-lock me-1"></i>{{ avisoItemReservado(itemPasajeAereoEnEdicion) }}
                    </div>
                    <PasajeAereoForm :alternativa-id="alternativaActiva.id" :dia-activo="diaActivoParaAgregar"
                        :alternativa-destino-id="destinoActivoId"
                        :pasajeros="cotizacion?.pasajeros ?? []" :item-existente="itemPasajeAereoEnEdicion" :solo-lectura="formPasajeSoloLectura"
                        :items-alternativa="alternativaActiva.items ?? []"
                        :tip-afe-igv-default="configAgencia?.tip_afe_igv_default"
                        :destino-tributario-default="configAgencia?.destino_tributario_default"
                        @agregado="onPasajeAereoAgregado" @actualizado="onPasajeAereoActualizado" />
                </div>

                <!-- Tabs de día — condicionales (Punto B): solo aparecen cuando ya
                     existe un segundo día real, o hay ítems legado "Sin día". Con un
                     solo día, el lienzo es una lista simple sin navegación de más. -->
                <ul v-if="mostrarTabsDia" class="nav nav-tabs mb-2">
                    <li class="nav-item" v-for="d in diasCreados" :key="d">
                        <a class="nav-link" href="#" :class="{ active: diaActivo === d }" @click.prevent="diaActivo = d">Día {{ d }}</a>
                    </li>
                    <li class="nav-item" v-if="itemsSinDia.length">
                        <a class="nav-link" href="#" :class="{ active: diaActivo === 0 }" @click.prevent="diaActivo = 0">Sin día ({{ itemsSinDia.length }})</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link text-muted" href="#" @click.prevent="agregarDia"><i class="fas fa-plus me-1"></i>Día</a>
                    </li>
                </ul>

                <!-- Vista previa al cargar un tour/paquete multi-día (Punto C) -->
                <div v-if="vistaPreviaCombo" class="card border-0 shadow-sm mb-2">
                    <div class="card-body py-2 px-3 small bg-info-subtle rounded">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <span class="fw-semibold"><i class="fas fa-info-circle me-1 text-info"></i>Tour de varios días cargado:</span>
                                <span v-for="(paso, idx) in vistaPreviaCombo" :key="paso.tourOrigenId">
                                    {{ idx > 0 ? ' / ' : ' ' }}Día {{ paso.dia }}: {{ paso.nombre }} · {{ alternativaActiva.moneda_cotizacion }} {{ paso.total.toFixed(2) }}
                                </span>
                            </div>
                            <i class="fas fa-times text-muted flex-shrink-0" style="cursor:pointer" @click="vistaPreviaCombo = null"></i>
                        </div>
                    </div>
                </div>

                <!-- Fila 13, fase 1b (§3.12) — la fecha cambió y algún tour quedó en
                     otra temporada: solo aviso, recalcular es a pedido del vendedor. -->
                <div v-if="temporadasDesactualizadas.length" class="alert alert-warning py-2 px-3 small mb-2 d-flex justify-content-between align-items-center gap-2 flex-wrap">
                    <span>
                        <i class="fas fa-calendar-alt me-1"></i><strong>Las fechas cambiaron de temporada:</strong>
                        <span v-for="(t, idx) in temporadasDesactualizadas" :key="t.carga_tour">
                            {{ idx > 0 ? '; ' : ' ' }}{{ t.tour_nombre }} ({{ t.temporada_actual ?? 'precio base' }} → {{ t.temporada_nueva ?? 'precio base' }}){{ t.precio_manual ? ' — precio a mano, no se toca' : '' }}
                        </span>
                    </span>
                    <button class="btn btn-sm btn-warning" @click="recalcularTemporadas" :disabled="recalculandoTemporadas">
                        <span v-if="recalculandoTemporadas" class="spinner-border spinner-border-sm me-1"></span>Recalcular precios por temporada
                    </button>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div v-if="bloquesLienzo.length === 0 && mostrarTabsDia && diasDesdeCombo.has(diaActivo)" class="drop-hint text-center text-muted py-4 border rounded" style="border-style:dashed">
                            <i class="fas fa-triangle-exclamation text-warning me-1"></i>
                            Este día no tiene ítems porque el tour de origen no tiene Incluye cargado — revisa el catálogo.
                        </div>
                        <div v-else-if="bloquesLienzo.length === 0" class="drop-hint text-center text-muted py-4 border rounded" style="border-style:dashed">
                            Agregá un servicio con el botón de arriba
                        </div>

                        <div v-for="bloque in bloquesLienzo" :key="bloque.clave" class="mb-3" :data-item-id="bloque.carga ? bloque.items.map((i) => i.id).join(' ') : null"
                            :class="[{ 'border-start border-3 border-primary bg-light-subtle rounded-2 p-2': bloque.tourOrigenId }, bloque.carga ? claseResaltado(bloque.items.map((i) => i.id)) : null]">
                            <!-- Fila 13, fase 1b — línea de tour (modo nuevo): una carga =
                                 un tour, con una línea por grupo de IGV. Precio del tour
                                 completo editable; se mueve y se quita entera. -->
                            <template v-if="bloque.carga">
                                <div class="d-flex justify-content-between align-items-center mb-1 flex-wrap gap-1">
                                    <span class="small fw-semibold text-dark">
                                        <i class="fas fa-route me-1 text-primary"></i>{{ bloque.tourNombre ?? 'Tour' }}
                                        <span v-if="bloque.comboNombre" class="badge bg-primary-subtle text-primary ms-1" style="font-size:10px"><i class="fas fa-layer-group me-1"></i>Combo {{ bloque.comboNombre }}</span>
                                        <span class="badge ms-1" :class="temporadaDeCarga(bloque) ? 'bg-warning-subtle text-warning-emphasis' : 'bg-light text-dark border'" style="font-size:10px"
                                            :title="bloque.items[0]?.tour_linea?.fecha_tour ? `Día del tour: ${formatFecha(bloque.items[0].tour_linea.fecha_tour)}` : 'La cotización no tiene fecha de viaje'">
                                            <i class="fas fa-calendar-alt me-1"></i>{{ temporadaDeCarga(bloque) ?? 'Precio base' }}
                                        </span>
                                        <span v-if="bloque.items.some((i) => i.precio_manual)" class="badge bg-secondary-subtle text-secondary ms-1" style="font-size:10px"><i class="fas fa-pen me-1"></i>Precio a mano</span>
                                    </span>
                                    <span class="d-flex align-items-center gap-2">
                                        <select v-if="mostrarTabsDia && !lineaTourBloqueada(bloque)" class="form-select form-select-sm" style="width:auto;font-size:11px" :value="diaActivo" @change="onMoverCarga(bloque, $event)">
                                            <option v-for="d in diasCreados" :key="d" :value="d">{{ bloque.cargaCombo ? 'Mover combo a' : 'Mover a' }} Día {{ d }}</option>
                                            <option :value="diaSiguiente">Mover a Día {{ diaSiguiente }} (nuevo)</option>
                                        </select>
                                        <span v-if="eliminandoCarga === bloque.carga" class="spinner-border spinner-border-sm text-danger"></span>
                                        <i v-else-if="!lineaTourBloqueada(bloque)" class="fas fa-trash text-danger" style="cursor:pointer" title="Quitar el tour" @click="eliminarCargaTour(bloque)"></i>
                                    </span>
                                </div>
                                <div class="canvas-item border rounded p-2 small bg-white">
                                    <div v-if="desglosePrecioTour(bloque)" class="text-muted" style="font-size:11px">
                                        <i class="fas fa-users me-1"></i>{{ desglosePrecioTour(bloque) }}
                                    </div>
                                    <div class="d-flex align-items-end flex-wrap gap-2 mt-1">
                                        <div class="d-flex flex-column">
                                            <label class="form-label mb-0 text-muted" style="font-size:10px">Precio del tour</label>
                                            <div class="input-group input-group-sm" style="width:auto">
                                                <span class="input-group-text">{{ alternativaActiva.moneda_cotizacion }}</span>
                                                <input type="number" step="0.01" min="0" class="form-control" style="max-width:120px"
                                                    :value="borradorPrecioCarga[bloque.carga ?? ''] ?? subtotalGrupo(bloque.items).toFixed(2)" :disabled="lineaTourBloqueada(bloque) || guardandoPrecioCarga === bloque.carga"
                                                    @input="borradorPrecioCarga[bloque.carga ?? ''] = ($event.target as HTMLInputElement).value"
                                                    @change="onPrecioCarga(bloque, $event)">
                                                <span v-if="guardandoPrecioCarga === bloque.carga" class="input-group-text"><span class="spinner-border spinner-border-sm"></span></span>
                                            </div>
                                        </div>
                                        <div class="d-flex flex-column gap-1">
                                            <span v-for="linea in bloque.items" :key="linea.id" class="d-flex align-items-center gap-1" style="font-size:11px">
                                                <span v-if="badgeTratamientoTributario(linea)" class="badge" :class="badgeTratamientoTributario(linea)!.clase" style="font-size:10px">{{ badgeTratamientoTributario(linea)!.texto }}</span>
                                                <span v-if="bloque.items.length > 1" class="text-muted">{{ alternativaActiva.moneda_cotizacion }} {{ totalConvertidoLocal(linea).toFixed(2) }}</span>
                                            </span>
                                        </div>
                                    </div>
                                    <a v-if="incluyeDeCarga(bloque).length" href="#" class="d-inline-block mt-1" style="font-size:11px" @click.prevent="toggleIncluye(bloque.carga)">
                                        <i class="fas me-1" :class="incluyeAbierto.has(bloque.carga) ? 'fa-chevron-up' : 'fa-chevron-down'"></i>Incluye ({{ incluyeDeCarga(bloque).length }})
                                    </a>
                                    <ul v-if="incluyeAbierto.has(bloque.carga)" class="list-unstyled mb-0 mt-1 text-muted" style="font-size:11px">
                                        <li v-for="servicio in incluyeDeCarga(bloque)" :key="servicio.paquete_plantilla_item_id">
                                            <i class="fas fa-check me-1 text-success"></i>{{ servicio.servicio }}
                                            <span v-if="servicio.costo_persona != null"> · costo {{ bloque.items[0].moneda_costo }} {{ Number(servicio.costo_persona).toFixed(2) }}/pax</span>
                                        </li>
                                    </ul>
                                    <span v-if="lineaTourBloqueada(bloque)" class="d-block text-muted fst-italic mt-1" style="font-size:11px"><i class="fas fa-lock me-1"></i>Ya tiene una reserva generada</span>
                                </div>
                            </template>

                            <div v-if="bloque.tourOrigenId && !bloque.carga" class="d-flex justify-content-between align-items-center mb-1">
                                <span class="small fw-semibold text-dark"><i class="fas fa-route me-1 text-primary"></i>{{ bloque.tourNombre ?? 'Tour' }}</span>
                                <span class="d-flex align-items-center gap-2">
                                    <select v-if="mostrarTabsDia && !alternativaSoloLectura" class="form-select form-select-sm" style="width:auto;font-size:11px" :value="diaActivo" @change="onMoverBloque(bloque, $event)">
                                        <option v-for="d in diasCreados" :key="d" :value="d">Mover a Día {{ d }}</option>
                                        <option :value="diaSiguiente">Mover a Día {{ diaSiguiente }} (nuevo)</option>
                                    </select>
                                    <span v-if="eliminandoBloqueId === bloque.tourOrigenId" class="spinner-border spinner-border-sm text-danger"></span>
                                    <i v-else-if="!alternativaSoloLectura" class="fas fa-trash text-danger" style="cursor:pointer" title="Eliminar todo el tour" @click="eliminarBloque(bloque)"></i>
                                </span>
                            </div>

                            <template v-for="fila in (bloque.carga ? [] : filasDelBloque(bloque.items))" :key="fila.esGrupo ? fila.grupoId : fila.item.id">
                            <!-- Sesión M4 — grupo "comparar varias opciones": una tarjeta
                                 compacta con las N opciones y el botón de resolución, en
                                 vez de N tarjetas de ítem sueltas (ver filasDelBloque()). -->
                            <div v-if="fila.esGrupo" class="canvas-item border rounded p-2 mb-2 small" :class="{ 'border-warning': !fila.items.some((i) => i.opcion_elegida) }">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="fw-semibold">
                                        <i class="fas fa-layer-group me-2 text-primary"></i>{{ fila.items.length }} opciones de hotel
                                        <span v-if="nombreMayoristaDeGrupo(fila.items)" class="text-muted fw-normal"> — {{ nombreMayoristaDeGrupo(fila.items) }}</span>
                                    </span>
                                    <span class="d-flex align-items-center gap-2">
                                        <span v-if="!fila.items.some((i) => i.opcion_elegida)" class="badge bg-warning-subtle text-warning-emphasis" style="font-size:10px">
                                            <i class="fas fa-triangle-exclamation me-1"></i>Sin resolver
                                        </span>
                                        <span v-if="eliminandoGrupoId === fila.grupoId" class="spinner-border spinner-border-sm text-danger"></span>
                                        <i v-else-if="!alternativaSoloLectura" class="fas fa-trash text-danger" style="cursor:pointer;font-size:11px" title="Eliminar todo este bloque" @click="eliminarGrupoLienzo(fila)"></i>
                                    </span>
                                </div>
                                <div v-for="it in fila.items" :key="it.id" class="d-flex justify-content-between align-items-center py-1 border-top" :data-item-id="it.id" :class="[{ 'opacity-50': !it.opcion_elegida }, claseResaltado([it.id])]">
                                    <span>
                                        <i class="fas fa-bed me-1 text-primary"></i>{{ etiquetaItem(it) }}<span v-if="textoAdultosMayorista(it)" class="text-muted"> {{ textoAdultosMayorista(it) }}</span>
                                        <span class="text-muted"> — {{ alternativaActiva.moneda_cotizacion }} {{ Number(it.precio_convertido).toFixed(2) }}</span>
                                    </span>
                                    <span class="d-flex align-items-center gap-2">
                                        <span v-if="it.opcion_elegida" class="badge bg-success-subtle text-success" style="font-size:10px"><i class="fas fa-check me-1"></i>Elegida</span>
                                        <button v-else-if="!alternativaSoloLectura" class="btn btn-sm btn-outline-success py-0" style="font-size:11px" @click="elegirGrupoDesdeLienzo(it)" :disabled="eligiendoGrupoItemId === it.id">
                                            <span v-if="eligiendoGrupoItemId === it.id" class="spinner-border spinner-border-sm"></span><span v-else>Marcar como elegida</span>
                                        </button>
                                        <span v-if="eliminandoItemId === it.id" class="spinner-border spinner-border-sm text-danger"></span>
                                        <i v-else-if="!alternativaSoloLectura" class="fas fa-times text-danger" style="cursor:pointer" title="Eliminar" @click="eliminarItem(it)"></i>
                                    </span>
                                </div>
                                <!-- Pedido del usuario (05-sep-2026): el itinerario incluido
                                     (OpcionMayoristaTour, con su "Día") solo se veía si el
                                     vendedor entraba a la pestaña "Tours" del drawer — acá
                                     queda visible siempre, sin precio (ya está prorrateado en
                                     el paquete, no es un ítem propio). -->
                                <div v-if="toursDeGrupo(fila.items).length" class="border-top pt-1 mt-1">
                                    <div v-for="t in toursDeGrupo(fila.items)" :key="t.id" class="text-muted" style="font-size:11px">
                                        <i class="fas fa-route me-1 text-primary"></i>Día {{ t.orden }} · {{ t.paquete_plantilla?.nombre ?? t.nombre }}
                                    </div>
                                </div>
                            </div>

                            <div v-else class="canvas-item border rounded p-2 mb-2 small" :data-item-id="fila.item.id" :class="claseResaltado([fila.item.id])">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <span class="canvas-item-nombre">
                                        <i class="fas me-2 text-primary" :class="iconoItem(fila.item)"></i>
                                        {{ etiquetaItem(fila.item) }}
                                        <span v-if="textoAdultosMayorista(fila.item)" class="text-muted"> {{ textoAdultosMayorista(fila.item) }}</span>
                                        <span v-else-if="fila.item.cantidad > 1 && fila.item.modo_precio === 'tarifa_fija'" class="text-muted"> × {{ fila.item.cantidad }}</span>
                                        <span v-if="idsParaRevisar.has(fila.item.id)" class="badge bg-warning text-dark ms-2" style="font-size:10px">Para revisar</span>
                                        <button v-if="idsParaRevisarConfirmables.has(fila.item.id)" type="button" class="btn btn-primary btn-sm py-0 ms-1" style="font-size:11px"
                                            @click="confirmarRevisados([fila.item.id])">Revisado</button>
                                    </span>
                                    <span v-if="eliminandoItemId === fila.item.id" class="spinner-border spinner-border-sm text-danger flex-shrink-0"></span>
                                    <i v-else-if="!alternativaSoloLectura" class="fas fa-times text-danger flex-shrink-0" style="cursor:pointer" title="Eliminar" @click="eliminarItem(fila.item)"></i>
                                </div>

                                <!-- Análisis de impuestos (28-ago-2026) — badge de tratamiento
                                     tributario resuelto, visible antes de facturar (no solo al
                                     enterarse en el momento de facturar la reserva). -->
                                <span v-if="badgeTratamientoTributario(fila.item)" class="badge mt-1"
                                    :class="badgeTratamientoTributario(fila.item)!.clase" style="font-size:10px">
                                    {{ badgeTratamientoTributario(fila.item)!.texto }}
                                </span>

                                <!-- Mismo itinerario incluido que el bloque de grupo de arriba,
                                     para un ítem de mayorista SUELTO (sin comparador de hoteles). -->
                                <div v-if="fila.item.origen_tipo === 'mayorista' && toursDeGrupo([fila.item]).length" class="border-top pt-1 mt-1">
                                    <div v-for="t in toursDeGrupo([fila.item])" :key="t.id" class="text-muted" style="font-size:11px">
                                        <i class="fas fa-route me-1 text-primary"></i>Día {{ t.orden }} · {{ t.paquete_plantilla?.nombre ?? t.nombre }}
                                    </div>
                                </div>

                                <div v-if="fila.item.origen_tipo === 'manual'" class="mt-1 d-flex align-items-center gap-2 flex-wrap">
                                    <span v-if="fila.item.proveedor_promovido_id" class="badge bg-success-subtle text-success" style="font-size:10px">
                                        <i class="fas fa-check me-1"></i>Proveedor creado
                                    </span>
                                    <template v-else>
                                        <span class="badge" :class="fila.item.proveedor_sugerido_manual ? 'bg-warning-subtle text-warning-emphasis' : 'bg-danger-subtle text-danger'" style="font-size:10px">
                                            <i class="fas" :class="fila.item.proveedor_sugerido_manual ? 'fa-pen' : 'fa-triangle-exclamation'"></i>
                                            Manual · {{ fila.item.proveedor_sugerido_manual || 'sin proveedor asociado' }}
                                        </span>
                                        <a href="#" style="font-size:11px" @click.prevent="abrirPromoverProveedor(fila.item)">
                                            <i class="fas fa-arrow-up-right-from-square me-1"></i>Promover a proveedor
                                        </a>
                                    </template>
                                    <a v-if="!fila.item.reserva_item && !alternativaSoloLectura" href="#" style="font-size:11px" class="text-secondary" @click.prevent="abrirEdicionManual(fila.item)">
                                        <i class="fas fa-pencil me-1"></i>Editar
                                    </a>
                                    <a v-else href="#" style="font-size:11px" class="text-secondary" title="Ya tiene una reserva generada: solo se puede consultar" @click.prevent="abrirEdicionManual(fila.item)">
                                        <i class="fas fa-eye me-1"></i>Ver detalle
                                    </a>
                                </div>

                                <div v-if="desglosePasajerosTexto(fila.item)" class="text-muted mt-1" style="font-size:11px">
                                    <i class="fas fa-users me-1"></i>{{ desglosePasajerosTexto(fila.item) }}
                                </div>
                                <div class="text-muted mt-1 d-flex align-items-center gap-2" style="font-size:11px" v-if="fila.item.origen_tipo === 'pasaje_aereo' && fila.item.cotizacion_pasaje_aereo">
                                    <span>{{ fila.item.cotizacion_pasaje_aereo.aerolinea }}</span>
                                    <!-- Vigencia de la tarifa aérea (09-oct-2026): avisar antes de
                                         reenviar al cliente un precio que la aerolínea ya no sostiene. -->
                                    <template v-if="fila.item.cotizacion_pasaje_aereo.tarifa_valida_hasta && !fila.item.reserva_item">
                                        <span v-if="tarifaAereaVencida(fila.item.cotizacion_pasaje_aereo.tarifa_valida_hasta)" class="badge bg-danger-subtle text-danger">
                                            <i class="fas fa-exclamation-triangle me-1"></i>Tarifa vencida ({{ formatFechaHora(fila.item.cotizacion_pasaje_aereo.tarifa_valida_hasta) }})
                                        </span>
                                        <span v-else>
                                            <i class="far fa-clock me-1"></i>Tarifa válida hasta {{ formatFechaHora(fila.item.cotizacion_pasaje_aereo.tarifa_valida_hasta) }}
                                        </span>
                                    </template>
                                    <a v-if="!fila.item.reserva_item && !alternativaSoloLectura" href="#" class="text-secondary" @click.prevent="abrirEdicionPasajeAereo(fila.item)">
                                        <i class="fas fa-pencil me-1"></i>Editar
                                    </a>
                                    <a v-else href="#" class="text-secondary" title="Ya tiene una reserva generada: solo se puede consultar" @click.prevent="abrirEdicionPasajeAereo(fila.item)">
                                        <i class="fas fa-eye me-1"></i>Ver detalle
                                    </a>
                                </div>

                                <div class="d-flex align-items-center flex-wrap gap-2 mt-2">
                                    <!-- Punto B (Sesión 11i) — un único input, según configuracion_agencia. -->
                                    <div v-if="configAgencia && !configAgencia.permitir_descuento_item" class="d-flex flex-column">
                                        <label class="form-label mb-0 text-muted" style="font-size:10px">Precio de venta</label>
                                        <div class="input-group input-group-sm" style="width:auto">
                                            <span class="input-group-text">{{ alternativaActiva.moneda_cotizacion }}</span>
                                            <input type="number" step="0.01" class="form-control"
                                                style="max-width:110px"
                                                :class="{ 'border-danger text-danger': alertasPiso[fila.item.id] }"
                                                v-model.number="edicionItems[fila.item.id].precio_convertido" :disabled="alternativaSoloLectura" @input="onEditarPrecio(fila.item)">
                                            <span v-if="guardandoEdicion.includes(fila.item.id)" class="input-group-text"><span class="spinner-border spinner-border-sm"></span></span>
                                        </div>
                                    </div>
                                    <div v-else-if="configAgencia?.modo_descuento_item === 'monto'" class="d-flex flex-column">
                                        <label class="form-label mb-0 text-muted" style="font-size:10px">Descuento</label>
                                        <div class="input-group input-group-sm" style="width:auto">
                                            <span class="input-group-text">{{ alternativaActiva.moneda_cotizacion }}</span>
                                            <input type="number" step="0.01" min="0" class="form-control"
                                                style="max-width:110px"
                                                :class="{ 'border-danger text-danger': alertasPiso[fila.item.id] }"
                                                v-model.number="edicionItems[fila.item.id].monto_descuento" :disabled="alternativaSoloLectura" @input="onEditarMontoDescuentoItem(fila.item)">
                                            <span v-if="guardandoEdicion.includes(fila.item.id)" class="input-group-text"><span class="spinner-border spinner-border-sm"></span></span>
                                        </div>
                                    </div>
                                    <div v-else class="d-flex flex-column">
                                        <label class="form-label mb-0 text-muted" style="font-size:10px">Descuento</label>
                                        <div class="input-group input-group-sm" style="width:auto">
                                            <input type="number" step="0.01" min="0" max="100" class="form-control"
                                                style="max-width:80px"
                                                :class="{ 'border-danger text-danger': alertasPiso[fila.item.id] }"
                                                v-model.number="edicionItems[fila.item.id].descuento_pct" :disabled="alternativaSoloLectura" @input="onEditarDescuentoPct(fila.item)">
                                            <span v-if="guardandoEdicion.includes(fila.item.id)" class="input-group-text"><span class="spinner-border spinner-border-sm"></span></span>
                                            <span class="input-group-text">%</span>
                                        </div>
                                    </div>
                                    <!-- Badge de % efectivo — solo tiene sentido cuando el input primario NO
                                         es el % mismo (sería redundante), y solo si permitir_descuento_item
                                         (en modo "Precio de venta" el punto B pide explícitamente que no se
                                         muestre lenguaje de descuento). -->
                                    <span v-if="configAgencia?.permitir_descuento_item && configAgencia?.modo_descuento_item === 'monto' && Number(edicionItems[fila.item.id]?.descuento_pct) > 0"
                                        class="badge bg-light text-dark border" style="font-size:10px">
                                        -{{ Number(edicionItems[fila.item.id].descuento_pct).toFixed(0) }}%
                                    </span>
                                    <select v-if="!bloque.tourOrigenId && mostrarTabsDia" :disabled="alternativaSoloLectura" class="form-select form-select-sm ms-auto" style="width:auto;font-size:11px" :value="fila.item.dia_referencial ?? ''" @change="onReasignarDiaItem(fila.item, $event)">
                                        <option value="" disabled>Sin día</option>
                                        <option v-for="d in diasCreados" :key="d" :value="d">Día {{ d }}</option>
                                    </select>
                                </div>
                                <small v-if="alertasPiso[fila.item.id]" class="text-danger d-block mt-1"><i class="fas fa-exclamation-triangle me-1"></i>Por debajo del piso permitido</small>
                            </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══ PANEL DE PRECIO (resumen, jerárquico, sin edición) ═══ -->
            <div class="col-12 col-lg-4">
                <div class="card border-0 shadow-sm price-panel">
                    <div class="card-body">
                        <p class="small fw-semibold text-secondary mb-2">Alternativa {{ alternativaActiva.nombre }}</p>
                        <div class="small mb-2" style="max-height:380px;overflow-y:auto;">
                            <!-- Sesión 12f-2, §7.1 punto 4 — con 2+ destinos, un nivel extra
                                 colapsable por destino ENVUELVE el mismo desglose por tour de
                                 siempre (mismo markup, ahora reusado con :key de destino). Con
                                 1 solo destino, sigue el flujo de siempre sin este nivel. -->
                            <template v-if="mostrarChipsDestino">
                                <div v-for="bloqueDestino in gruposPrecioPorDestino" :key="bloqueDestino.destinoId ?? 'sin-destino'" class="mb-2 pb-2 border-bottom">
                                    <div class="d-flex justify-content-between align-items-center" style="cursor:pointer" @click="toggleDestinoExpandido(bloqueDestino.destinoId)">
                                        <span class="fw-semibold"><i class="fas fa-map-marker-alt me-1 text-primary"></i>{{ bloqueDestino.destinoTexto }}</span>
                                        <span class="d-flex align-items-center gap-1">
                                            <span class="text-nowrap">{{ bloqueDestino.subtotal.toFixed(2) }}</span>
                                            <i class="fas" :class="destinosExpandido.has(bloqueDestino.destinoId) ? 'fa-chevron-up' : 'fa-chevron-down'" style="font-size:10px"></i>
                                        </span>
                                    </div>
                                    <div v-if="destinosExpandido.has(bloqueDestino.destinoId)" class="ms-2 mt-1">
                                        <div v-for="grupo in bloqueDestino.grupos" :key="grupo.clave" class="mb-2 pb-1">
                                            <template v-if="grupo.tourOrigenId">
                                                <div class="d-flex justify-content-between align-items-center" style="cursor:pointer" @click="toggleGrupoExpandido(grupo.tourOrigenId)">
                                                    <span class="fw-semibold"><i class="fas fa-route me-1 text-primary"></i>{{ grupo.tourNombre ?? 'Tour' }}</span>
                                                    <span class="d-flex align-items-center gap-1">
                                                        <span class="text-nowrap">{{ subtotalGrupo(grupo.items).toFixed(2) }}</span>
                                                        <i class="fas" :class="gruposExpandido.has(grupo.tourOrigenId) ? 'fa-chevron-up' : 'fa-chevron-down'" style="font-size:10px"></i>
                                                    </span>
                                                </div>
                                                <div v-if="gruposExpandido.has(grupo.tourOrigenId)" class="ms-3 mt-1">
                                                    <div v-for="item in grupo.items" :key="item.id" class="d-flex justify-content-between gap-2 text-muted" style="font-size:11px">
                                                        <span style="word-break:break-word">
                                                            {{ etiquetaItem(item) }}
                                                            <span v-if="badgeTratamientoTributario(item)" class="badge ms-1"
                                                                :class="badgeTratamientoTributario(item)!.clase" style="font-size:9px">{{ badgeTratamientoTributario(item)!.texto }}</span>
                                                        </span>
                                                        <span class="text-nowrap">{{ totalConvertidoLocal(item).toFixed(2) }}</span>
                                                    </div>
                                                </div>
                                            </template>
                                            <template v-else>
                                                <div v-for="item in filasResumen(grupo.items)" :key="item.id" class="d-flex justify-content-between gap-2 mb-1">
                                                    <span class="text-muted" style="word-break:break-word;font-size:11px">
                                                        {{ etiquetaItem(item) }}
                                                        <span v-if="item.grupo_opcion_id && !item.opcion_elegida" class="text-muted" style="font-size:10px">(desde, sin resolver)</span>
                                                        <span v-if="badgeTratamientoTributario(item)" class="badge ms-1"
                                                            :class="badgeTratamientoTributario(item)!.clase" style="font-size:9px">{{ badgeTratamientoTributario(item)!.texto }}</span>
                                                    </span>
                                                    <span class="text-nowrap">{{ totalConvertidoLocal(item).toFixed(2) }}</span>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </template>
                            <template v-else>
                                <div v-for="grupo in gruposPrecioPanel" :key="grupo.clave" class="mb-2 pb-2 border-bottom">
                                    <template v-if="grupo.tourOrigenId">
                                        <div class="d-flex justify-content-between align-items-center" style="cursor:pointer" @click="toggleGrupoExpandido(grupo.tourOrigenId)">
                                            <span class="fw-semibold"><i class="fas fa-route me-1 text-primary"></i>{{ grupo.tourNombre ?? 'Tour' }}</span>
                                            <span class="d-flex align-items-center gap-1">
                                                <span class="text-nowrap">{{ subtotalGrupo(grupo.items).toFixed(2) }}</span>
                                                <i class="fas" :class="gruposExpandido.has(grupo.tourOrigenId) ? 'fa-chevron-up' : 'fa-chevron-down'" style="font-size:10px"></i>
                                            </span>
                                        </div>
                                        <div v-if="gruposExpandido.has(grupo.tourOrigenId)" class="ms-3 mt-1">
                                            <div v-for="item in grupo.items" :key="item.id" class="d-flex justify-content-between gap-2 text-muted" style="font-size:11px">
                                                <span style="word-break:break-word">
                                                    {{ etiquetaItem(item) }}
                                                    <span v-if="badgeTratamientoTributario(item)" class="badge ms-1"
                                                        :class="badgeTratamientoTributario(item)!.clase" style="font-size:9px">{{ badgeTratamientoTributario(item)!.texto }}</span>
                                                </span>
                                                <span class="text-nowrap">{{ totalConvertidoLocal(item).toFixed(2) }}</span>
                                            </div>
                                        </div>
                                    </template>
                                    <template v-else>
                                        <div v-for="item in filasResumen(grupo.items)" :key="item.id" class="d-flex justify-content-between gap-2 mb-1">
                                            <span class="text-muted" style="word-break:break-word;">
                                                {{ etiquetaItem(item) }}<span v-if="textoAdultosMayorista(item)"> {{ textoAdultosMayorista(item) }}</span>
                                                <span v-if="item.grupo_opcion_id && !item.opcion_elegida" class="text-muted" style="font-size:10px">(desde, sin resolver)</span>
                                                <span v-if="badgeTratamientoTributario(item)" class="badge ms-1"
                                                    :class="badgeTratamientoTributario(item)!.clase" style="font-size:9px">{{ badgeTratamientoTributario(item)!.texto }}</span>
                                            </span>
                                            <span class="text-nowrap">{{ totalConvertidoLocal(item).toFixed(2) }}</span>
                                        </div>
                                    </template>
                                </div>
                            </template>
                            <div v-if="(alternativaActiva.items?.length ?? 0) === 0" class="text-muted">Sin ítems todavía</div>
                        </div>

                        <div class="border-top pt-2 mb-2" v-if="(alternativaActiva.items?.length ?? 0) > 0">
                            <!-- Punto C (Sesión 11i) — un único input, según configuracion_agencia.
                                 Guardrail (19-sep-2026, hallazgo del usuario) — antes el símbolo
                                 (USD/%) era un <span> suelto al lado del input, no "pegado" como
                                 un input-group real (mismo patrón que ya usa el descuento POR
                                 ÍTEM, línea ~444 más abajo) — menos claro a simple vista de qué
                                 modo está activo. El label ya no repite el modo en texto (antes
                                 "Descuento global %" vs "Descuento global") — una sola señal (el
                                 símbolo pegado al input, que siempre refleja la configuración real
                                 gracias a la invalidación de caché de la sesión anterior) en vez
                                 de dos que podían leerse como contradictorias si una quedaba vieja. -->
                            <label class="form-label mb-1 small fw-semibold text-secondary">Descuento global</label>
                            <template v-if="configAgencia?.modo_descuento_global === 'monto'">
                                <div class="input-group input-group-sm" style="max-width:160px">
                                    <span class="input-group-text">{{ alternativaActiva.moneda_cotizacion }}</span>
                                    <input type="number" min="0" class="form-control"
                                        v-model.number="descuentoGlobalMontoLocal" :disabled="alternativaSoloLectura || aplicandoDescuentoGlobal" @change="onEditarDescuentoGlobalMonto">
                                    <span v-if="aplicandoDescuentoGlobal" class="input-group-text"><span class="spinner-border spinner-border-sm"></span></span>
                                </div>
                            </template>
                            <template v-else>
                                <div class="input-group input-group-sm" style="max-width:160px">
                                    <input type="number" min="0" max="100" class="form-control"
                                        v-model.number="descuentoGlobalLocal" :disabled="alternativaSoloLectura || aplicandoDescuentoGlobal" @change="onEditarDescuentoGlobal">
                                    <span v-if="aplicandoDescuentoGlobal" class="input-group-text"><span class="spinner-border spinner-border-sm"></span></span>
                                    <span class="input-group-text">%</span>
                                </div>
                            </template>
                            <small v-if="lineasFueraDePiso.length" class="text-danger d-block mt-1">
                                <i class="fas fa-exclamation-triangle me-1"></i>{{ lineasFueraDePiso.length }} línea(s) quedaron bajo el piso permitido — revisalas en el lienzo.
                            </small>
                        </div>

                        <hr>
                        <div class="d-flex justify-content-between align-items-baseline">
                            <span class="small text-secondary">Total <template v-if="notaMayoristaSinNinos">(el paquete de mayorista no incluye niños)</template><template v-else>{{ resumenPaxTotal }}</template></span>
                            <span class="fs-4 fw-semibold">{{ alternativaActiva.moneda_cotizacion }} {{ totalLocal.toFixed(2) }}</span>
                        </div>
                        <div v-if="itemsAjuste.length" class="small text-muted mt-2">
                            <div class="fw-semibold">Ajuste — no se suma: ya está en el precio del servicio</div>
                            <div v-for="a in itemsAjuste" :key="a.id" class="d-flex justify-content-between gap-2">
                                <span>{{ etiquetaItem(a) }}</span>
                                <span class="text-nowrap">{{ alternativaActiva.moneda_cotizacion }} {{ totalConvertidoLocal(a).toFixed(2) }}</span>
                            </div>
                        </div>
                        <div class="text-end">
                            <a href="#" class="small" @click.prevent="mostrarCalculadoraTipoCambio = true">
                                Convertir a {{ alternativaActiva.moneda_cotizacion === 'USD' ? 'soles' : 'dólares' }}
                            </a>
                        </div>

                        <div class="mt-3 d-flex flex-column gap-2">
                            <!-- Solo en borrador o enviada, la misma regla que ReservaController::alternativaAceptable():
                                 una descartada o reemplazada respondía 422. -->
                            <template v-if="['borrador', 'enviada'].includes(alternativaActiva.estado) && !modificacionInfo && !cotizacionPerdida">
                                <button class="btn btn-success btn-sm w-100" @click="marcarAceptada" :disabled="(alternativaActiva.items?.length ?? 0) === 0 || marcandoAceptada">
                                    <span v-if="marcandoAceptada" class="spinner-border spinner-border-sm me-1"></span>
                                    <i v-else class="fas fa-check me-1"></i>Aceptado por cliente
                                </button>
                                <small v-if="(alternativaActiva.items?.length ?? 0) === 0" class="text-muted d-block text-center">
                                    Agregá al menos un ítem para poder aceptar
                                </small>
                            </template>

                            <button v-if="!modificacionInfo && !cotizacionPerdida" class="btn btn-outline-primary btn-sm w-100" @click="duplicarAlternativa"
                                :disabled="duplicando || alcanzoLimiteAlternativas" :title="alcanzoLimiteAlternativas ? 'Máximo 5 alternativas por cotización' : ''">
                                <span v-if="duplicando" class="spinner-border spinner-border-sm me-1"></span>
                                <i v-else class="fas fa-copy me-1"></i>Duplicar alternativa
                            </button>

                            <button class="btn btn-outline-secondary btn-sm w-100" :disabled="descargandoPdf" @click="descargarPdfAlternativa">
                                <span v-if="descargandoPdf" class="spinner-border spinner-border-sm me-1"></span>
                                <i v-else class="fas fa-file-pdf me-1"></i>Ver PDF
                            </button>
                            <button class="btn btn-outline-secondary btn-sm w-100" :disabled="descargandoCondiciones" @click="descargarCondicionesGenerales">
                                <span v-if="descargandoCondiciones" class="spinner-border spinner-border-sm me-1"></span>
                                <i v-else class="fas fa-file-contract me-1"></i>Descargar condiciones generales
                            </button>
                            <!-- T-053: vigencia de la enviada y «Extender vigencia» (vigente o vencida). -->
                            <div v-if="alternativaActiva.estado === 'enviada' && !modificacionInfo && !cotizacionPerdida"
                                class="d-flex align-items-center justify-content-between gap-2 small">
                                <span v-if="vigenciaActiva" :class="vigenciaActiva.vencida ? 'text-danger' : 'text-muted'">
                                    <i class="fas fa-hourglass-half me-1"></i>{{ vigenciaActiva.vencida ? 'Vencida el' : 'Vigente hasta el' }} {{ vigenciaActiva.dia }}
                                </span>
                                <span v-else class="text-muted"><i class="fas fa-hourglass-half me-1"></i>Sin fecha de vencimiento</span>
                                <button type="button" class="btn btn-outline-primary btn-sm text-nowrap" @click="extendiendoVigencia = true">
                                    <i class="fas fa-calendar-plus me-1"></i>Extender vigencia
                                </button>
                            </div>
                            <ExtenderVigenciaModal v-if="extendiendoVigencia" :alternativa-id="alternativaActiva.id" :nombre="alternativaActiva.nombre"
                                :dias-vigencia="configAgencia?.dias_vigencia_cotizacion" @cerrar="extendiendoVigencia = false" @extendida="onVigenciaExtendida" />
                            <!-- T-022: "¿lista para el cliente?" junto a los botones de envío. -->
                            <RevisionAlternativa v-if="!modificacionInfo" :alternativa-id="alternativaActiva.id" :moneda="alternativaActiva.moneda_cotizacion"
                                :refrescar="claveRevision" :nombre-de-item="nombreItemRevision"
                                @cambio="revisionActual = $event" @ir="irARevision" @actualizada="cargarCotizacion" />
                            <EnviarAlternativa v-if="!modificacionInfo" :alternativa="alternativaActiva" :cliente="cotizacion?.cliente ?? null"
                                :destino="cotizacion?.destino ?? ''" :total="totalLocal" :descargar-pdf="descargarPdfAlternativa"
                                :hay-acciones-pendientes="(accionesRef?.cantidad ?? 0) > 0" :revision="revisionActual"
                                @enviada="onAlternativaEnviada" @registrada="historialRef?.recargar(); accionesRef?.recargar()" />
                            <AccionesPendientes v-if="cotizacion?.cliente_id" ref="accionesRef" :cliente-id="cotizacion.cliente_id"
                                referencia-tipo="cotizacion" :referencia-id="cotizacion.id" @cambio="historialRef?.recargar()" />
                            <HistorialActividadesCotizacion v-if="cotizacion" ref="historialRef" :cotizacion-id="cotizacion.id"
                                :cliente-id="cotizacion.cliente_id ?? null" />
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-else-if="cotizacion" class="text-center text-muted py-5">
            <!-- T-052: perdida, "+ Nueva" no se muestra (el backend responde 422). -->
            <template v-if="cotizacionPerdida">Esta cotización está cerrada como perdida y no tiene alternativas — reábrela para crear la primera.</template>
            <template v-else>Esta cotización todavía no tiene alternativas — creá la primera con el botón "+ Nueva" de arriba.</template>
        </div>

        <!-- ═══ DRAWER — biblioteca / comparador de mayoristas (Punto A) ═══ -->
        <Teleport to="body">
            <div v-if="drawerBibliotecaAbierto" class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)" @click.self="cerrarDrawerBiblioteca">
                <div class="modal-dialog modal-dialog-centered modal-xl">
                    <div class="modal-content">
                        <div class="modal-header">
                            <div class="d-flex align-items-center gap-2">
                                <i v-if="pasoDrawer !== 'grid'" class="fas fa-arrow-left text-white" style="cursor:pointer" title="Volver" @click="volverAGridDrawer"></i>
                                <strong class="modal-title">{{ tituloDrawer }}</strong>
                            </div>
                            <button type="button" class="btn-close" @click="cerrarDrawerBiblioteca" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body" style="max-height:80vh; overflow-y:auto">
                        <template v-if="pasoDrawer === 'grid'">
                            <div class="btn-group w-100 mb-2" role="group" aria-label="Tipo de biblioteca">
                                <input type="radio" class="btn-check" name="modoBiblioteca" id="modoLocal" autocomplete="off" :checked="modo === 'local'" @change="modo = 'local'">
                                <label class="btn btn-outline-primary btn-sm" for="modoLocal">Servicios de la agencia</label>
                                <input type="radio" class="btn-check" name="modoBiblioteca" id="modoIntl" autocomplete="off" :checked="modo === 'intl'" @change="modo = 'intl'">
                                <label class="btn btn-outline-primary btn-sm" for="modoIntl">Paquete de mayorista</label>
                                <input type="radio" class="btn-check" name="modoBiblioteca" id="modoGuia" autocomplete="off" :checked="modo === 'guia'" @change="modo = 'guia'">
                                <label class="btn btn-outline-primary btn-sm" for="modoGuia"><i class="fas fa-user-tie me-1"></i>Guías</label>
                            </div>

                            <!-- Biblioteca local -->
                            <template v-if="modo === 'local'">
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

                            <!-- Buscador de guías (Fix guia-como-item-real) — mismo patrón visual
                                 que ya usa paquetes/detalle.vue: elegir guía, después su lista de
                                 tarifas (cada una ya trae su destino/modalidad/costo_diario). -->
                            <template v-else-if="modo === 'guia'">
                                <GuiaBuscador v-model="guiaSeleccionadaIdCotizador" class="mb-2" placeholder="Buscar guía…"
                                    @elegido="cargarTarifasGuiaCotizador" />
                                <div v-if="guiaSeleccionadaIdCotizador" class="d-flex flex-column gap-1 mb-2">
                                    <div v-for="t in tarifasGuiaCotizador" :key="t.id" class="border rounded p-2 small lib-item"
                                        :class="{ 'border-primary bg-light': guiaTarifaSeleccionadaCotizador?.id === t.id }"
                                        style="cursor:pointer"
                                        @click="guiaTarifaSeleccionadaCotizador = t">
                                        <div class="d-flex justify-content-between">
                                            <span><i class="fas fa-user-tie me-1 text-primary"></i>{{ t.destino?.nombre }}</span>
                                            <span class="text-muted">{{ t.moneda }} {{ Number(t.costo_diario).toFixed(0) }}/día</span>
                                        </div>
                                        <div class="text-muted" style="font-size:11px">{{ t.modalidad === 'dia_local' ? 'Día local' : 'Grupo multidía' }}</div>
                                    </div>
                                    <div v-if="tarifasGuiaCotizador.length === 0" class="text-muted small text-center py-2">Este guía no tiene tarifas cargadas.</div>
                                </div>
                                <div v-if="guiaTarifaSeleccionadaCotizador" class="mb-2">
                                    <label class="form-label mb-1 small text-secondary">Cantidad de días</label>
                                    <input type="number" min="1" class="form-control form-control-sm" v-model.number="cantidadDiasGuia">
                                </div>
                                <div v-if="guiaTarifaSeleccionadaCotizador" class="row g-2 mb-2">
                                    <div class="col-6">
                                        <label class="form-label mb-1 small text-secondary">Tratamiento tributario</label>
                                        <select class="form-select form-select-sm" v-model="tipAfeIgvGuia">
                                            <option value="10">Gravado</option>
                                            <option value="20">Exonerado</option>
                                            <option value="30">Inafecto</option>
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label mb-1 small text-secondary">Destino</label>
                                        <select class="form-select form-select-sm" v-model="destinoTributarioGuia">
                                            <option value="amazonia">Amazonía</option>
                                            <option value="nacional">Nacional</option>
                                            <option value="extranjero">Extranjero</option>
                                        </select>
                                    </div>
                                </div>
                                <button class="btn btn-primary btn-sm w-100" @click="agregarItemGuiaCotizador" :disabled="!guiaTarifaSeleccionadaCotizador || agregandoGuia">
                                    <span v-if="agregandoGuia" class="spinner-border spinner-border-sm me-1"></span>Agregar guía
                                </button>
                            </template>

                            <!-- Comparador de mayoristas -->
                            <div v-else class="d-flex flex-column gap-2">
                                <div v-for="op in opcionesMayorista" :key="op.id" class="card border p-2 small mayorista-card"
                                    :class="{ 'border-success border-2': op.estado === 'elegida', 'opacity-50': op.estado === 'descartada' }">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <strong>{{ op.proveedor?.nombre_comercial ?? op.proveedor?.razon_social }}</strong>
                                        <div v-if="!alternativaSoloLectura" class="d-flex gap-2">
                                            <i class="fas fa-pen text-muted" style="cursor:pointer;font-size:11px" title="Editar esta opción" @click="abrirFormEditarOpcionMayorista(op)"></i>
                                            <i v-if="eliminandoOpcionMayoristaId !== op.id" class="fas fa-trash text-muted" style="cursor:pointer;font-size:11px" title="Eliminar esta opción (borra hoteles/opcionales/tours vinculados)" @click="eliminarOpcionMayorista(op)"></i>
                                            <span v-else class="spinner-border spinner-border-sm" style="width:11px;height:11px"></span>
                                        </div>
                                    </div>
                                    <div class="text-muted" v-if="op.vuelo_aerolinea"><i class="fas fa-plane me-1"></i>{{ op.vuelo_aerolinea }}</div>
                                    <div class="text-muted mb-1" v-if="op.incluye">{{ textoPlano(op.incluye) }}</div>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="badge" :class="{
                                            'bg-success-subtle text-success': op.estado === 'elegida',
                                            'bg-warning-subtle text-warning-emphasis': op.estado === 'candidata',
                                            'bg-light text-muted': op.estado === 'descartada',
                                        }">{{ op.estado === 'candidata' ? 'Pendiente' : op.estado === 'elegida' ? 'Elegida' : 'Descartada' }}</span>
                                        <div class="d-flex gap-1" v-if="op.estado !== 'descartada'">
                                            <button class="btn btn-sm btn-outline-primary" @click="verHoteles(op)">Hoteles</button>
                                            <button class="btn btn-sm btn-outline-secondary" @click="verOpcionales(op)">
                                                Opcionales<span v-if="op.opcionales?.length" class="badge bg-light text-dark border ms-1">{{ op.opcionales.length }}</span>
                                            </button>
                                            <button class="btn btn-sm btn-outline-secondary" @click="verTours(op)">
                                                Tours<span v-if="op.tours?.length" class="badge bg-light text-dark border ms-1">{{ op.tours.length }}</span>
                                            </button>
                                            <button v-if="op.estado !== 'elegida' && !alternativaSoloLectura" class="btn btn-sm btn-outline-success" @click="elegirOpcion(op)" :disabled="eligiendoOpcionId === op.id">
                                                <span v-if="eligiendoOpcionId === op.id" class="spinner-border spinner-border-sm"></span><span v-else>Elegir mayorista</span>
                                            </button>
                                            <button v-if="!alternativaSoloLectura" class="btn btn-sm btn-outline-secondary" @click="descartarOpcion(op)" :disabled="descartandoOpcionId === op.id" title="Descartar esta opción">
                                                <span v-if="descartandoOpcionId === op.id" class="spinner-border spinner-border-sm"></span><i v-else class="fas fa-ban"></i>
                                            </button>
                                        </div>
                                        <button v-else-if="!alternativaSoloLectura" class="btn btn-sm btn-outline-secondary" @click="reactivarOpcion(op)" :disabled="reactivandoOpcionId === op.id">
                                            <span v-if="reactivandoOpcionId === op.id" class="spinner-border spinner-border-sm"></span><span v-else>Reactivar</span>
                                        </button>
                                    </div>
                                    <OpcionMayoristaForm v-if="opcionMayoristaEnEdicionId === op.id"
                                        :alternativa-id="alternativaActiva!.id" :destino-activo-id="destinoActivoId"
                                        :opcion-existente="op" :tipo-mayorista-id="tipoMayoristaId"
                                        class="mt-2 border-top pt-2"
                                        @actualizado="onOpcionMayoristaGuardada" @cancelar="opcionMayoristaEnEdicionId = null" />
                                    <div v-if="opcionHotelesActivaId === op.id" class="mt-2 border-top pt-2">
                                        <div v-if="op.estado !== 'elegida' && !alternativaSoloLectura" class="text-muted small fst-italic mb-1">Marca esta opción como elegida para poder confirmar una habitación (puedes seguir cargando hoteles mientras tanto).</div>
                                        <!-- 04-sep-2026 — una sola vez arriba de todo, en vez de metida
                                             solo en el form de "crear hotel nuevo" (ahí el usuario la veía
                                             al crear, pero no al editar/agregar un tipo de habitación desde
                                             la tabla — el mismo error de duplicación/inconsistencia que ya
                                             se corrigió una vez con la lista de hoteles repetida). Cubre los
                                             3 lugares donde aparecen Costo/Venta acá abajo: ver precios,
                                             editar una tarifa, agregar una tarifa nueva a un hotel ya
                                             cargado, y el form de crear hotel. -->
                                        <p class="text-muted fst-italic mb-2" style="font-size:11px">El costo/venta de cada tipo de habitación es el total del paquete por persona (aéreo + tours + hotel según este mayorista), no solo el hospedaje. Si el hotel es nuevo, no hace falta registrarle una tarifa en Proveedores para reusarlo — elígelo del selector de abajo (o déjalo "manual/referencial") y carga este precio de paquete directo acá.</p>
                                        <!-- 04-sep-2026 — vista unificada (hallazgo del usuario: la tabla de
                                             "elegir" y la lista de gestión de abajo mostraban los mismos
                                             hoteles dos veces, en dos formatos). permitir-gestion-hoteles
                                             agrupa las filas por hotel con edición/borrado inline, en el
                                             mismo lugar donde ya se elige la habitación. -->
                                        <p class="small text-muted mb-1"><strong>Adultos:</strong> precio por adulto. Los niños no están incluidos: si el mayorista los cobra, agrégalos como servicio aparte.</p>
                                        <HabitacionMatrixPicker
                                            :tarifas="tarifasHotelPlanas(op)" :moneda="op.moneda"
                                            cantidad-label="Adultos" :cantidad-default="cantidadAdultosCotizacion"
                                            :deshabilitar-confirmar="op.estado !== 'elegida' || alternativaSoloLectura"
                                            :motivo-deshabilitado="alternativaSoloLectura ? 'Esta alternativa ya está reservada: los cambios se hacen con Modificar reserva' : 'Marca esta opción como elegida para poder confirmar una habitación'"
                                            :permitir-gestion-hoteles="!alternativaSoloLectura"
                                            @seleccionar="({ id, cantidad }) => agregarItemMayorista(op, id, cantidad)"
                                            @agregar-grupo="({ ids }) => agregarGrupoMayorista(op, ids)"
                                            @guardar-hotel="onGuardarHotelMatrix"
                                            @eliminar-hotel="onEliminarHotelMatrix"
                                            @agregar-fotos-hotel="onAgregarFotosHotelMatrix"
                                            @eliminar-foto-hotel="onEliminarFotoHotelMatrix"
                                            @promover-hotel="onPromoverHotelMatrix"
                                            @guardar-tarifa="onGuardarTarifaMatrix"
                                            @eliminar-tarifa="onEliminarTarifaMatrix"
                                            @agregar-tarifa="onAgregarTarifaMatrix" />

                                        <button v-if="!alternativaSoloLectura" class="btn btn-sm btn-outline-secondary w-100 mt-2" @click="mostrarFormHotel = mostrarFormHotel === op.id ? null : op.id">
                                            <i class="fas fa-plus me-1"></i>Agregar hotel a esta opción
                                        </button>
                                        <div v-if="mostrarFormHotel === op.id && !alternativaSoloLectura" class="border rounded p-2 mt-2">
                                            <label class="form-label mb-1 small text-secondary">Nombre del hotel</label>
                                            <input type="text" class="form-control form-control-sm mb-1" placeholder="Nombre del hotel" v-model="formHotel.nombre_hotel">
                                            <label class="form-label mb-1 small text-secondary">Proveedor</label>
                                            <ProveedorBuscador v-model="formHotel.proveedor_id" :tipo-id="tipoHotelId" placeholder="Hotel manual o buscar proveedor" @elegido="onCambiarProveedorHotel" />
                                            <!-- Nota de costo/venta ya no va acá — quedó una sola vez arriba
                                                 de toda la tabla (04-sep-2026), cubre también este form. -->
                                            <!-- Encabezados de columna — cumplen el rol de label para cada
                                                 fila de tarifas sin repetir el texto en cada una. -->
                                            <div class="row g-1 mb-1">
                                                <div class="col-3"><label class="form-label mb-0 small text-secondary">Tipo de habitación</label></div>
                                                <div class="col-3" v-if="formHotel.proveedor_id"><label class="form-label mb-0 small text-secondary">Tarifa registrada</label></div>
                                                <div :class="formHotel.proveedor_id ? 'col-3' : 'col-4'"><label class="form-label mb-0 small text-secondary">Costo total paquete</label></div>
                                                <div :class="formHotel.proveedor_id ? 'col-3' : 'col-4'"><label class="form-label mb-0 small text-secondary">Venta total paquete</label></div>
                                            </div>
                                            <div v-for="(tf, idx) in formHotel.tarifas" :key="idx" class="row g-1 mb-1">
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
                                                        <option v-for="t in tarifasHotelParaTipo(tf.tipo_habitacion)" :key="t.id" :value="t.id">Registrada ({{ t.precio_venta_adulto }})</option>
                                                    </select>
                                                </div>
                                                <div :class="formHotel.proveedor_id ? 'col-3' : 'col-4'">
                                                    <input type="number" class="form-control form-control-sm" placeholder="Costo" v-model.number="tf.precio_costo" :readonly="!!tf.proveedor_tarifa_id">
                                                </div>
                                                <div :class="formHotel.proveedor_id ? 'col-3' : 'col-4'">
                                                    <input type="number" class="form-control form-control-sm" placeholder="Venta" v-model.number="tf.precio_venta" :readonly="!!tf.proveedor_tarifa_id">
                                                </div>
                                            </div>
                                            <button class="btn btn-sm btn-outline-secondary mb-1" @click="formHotel.tarifas.push({ tipo_habitacion: 'doble', precio_costo: 0, precio_venta: 0, proveedor_tarifa_id: null })">+ tipo de habitación</button>

                                            <!-- Pedido del usuario (05-sep-2026): hasta 3 fotos por hotel
                                                 (1 fachada + 2 habitación desde la mejora del PDF de
                                                 cotización — el PDF necesita distinguirlas). -->
                                            <label class="form-label mb-1 small text-secondary d-block">Fotos (opcional, máx. 1 fachada + 2 habitación)</label>
                                            <div v-if="fotosHotelNuevo.length" class="d-flex flex-wrap gap-1 mb-1">
                                                <div v-for="(foto, idx) in fotosHotelNuevo" :key="idx" class="position-relative">
                                                    <img :src="foto.previewUrl" style="width:50px;height:50px;object-fit:cover;border:1px solid #ccc;border-radius:3px;">
                                                    <select class="form-select form-select-sm position-absolute" style="bottom:-4px;left:0;right:0;font-size:8px;padding:0;height:14px;" v-model="foto.tipoFoto">
                                                        <option value="fachada">Fachada</option>
                                                        <option value="habitacion">Hab.</option>
                                                    </select>
                                                    <i class="fas fa-times-circle text-danger position-absolute" style="top:-6px;right:-6px;cursor:pointer;background:#fff;border-radius:50%" title="Quitar" @click="quitarFotoHotelNuevo(idx)"></i>
                                                </div>
                                            </div>
                                            <input type="file" accept="image/*" multiple class="form-control form-control-sm mb-1" :disabled="fotosHotelNuevo.length >= 3" @change="onFotosHotelNuevoSeleccionadas">

                                            <button class="btn btn-sm btn-primary w-100" @click="guardarHotel(op)" :disabled="guardandoHotelMayorista">
                                                <span v-if="guardandoHotelMayorista" class="spinner-border spinner-border-sm me-1"></span>Guardar hotel
                                            </button>
                                        </div>
                                    </div>
                                    <div v-if="mostrarOpcionalesId === op.id" class="mt-2 border-top pt-2">
                                        <div v-if="!op.opcionales?.length" class="text-muted small fst-italic mb-2">Sin opcionales cargados todavía — son actividades que el cliente puede agregar aparte; "Agregar al lienzo" recién los suma al total.</div>
                                        <div v-for="opl in op.opcionales" :key="opl.id" class="border rounded p-2 mb-1">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <strong>{{ opl.nombre }}</strong>
                                                    <div v-if="opl.incluye" class="text-muted" style="font-size:11px">{{ textoPlano(opl.incluye) }}</div>
                                                </div>
                                                <div class="d-flex align-items-start gap-2 text-nowrap">
                                                    <span>{{ opl.moneda }} {{ Number(opl.precio_por_persona).toFixed(2) }} /pax</span>
                                                    <template v-if="!alternativaSoloLectura">
                                                        <i class="fas fa-pen text-muted" style="cursor:pointer;font-size:11px" title="Editar" @click="abrirEdicionOpcional(opl)"></i>
                                                        <i v-if="eliminandoOpcionalId !== opl.id" class="fas fa-trash text-muted" style="cursor:pointer;font-size:11px" title="Eliminar" @click="eliminarOpcionalCargado(opl)"></i>
                                                        <span v-else class="spinner-border spinner-border-sm" style="width:11px;height:11px"></span>
                                                    </template>
                                                </div>
                                            </div>
                                            <!-- Hueco real cerrado (07-sep-2026): hasta ahora un opcional
                                                 solo era info de referencia en el PDF, sin forma de que el
                                                 cliente lo elija de verdad y sume al total. Mismo criterio
                                                 "por persona" que ya usa el hotel de la matriz mayorista. -->
                                            <button v-if="!alternativaSoloLectura" class="btn btn-sm btn-outline-success w-100 mt-1"
                                                :disabled="agregandoOpcionalAlLienzoId === opl.id || op.estado !== 'elegida'"
                                                :title="op.estado !== 'elegida' ? 'Marca esta opción como elegida para poder agregar sus opcionales' : ''"
                                                @click="agregarOpcionalAlLienzo(op, opl)">
                                                <span v-if="agregandoOpcionalAlLienzoId === opl.id" class="spinner-border spinner-border-sm me-1"></span>
                                                <i v-else class="fas fa-plus me-1"></i>Agregar al lienzo
                                            </button>
                                            <div v-if="opcionalEnEdicionId === opl.id" class="mt-1 border-top pt-1">
                                                <input type="text" class="form-control form-control-sm mb-1" placeholder="Nombre" v-model="formEdicionOpcional.nombre">
                                                <div class="row g-1 mb-1">
                                                    <div class="col-8">
                                                        <input type="number" step="0.01" class="form-control form-control-sm" placeholder="Precio por persona" v-model.number="formEdicionOpcional.precio_por_persona">
                                                    </div>
                                                    <div class="col-4">
                                                        <select class="form-select form-select-sm" v-model="formEdicionOpcional.moneda">
                                                            <option value="USD">USD</option>
                                                            <option value="PEN">PEN</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="mb-1">
                                                    <RichTextEditor v-model="formEdicionOpcional.incluye" placeholder="Incluye..." />
                                                </div>
                                                <div class="mb-1">
                                                    <RichTextEditor v-model="formEdicionOpcional.no_incluye" placeholder="No incluye..." />
                                                </div>
                                                <button class="btn btn-sm btn-primary w-100" @click="guardarEdicionOpcional(opl)" :disabled="guardandoEdicionOpcional">
                                                    <span v-if="guardandoEdicionOpcional" class="spinner-border spinner-border-sm me-1"></span>Guardar
                                                </button>
                                            </div>
                                        </div>
                                        <button v-if="!alternativaSoloLectura" class="btn btn-sm btn-outline-secondary w-100 mt-1" @click="mostrarFormOpcional = mostrarFormOpcional === op.id ? null : op.id">
                                            <i class="fas fa-plus me-1"></i>Agregar opcional
                                        </button>
                                        <div v-if="mostrarFormOpcional === op.id && !alternativaSoloLectura" class="border rounded p-2 mt-2">
                                            <input type="text" class="form-control form-control-sm mb-1" placeholder="Nombre (ej. Isla de Taboga - Full Day)" v-model="formOpcional.nombre">
                                            <div class="row g-1 mb-1">
                                                <div class="col-8">
                                                    <input type="number" step="0.01" class="form-control form-control-sm" placeholder="Precio por persona" v-model.number="formOpcional.precio_por_persona">
                                                </div>
                                                <div class="col-4">
                                                    <select class="form-select form-select-sm" v-model="formOpcional.moneda">
                                                        <option value="USD">USD</option>
                                                        <option value="PEN">PEN</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="mb-1">
                                                <RichTextEditor v-model="formOpcional.incluye" placeholder="Incluye..." />
                                            </div>
                                            <div class="mb-1">
                                                <RichTextEditor v-model="formOpcional.no_incluye" placeholder="No incluye..." />
                                            </div>
                                            <button class="btn btn-sm btn-primary w-100" @click="guardarOpcional(op)" :disabled="guardandoOpcional">
                                                <span v-if="guardandoOpcional" class="spinner-border spinner-border-sm me-1"></span>Guardar
                                            </button>
                                        </div>
                                    </div>
                                    <!-- Simulación Panamá (04-sep-2026) — tours incluidos con itinerario
                                         real, distinto de "Incluye" (texto plano). Alimenta la sección
                                         "Itinerario" del PDF (AlternativaController::itinerarioAlternativa()). -->
                                    <div v-if="mostrarToursId === op.id" class="mt-2 border-top pt-2">
                                        <div v-if="!op.tours?.length" class="text-muted small fst-italic mb-2">Sin tours incluidos todavía — con itinerario real, salen en la sección "Itinerario" del PDF.</div>
                                        <div v-for="t in op.tours" :key="t.id" class="d-flex justify-content-between align-items-center border rounded p-2 mb-1">
                                            <span><span class="badge bg-light text-dark border me-1">Día {{ t.orden }}</span>{{ t.paquete_plantilla?.nombre ?? t.nombre }}</span>
                                            <div v-if="!alternativaSoloLectura" class="d-flex gap-2">
                                                <i class="fas fa-pen text-muted" style="cursor:pointer;font-size:11px" title="Editar este tour" @click="abrirEdicionTour(t)"></i>
                                                <i class="fas fa-times text-muted" style="cursor:pointer" title="Quitar este tour" @click="quitarTourIncluido(t, op)"></i>
                                            </div>
                                        </div>
                                        <TourIncluidoForm v-if="op.tours?.some((t) => t.id === tourEnEdicionId)"
                                            :opcion-mayorista-id="op.id" :destino-atractivo-id="destinoAtractivoIdActivo"
                                            :dia-sugerido="0" :tour-existente="op.tours?.find((t) => t.id === tourEnEdicionId) ?? null" class="mt-2 mb-2"
                                            @actualizado="onTourIncluidoActualizado" @cancelar="tourEnEdicionId = null" />
                                        <button v-if="!alternativaSoloLectura" class="btn btn-sm btn-outline-secondary w-100 mt-1" @click="mostrarFormTourIncluido = mostrarFormTourIncluido === op.id ? null : op.id; tourEnEdicionId = null">
                                            <i class="fas fa-plus me-1"></i>Agregar tour nuevo
                                        </button>
                                        <TourIncluidoForm v-if="mostrarFormTourIncluido === op.id"
                                            :opcion-mayorista-id="op.id" :destino-atractivo-id="destinoAtractivoIdActivo"
                                            :dia-sugerido="(op.tours?.length ?? 0) + 1" class="mt-2"
                                            @agregado="(t) => onTourIncluidoAgregado(op, t)" @cancelar="mostrarFormTourIncluido = null" />
                                    </div>
                                </div>
                                <button v-if="!alternativaSoloLectura" class="btn btn-outline-primary btn-sm w-100" @click="mostrarFormMayorista = !mostrarFormMayorista; opcionMayoristaEnEdicionId = null">
                                    <i class="fas fa-plus me-1"></i>Agregar cotización de mayorista
                                </button>
                                <OpcionMayoristaForm v-if="mostrarFormMayorista && !alternativaSoloLectura"
                                    :alternativa-id="alternativaActiva!.id" :destino-activo-id="destinoActivoId"
                                    :opcion-existente="null" :tipo-mayorista-id="tipoMayoristaId"
                                    @agregado="onOpcionMayoristaGuardada" @cancelar="mostrarFormMayorista = false" />
                            </div>
                        </template>

                        <template v-else-if="pasoDrawer === 'matrizHotel' && matrizHotelActiva">
                            <p class="small fw-semibold mb-2">{{ matrizHotelActiva.nombreProveedor }}</p>
                            <div v-if="matrizHotelActiva.grupoAbierto" class="alert alert-info py-2 px-3 small mb-2">
                                <i class="fas fa-layer-group me-1"></i>
                                Sumando este hotel a la comparación en curso ({{ matrizHotelActiva.grupoAbierto.hoteles }} hotel(es) ya cargado(s) para este día) — elige 1 o más habitaciones para agregarlas a la misma comparación.
                            </div>
                            <!-- T-020 — fotos del hotel (el PDF usa la fachada primero). Hotel no registrado:
                                 se suben/borran acá (mismas rutas opciones-hotel/{id}/fotos que mayorista).
                                 Hotel Proveedor: la galería vive en su ficha. -->
                            <div v-if="matrizHotelActiva.adhoc && matrizHotelActiva.hotelId" class="border rounded p-2 mb-2">
                                <small class="fw-semibold d-block mb-1"><i class="fas fa-images me-1"></i>Fotos del hotel (salen en el PDF)</small>
                                <div v-if="(matrizHotelActiva.fotos ?? []).length" class="d-flex flex-wrap gap-2 mb-2">
                                    <div v-for="foto in matrizHotelActiva.fotos" :key="foto.path" class="position-relative">
                                        <img :src="foto.url" style="width:56px;height:56px;object-fit:cover;border:1px solid #ccc;border-radius:3px;">
                                        <span class="badge bg-dark position-absolute" style="bottom:-4px;left:0;right:0;font-size:8px;">{{ foto.tipo_foto === 'fachada' ? 'Fachada' : 'Hab.' }}</span>
                                        <i class="fas fa-times-circle text-danger position-absolute" style="top:-6px;right:-6px;cursor:pointer;background:#fff;border-radius:50%" title="Eliminar foto" @click="onEliminarFotoHotelAdhoc(foto.path)"></i>
                                    </div>
                                </div>
                                <div class="d-flex gap-2">
                                    <label class="form-label mb-0 small text-secondary">Fachada
                                        <input type="file" accept="image/*" class="form-control form-control-sm" :disabled="subiendoFotoHotelAdhoc || (matrizHotelActiva.fotos ?? []).filter((f) => f.tipo_foto === 'fachada').length >= 1" @change="onSubirFotoHotelAdhoc($event, 'fachada')">
                                    </label>
                                    <label class="form-label mb-0 small text-secondary">Habitación
                                        <input type="file" accept="image/*" class="form-control form-control-sm" :disabled="subiendoFotoHotelAdhoc || (matrizHotelActiva.fotos ?? []).filter((f) => f.tipo_foto === 'habitacion').length >= 2" @change="onSubirFotoHotelAdhoc($event, 'habitacion')">
                                    </label>
                                </div>
                            </div>
                            <p v-else-if="!matrizHotelActiva.adhoc && matrizHotelActiva.proveedorId" class="small mb-2">
                                <router-link :to="`/agencia-viajes/proveedores/${matrizHotelActiva.proveedorId}`" target="_blank"><i class="fas fa-images me-1"></i>Fotos en la ficha del proveedor</router-link>
                            </p>
                            <HabitacionMatrixPicker :tarifas="matrizHotelActiva.tarifas" :moneda="matrizHotelActiva.moneda"
                                :pasajeros="cotizacion?.pasajeros ?? []"
                                permitir-cama-adicional
                                mostrar-cantidad-en-grupo
                                :edad-max-infante-gratis="matrizHotelActiva.edadMaxInfanteGratis"
                                :edad-max-nino-cama-adicional="matrizHotelActiva.edadMaxNinoCamaAdicional"
                                :minimo-grupo="1"
                                :iniciar-en-modo-grupo="!!matrizHotelActiva.grupoAbierto"
                                :cantidad-default="matrizHotelActiva.grupoAbierto?.cantidad ?? nochesHotelDefault.noches"
                                :texto-noches="matrizHotelActiva.grupoAbierto ? undefined : nochesHotelDefault.texto"
                                marcar-todos-los-pasajeros
                                @seleccionar="({ id, cantidad, pax_incluidos, camas_adicionales_nino }) => matrizHotelActiva!.adhoc ? agregarItemHotelAdhocLocal(id, cantidad, pax_incluidos, camas_adicionales_nino) : agregarItemProveedorHotel(id, cantidad, pax_incluidos, camas_adicionales_nino)"
                                @agregar-grupo="({ ids, cantidad }) => matrizHotelActiva!.adhoc ? agregarGrupoHotelAdhocLocal(ids, cantidad) : agregarGrupoProveedorHotel(ids, cantidad)" />
                        </template>

                        <template v-else-if="pasoDrawer === 'modoPrecio' && modoPrecioPendiente">
                            <p class="fw-semibold mb-2 small">¿Cómo se cobra "{{ modoPrecioPendiente.nombre }}"?</p>
                            <div class="d-flex gap-2 mb-2">
                                <button class="btn btn-sm btn-outline-primary flex-fill" @click="confirmarModoPrecio('tarifa_fija')" :disabled="!!confirmandoModoPrecio">
                                    <span v-if="confirmandoModoPrecio === 'tarifa_fija'" class="spinner-border spinner-border-sm me-1"></span>Tarifa fija (total)
                                </button>
                                <button class="btn btn-sm btn-outline-primary flex-fill" @click="confirmarModoPrecio('por_persona')" :disabled="!!confirmandoModoPrecio">
                                    <span v-if="confirmandoModoPrecio === 'por_persona'" class="spinner-border spinner-border-sm me-1"></span>Por persona (adulto/niño/infante)
                                </button>
                            </div>
                        </template>

                        <template v-else-if="pasoDrawer === 'manual' && alternativaActiva">
                            <div v-if="!alternativaSoloLectura && avisoItemReservado(itemManualEnEdicion)" class="alert alert-info py-2 small mb-2">
                                <i class="fas fa-lock me-1"></i>{{ avisoItemReservado(itemManualEnEdicion) }}
                            </div>
                            <ItemManualForm :alternativa-id="alternativaActiva.id" :dia-activo="diaActivoParaAgregar"
                                :alternativa-destino-id="destinoActivoId"
                                :pasajeros="cotizacion?.pasajeros ?? []" :item-existente="itemManualEnEdicion" :solo-lectura="formManualSoloLectura"
                                :tip-afe-igv-default="configAgencia?.tip_afe_igv_default"
                                :destino-tributario-default="configAgencia?.destino_tributario_default"
                                @agregado="onServicioSueltoAgregado" @actualizado="onServicioSueltoActualizado" />
                        </template>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

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

<style scoped>
.price-panel { position: sticky; top: 1rem; }
.lib-item:hover { background: #eef2ff; border-color: #6366f1 !important; }
.canvas-item-nombre { white-space: normal; word-break: break-word; }
.resaltado-item { box-shadow: 0 0 0 3px rgba(255, 193, 7, .6); transition: box-shadow .3s; }

/* Tacho de la alternativa activa — oculto hasta hover del pill, para no
   saturar la fila cuando hay varias alternativas (Parte B). */
.alt-pill-delete { opacity: 0; transition: opacity .15s; }
.alt-pill:hover .alt-pill-delete { opacity: 1; }

/* T-080: pestaña de la alternativa reservada — borde verde de 2px aunque esté activa. */
.alt-pill-reservada { border: 2px solid var(--bs-success, #198754) !important; }
.alt-pill-marca { width: 1.4rem; height: 1.4rem; padding: 0; display: inline-flex; align-items: center; justify-content: center; font-size: .65rem; }

/* Lápiz de renombrar — mismo criterio de visibilidad que el tacho de
   arriba, separados con un poco de margen para no quedar pegados. */
.alt-pill-edit { opacity: 0; transition: opacity .15s; margin-left: 2px; }
.alt-pill:hover .alt-pill-edit { opacity: 1; }

/* Íconos de editar/eliminar del chip de destino activo — mismo patrón de
   hover-reveal que .alt-pill-edit/.alt-pill-delete arriba. */
.destino-chip-action { opacity: 0; transition: opacity .15s; }
.destino-chip:hover .destino-chip-action { opacity: 1; }

.biblioteca-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.5rem;
}
@media (max-width: 767px) {
    .biblioteca-grid { grid-template-columns: 1fr; }
}
</style>
