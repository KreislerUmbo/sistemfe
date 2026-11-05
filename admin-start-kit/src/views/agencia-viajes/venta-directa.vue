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
                                <div class="d-flex gap-2" role="group" aria-label="Moneda de la venta">
                                    <button type="button" class="btn btn-sm flex-fill" :class="moneda === 'PEN' ? 'btn-primary' : 'btn-outline-secondary'" :aria-pressed="moneda === 'PEN'" @click="moneda = 'PEN'">Soles (S/)</button>
                                    <button type="button" class="btn btn-sm flex-fill" :class="moneda === 'USD' ? 'btn-primary' : 'btn-outline-secondary'" :aria-pressed="moneda === 'USD'" @click="moneda = 'USD'">Dólares (US$)</button>
                                </div>
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

                <!-- 3 · Servicios (maqueta aprobada «Venta directa — rediseño B», 05-nov) -->
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white border-bottom py-2">
                        <span class="fw-semibold text-dark"><span class="badge bg-primary me-2">3</span>Servicios</span>
                    </div>
                    <div class="card-body py-3">
                        <div v-if="lineas.length === 0" class="small text-muted mb-2">Todavía no agregaste servicios. Elige el primero:</div>

                        <div v-if="lineas.length > 0" class="d-flex flex-column gap-2 mb-3">
                            <div v-for="(l, i) in lineas" :key="l.uid" :ref="(el) => guardarRefLinea(l.uid, el)"
                                class="border rounded p-3 linea-servicio"
                                :class="{ 'linea-resaltada': resaltadoUid === l.uid, 'linea-pendiente': esPendiente(l) }">
                                <div class="d-flex align-items-start gap-3">
                                    <span class="badge flex-shrink-0 text-uppercase" :class="etiquetaTipo(l).clase">{{ etiquetaTipo(l).texto }}</span>
                                    <div class="flex-grow-1" style="min-width:0">
                                        <div class="fw-semibold">
                                            {{ nombreLinea(l) }}
                                            <span v-if="resaltadoUid === l.uid" class="small fw-bold text-success ms-2">✓ Agregado</span>
                                        </div>
                                        <div class="small text-muted">{{ subLinea(l) }}</div>
                                        <div class="d-flex align-items-center gap-2 flex-wrap mt-1">
                                            <i class="fas fa-calendar-days text-muted small"></i>
                                            <select class="form-select form-select-sm w-auto py-0" v-model.number="l.dia" :aria-label="`Fecha de ${nombreLinea(l)}`">
                                                <option v-for="d in DIAS" :key="d" :value="d">{{ esHotel(l) ? 'Entra el ' : '' }}{{ etiquetaDia(d) }}</option>
                                            </select>
                                            <span v-for="a in afectacionesDe(i)" :key="a.texto" class="badge" :class="a.clase">{{ a.texto }}</span>
                                        </div>
                                        <div v-if="detalleLinea(l, i)" class="small mt-1">{{ detalleLinea(l, i) }}</div>
                                    </div>
                                    <div class="fw-bold text-nowrap">{{ textoTotalLinea(l, i) }}</div>
                                    <button type="button" class="btn btn-sm btn-link text-muted p-1" @click="quitarLinea(i)" :aria-label="`Quitar ${nombreLinea(l)}`" title="Quitar este servicio">
                                        <i class="fas fa-trash-can"></i>
                                    </button>
                                </div>

                                <!-- Hotel: noches con contador -->
                                <div v-if="esHotel(l)" class="d-flex align-items-center flex-wrap gap-3 mt-2">
                                    <div class="d-flex align-items-center gap-1">
                                        <span class="small fw-semibold me-1">Noches</span>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="l.cantidad <= 1" aria-label="Una noche menos" @click="cambiarCantidad(l, -1)"><i class="fas fa-minus"></i></button>
                                        <span class="fw-semibold text-center" style="min-width:1.75rem">{{ l.cantidad }}</span>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" aria-label="Una noche más" @click="cambiarCantidad(l, 1)"><i class="fas fa-plus"></i></button>
                                    </div>
                                    <span v-if="cantidadValida(l.cantidad)" class="small text-muted">Sale el {{ formatDiaCorto(sumarDiasISO(fechaInicio, l.dia - 1 + l.cantidad)) }}</span>
                                </div>

                                <!-- Tarifa que no es hotel: por persona / por unidad -->
                                <div v-else-if="l.origen === 'proveedor'" class="mt-2">
                                    <div v-if="esPendiente(l)" class="small fw-semibold text-warning-emphasis mb-1">¿Cómo se cobra? Elige una opción.</div>
                                    <div v-if="!lineaPrevia(i)" class="small text-muted">
                                        <span v-if="motivoSinCalculo" class="text-warning-emphasis"><i class="fas fa-circle-info me-1"></i>{{ motivoSinCalculo }}</span>
                                        <span v-else-if="!vistaPreviaError"><span class="spinner-border spinner-border-sm me-1"></span>Calculando…</span>
                                    </div>
                                    <div v-else class="row g-2">
                                        <div class="col-12 col-md-6">
                                            <button type="button" class="opcion-cobro border rounded p-2 w-100 h-100 text-start d-flex flex-column justify-content-start" :class="{ 'opcion-elegida': l.modoPrecio === 'por_persona' }" @click="l.modoPrecio = 'por_persona'">
                                                <span class="d-block small fw-semibold">Por persona ({{ pax.length }} pasajero{{ pax.length === 1 ? '' : 's' }}): {{ totalesAlDia ? dinero(lineaPrevia(i)!.por_persona!.total) : '…' }}</span>
                                                <span class="d-block small text-muted">{{ totalesAlDia ? textoDesglose(lineaPrevia(i)!) : 'Calculando…' }}</span>
                                            </button>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <div class="opcion-cobro border rounded p-2 h-100" :class="{ 'opcion-elegida': l.modoPrecio === 'tarifa_fija' }">
                                                <button type="button" class="btn p-0 border-0 text-start w-100" @click="l.modoPrecio = 'tarifa_fija'">
                                                    <span class="d-block small fw-semibold">Por unidad (cantidad {{ l.cantidad }}): {{ totalesAlDia ? dinero(lineaPrevia(i)!.tarifa_fija!.total) : '…' }}</span>
                                                    <span class="d-block small text-muted">{{ dinero(lineaPrevia(i)!.tarifa_fija!.precio_unitario) }} por unidad (vehículo, habitación…)</span>
                                                </button>
                                                <div class="d-flex align-items-center gap-1 mt-2">
                                                    <span class="small text-muted me-1">Cantidad</span>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="l.cantidad <= 1" aria-label="Una unidad menos" @click="cambiarCantidad(l, -1); l.modoPrecio = 'tarifa_fija'"><i class="fas fa-minus"></i></button>
                                                    <span class="fw-semibold text-center" style="min-width:1.75rem">{{ l.cantidad }}</span>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" aria-label="Una unidad más" @click="cambiarCantidad(l, 1); l.modoPrecio = 'tarifa_fija'"><i class="fas fa-plus"></i></button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Catálogo, en el mismo lugar -->
                        <div v-if="catalogoAbierto" class="border rounded p-3 bg-light">
                            <div class="d-flex align-items-center justify-content-between gap-2 mb-3 flex-wrap">
                                <span class="fw-semibold small">¿Qué servicio agregas?</span>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="btn-group btn-group-sm" role="tablist" aria-label="Tipo de servicio">
                                        <button type="button" role="tab" :aria-selected="pestana === 'tour'" class="btn" :class="pestana === 'tour' ? 'btn-primary' : 'btn-outline-primary'" @click="pestana = 'tour'">Tours</button>
                                        <button type="button" role="tab" :aria-selected="pestana === 'proveedor'" class="btn" :class="pestana === 'proveedor' ? 'btn-primary' : 'btn-outline-primary'" @click="pestana = 'proveedor'">Tarifas de proveedor</button>
                                        <button type="button" role="tab" :aria-selected="pestana === 'libre'" class="btn" :class="pestana === 'libre' ? 'btn-primary' : 'btn-outline-primary'" @click="pestana = 'libre'">Concepto libre</button>
                                    </div>
                                    <button v-if="lineas.length > 0" type="button" class="btn btn-sm btn-outline-secondary" @click="catalogoAbierto = false">Cancelar</button>
                                </div>
                            </div>

                            <div v-if="pestana !== 'libre'">
                                <div class="row g-2 mb-2">
                                    <div class="col-12 col-md-6">
                                        <!-- Filtro propio del catálogo: cambiarlo no toca el destino de la reserva. -->
                                        <DestinoTreeSelect v-model="filtroDestinoId" @update:label="filtroDestinoTexto = $event"
                                            placeholder="Todos los destinos" />
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <input type="text" class="form-control form-control-sm" v-model="busqueda" @input="onBusqueda"
                                            :placeholder="pestana === 'tour' ? `Buscar tour${filtroDestinoTexto ? ' en ' + filtroDestinoTexto : ''}…` : 'Buscar por proveedor o servicio (opcional)'" aria-label="Buscar en el catálogo">
                                    </div>
                                </div>
                                <div class="small text-muted mb-2">
                                    <template v-if="filtroDestinoId">{{ pestana === 'tour' ? 'Tours' : 'Tarifas' }} de «{{ filtroDestinoTexto }}» y sus lugares.</template>
                                    <template v-else>{{ pestana === 'tour' ? 'Tours' : 'Tarifas' }} de todos los destinos.</template>
                                </div>
                                <div v-if="cargandoCatalogo" class="small text-muted"><span class="spinner-border spinner-border-sm me-1"></span>Cargando…</div>
                                <div v-else-if="errorCatalogo" class="alert alert-warning py-2 px-3 small mb-0">{{ errorCatalogo }}</div>
                                <div v-else-if="pestana === 'tour' && catalogoTours.length === 0" class="small text-muted fst-italic">No hay tours para este filtro.</div>
                                <!-- Tours: tarjetas sin escribir, con «Desde S/ X p/p» (maqueta). Los combos se ven, pero se venden desde el cotizador. -->
                                <div v-else-if="pestana === 'tour'" class="catalogo-grilla">
                                    <div v-for="t in catalogoTours" :key="t.id" class="border rounded bg-white p-2 d-flex flex-column gap-2">
                                        <div>
                                            <div class="fw-semibold small">{{ t.nombre }}</div>
                                            <div class="small text-muted">{{ t.tipo === 'paquete_combo' ? 'Combo de varios tours' : subTour(t) }}</div>
                                        </div>
                                        <div class="d-flex align-items-center justify-content-between gap-2 mt-auto">
                                            <span class="small">{{ precioTour(t) }}</span>
                                            <button type="button" class="btn btn-sm text-nowrap"
                                                :class="estadoTour(t).puede ? 'btn-primary' : 'btn-outline-secondary'"
                                                :disabled="!estadoTour(t).puede" @click="agregarTour(t)">{{ estadoTour(t).texto }}</button>
                                        </div>
                                    </div>
                                </div>
                                <div v-else-if="catalogo.length === 0" class="small text-muted fst-italic">No hay tarifas para este filtro.</div>
                                <div v-else class="catalogo-grilla">
                                    <div v-for="t in catalogo" :key="t.id" class="border rounded bg-white p-2 d-flex flex-column gap-2">
                                        <div>
                                            <div class="fw-semibold small">{{ nombreTarifa(t) }}</div>
                                            <div class="small text-muted">
                                                <template v-if="t.tipo_habitacion">Hotel · {{ t.tipo_habitacion }}</template>
                                                <template v-else>{{ t.proveedor_servicio?.proveedor?.razon_social }}<span v-if="t.proveedor_servicio?.proveedor?.es_referencial"> (Referencial)</span></template>
                                            </div>
                                        </div>
                                        <div class="d-flex align-items-center justify-content-between gap-2 mt-auto">
                                            <span class="small">{{ precioCatalogo(t) }}</span>
                                            <button type="button" class="btn btn-sm text-nowrap"
                                                :class="t.moneda === moneda ? 'btn-primary' : 'btn-outline-secondary'"
                                                :disabled="t.moneda !== moneda" @click="agregarTarifa(t)">
                                                <template v-if="t.moneda === moneda">Agregar</template>
                                                <template v-else>Está en {{ NOMBRE_MONEDA[t.moneda] }}</template>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Concepto libre: formulario dentro del catálogo -->
                            <div v-else class="row g-2 align-items-end">
                                <div class="col-12 col-md-4">
                                    <label class="form-label small text-secondary mb-1" for="vd-libre-desc">Descripción</label>
                                    <input id="vd-libre-desc" type="text" class="form-control form-control-sm" v-model="libreDesc" placeholder="Ej. Traslado aeropuerto → hotel">
                                </div>
                                <div class="col-6 col-md-2">
                                    <label class="form-label small text-secondary mb-1" for="vd-libre-precio">Precio unitario ({{ simbolo }})</label>
                                    <input id="vd-libre-precio" type="number" min="0" step="0.01" class="form-control form-control-sm" v-model.number="librePrecio">
                                </div>
                                <div class="col-6 col-md-2">
                                    <label class="form-label small text-secondary mb-1" for="vd-libre-cant">Cantidad</label>
                                    <input id="vd-libre-cant" type="number" min="1" class="form-control form-control-sm" v-model.number="libreCant">
                                </div>
                                <div class="col-6 col-md-2">
                                    <label class="form-label small text-secondary mb-1" for="vd-libre-costo">Costo unitario ({{ simbolo }})</label>
                                    <input id="vd-libre-costo" type="number" min="0" step="0.01" class="form-control form-control-sm" v-model.number="libreCosto" placeholder="0 si no tiene">
                                </div>
                                <div class="col-6 col-md-2">
                                    <button type="button" class="btn btn-sm btn-primary w-100" :disabled="!libreListo" @click="agregarLibre">Agregar</button>
                                </div>
                            </div>
                        </div>

                        <button v-else type="button" class="btn btn-agregar-servicio w-100 fw-semibold" @click="abrirCatalogo">
                            + Agregar servicio
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
import { useToast } from '@/composables/useToast';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import httpClient from '@/helpers/http-client';
import ClientFormQuick from '@/components/Sales/ClientFormQuick.vue';
import { proveedorService } from '@/services/admin/proveedorService';
import { bibliotecaCotizadorService } from '@/services/admin/bibliotecaCotizadorService';
import {
    ventaDirectaService,
    type LineaVentaDirecta,
    type MonedaVenta,
    type TipoPaxVistaPrevia,
    type VistaPreviaLinea,
    type VistaPreviaVentaDirecta,
} from '@/services/admin/ventaDirectaService';
import type { Client } from '@/types/clients';
import type { PaquetePlantilla, ProveedorTarifa } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const router = useRouter();
const toast = useToast();

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
// Una línea es un servicio de la venta: una tarifa de proveedor, un tour del catálogo (T-047) o un concepto libre.
type Linea = {
    uid: number;
    origen: 'proveedor' | 'manual' | 'tour';
    dia: number; // día del viaje: 1 = fecha de inicio
    cantidad: number; // unidades; en un hotel, las noches
    // proveedor
    tarifa?: ProveedorTarifa;
    // tour del catálogo
    tour?: PaquetePlantilla;
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
const nombreLinea = (l: Linea) => (l.origen === 'tour' ? (l.tour?.nombre ?? 'Tour') : l.origen === 'proveedor' ? nombreTarifa(l.tarifa) : (l.descripcion.trim() || 'Concepto libre'));
const proveedorDe = (l: Linea) => l.tarifa?.proveedor_servicio?.proveedor?.razon_social ?? '';
const etiquetaTipo = (l: Linea) => l.origen === 'tour'
    ? { texto: 'Tour', clase: 'bg-success-subtle text-success-emphasis' }
    : esHotel(l)
    ? { texto: 'Hotel', clase: 'bg-info-subtle text-info-emphasis' }
    : l.origen === 'proveedor'
        ? { texto: 'Proveedor', clase: 'bg-primary-subtle text-primary-emphasis' }
        : { texto: 'Libre', clase: 'bg-secondary-subtle text-secondary-emphasis' };
const etiquetaDia = (d: number) => `${formatDiaCorto(sumarDiasISO(fechaInicio.value, d - 1))}${d === 1 ? ' (inicio)' : ` · día ${d}`}`;
// Segunda línea de la tarjeta: el proveedor (o «Hotel · tipo de habitación»).
const subLinea = (l: Linea) => l.origen === 'manual' ? 'Concepto libre'
    : l.origen === 'tour' ? `Tour · ${subTour(l.tour!)} · se engancha a la salida del día`
    : l.tarifa?.tipo_habitacion ? `Hotel · ${l.tarifa.tipo_habitacion}` : proveedorDe(l);

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

// Tours (T-047). «Medio día · salida 08:30»: lo que el vendedor necesita para reconocerlo.
const subTour = (t: PaquetePlantilla): string => {
    const horas = Number(t.duracion_horas);
    const duracion = horas >= 24 ? `${Math.round(horas / 24)} días` : (horas > 0 ? `${horas} h` : '');
    return [duracion, t.hora_salida ? `salida ${t.hora_salida.slice(0, 5)}` : ''].filter(Boolean).join(' · ');
};
const monedaDeTour = (t: PaquetePlantilla): MonedaVenta => t.moneda ?? 'PEN';
const precioTour = (t: PaquetePlantilla) => t.tipo === 'paquete_combo' || t.precio_venta_final == null
    ? ''
    : `Desde ${dinero(Number(t.precio_venta_final), monedaDeTour(t))} p/p`;
// Por qué un tour no se puede agregar (el botón lo dice, como en la maqueta).
const estadoTour = (t: PaquetePlantilla): { puede: boolean; texto: string } => {
    if (t.tipo === 'paquete_combo') return { puede: false, texto: 'Solo desde el cotizador' };
    if (t.precio_venta_final == null) return { puede: false, texto: 'Sin precio' };
    if (monedaDeTour(t) !== moneda.value) return { puede: false, texto: `Está en ${NOMBRE_MONEDA[monedaDeTour(t)]}` };
    if (lineas.value.some((l) => l.tour?.id === t.id)) return { puede: false, texto: 'Agregado' };
    return { puede: true, texto: 'Agregar' };
};
const agregarTour = (t: PaquetePlantilla) => {
    if (!estadoTour(t).puede) return;
    // Sin destino todavía, se toma el del primer tour (se llena solo y se puede cambiar).
    if (!destinoId.value && t.destino_atractivo_id) {
        destinoId.value = t.destino_atractivo_id;
        destinoTexto.value = t.destino_atractivo?.nombre ?? '';
    }
    agregarLinea(nuevaLinea({ origen: 'tour', tour: t }));
};

// Concepto libre: se llena en el formulario del catálogo y la línea nace completa.
const libreDesc = ref<string>('');
const librePrecio = ref<number | null>(null);
const libreCant = ref<number>(1);
const libreCosto = ref<number | null>(0);
const libreListo = computed<boolean>(() =>
    libreDesc.value.trim() !== '' && typeof librePrecio.value === 'number' && librePrecio.value > 0
    && cantidadValida(libreCant.value) && typeof libreCosto.value === 'number' && libreCosto.value >= 0);
const agregarLibre = () => {
    if (!libreListo.value) return;
    agregarLinea(nuevaLinea({
        origen: 'manual', descripcion: libreDesc.value.trim(), precio: librePrecio.value, cantidad: libreCant.value, costo: libreCosto.value ?? 0,
    }));
    libreDesc.value = '';
    librePrecio.value = null;
    libreCant.value = 1;
    libreCosto.value = 0;
};

// Contador − / + de noches (hotel) y de unidades: nunca baja de 1.
const cambiarCantidad = (l: Linea, delta: number) => {
    l.cantidad = Math.max(1, (cantidadValida(l.cantidad) ? l.cantidad : 1) + delta);
};
// Tarifa que no es hotel y todavía sin «por persona / por unidad»: queda marcada hasta elegir.
const esPendiente = (l: Linea) => l.origen === 'proveedor' && !esHotel(l) && !l.modoPrecio;
const quitarLinea = (i: number) => { lineas.value.splice(i, 1); if (lineas.value.length === 0) abrirCatalogo(); };

// ── Catálogo (en el mismo lugar que las líneas) ────────────────────────
const catalogoAbierto = ref<boolean>(true); // sin líneas, viene abierto
const pestana = ref<'tour' | 'proveedor' | 'libre'>('tour');
const busqueda = ref('');
// Filtro del catálogo, separado del destino de la reserva: buscar en otro lugar no cambia la venta.
const filtroDestinoId = ref<number | null>(null);
const filtroDestinoTexto = ref<string>('');
const catalogo = ref<ProveedorTarifa[]>([]);
// Tours del destino y, al final, los combos (que solo se muestran: se venden desde el cotizador).
const catalogoTours = ref<PaquetePlantilla[]>([]);
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
        const filtros = { search: busqueda.value.trim() || undefined, destino_atractivo_id: filtroDestinoId.value ?? undefined };
        const [res, tours, combos] = await Promise.all([
            proveedorService.biblioteca(filtros),
            bibliotecaCotizadorService.buscar({ tipo: 'tour', ...filtros }),
            bibliotecaCotizadorService.buscar({ tipo: 'paquete', ...filtros }),
        ]);
        if (seq === catalogoSeq) {
            catalogo.value = res.proveedor_tarifas;
            // Un tour solo de itinerario (de mayorista) no se vende.
            catalogoTours.value = [...tours.resultados, ...combos.resultados].filter((r) => r.tipo_resultado !== 'proveedor_tarifa' && r.uso !== 'solo_itinerario') as PaquetePlantilla[];
        }
    } catch (error: any) {
        if (seq !== catalogoSeq) return;
        catalogo.value = [];
        catalogoTours.value = [];
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
    pestana.value = 'tour';
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
const monedaDeLinea = (l: Linea): MonedaVenta | null => (l.origen === 'proveedor' ? (l.tarifa?.moneda ?? null) : l.origen === 'tour' ? monedaDeTour(l.tour!) : null);
const motivoMoneda = computed<string>(() => {
    const l = lineas.value.find((x) => monedaDeLinea(x) !== null && monedaDeLinea(x) !== moneda.value);
    return l
        ? `La venta es en ${NOMBRE_MONEDA[moneda.value]} y «${nombreLinea(l)}» está en ${NOMBRE_MONEDA[monedaDeLinea(l)!]}. No se mezclan monedas: cambia la moneda de la venta o quita esa línea.`
        : '';
});

const payloadLineas = (): LineaVentaDirecta[] => lineas.value.map((l) => l.origen === 'tour'
    ? { origen_tipo: 'tour', dia: l.dia, paquete_plantilla_id: l.tour!.id }
    : l.origen === 'proveedor'
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
    if (esPendiente(l)) return resumen ? 'Por elegir' : '—';
    if (!totalesAlDia.value) return vistaPrevia.value || resumen ? '…' : '';
    const total = vistaPrevia.value!.lineas[i]?.total;
    return total === null || total === undefined ? 'Por elegir' : dinero(total);
};

// Una línea de detalle bajo la fecha: «2 noches × S/ 120.00 por noche», «2 × S/ 50.00», el desglose…
const GRUPO_TOUR: Record<string, [string, string]> = {
    adulto_nac: ['adulto nac.', 'adultos nac.'], adulto_ext: ['adulto ext.', 'adultos ext.'],
    nino_nac: ['niño nac.', 'niños nac.'], nino_ext: ['niño ext.', 'niños ext.'], infante: ['infante', 'infantes'],
};
const detalleLinea = (l: Linea, i: number): string => {
    if (l.origen === 'tour') {
        const t = totalesAlDia.value ? vistaPrevia.value!.lineas[i]?.tour : null;
        if (!t) return '';
        const partes = t.detalle.map((d) => `${d.cantidad} ${GRUPO_TOUR[d.grupo][d.cantidad === 1 ? 0 : 1]} × ${d.precio}`).join(' + ');
        return t.temporada ? `${partes} · ${t.temporada}` : partes;
    }
    if (l.origen === 'manual') return l.precio !== null && cantidadValida(l.cantidad) ? `${l.cantidad} × ${dinero(l.precio)}` : '';
    if (esHotel(l)) {
        return cantidadValida(l.cantidad)
            ? `${l.cantidad} noche${l.cantidad === 1 ? '' : 's'} × ${dinero(Number(l.tarifa!.precio_venta_adulto), l.tarifa!.moneda)} por noche`
            : '';
    }
    const lp = lineaPrevia(i);
    if (!lp || !totalesAlDia.value || !l.modoPrecio) return '';
    return l.modoPrecio === 'tarifa_fija' ? `${l.cantidad} × ${dinero(lp.tarifa_fija!.precio_unitario)}` : textoDesglose(lp);
};

const ETIQUETA_AFECTACION = (lp: Pick<VistaPreviaLinea, 'destino_tributario' | 'tip_afe_igv'>) => {
    if (lp.destino_tributario === 'amazonia') return { texto: 'Exonerado · Amazonía', clase: 'bg-success-subtle text-success-emphasis' };
    if (lp.destino_tributario === 'extranjero') return { texto: 'Exportación', clase: 'bg-warning-subtle text-warning-emphasis' };
    if (lp.tip_afe_igv === '20') return { texto: 'Exonerado', clase: 'bg-success-subtle text-success-emphasis' };
    if (lp.tip_afe_igv === '30') return { texto: 'Inafecto', clase: 'bg-secondary-subtle text-secondary-emphasis' };
    return { texto: 'Gravado', clase: 'bg-light text-dark border' };
};
// Un tour de IGV mezclado muestra una etiqueta por cada afectación de su carga.
const afectacionesDe = (i: number) => {
    const lp = vistaPrevia.value?.lineas[i];
    if (!totalesAlDia.value || !lp) return [];
    const etiquetas = (lp.afectaciones?.length ? lp.afectaciones : [lp]).map(ETIQUETA_AFECTACION);
    return etiquetas.filter((e, k) => etiquetas.findIndex((x) => x.texto === e.texto) === k);
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

        // T-047: salida del tour cancelada (T-042) o avisos del tour, como al aceptar en el cotizador.
        [...(res.avisos_salida_cancelada ?? []), ...(res.avisos ?? [])].forEach((aviso) => toast.warning(aviso));
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
    border-color: var(--bs-success) !important;
    background-color: var(--bs-success-bg-subtle);
    box-shadow: 0 0 0 1px var(--bs-success);
}

/* Cobro pendiente: la tarjeta pide una decisión (maqueta). */
.linea-pendiente {
    border-color: var(--bs-warning) !important;
    background-color: var(--bs-warning-bg-subtle);
}

.opcion-cobro {
    background: var(--bs-body-bg);
    cursor: pointer;
}

.opcion-elegida {
    border-color: var(--bs-primary) !important;
    background-color: var(--bs-primary-bg-subtle);
    box-shadow: inset 0 0 0 1px var(--bs-primary);
}

.catalogo-grilla {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
    gap: 0.5rem;
    max-height: 340px;
    overflow-y: auto;
}

.btn-agregar-servicio {
    border: 1px dashed var(--bs-primary);
    color: var(--bs-primary);
    background: var(--bs-primary-bg-subtle);
}
</style>
