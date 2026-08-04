<template>
    <DefaultLayout>
        <div v-if="cotizacion" class="mb-3">
            <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
                <div>
                    <h5 class="fw-bold mb-1"><i class="fas fa-route me-2 text-primary"></i>Cotización {{ cotizacion.codigo }}</h5>
                    <div class="fw-semibold text-dark mb-2"><i class="fas fa-user me-2 text-muted" style="font-size:12px"></i>{{ cotizacion.cliente?.full_name ?? 'Sin cliente' }}</div>
                </div>
                <router-link to="/agencia-viajes/cotizador" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left me-2"></i>Volver
                </router-link>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="badge bg-light text-dark border fw-normal"><i class="fas fa-map-marker-alt me-1 text-primary"></i>{{ cotizacion.destino }}</span>
                <span class="badge bg-light text-dark border fw-normal"><i class="fas fa-calendar me-1 text-primary"></i>{{ textoFechasViaje }}</span>
                <span class="badge bg-light text-dark border fw-normal"><i class="fas fa-users me-1 text-primary"></i>{{ resumenPax }}</span>
                <i class="fas fa-pen text-primary" style="cursor:pointer;font-size:12px" title="Corregir cliente/destino/fecha" @click="abrirEdicionCabecera"></i>
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
                            <button v-if="clienteEditado" class="btn btn-outline-danger" type="button" @click="limpiarClienteEditado">
                                <i class="fas fa-times"></i>
                            </button>
                            <button v-else class="btn btn-success" type="button" @click="showQuickClientModal = true" title="Registrar cliente nuevo">
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
                        <input type="date" class="form-control form-control-sm" v-model="formCabecera.fecha_viaje_desde">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Fecha hasta</label>
                        <input type="date" class="form-control form-control-sm" v-model="formCabecera.fecha_viaje_hasta">
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

        <!-- Pestañas de alternativas -->
        <div class="d-flex align-items-center gap-2 mb-3 flex-wrap" v-if="cotizacion">
            <span v-for="alt in cotizacion.alternativas" :key="alt.id" class="alt-pill badge rounded-pill px-3 py-2 d-inline-flex align-items-center gap-2"
                :class="alt.id === alternativaActivaId ? 'bg-primary' : 'bg-light text-dark border'"
                style="cursor:pointer" @click="seleccionarAlternativa(alt.id)">
                <span>
                    {{ alt.nombre }} · {{ alt.moneda_cotizacion }} {{ Number(alt.total).toFixed(0) }}
                    <span v-if="alt.estado === 'aceptada'" class="ms-1"><i class="fas fa-check-circle"></i></span>
                    <span v-else-if="alt.estado === 'descartada'" class="ms-1 opacity-50"><i class="fas fa-times-circle"></i></span>
                </span>
                <i v-if="alt.id === alternativaActivaId" class="fas fa-trash alt-pill-delete" title="Eliminar esta alternativa" @click.stop="eliminarAlternativa"></i>
            </span>
            <!-- Punto F — siempre visible; deshabilitado con motivo al llegar al tope,
                 en vez de desaparecer sin explicación. -->
            <span class="badge rounded-pill px-3 py-2"
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
                        <input type="text" class="form-control form-control-sm" v-model="formAlternativa.nombre" placeholder="Alternativa B">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Moneda</label>
                        <select class="form-select form-select-sm" v-model="formAlternativa.moneda_cotizacion">
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
                        <input type="number" step="0.0001" class="form-control form-control-sm" v-model.number="formAlternativa.tipo_cambio_valor">
                    </div>
                    <div class="col-12 col-md-2 d-flex gap-2">
                        <button class="btn btn-primary btn-sm w-100" @click="crearAlternativa">Crear</button>
                        <button class="btn btn-outline-secondary btn-sm" @click="mostrarFormAlternativa = false"><i class="fas fa-times"></i></button>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3" v-if="alternativaActiva">
            <!-- ═══ LIENZO ═══ -->
            <div class="col-12 col-lg-8">
                <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                    <button class="btn btn-primary btn-sm" @click="abrirDrawerBiblioteca">
                        <i class="fas fa-plus me-1"></i>Agregar servicio
                    </button>
                    <button v-if="!mostrarTabsDia" class="btn btn-link btn-sm text-decoration-none" @click="agregarDia">
                        <i class="fas fa-calendar-plus me-1"></i>Agregar otro día
                    </button>
                    <button class="btn btn-outline-secondary btn-sm ms-auto" @click="mostrarFormPasajeAereo = !mostrarFormPasajeAereo">
                        <i class="fas fa-plane me-1"></i>{{ mostrarFormPasajeAereo ? 'Cerrar' : 'Pasaje aéreo suelto' }}
                    </button>
                </div>

                <div v-if="mostrarFormPasajeAereo" class="border rounded p-2 mb-2">
                    <PasajeAereoForm :alternativa-id="alternativaActiva.id" :dia-activo="diaActivoParaAgregar" @agregado="onPasajeAereoAgregado" />
                </div>

                <!-- Tabs de día — condicionales (Punto B): solo aparecen cuando ya
                     existe un segundo día real, o hay ítems legado "Sin día". Con un
                     solo día, el lienzo es una lista simple sin navegación de más. -->
                <ul v-if="mostrarTabsDia" class="nav nav-tabs dia-tabs mb-2">
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

                <!-- Sesión 11k — hoteles disponibles del tour/paquete recién cargado,
                     sin auto-agregarse (una matriz de hotel tiene varias tarifas por
                     tipo_habitacion, el vendedor elige la habitación acá). -->
                <div v-if="hotelesPlantillaPendientes.length" class="card border-0 shadow-sm mb-2">
                    <div class="card-body py-2 px-3 bg-warning-subtle rounded">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                            <span class="small fw-semibold"><i class="fas fa-hotel me-1 text-warning-emphasis"></i>Este tour/paquete tiene hoteles — elegí la habitación para agregarla al lienzo:</span>
                            <i class="fas fa-times text-muted flex-shrink-0" style="cursor:pointer" @click="hotelesPlantillaPendientes = []"></i>
                        </div>
                        <div v-for="hotel in hotelesPlantillaPendientes" :key="hotel.id" class="card border p-2 small mb-2">
                            <div class="d-flex justify-content-between align-items-center" style="cursor:pointer" @click="hotelPlantillaActivoId = hotelPlantillaActivoId === hotel.id ? null : hotel.id">
                                <strong>
                                    <i class="fas fa-hotel me-1 text-primary"></i>{{ hotel.nombre_hotel }}
                                    <span v-if="hotel.categoria_estrellas" class="text-warning ms-1">
                                        <i v-for="n in hotel.categoria_estrellas" :key="n" class="fas fa-star" style="font-size:10px"></i>
                                    </span>
                                </strong>
                                <i class="fas" :class="hotelPlantillaActivoId === hotel.id ? 'fa-chevron-up' : 'fa-chevron-down'" style="font-size:10px"></i>
                            </div>
                            <div v-if="hotelPlantillaActivoId === hotel.id" class="mt-2 border-top pt-2">
                                <HabitacionMatrixPicker :tarifas="tarifasHotelPlantillaPlanas(hotel)" :moneda="hotel.moneda"
                                    @seleccionar="({ id, cantidad }) => agregarItemHotelPlantilla(hotel, id, cantidad)" />
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div v-if="bloquesLienzo.length === 0" class="drop-hint text-center text-muted py-4 border rounded" style="border-style:dashed">
                            Agregá un servicio con el botón de arriba
                        </div>

                        <div v-for="bloque in bloquesLienzo" :key="bloque.tourOrigenId ?? 'sueltos'" class="mb-3">
                            <div v-if="bloque.tourOrigenId" class="d-flex justify-content-between align-items-center mb-1">
                                <span class="small fw-semibold text-dark"><i class="fas fa-route me-1 text-primary"></i>{{ bloque.tourNombre ?? 'Tour' }}</span>
                                <span class="d-flex align-items-center gap-2">
                                    <select v-if="mostrarTabsDia" class="form-select form-select-sm" style="width:auto;font-size:11px" :value="diaActivo" @change="onMoverBloque(bloque, $event)">
                                        <option v-for="d in diasCreados" :key="d" :value="d">Mover a Día {{ d }}</option>
                                        <option :value="diaSiguiente">Mover a Día {{ diaSiguiente }} (nuevo)</option>
                                    </select>
                                    <i class="fas fa-trash text-danger" style="cursor:pointer" title="Eliminar todo el tour" @click="eliminarBloque(bloque)"></i>
                                </span>
                            </div>

                            <div v-for="item in bloque.items" :key="item.id" class="canvas-item border rounded p-2 mb-2 small" :class="{ 'ms-3': bloque.tourOrigenId }">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <span class="canvas-item-nombre">
                                        <i class="fas me-2 text-primary" :class="iconoItem(item)"></i>
                                        {{ etiquetaItem(item) }}
                                        <span v-if="item.cantidad > 1 && item.modo_precio === 'tarifa_fija' && item.origen_tipo !== 'manual'" class="text-muted"> × {{ item.cantidad }}</span>
                                    </span>
                                    <i class="fas fa-times text-danger flex-shrink-0" style="cursor:pointer" title="Eliminar" @click="eliminarItem(item)"></i>
                                </div>

                                <div v-if="desglosePasajerosTexto(item)" class="text-muted mt-1" style="font-size:11px">
                                    <i class="fas fa-users me-1"></i>{{ desglosePasajerosTexto(item) }}
                                </div>
                                <div class="text-muted mt-1" style="font-size:11px" v-if="item.origen_tipo === 'pasaje_aereo' && item.cotizacion_pasaje_aereo">
                                    {{ item.cotizacion_pasaje_aereo.aerolinea }}
                                </div>

                                <div class="d-flex align-items-center flex-wrap gap-2 mt-2">
                                    <!-- Punto B (Sesión 11i) — un único input, según configuracion_agencia. -->
                                    <div v-if="configAgencia && !configAgencia.permitir_descuento_item" class="d-flex flex-column">
                                        <label class="form-label mb-0 text-muted" style="font-size:10px">Precio de venta</label>
                                        <div class="input-group input-group-sm" style="width:auto">
                                            <span class="input-group-text">{{ alternativaActiva.moneda_cotizacion }}</span>
                                            <input type="number" step="0.01" class="form-control"
                                                style="max-width:110px"
                                                :class="{ 'border-danger text-danger': alertasPiso[item.id] }"
                                                v-model.number="edicionItems[item.id].precio_convertido" @input="onEditarPrecio(item)">
                                        </div>
                                    </div>
                                    <div v-else-if="configAgencia?.modo_descuento_item === 'monto'" class="d-flex flex-column">
                                        <label class="form-label mb-0 text-muted" style="font-size:10px">Descuento</label>
                                        <div class="input-group input-group-sm" style="width:auto">
                                            <span class="input-group-text">{{ alternativaActiva.moneda_cotizacion }}</span>
                                            <input type="number" step="0.01" min="0" class="form-control"
                                                style="max-width:110px"
                                                :class="{ 'border-danger text-danger': alertasPiso[item.id] }"
                                                v-model.number="edicionItems[item.id].monto_descuento" @input="onEditarMontoDescuentoItem(item)">
                                        </div>
                                    </div>
                                    <div v-else class="d-flex flex-column">
                                        <label class="form-label mb-0 text-muted" style="font-size:10px">Descuento</label>
                                        <div class="input-group input-group-sm" style="width:auto">
                                            <input type="number" step="0.01" min="0" max="100" class="form-control"
                                                style="max-width:80px"
                                                :class="{ 'border-danger text-danger': alertasPiso[item.id] }"
                                                v-model.number="edicionItems[item.id].descuento_pct" @input="onEditarDescuentoPct(item)">
                                            <span class="input-group-text">%</span>
                                        </div>
                                    </div>
                                    <!-- Badge de % efectivo — solo tiene sentido cuando el input primario NO
                                         es el % mismo (sería redundante), y solo si permitir_descuento_item
                                         (en modo "Precio de venta" el punto B pide explícitamente que no se
                                         muestre lenguaje de descuento). -->
                                    <span v-if="configAgencia?.permitir_descuento_item && configAgencia?.modo_descuento_item === 'monto' && Number(edicionItems[item.id]?.descuento_pct) > 0"
                                        class="badge bg-light text-dark border" style="font-size:10px">
                                        -{{ Number(edicionItems[item.id].descuento_pct).toFixed(0) }}%
                                    </span>
                                    <select v-if="!bloque.tourOrigenId && mostrarTabsDia" class="form-select form-select-sm ms-auto" style="width:auto;font-size:11px" :value="item.dia_referencial ?? ''" @change="onReasignarDiaItem(item, $event)">
                                        <option value="" disabled>Sin día</option>
                                        <option v-for="d in diasCreados" :key="d" :value="d">Día {{ d }}</option>
                                    </select>
                                </div>
                                <small v-if="alertasPiso[item.id]" class="text-danger d-block mt-1"><i class="fas fa-exclamation-triangle me-1"></i>Por debajo del piso permitido</small>
                            </div>
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
                            <div v-for="grupo in gruposPrecioPanel" :key="grupo.tourOrigenId ?? 'sueltos'" class="mb-2 pb-2 border-bottom">
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
                                            <span style="word-break:break-word">{{ etiquetaItem(item) }}</span>
                                            <span class="text-nowrap">{{ totalConvertidoLocal(item).toFixed(2) }}</span>
                                        </div>
                                    </div>
                                </template>
                                <template v-else>
                                    <div v-for="item in grupo.items" :key="item.id" class="d-flex justify-content-between gap-2 mb-1">
                                        <span class="text-muted" style="word-break:break-word;">{{ etiquetaItem(item) }}</span>
                                        <span class="text-nowrap">{{ totalConvertidoLocal(item).toFixed(2) }}</span>
                                    </div>
                                </template>
                            </div>
                            <div v-if="(alternativaActiva.items?.length ?? 0) === 0" class="text-muted">Sin ítems todavía</div>
                        </div>

                        <div class="border-top pt-2 mb-2" v-if="(alternativaActiva.items?.length ?? 0) > 0">
                            <!-- Punto C (Sesión 11i) — un único input, según configuracion_agencia. -->
                            <template v-if="configAgencia?.modo_descuento_global === 'monto'">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Descuento global</label>
                                <div class="d-flex align-items-center gap-1">
                                    <span class="small text-muted">{{ alternativaActiva.moneda_cotizacion }}</span>
                                    <input type="number" min="0" class="form-control form-control-sm"
                                        v-model.number="descuentoGlobalMontoLocal" @change="onEditarDescuentoGlobalMonto">
                                </div>
                            </template>
                            <template v-else>
                                <label class="form-label mb-1 small fw-semibold text-secondary">Descuento global %</label>
                                <div class="d-flex align-items-center gap-1">
                                    <input type="number" min="0" max="100" class="form-control form-control-sm"
                                        v-model.number="descuentoGlobalLocal" @change="onEditarDescuentoGlobal">
                                    <span class="small text-muted">%</span>
                                </div>
                            </template>
                            <small v-if="lineasFueraDePiso.length" class="text-danger d-block mt-1">
                                <i class="fas fa-exclamation-triangle me-1"></i>{{ lineasFueraDePiso.length }} línea(s) quedaron bajo el piso permitido — revisalas en el lienzo.
                            </small>
                        </div>

                        <hr>
                        <div class="d-flex justify-content-between align-items-baseline">
                            <span class="small text-secondary">Total</span>
                            <span class="fs-4 fw-semibold">{{ alternativaActiva.moneda_cotizacion }} {{ totalLocal.toFixed(2) }}</span>
                        </div>

                        <div class="mt-3 d-flex flex-column gap-2">
                            <template v-if="alternativaActiva.estado !== 'aceptada'">
                                <button class="btn btn-success btn-sm w-100" @click="marcarAceptada" :disabled="(alternativaActiva.items?.length ?? 0) === 0">
                                    <i class="fas fa-check me-1"></i>Aceptado por cliente
                                </button>
                                <small v-if="(alternativaActiva.items?.length ?? 0) === 0" class="text-muted d-block text-center">
                                    Agregá al menos un ítem para poder aceptar
                                </small>
                            </template>

                            <button class="btn btn-outline-primary btn-sm w-100" @click="duplicarAlternativa"
                                :disabled="duplicando || alcanzoLimiteAlternativas" :title="alcanzoLimiteAlternativas ? 'Máximo 5 alternativas por cotización' : ''">
                                <span v-if="duplicando" class="spinner-border spinner-border-sm me-1"></span>
                                <i v-else class="fas fa-copy me-1"></i>Duplicar alternativa
                            </button>

                            <div class="d-flex gap-2">
                                <button class="btn btn-outline-secondary btn-sm flex-fill" disabled title="Disponible cuando exista el generador de PDF">
                                    <i class="fas fa-file-pdf me-1"></i>Ver PDF
                                </button>
                                <button class="btn btn-outline-secondary btn-sm flex-fill" disabled title="Disponible cuando exista el generador de PDF">
                                    <i class="fas fa-paper-plane me-1"></i>Enviar
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-else-if="cotizacion" class="text-center text-muted py-5">
            Esta cotización todavía no tiene alternativas — creá la primera con el botón "+ Nueva" de arriba.
        </div>

        <!-- ═══ DRAWER — biblioteca / comparador de mayoristas (Punto A) ═══ -->
        <Teleport to="body">
            <div v-if="drawerBibliotecaAbierto" class="drawer-overlay" @click.self="cerrarDrawerBiblioteca">
                <div class="drawer-panel">
                    <div class="drawer-header d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center gap-2">
                            <i v-if="pasoDrawer !== 'grid'" class="fas fa-arrow-left" style="cursor:pointer" title="Volver" @click="volverAGridDrawer"></i>
                            <strong>{{ tituloDrawer }}</strong>
                        </div>
                        <i class="fas fa-times" style="cursor:pointer" @click="cerrarDrawerBiblioteca"></i>
                    </div>
                    <div class="drawer-body">
                        <template v-if="pasoDrawer === 'grid'">
                            <div class="d-flex gap-1 mb-2">
                                <button class="btn btn-sm flex-fill" :class="modo === 'local' ? 'btn-primary' : 'btn-outline-secondary'" @click="modo = 'local'">Local / Nacional</button>
                                <button class="btn btn-sm flex-fill" :class="modo === 'intl' ? 'btn-primary' : 'btn-outline-secondary'" @click="modo = 'intl'">Internacional</button>
                            </div>

                            <!-- Biblioteca local -->
                            <template v-if="modo === 'local'">
                                <div class="d-flex flex-wrap align-items-center gap-1 mb-2">
                                    <span v-for="chip in chipsFijos" :key="chip.proveedorTipoId ?? chip.tipo"
                                        class="badge rounded-pill px-2 py-1" style="cursor:pointer;font-weight:500;"
                                        :class="chipActivo(chip) ? 'bg-primary' : 'bg-light text-dark border'"
                                        @click="seleccionarChip(chip)">
                                        <i class="fas me-1" :class="chip.icono"></i>{{ chip.nombre }}
                                    </span>
                                    <div class="position-relative">
                                        <span class="badge rounded-pill px-2 py-1" style="cursor:pointer;font-weight:500;"
                                            :class="chipMasActivo ? 'bg-primary' : 'bg-light text-dark border'"
                                            @click="mostrarMasChips = !mostrarMasChips">
                                            <i class="fas fa-ellipsis-h me-1"></i>{{ chipMasActivo ? chipMasActivo.nombre : 'Más' }} <i class="fas fa-caret-down ms-1"></i>
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

                                <div class="biblioteca-grid">
                                    <div v-for="fila in filasBibliotecaPagina" :key="fila.kind + '-' + fila.data.id"
                                        class="border rounded p-2 small lib-item" style="cursor:pointer"
                                        @click="onClicFilaBiblioteca(fila)">
                                        <template v-if="fila.kind === 'proveedor'">
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
                                <button class="btn btn-outline-secondary btn-sm w-100 mt-2" @click="mostrarFormManual = true"><i class="fas fa-plus me-1"></i>Ítem manual</button>
                            </template>

                            <!-- Comparador de mayoristas -->
                            <div v-else class="d-flex flex-column gap-2">
                                <div v-for="op in opcionesMayorista" :key="op.id" class="card border p-2 small mayorista-card"
                                    :class="{ 'border-primary border-2': op.estado === 'elegida' }">
                                    <strong>{{ op.proveedor?.nombre_comercial ?? op.proveedor?.razon_social }}</strong>
                                    <div class="text-muted" v-if="op.vuelo_aerolinea"><i class="fas fa-plane me-1"></i>{{ op.vuelo_aerolinea }}</div>
                                    <div class="text-muted mb-1" v-if="op.incluye">{{ op.incluye }}</div>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="badge" :class="op.estado === 'elegida' ? 'bg-primary' : 'bg-light text-dark border'">{{ op.estado }}</span>
                                        <div class="d-flex gap-1">
                                            <button class="btn btn-sm btn-outline-primary" @click="verHoteles(op)">Hoteles</button>
                                            <button v-if="op.estado !== 'elegida'" class="btn btn-sm btn-outline-success" @click="elegirOpcion(op)">Elegir</button>
                                        </div>
                                    </div>
                                    <div v-if="opcionHotelesActivaId === op.id" class="mt-2 border-top pt-2">
                                        <HabitacionMatrixPicker v-if="op.estado === 'elegida'"
                                            :tarifas="tarifasHotelPlanas(op)" :moneda="op.moneda"
                                            @seleccionar="({ id, cantidad }) => agregarItemMayorista(op, id, cantidad)" />
                                        <div v-else class="text-muted small fst-italic">Marcá esta opción como elegida para poder agregar una habitación.</div>
                                        <button class="btn btn-sm btn-outline-secondary w-100 mt-2" @click="mostrarFormHotel = op.id">
                                            <i class="fas fa-plus me-1"></i>Agregar hotel a esta opción
                                        </button>
                                        <div v-if="mostrarFormHotel === op.id" class="border rounded p-2 mt-2">
                                            <label class="form-label mb-1 small text-secondary">Nombre del hotel</label>
                                            <input type="text" class="form-control form-control-sm mb-1" placeholder="Nombre del hotel" v-model="formHotel.nombre_hotel">
                                            <label class="form-label mb-1 small text-secondary">Proveedor</label>
                                            <select class="form-select form-select-sm mb-1" v-model="formHotel.proveedor_id" @change="onCambiarProveedorHotel">
                                                <option :value="null">Hotel manual/referencial (sin proveedor)</option>
                                                <option v-for="p in proveedoresHotel" :key="p.id" :value="p.id">{{ p.nombre_comercial ?? p.razon_social }}</option>
                                            </select>
                                            <!-- Encabezados de columna — cumplen el rol de label para cada
                                                 fila de tarifas sin repetir el texto en cada una. -->
                                            <div class="row g-1 mb-1">
                                                <div class="col-3"><label class="form-label mb-0 small text-secondary">Tipo de habitación</label></div>
                                                <div class="col-3" v-if="formHotel.proveedor_id"><label class="form-label mb-0 small text-secondary">Tarifa registrada</label></div>
                                                <div :class="formHotel.proveedor_id ? 'col-3' : 'col-4'"><label class="form-label mb-0 small text-secondary">Costo</label></div>
                                                <div :class="formHotel.proveedor_id ? 'col-3' : 'col-4'"><label class="form-label mb-0 small text-secondary">Venta</label></div>
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
                                            <button class="btn btn-sm btn-primary w-100" @click="guardarHotel(op)">Guardar hotel</button>
                                        </div>
                                    </div>
                                </div>
                                <button class="btn btn-outline-primary btn-sm w-100" @click="mostrarFormMayorista = true">
                                    <i class="fas fa-plus me-1"></i>Agregar cotización de mayorista
                                </button>
                                <div v-if="mostrarFormMayorista" class="card border p-2 small">
                                    <select class="form-select form-select-sm mb-1" v-model="formMayorista.proveedor_id">
                                        <option :value="null">— Proveedor mayorista —</option>
                                        <option v-for="p in proveedoresMayoristas" :key="p.id" :value="p.id">{{ p.nombre_comercial ?? p.razon_social }}</option>
                                    </select>
                                    <select class="form-select form-select-sm mb-1" v-model="formMayorista.moneda">
                                        <option value="USD">USD</option>
                                        <option value="PEN">PEN</option>
                                    </select>
                                    <input type="text" class="form-control form-control-sm mb-1" placeholder="Vuelo (aerolínea)" v-model="formMayorista.vuelo_aerolinea">
                                    <textarea class="form-control form-control-sm mb-1" rows="2" placeholder="Incluye..." v-model="formMayorista.incluye"></textarea>
                                    <button class="btn btn-primary btn-sm w-100" @click="guardarOpcionMayorista">Guardar</button>
                                </div>
                            </div>
                        </template>

                        <template v-else-if="pasoDrawer === 'matrizHotel' && matrizHotelActiva">
                            <p class="small fw-semibold mb-2">{{ matrizHotelActiva.nombreProveedor }}</p>
                            <HabitacionMatrixPicker :tarifas="matrizHotelActiva.tarifas" :moneda="matrizHotelActiva.moneda"
                                @seleccionar="({ id, cantidad }) => agregarItemProveedorHotel(id, cantidad)" />
                        </template>

                        <template v-else-if="pasoDrawer === 'modoPrecio' && modoPrecioPendiente">
                            <p class="fw-semibold mb-2 small">¿Cómo se cobra "{{ modoPrecioPendiente.nombre }}"?</p>
                            <div class="d-flex gap-2 mb-2">
                                <button class="btn btn-sm btn-outline-primary flex-fill" @click="confirmarModoPrecio('tarifa_fija')">Tarifa fija (total)</button>
                                <button class="btn btn-sm btn-outline-primary flex-fill" @click="confirmarModoPrecio('por_persona')">Por persona (adulto/niño/infante)</button>
                            </div>
                        </template>

                        <template v-else-if="pasoDrawer === 'manual' && alternativaActiva">
                            <ItemManualForm :alternativa-id="alternativaActiva.id" :dia-activo="diaActivoParaAgregar" @agregado="onServicioSueltoAgregado" />
                        </template>
                    </div>
                </div>
            </div>
        </Teleport>
    </DefaultLayout>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import httpClient from '@/helpers/http-client';
import HabitacionMatrixPicker from '@/components/AgenciaViajes/HabitacionMatrixPicker.vue';
import PasajeAereoForm from '@/components/AgenciaViajes/PasajeAereoForm.vue';
import ItemManualForm from '@/components/AgenciaViajes/ItemManualForm.vue';
import ClientFormQuick from '@/components/Sales/ClientFormQuick.vue';
import { useToast } from '@/composables/useToast';
import { cotizacionService } from '@/services/admin/cotizacionService';
import { alternativaService } from '@/services/admin/alternativaService';
import { alternativaItemService } from '@/services/admin/alternativaItemService';
import { opcionMayoristaService } from '@/services/admin/opcionMayoristaService';
import { proveedorService, proveedorTipoService } from '@/services/admin/proveedorService';
import { bibliotecaCotizadorService, type BibliotecaTipo } from '@/services/admin/bibliotecaCotizadorService';
import { reservaService } from '@/services/admin/reservaService';
import { configuracionAgenciaService } from '@/services/admin/configuracionAgenciaService';
import { formatFecha } from '@/helpers/fecha';
import type { Cotizacion, Alternativa, AlternativaItem, ProveedorTarifa, OpcionMayorista, OpcionHotel, Proveedor, ProveedorTipo, BibliotecaResultado, ConfiguracionAgencia, DestinoServicio } from '@/types/agencia-viajes';
import type { Client } from '@/types/clients';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const route = useRoute();
const router = useRouter();
const cotizacionId = Number(route.params.id);
const toast = useToast();

const cotizacion = ref<Cotizacion | null>(null);
const alternativaActivaId = ref<number | null>(null);
const modo = ref<'local' | 'intl'>('local');

// Sesión 11i — descuento configurable por agencia (Puntos B/C). Se carga
// una sola vez al montar (singleton por tenant, no cambia mientras el
// vendedor está cotizando) y gobierna qué input(s) se muestran en el
// lienzo y en el resumen.
const configAgencia = ref<ConfiguracionAgencia | null>(null);

const alternativaActiva = computed<Alternativa | null>(() =>
    cotizacion.value?.alternativas?.find((a) => a.id === alternativaActivaId.value) ?? null
);

// Punto F — el "+ Nueva" y "Duplicar alternativa" comparten el mismo tope.
const alcanzoLimiteAlternativas = computed(() => (cotizacion.value?.alternativas?.length ?? 0) >= 5);

const resumenPax = computed(() => {
    const pax = cotizacion.value?.pasajeros ?? [];
    const counts: Record<string, number> = {};
    pax.forEach((p) => { counts[p.tipo_pax] = (counts[p.tipo_pax] ?? 0) + 1; });
    return Object.entries(counts).map(([t, n]) => `${n} ${t}`).join(', ') || 'sin pasajeros';
});

// Antes: cada lado se formateaba por separado ("sin fecha — sin fecha" con
// ambas vacías, o peor si alguna venía como string vacío en vez de null).
// Un único mensaje cuando falta cualquiera de las dos fechas es más claro
// que dos placeholders pegados con un guion.
const textoFechasViaje = computed(() => {
    const desde = cotizacion.value?.fecha_viaje_desde;
    const hasta = cotizacion.value?.fecha_viaje_hasta;
    if (!desde || !hasta) return 'Fecha por confirmar';
    return `${formatFecha(desde)} — ${formatFecha(hasta)}`;
});

// ── Corregir cliente/destino/fecha (me equivoqué al crear la cotización) ──
const editandoCabecera = ref(false);
const formCabecera = ref({ destino: '', fecha_viaje_desde: '' as string | null, fecha_viaje_hasta: '' as string | null });
const clienteEditado = ref<Client | null>(null);
const guardandoCabecera = ref(false);

const clientSearchText = ref('');
const clientSuggestions = ref<Client[]>([]);
const showClientSuggestions = ref(false);
const showQuickClientModal = ref(false);
let clientSearchTimeout: any = null;

const abrirEdicionCabecera = () => {
    if (!cotizacion.value) return;
    formCabecera.value = {
        destino: cotizacion.value.destino,
        fecha_viaje_desde: cotizacion.value.fecha_viaje_desde ?? '',
        fecha_viaje_hasta: cotizacion.value.fecha_viaje_hasta ?? '',
    };
    clienteEditado.value = cotizacion.value.cliente
        ? { id: cotizacion.value.cliente.id, full_name: cotizacion.value.cliente.full_name, n_document: cotizacion.value.cliente.n_document } as Client
        : null;
    clientSearchText.value = cotizacion.value.cliente?.full_name ?? '';
    editandoCabecera.value = true;
};

const onClientSearchInput = () => {
    clearTimeout(clientSearchTimeout);
    clientSearchTimeout = setTimeout(buscarClientesEditar, 300);
};

const buscarClientesEditar = async () => {
    if (clientSearchText.value.trim().length < 2) { clientSuggestions.value = []; return; }
    const res = await httpClient.get('/clients', { params: { search: clientSearchText.value } });
    // ClientController::index() envuelve el listado en ClientCollection
    // ({ data: [...] }) — clients.data, no clients directo.
    clientSuggestions.value = res.data.clients?.data ?? [];
};

const seleccionarClienteEditado = (c: Client) => {
    clienteEditado.value = c;
    clientSearchText.value = c.full_name;
    showClientSuggestions.value = false;
};

const limpiarClienteEditado = () => {
    clienteEditado.value = null;
    clientSearchText.value = '';
};

const onClientSearchBlur = () => { setTimeout(() => { showClientSuggestions.value = false; }, 150); };

const onClientCreated = (client: Client) => {
    seleccionarClienteEditado(client);
    showQuickClientModal.value = false;
};

const guardarCabecera = async () => {
    if (!cotizacion.value) return;
    if (!clienteEditado.value) { (Swal as TVueSwalInstance).fire('Error', 'Seleccioná un cliente.', 'error'); return; }
    if (!formCabecera.value.destino.trim()) { (Swal as TVueSwalInstance).fire('Error', 'Ingresá un destino.', 'error'); return; }

    guardandoCabecera.value = true;
    try {
        await cotizacionService.actualizar(cotizacion.value.id, {
            cliente_id: clienteEditado.value.id,
            destino: formCabecera.value.destino.trim(),
            fecha_viaje_desde: formCabecera.value.fecha_viaje_desde || null,
            fecha_viaje_hasta: formCabecera.value.fecha_viaje_hasta || null,
        });
        editandoCabecera.value = false;
        await cargarCotizacion();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo actualizar la cotización', 'error');
    } finally {
        guardandoCabecera.value = false;
    }
};

const cargarCotizacion = async () => {
    const res = await cotizacionService.obtener(cotizacionId);
    cotizacion.value = res.cotizacion;
    if (!alternativaActivaId.value && cotizacion.value.alternativas?.length) {
        alternativaActivaId.value = cotizacion.value.alternativas[0].id;
    }
    inicializarEdicionItems();
    inicializarDias();
};

const seleccionarAlternativa = (id: number) => {
    alternativaActivaId.value = id;
    if (modo.value === 'intl') cargarOpcionesMayorista();
};

// ── Nueva alternativa ─────────────────────────────────────────────────
const mostrarFormAlternativa = ref(false);
const formAlternativa = ref({ nombre: '', moneda_cotizacion: 'PEN' as 'PEN' | 'USD', tipo_cambio_origen: 'dia' as 'dia' | 'agencia', tipo_cambio_valor: null as number | null });

const crearAlternativa = async () => {
    try {
        const res = await alternativaService.crear(cotizacionId, formAlternativa.value);
        mostrarFormAlternativa.value = false;
        await cargarCotizacion();
        alternativaActivaId.value = res.alternativa.id;
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo crear', 'error');
    }
};

// Sesión 11h — clona la alternativa activa completa (ver AlternativaController::
// duplicar()) en una alternativa nueva de la misma cotización, y salta a ella.
const duplicando = ref(false);
const duplicarAlternativa = async () => {
    if (!alternativaActiva.value) return;
    duplicando.value = true;
    try {
        const res = await alternativaService.duplicar(alternativaActiva.value.id);
        await cargarCotizacion();
        alternativaActivaId.value = res.alternativa.id;
        toast.success('Alternativa duplicada');
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo duplicar la alternativa', 'error');
    } finally {
        duplicando.value = false;
    }
};

// Sesión 11c: aceptar ya no solo cambia el estado (AlternativaController::
// update()) — dispara la creación real de la Reserva
// (ReservaController::aceptar()) y redirige a su pantalla de detalle, en
// vez de quedarse en el cotizador.
const marcarAceptada = async () => {
    if (!alternativaActiva.value) return;
    try {
        const res = await reservaService.aceptarAlternativa(alternativaActiva.value.id);
        if (res.alerta_cupo_excedido) {
            await (Swal as TVueSwalInstance).fire('Cupo excedido', 'La salida de mayorista elegida ya superó su cupo total — la reserva se creó igual, es solo un aviso.', 'warning');
        }
        router.push(`/agencia-viajes/reservas/${res.reserva.id}`);
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo aceptar la alternativa', 'error');
    }
};

// Antes de esta sesión no había try/catch acá — si el backend rechazaba el
// borrado (ya generó una reserva) o si tenía ítems (ahora se cascadea, ver
// AlternativaController::destroy()), un error sin capturar dejaba el botón
// "sin hacer nada" a los ojos del usuario. Ahora: confirmación previa
// (borra ítems junto con la alternativa) + error visible si el backend
// rechaza el borrado.
const eliminarAlternativa = async () => {
    if (!alternativaActiva.value) return;

    const tieneItems = (alternativaActiva.value.items?.length ?? 0) > 0;
    const confirmacion = await (Swal as TVueSwalInstance).fire({
        title: '¿Eliminar esta alternativa?',
        text: tieneItems ? 'Tiene ítems agregados — se eliminarán junto con la alternativa.' : 'Esta acción no se puede deshacer.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar',
    });
    if (!confirmacion.isConfirmed) return;

    try {
        await alternativaService.eliminar(alternativaActiva.value.id);
        alternativaActivaId.value = null;
        await cargarCotizacion();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo eliminar la alternativa', 'error');
    }
};

// ── Lienzo día-por-día (Sesión 11b3/11h, §7.1) ─────────────────────────
// diaActivo=0 es el sentinel "Sin día" (bucket de solo-lectura para ítems
// creados antes de esta sesión, dia_referencial=null) — NO es un día real,
// nunca se manda al backend como destino (reasignarDia/desde-plantilla
// exigen min:1). diaActivoParaAgregar resuelve ese caso a un día real.
const diaActivo = ref(1);
const diasCreados = ref<number[]>([1]);

const inicializarDias = () => {
    const items = alternativaActiva.value?.items ?? [];
    const maxDia = items.reduce((max, it) => Math.max(max, it.dia_referencial ?? 0), 1);
    diasCreados.value = Array.from({ length: maxDia }, (_, i) => i + 1);
    if (!diasCreados.value.includes(diaActivo.value) && diaActivo.value !== 0) {
        diaActivo.value = diasCreados.value[0] ?? 1;
    }
};

const diaSiguiente = computed(() => (diasCreados.value.length ? Math.max(...diasCreados.value) : 0) + 1);
const diaActivoParaAgregar = computed(() => (diaActivo.value === 0 ? (diasCreados.value[0] ?? 1) : diaActivo.value));

// Sesión 11h — fix incidental encontrado al construir el "+ Agregar otro
// día" del modo simple (Punto B): diaSiguiente.value se leía DOS veces, y
// la segunda lectura ya veía el push recién hecho (diasCreados invalida el
// computed apenas se muta el array reactivo), así que diaActivo terminaba
// apuntando a un día que no existía en diasCreados (ej. push(2) pero
// diaActivo=3). Capturar el valor una sola vez antes de mutar lo corrige.
const agregarDia = () => {
    const nuevoDia = diaSiguiente.value;
    diasCreados.value.push(nuevoDia);
    diaActivo.value = nuevoDia;
};

const itemsSinDia = computed(() => (alternativaActiva.value?.items ?? []).filter((i) => i.dia_referencial == null));

// Punto B — con un solo día real y sin ítems legado "sin día", las pestañas
// no aportan nada (todo cae en el mismo bucket) y se ocultan del todo.
const mostrarTabsDia = computed(() => diasCreados.value.length > 1 || itemsSinDia.value.length > 0);

const itemsDelDiaActivo = computed(() => {
    const items = alternativaActiva.value?.items ?? [];
    return diaActivo.value === 0
        ? items.filter((i) => i.dia_referencial == null)
        : items.filter((i) => i.dia_referencial === diaActivo.value);
});

// Sin pestañas (modo simple), el lienzo muestra TODOS los ítems de la
// alternativa de una — no tiene sentido filtrar por día cuando no hay
// navegación de día visible.
const itemsVisiblesLienzo = computed(() => (mostrarTabsDia.value ? itemsDelDiaActivo.value : (alternativaActiva.value?.items ?? [])));

// Agrupa por tour_origen_id (Sesión 11b4a) — mismo patrón visual que
// paquetes/detalle.vue::itemsPorTourAgrupados. Los ítems sin tour_origen_id
// ("sueltos") van en un bloque final sin encabezado. Compartido por el
// lienzo (solo el día activo, o todo en modo simple) y por el panel de
// precio (siempre TODOS los días) — un solo lugar que arma el agrupamiento.
type BloqueItem = { tourOrigenId: number | null; tourNombre: string | null; items: AlternativaItem[] };

const agruparPorTour = (items: AlternativaItem[]): BloqueItem[] => {
    const bloques = new Map<number, BloqueItem>();
    const sueltos: AlternativaItem[] = [];

    for (const item of items) {
        if (item.tour_origen_id) {
            if (!bloques.has(item.tour_origen_id)) {
                bloques.set(item.tour_origen_id, { tourOrigenId: item.tour_origen_id, tourNombre: item.tour_origen?.nombre ?? null, items: [] });
            }
            bloques.get(item.tour_origen_id)!.items.push(item);
        } else {
            sueltos.push(item);
        }
    }

    const resultado = Array.from(bloques.values());
    if (sueltos.length) resultado.push({ tourOrigenId: null, tourNombre: null, items: sueltos });

    return resultado;
};

const bloquesLienzo = computed<BloqueItem[]>(() => agruparPorTour(itemsVisiblesLienzo.value));
const gruposPrecioPanel = computed<BloqueItem[]>(() => agruparPorTour(alternativaActiva.value?.items ?? []));

const onReasignarDiaItem = async (item: AlternativaItem, event: Event) => {
    const valor = Number((event.target as HTMLSelectElement).value);
    if (!valor) return;
    try {
        await alternativaItemService.reasignarDia(item.id, valor);
        await cargarCotizacion();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo mover el ítem', 'error');
    }
};

const onMoverBloque = async (bloque: BloqueItem, event: Event) => {
    const valor = Number((event.target as HTMLSelectElement).value);
    if (!bloque.tourOrigenId || !alternativaActiva.value || !valor || valor === diaActivo.value) return;
    try {
        await alternativaItemService.moverBloque(alternativaActiva.value.id, { tour_origen_id: bloque.tourOrigenId, dia_referencial: valor });
        await cargarCotizacion();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo mover el bloque', 'error');
    }
};

// Punto D — elimina TODOS los ítems del bloque (mismo tour_origen_id). Sin
// endpoint de borrado masivo en el backend — se dispara un DELETE por ítem
// (mismo criterio ya usado en el resto del proyecto de resolver en el
// frontend cuando alcanza, ver CLAUDE.md "Frontend-first").
const eliminarBloque = async (bloque: BloqueItem) => {
    if (!bloque.tourOrigenId) return;

    const confirmacion = await (Swal as TVueSwalInstance).fire({
        title: `¿Eliminar "${bloque.tourNombre ?? 'este tour'}"?`,
        text: `Se eliminarán sus ${bloque.items.length} ítem(s).`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar',
    });
    if (!confirmacion.isConfirmed) return;

    try {
        await Promise.all(bloque.items.map((item) => alternativaItemService.eliminar(item.id)));
        toast.success('Tour eliminado');
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudieron eliminar todos los ítems del bloque', 'error');
    } finally {
        await cargarCotizacion();
    }
};

// ── Biblioteca unificada (Sesión 11b3, §7.1) — un único endpoint decide
// contra qué tabla(s) consulta según el chip activo, ver
// BibliotecaCotizadorController en el backend. "Todos"/"Tour"/"Paquete" son
// fijos; el resto de chips sale del catálogo real de proveedor_tipos (NO
// una lista hardcodeada — ese catálogo cambia por tenant/sesión).
const proveedorTipos = ref<ProveedorTipo[]>([]);

type ChipBiblioteca = { tipo: BibliotecaTipo; proveedorTipoId: number | null; nombre: string; icono: string };

// Sesión 11j — orden fijo confirmado con el usuario: Todos/Tours/Paquetes +
// los 4 proveedor_tipos de mayor uso (identificados por slug, no por id —
// el id cambia por tenant/catálogo). El resto del catálogo (Guía,
// Atractivos, Operador Turismo, Agencia Mayorista, y cualquier
// proveedor_tipo nuevo) sigue cayendo en "Más ▾".
const SLUGS_CHIPS_FIJOS_PROVEEDOR: Array<{ slug: string; nombre: string; icono: string }> = [
    { slug: 'alojamiento-hoteles', nombre: 'Alojamiento', icono: 'fa-bed' },
    { slug: 'transporte', nombre: 'Transporte', icono: 'fa-bus' },
    { slug: 'alimentacion', nombre: 'Alimentación', icono: 'fa-utensils' },
    { slug: 'actividades', nombre: 'Actividades', icono: 'fa-hiking' },
];

const chipsFijos = computed<ChipBiblioteca[]>(() => [
    { tipo: 'todos', proveedorTipoId: null, nombre: 'Todos', icono: 'fa-th' },
    { tipo: 'tour', proveedorTipoId: null, nombre: 'Tours', icono: 'fa-route' },
    { tipo: 'paquete', proveedorTipoId: null, nombre: 'Paquetes', icono: 'fa-suitcase-rolling' },
    ...SLUGS_CHIPS_FIJOS_PROVEEDOR.flatMap((def) => {
        const tipo = proveedorTipos.value.find((t) => t.slug === def.slug);
        return tipo ? [{ tipo: 'proveedor' as BibliotecaTipo, proveedorTipoId: tipo.id, nombre: def.nombre, icono: def.icono }] : [];
    }),
]);

// "Más ▾" — cola larga: todo proveedor_tipo que no tenga ya su chip fijo arriba.
const chipsProveedores = computed<ChipBiblioteca[]>(() => {
    const slugsFijos = new Set(SLUGS_CHIPS_FIJOS_PROVEEDOR.map((d) => d.slug));
    return proveedorTipos.value
        .filter((t) => !slugsFijos.has(t.slug))
        .map((t) => ({ tipo: 'proveedor' as BibliotecaTipo, proveedorTipoId: t.id, nombre: t.nombre, icono: 'fa-ellipsis-h' }));
});

const mostrarMasChips = ref(false);
const chipMasActivo = computed(() => chipsProveedores.value.find((c) => chipActivo(c)) ?? null);

const chipActivoState = ref<ChipBiblioteca>({ tipo: 'todos', proveedorTipoId: null, nombre: 'Todos' });
const chipActivo = (chip: ChipBiblioteca) => chip.tipo === chipActivoState.value.tipo && chip.proveedorTipoId === chipActivoState.value.proveedorTipoId;

const seleccionarChip = (chip: ChipBiblioteca) => {
    chipActivoState.value = chip;
    cargarBiblioteca();
};

const bibliotecaSearch = ref('');
const bibliotecaResultados = ref<BibliotecaResultado[]>([]);
let bibliotecaTimeout: any = null;

const cargarBiblioteca = async () => {
    const res = await bibliotecaCotizadorService.buscar({
        tipo: chipActivoState.value.tipo,
        proveedor_tipo_id: chipActivoState.value.proveedorTipoId ?? undefined,
        search: bibliotecaSearch.value || undefined,
    });
    bibliotecaResultados.value = res.resultados;
    bibliotecaPaginaActual.value = 1;
};

const onBibliotecaSearch = () => {
    clearTimeout(bibliotecaTimeout);
    bibliotecaTimeout = setTimeout(cargarBiblioteca, 300);
};

const etiquetaCategoriaPaquete = (c: string) => ({ local: 'Local', nacional: 'Nacional', internacional: 'Internacional' } as Record<string, string>)[c] ?? c;

// Mismo problema y misma solución que paquetes/detalle.vue
// (descripcionDestinoServicio) — duplicada a propósito acá, no hay
// composable compartido para algo tan chico (mismo criterio que
// BibliotecaCotizadorController::buscarProveedorTarifas() ya usa contra
// ProveedorTarifaController::biblioteca()). destino_atractivo puede venir
// null (no todo servicio está atado a un destino) — en ese caso solo el
// nombre del servicio, sin romper el render.
const descripcionDestinoServicio = (ds?: DestinoServicio) => {
    const destino = ds?.destino_atractivo?.nombre;
    const servicio = ds?.servicio?.nombre ?? '';
    return destino ? `${destino} · ${servicio}` : servicio;
};

const bibliotecaToursPaquetes = computed(() =>
    bibliotecaResultados.value.filter((r) => r.tipo_resultado === 'tour' || r.tipo_resultado === 'paquete') as Array<
        Extract<BibliotecaResultado, { tipo_resultado: 'tour' | 'paquete' }>
    >
);

// Agrupa SOLO las tarifas de hotel (con tipo_habitacion) por proveedor_servicio_id —
// un hotel con 4 tipos de habitación aparecía 4 veces en la biblioteca con el mismo
// nombre de proveedor. Se queda con la fila de menor precio_venta_adulto como
// representativa (clicBibliotecaItem ya sabe pedir la matriz completa de ese
// proveedor_servicio_id, no hace falta tocarla). Tarifas sin tipo_habitacion (tour,
// transporte, restaurante, etc.) se listan una por una, igual que antes.
type BibliotecaFila = ProveedorTarifa & { _rangoHabitaciones?: boolean };

const bibliotecaProveedorAgrupada = computed<BibliotecaFila[]>(() => {
    const tarifas = bibliotecaResultados.value.filter((r) => r.tipo_resultado === 'proveedor_tarifa') as ProveedorTarifa[];

    const gruposPorServicio = new Map<number, ProveedorTarifa[]>();
    for (const t of tarifas) {
        if (!t.tipo_habitacion) continue;
        const grupo = gruposPorServicio.get(t.proveedor_servicio_id) ?? [];
        grupo.push(t);
        gruposPorServicio.set(t.proveedor_servicio_id, grupo);
    }

    const vistos = new Set<number>();
    const filas: BibliotecaFila[] = [];

    for (const t of tarifas) {
        if (!t.tipo_habitacion) {
            filas.push(t);
            continue;
        }
        if (vistos.has(t.proveedor_servicio_id)) continue;
        vistos.add(t.proveedor_servicio_id);

        const grupo = gruposPorServicio.get(t.proveedor_servicio_id)!;
        const representativa = grupo.reduce((min, cur) =>
            Number(cur.precio_venta_adulto) < Number(min.precio_venta_adulto) ? cur : min
        );
        filas.push({ ...representativa, _rangoHabitaciones: grupo.length > 1 });
    }

    return filas;
});

// Punto A — grilla unificada (proveedor_tarifa + tour/paquete) con
// paginación 100% en el cliente: el endpoint ya trae como mucho ~150 filas
// (limit 50/100 en BibliotecaCotizadorController), así que paginar acá
// evita tocar el backend para algo puramente de presentación (mismo
// criterio "frontend-first" del proyecto).
type FilaBibliotecaProveedor = { kind: 'proveedor'; data: BibliotecaFila };
type FilaBibliotecaPlantilla = { kind: 'plantilla'; data: Extract<BibliotecaResultado, { tipo_resultado: 'tour' | 'paquete' }> };
type FilaBiblioteca = FilaBibliotecaProveedor | FilaBibliotecaPlantilla;

const filasBibliotecaUnificadas = computed<FilaBiblioteca[]>(() => [
    ...bibliotecaProveedorAgrupada.value.map((data): FilaBiblioteca => ({ kind: 'proveedor', data })),
    ...bibliotecaToursPaquetes.value.map((data): FilaBiblioteca => ({ kind: 'plantilla', data })),
]);

const BIBLIOTECA_POR_PAGINA = 12; // grilla de 3 columnas × 4 filas
const bibliotecaPaginaActual = ref(1);
const bibliotecaTotalPaginas = computed(() => Math.max(1, Math.ceil(filasBibliotecaUnificadas.value.length / BIBLIOTECA_POR_PAGINA)));
const filasBibliotecaPagina = computed(() => {
    const inicio = (bibliotecaPaginaActual.value - 1) * BIBLIOTECA_POR_PAGINA;
    return filasBibliotecaUnificadas.value.slice(inicio, inicio + BIBLIOTECA_POR_PAGINA);
});

const onClicFilaBiblioteca = (fila: FilaBiblioteca) => {
    if (fila.kind === 'proveedor') clicBibliotecaItem(fila.data);
    else clicResultadoPlantilla(fila.data);
};

// Punto C — franja de vista previa cuando el tour/paquete recién cargado
// ocupa más de un día (solo paquete_combo puede — un tour_simple siempre
// cae entero en el día activo, ver AlternativaItemController::desdePlantilla()).
const vistaPreviaCombo = ref<Array<{ tourOrigenId: number; dia: number; nombre: string; total: number }> | null>(null);

// Sesión 11k — hoteles del tour/paquete recién cargado, devueltos por
// desdePlantilla() sin auto-agregarse (ver hoteles_disponibles). diaCarga
// es el día usado en la carga (para un tour_simple, TODOS sus ítems caen
// ahí — mismo criterio que ComboExplosionService/desdePlantilla() ya usan,
// sin offset), snapshoteado porque el vendedor puede cambiar de día activo
// mientras el banner sigue abierto.
const hotelesPlantillaPendientes = ref<OpcionHotel[]>([]);
const hotelPlantillaActivoId = ref<number | null>(null);
const diaCargaHotelesPendientes = ref<number>(1);

// Click en una tarjeta de tour/paquete — explota TODOS sus ítems en la
// alternativa activa (AlternativaItemController::desdePlantilla()). Sin
// modal de confirmación intermedio: el badge de cantidad de ítems, visible
// ANTES del click en la tarjeta, ya es el requisito explícito de la spec
// para que el vendedor anticipe cuántas líneas va a inyectar. Punto A: a
// diferencia de un ítem suelto, esto SÍ cierra el drawer — el vendedor debe
// ver el resultado (lienzo + vista previa) antes de seguir agregando.
const clicResultadoPlantilla = async (p: Extract<BibliotecaResultado, { tipo_resultado: 'tour' | 'paquete' }>) => {
    if (!alternativaActiva.value) return;
    try {
        const res = await alternativaItemService.cargarDesdePlantilla(alternativaActiva.value.id, {
            paquete_plantilla_id: p.id,
            dia_referencial: diaActivoParaAgregar.value,
        });
        if (res.guias_pendientes.length) {
            const lista = res.guias_pendientes
                .map((g) => `<li>${g.guia_nombre ?? 'Guía'} — ${g.tour_origen_nombre ?? ''}${g.destino_nombre ? ` (${g.destino_nombre})` : ''}</li>`)
                .join('');
            await (Swal as TVueSwalInstance).fire({
                title: 'Ítems de guía no cargados',
                html: `<p class="text-start mb-1">Sin equivalente automático en la cotización — se asignan recién al reservar:</p><ul class="text-start">${lista}</ul>`,
                icon: 'info',
            });
        }

        const idsTourOrigen = new Set(res.items_agregados.map((i) => i.tour_origen_id).filter((id): id is number => id != null));

        diaCargaHotelesPendientes.value = diaActivoParaAgregar.value;
        hotelesPlantillaPendientes.value = res.hoteles_disponibles ?? [];
        hotelPlantillaActivoId.value = null;

        await cargarCotizacion();
        drawerBibliotecaAbierto.value = false;
        volverAGridDrawer();

        vistaPreviaCombo.value = calcularVistaPreviaCombo(idsTourOrigen);
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo cargar la plantilla', 'error');
    }
};

// Habitación por hotel de plantilla — mismo shape que tarifasHotelPlanas()
// (mayorista) pero SIN prefijo de nombre_hotel (el header de la tarjeta ya
// lo muestra).
const tarifasHotelPlantillaPlanas = (hotel: OpcionHotel) => {
    return (hotel.opciones_hotel_tarifas ?? []).map((t) => ({ id: t.id, tipo_habitacion: t.tipo_habitacion, precio: Number(t.precio_venta), registrada: !!t.proveedor_tarifa_id }));
};

// tour_origen_id/día: si el tour dueño de este hotel ya generó algún ítem
// en el lienzo (explotarTourSimple() siempre tagea tour_origen_id, incluso
// para un tour_simple suelto), el hotel se une al mismo bloque y hereda su
// día real — si no (hotel a nivel del combo, sin bloque propio, o un
// tour_simple que solo tenía el hotel y ningún otro ítem), queda "suelto"
// en el día en que se cargó la plantilla.
const tourOrigenParaHotelPlantilla = (hotel: OpcionHotel): number | null => {
    const perteneceABloque = (alternativaActiva.value?.items ?? []).some((i) => i.tour_origen_id === hotel.paquete_plantilla_id);
    return perteneceABloque ? (hotel.paquete_plantilla_id ?? null) : null;
};

const diaParaHotelPlantilla = (hotel: OpcionHotel): number => {
    const itemDelTour = (alternativaActiva.value?.items ?? []).find((i) => i.tour_origen_id === hotel.paquete_plantilla_id);
    return itemDelTour?.dia_referencial ?? diaCargaHotelesPendientes.value;
};

const agregarItemHotelPlantilla = async (hotel: OpcionHotel, opcionHotelTarifaId: number, cantidad: number) => {
    if (!alternativaActiva.value || !hotel.paquete_plantilla_id) return;
    try {
        const res = await alternativaItemService.agregarHotelPlantilla(alternativaActiva.value.id, {
            paquete_plantilla_id: hotel.paquete_plantilla_id,
            opcion_hotel_tarifa_id: opcionHotelTarifaId,
            cantidad,
            tour_origen_id: tourOrigenParaHotelPlantilla(hotel),
            dia_referencial: diaParaHotelPlantilla(hotel),
        });
        hotelPlantillaActivoId.value = null;
        await onServicioSueltoAgregado(res.alternativa_item);
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo agregar', 'error');
    }
};

const calcularVistaPreviaCombo = (idsTourOrigen: Set<number>) => {
    if (idsTourOrigen.size < 2) return null;

    const grupos = new Map<number, { tourOrigenId: number; dia: number; nombre: string; total: number }>();
    for (const item of alternativaActiva.value?.items ?? []) {
        if (!item.tour_origen_id || !idsTourOrigen.has(item.tour_origen_id)) continue;
        const existente = grupos.get(item.tour_origen_id);
        if (existente) {
            existente.total += Number(item.total_convertido);
        } else {
            grupos.set(item.tour_origen_id, {
                tourOrigenId: item.tour_origen_id,
                dia: item.dia_referencial ?? 0,
                nombre: item.tour_origen?.nombre ?? 'Tour',
                total: Number(item.total_convertido),
            });
        }
    }

    const dias = new Set([...grupos.values()].map((g) => g.dia));
    return dias.size > 1 ? [...grupos.values()].sort((a, b) => a.dia - b.dia) : null;
};

const matrizHotelActiva = ref<{ tarifas: Array<{ id: number; tipo_habitacion: string; precio: number }>; moneda: string; nombreProveedor: string } | null>(null);
const modoPrecioPendiente = ref<{ id: number; nombre: string } | null>(null);

const clicBibliotecaItem = async (tarifa: ProveedorTarifa) => {
    if (tarifa.tipo_habitacion) {
        // Hotel: matriz completa de habitaciones de ESE proveedor_servicio.
        const res = await proveedorService.listarTarifas(tarifa.proveedor_servicio_id);
        matrizHotelActiva.value = {
            tarifas: res.proveedor_tarifas.map((t) => ({ id: t.id, tipo_habitacion: t.tipo_habitacion ?? '—', precio: Number(t.precio_venta_adulto) })),
            moneda: tarifa.moneda,
            nombreProveedor: tarifa.proveedor_servicio?.proveedor?.nombre_comercial ?? tarifa.proveedor_servicio?.proveedor?.razon_social ?? '',
        };
        return;
    }

    modoPrecioPendiente.value = { id: tarifa.id, nombre: tarifa.proveedor_servicio?.destino_servicio?.servicio?.nombre ?? 'este ítem' };
};

const confirmarModoPrecio = async (modoPrecio: 'tarifa_fija' | 'por_persona') => {
    if (!modoPrecioPendiente.value || !alternativaActiva.value) return;
    try {
        const res = await alternativaItemService.agregarProveedor(alternativaActiva.value.id, {
            proveedor_tarifa_id: modoPrecioPendiente.value.id,
            modo_precio: modoPrecio,
            cantidad: 1,
            dia_referencial: diaActivoParaAgregar.value,
        });
        await onServicioSueltoAgregado(res.alternativa_item);
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo agregar', 'error');
    } finally {
        modoPrecioPendiente.value = null;
    }
};

const agregarItemProveedorHotel = async (proveedorTarifaId: number, cantidad: number) => {
    if (!alternativaActiva.value) return;
    try {
        const res = await alternativaItemService.agregarProveedor(alternativaActiva.value.id, {
            proveedor_tarifa_id: proveedorTarifaId,
            modo_precio: 'tarifa_fija',
            cantidad,
            dia_referencial: diaActivoParaAgregar.value,
        });
        await onServicioSueltoAgregado(res.alternativa_item);
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo agregar', 'error');
    }
};

// ── Comparador de mayoristas ──────────────────────────────────────────
const opcionesMayorista = ref<OpcionMayorista[]>([]);
const proveedoresMayoristas = ref<Proveedor[]>([]);
const opcionHotelesActivaId = ref<number | null>(null);
const mostrarFormMayorista = ref(false);
const mostrarFormHotel = ref<number | null>(null);
const formMayorista = ref({ proveedor_id: null as number | null, moneda: 'USD' as 'PEN' | 'USD', vuelo_aerolinea: '', incluye: '' });
const formHotel = ref<{
    nombre_hotel: string; proveedor_id: number | null;
    tarifas: Array<{ tipo_habitacion: string; precio_costo: number; precio_venta: number; proveedor_tarifa_id?: number | null }>;
}>({
    nombre_hotel: '', proveedor_id: null, tarifas: [{ tipo_habitacion: 'doble', precio_costo: 0, precio_venta: 0, proveedor_tarifa_id: null }],
});

// Sesión 11k, Fix 9 — proveedores tipo Hotel (para "usar tarifa registrada")
// + sus tarifas Hotel una vez elegido uno. Mismo patrón que detalle.vue.
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

const cargarOpcionesMayorista = async () => {
    if (!alternativaActiva.value) return;
    const res = await opcionMayoristaService.listar(alternativaActiva.value.id);
    opcionesMayorista.value = res.opciones_mayorista;
};

const verHoteles = (op: OpcionMayorista) => {
    opcionHotelesActivaId.value = opcionHotelesActivaId.value === op.id ? null : op.id;
};

const elegirOpcion = async (op: OpcionMayorista) => {
    await opcionMayoristaService.elegir(op.id);
    await cargarOpcionesMayorista();
};

const guardarOpcionMayorista = async () => {
    if (!alternativaActiva.value || !formMayorista.value.proveedor_id) return;
    try {
        await opcionMayoristaService.crear(alternativaActiva.value.id, formMayorista.value as any);
        mostrarFormMayorista.value = false;
        formMayorista.value = { proveedor_id: null, moneda: 'USD', vuelo_aerolinea: '', incluye: '' };
        await cargarOpcionesMayorista();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo agregar', 'error');
    }
};

const guardarHotel = async (op: OpcionMayorista) => {
    try {
        await opcionMayoristaService.crearHotel(op.id, formHotel.value);
        mostrarFormHotel.value = null;
        formHotel.value = { nombre_hotel: '', proveedor_id: null, tarifas: [{ tipo_habitacion: 'doble', precio_costo: 0, precio_venta: 0, proveedor_tarifa_id: null }] };
        tarifasHotelProveedorSeleccionado.value = [];
        await cargarOpcionesMayorista();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo guardar', 'error');
    }
};

const tarifasHotelPlanas = (op: OpcionMayorista) => {
    const filas: Array<{ id: number; tipo_habitacion: string; precio: number; registrada: boolean }> = [];
    (op.opciones_hotel ?? []).forEach((h) => {
        (h.opciones_hotel_tarifas ?? []).forEach((t) => {
            filas.push({ id: t.id, tipo_habitacion: `${h.nombre_hotel} · ${t.tipo_habitacion}`, precio: Number(t.precio_venta), registrada: !!t.proveedor_tarifa_id });
        });
    });
    return filas;
};

const agregarItemMayorista = async (op: OpcionMayorista, opcionHotelTarifaId: number, cantidad: number) => {
    if (!alternativaActiva.value) return;
    try {
        const res = await alternativaItemService.agregarMayorista(alternativaActiva.value.id, {
            opcion_mayorista_id: op.id,
            opcion_hotel_tarifa_id: opcionHotelTarifaId,
            cantidad,
            dia_referencial: diaActivoParaAgregar.value,
        });
        await onServicioSueltoAgregado(res.alternativa_item);
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo agregar', 'error');
    }
};

// ── Drawer de biblioteca (Punto A) ─────────────────────────────────────
const drawerBibliotecaAbierto = ref(false);

const abrirDrawerBiblioteca = () => {
    drawerBibliotecaAbierto.value = true;
};

const cerrarDrawerBiblioteca = () => {
    drawerBibliotecaAbierto.value = false;
    volverAGridDrawer();
};

const volverAGridDrawer = () => {
    matrizHotelActiva.value = null;
    modoPrecioPendiente.value = null;
    mostrarFormManual.value = false;
};

const pasoDrawer = computed<'grid' | 'matrizHotel' | 'modoPrecio' | 'manual'>(() => {
    if (matrizHotelActiva.value) return 'matrizHotel';
    if (modoPrecioPendiente.value) return 'modoPrecio';
    if (mostrarFormManual.value) return 'manual';
    return 'grid';
});

const tituloDrawer = computed(() => {
    switch (pasoDrawer.value) {
        case 'matrizHotel': return 'Elegí la habitación';
        case 'modoPrecio': return 'Cómo se cobra';
        case 'manual': return 'Ítem manual';
        default: return 'Agregar servicio';
    }
});

// ── Ítem manual / pasaje aéreo ────────────────────────────────────────
const mostrarFormManual = ref(false);
const mostrarFormPasajeAereo = ref(false);

// Cualquier ítem SUELTO agregado desde el drawer (biblioteca, matriz de
// hotel, modo de precio, manual, mayorista) — el drawer NUNCA se cierra acá
// (a diferencia de clicResultadoPlantilla), solo vuelve al paso "grid" para
// que el vendedor pueda seguir agregando de una.
const onServicioSueltoAgregado = async (_item: AlternativaItem) => {
    volverAGridDrawer();
    toast.success('Servicio agregado');
    await cargarCotizacion();
};

// Pasaje aéreo suelto vive fuera del drawer (no es parte de la biblioteca
// de proveedores/tours) — mismo criterio de siempre, solo renombrado.
const onPasajeAereoAgregado = async (_item: AlternativaItem) => {
    mostrarFormPasajeAereo.value = false;
    toast.success('Pasaje aéreo agregado');
    await cargarCotizacion();
};

const eliminarItem = async (item: AlternativaItem) => {
    await alternativaItemService.eliminar(item.id);
    await cargarCotizacion();
};

// ── Edición en vivo (descuento_pct / precio_convertido) ────────────────
// Punto B (Sesión 11i) — monto_descuento se agrega SOLO para inicializar/
// mostrar el input cuando modo_descuento_item='monto' (derivado de
// precioListaConvertido - precio_convertido, nunca al revés): precio_convertido
// sigue siendo el único campo real que se manda al backend, igual que antes.
const edicionItems = ref<Record<number, { descuento_pct: number; precio_convertido: number; monto_descuento: number }>>({});
const alertasPiso = ref<Record<number, boolean>>({});
const edicionTimeouts: Record<number, any> = {};

// Mismo PriceEngineService::convertirMoneda() del backend, replicado acá
// SOLO para poder inicializar/previsualizar en el cliente — precio_convertido
// que persiste siempre lo calcula y devuelve el servidor, esto nunca es la
// fuente de verdad.
const convertirMonedaLocal = (monto: number, monedaOrigen: string, monedaDestino: string, tipoCambio: number) => {
    if (monedaOrigen === monedaDestino) return Math.round(monto * 100) / 100;
    return monedaOrigen === 'USD' && monedaDestino === 'PEN'
        ? Math.round(monto * tipoCambio * 100) / 100
        : Math.round((monto / tipoCambio) * 100) / 100;
};

const precioListaConvertidoDe = (item: AlternativaItem) => {
    if (!alternativaActiva.value) return Number(item.precio_venta_snapshot);
    return convertirMonedaLocal(Number(item.precio_venta_snapshot), item.moneda_costo, alternativaActiva.value.moneda_cotizacion, Number(alternativaActiva.value.tipo_cambio_aplicado));
};

const inicializarEdicionItems = () => {
    (alternativaActiva.value?.items ?? []).forEach((item) => {
        const precioConvertido = Number(item.precio_convertido);
        const precioLista = precioListaConvertidoDe(item);
        edicionItems.value[item.id] = {
            descuento_pct: Number(item.descuento_pct ?? 0),
            precio_convertido: precioConvertido,
            monto_descuento: Math.round((precioLista - precioConvertido) * 100) / 100,
        };
    });
    descuentoGlobalLocal.value = Number(alternativaActiva.value?.descuento_global_pct ?? 0);
    descuentoGlobalMontoLocal.value = calcularMontoGlobalEquivalente();
    lineasFueraDePiso.value = [];
};

// Punto D — mismo multiplicador que AlternativaItem::getTotalConvertidoAttribute()
// en el backend (manual nunca multiplica, tarifa_fija multiplica por
// cantidad, por_persona ya viene resuelto): se replica acá SOLO para poder
// mostrar el total localmente mientras se edita, con el valor todavía sin
// confirmar por el servidor — nunca se usa para decidir nada, el backend
// sigue siendo la única fuente de verdad una vez que responde.
const totalConvertidoLocal = (item: AlternativaItem) => {
    const precio = edicionItems.value[item.id]?.precio_convertido ?? Number(item.precio_convertido);
    if (item.origen_tipo === 'manual') return precio;
    if (item.modo_precio === 'tarifa_fija') return precio * item.cantidad;
    return precio;
};

const subtotalGrupo = (items: AlternativaItem[]) => items.reduce((sum, item) => sum + totalConvertidoLocal(item), 0);

const totalLocal = computed(() => (alternativaActiva.value?.items ?? []).reduce((sum, item) => sum + totalConvertidoLocal(item), 0));

// ── Descuento global (Parte B, punto 3.1 del plan de dominio) — reparte el
// % a CADA alternativa_item respetando su piso individual
// (AlternativaController::aplicarDescuentoGlobal(), mismo PriceEngineService
// que ya usa la edición por ítem — NUNCA se recalcula acá, el backend
// siempre manda el resultado final). Antes de esta sesión el campo se
// guardaba pero no se aplicaba a ningún ítem — gap real cerrado en 11b3.
const descuentoGlobalLocal = ref(0);
// Sesión 11i — modo_descuento_global='monto'. No se persiste como monto
// (ver aplicarDescuentoGlobalMonto() en el backend — se resuelve a un %
// efectivo y ESE es el que se guarda en alternativas.descuento_global_pct),
// así que al recargar se reconstruye desde ese % efectivo para que el
// input muestre algo consistente en vez de volver a 0.
const descuentoGlobalMontoLocal = ref(0);
const lineasFueraDePiso = ref<Array<{ alternativa_item_id: number; precio_minimo_permitido: number | null }>>([]);

const calcularMontoGlobalEquivalente = () => {
    const pct = Number(alternativaActiva.value?.descuento_global_pct ?? 0);
    if (!pct) return 0;
    const sumaListaTotal = (alternativaActiva.value?.items ?? []).reduce((sum, item) => sum + precioListaConvertidoDe(item), 0);
    return Math.round(sumaListaTotal * (pct / 100) * 100) / 100;
};

const onEditarDescuentoGlobal = async () => {
    if (!alternativaActiva.value) return;
    try {
        const res = await alternativaService.actualizar(alternativaActiva.value.id, { descuento_global_pct: descuentoGlobalLocal.value });
        lineasFueraDePiso.value = res.lineas_fuera_de_piso ?? [];
        await cargarCotizacion();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo aplicar el descuento global', 'error');
    }
};

// Sesión 11i — versión en monto de lo de arriba: mismo endpoint, campo
// distinto (descuento_global_monto — ver AlternativaController::update()/
// aplicarDescuentoGlobalMonto(), que reparte proporcionalmente y valida el
// piso de cada línea exactamente igual que la versión en %).
const onEditarDescuentoGlobalMonto = async () => {
    if (!alternativaActiva.value) return;
    try {
        const res = await alternativaService.actualizar(alternativaActiva.value.id, { descuento_global_monto: descuentoGlobalMontoLocal.value });
        lineasFueraDePiso.value = res.lineas_fuera_de_piso ?? [];
        await cargarCotizacion();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo aplicar el descuento global', 'error');
    }
};

// Punto B/D — 3 caminos según configuracion_agencia, todos con el mismo
// debounce ~400ms y el mismo endpoint (alternativaItemService.actualizar):
// solo cambia qué campo se manda y cómo se deriva el otro para la vista
// previa local (edicionItems ya deja precio_convertido actualizado al
// instante, que es lo único que totalConvertidoLocal() necesita leer).

// permitir_descuento_item=false → "Precio de venta": edición directa,
// mismo comportamiento que la Sesión 1.
const onEditarPrecio = (item: AlternativaItem) => {
    clearTimeout(edicionTimeouts[item.id]);
    edicionTimeouts[item.id] = setTimeout(() => enviarEdicion(item.id, { precio_convertido: edicionItems.value[item.id].precio_convertido }), 400);
};

// permitir_descuento_item=true && modo_descuento_item='porcentaje' — el
// vendedor edita el % directo, se manda descuento_pct (el backend deriva
// precio_convertido). Acá se recalcula precio_convertido de una para la
// vista previa local, con la MISMA fórmula que usa el backend.
const onEditarDescuentoPct = (item: AlternativaItem) => {
    const edicion = edicionItems.value[item.id];
    const precioLista = precioListaConvertidoDe(item);
    edicion.precio_convertido = Math.round(precioLista * (1 - edicion.descuento_pct / 100) * 100) / 100;
    edicion.monto_descuento = Math.round((precioLista - edicion.precio_convertido) * 100) / 100;

    clearTimeout(edicionTimeouts[item.id]);
    edicionTimeouts[item.id] = setTimeout(() => enviarEdicion(item.id, { descuento_pct: edicion.descuento_pct }), 400);
};

// permitir_descuento_item=true && modo_descuento_item='monto' — el
// vendedor edita el monto de descuento; precio_convertido se deriva acá
// (precio de lista - monto) y ES ese el que se manda — el backend deriva
// descuento_pct% de vuelta solo para guardarlo internamente (nunca se
// muestra como "%" en este modo, salvo el badge informativo del template).
const onEditarMontoDescuentoItem = (item: AlternativaItem) => {
    const edicion = edicionItems.value[item.id];
    const precioLista = precioListaConvertidoDe(item);
    edicion.precio_convertido = Math.round((precioLista - edicion.monto_descuento) * 100) / 100;
    edicion.descuento_pct = precioLista > 0 ? Math.round((1 - edicion.precio_convertido / precioLista) * 10000) / 100 : 0;

    clearTimeout(edicionTimeouts[item.id]);
    edicionTimeouts[item.id] = setTimeout(() => enviarEdicion(item.id, { precio_convertido: edicion.precio_convertido }), 400);
};

const enviarEdicion = async (itemId: number, payload: { descuento_pct?: number; precio_convertido?: number }) => {
    try {
        const res = await alternativaItemService.actualizar(itemId, payload);
        alertasPiso.value[itemId] = !!res.alerta_piso;
        const item = alternativaActiva.value?.items?.find((i) => i.id === itemId);
        const precioConvertido = Number(res.alternativa_item.precio_convertido);
        const precioLista = item ? precioListaConvertidoDe(item) : precioConvertido;
        edicionItems.value[itemId] = {
            descuento_pct: Number(res.alternativa_item.descuento_pct ?? 0),
            precio_convertido: precioConvertido,
            monto_descuento: Math.round((precioLista - precioConvertido) * 100) / 100,
        };
        await cargarCotizacion();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo actualizar', 'error');
    }
};

const iconoItem = (item: AlternativaItem) => {
    if (item.origen_tipo === 'pasaje_aereo') return 'fa-plane';
    if (item.origen_tipo === 'mayorista') return 'fa-plane-departure';
    if (item.origen_tipo === 'manual') return 'fa-pen';
    if (item.origen_tipo === 'hotel_plantilla') return 'fa-bed';
    if (item.proveedor_tarifa?.tipo_habitacion) return 'fa-bed';
    return 'fa-concierge-bell';
};

const etiquetaItem = (item: AlternativaItem) => {
    if (item.origen_tipo === 'manual') return item.descripcion_manual ?? 'Ítem manual';
    if (item.origen_tipo === 'pasaje_aereo') return item.cotizacion_pasaje_aereo?.aerolinea ?? 'Pasaje aéreo';
    if (item.origen_tipo === 'mayorista') return item.opcion_mayorista?.proveedor?.nombre_comercial ?? item.opcion_mayorista?.proveedor?.razon_social ?? 'Paquete mayorista';
    if (item.origen_tipo === 'hotel_plantilla') {
        const nombreHotel = item.opcion_hotel_tarifa?.opcion_hotel?.nombre_hotel ?? 'Hotel';
        return `${nombreHotel} · ${item.opcion_hotel_tarifa?.tipo_habitacion ?? ''}`;
    }
    if (item.proveedor_tarifa?.tipo_habitacion) {
        // Hotel: la categoría genérica del servicio ("Alojamiento") no dice nada útil
        // acá — mismo formato "Proveedor · tipo_habitación" que ya usa clicBibliotecaItem.
        const proveedor = item.proveedor_tarifa.proveedor_servicio?.proveedor?.nombre_comercial ?? item.proveedor_tarifa.proveedor_servicio?.proveedor?.razon_social ?? 'Hotel';
        return `${proveedor} · ${item.proveedor_tarifa.tipo_habitacion}`;
    }
    return descripcionDestinoServicio(item.proveedor_tarifa?.proveedor_servicio?.destino_servicio) || 'Servicio';
};

// Punto D — desglose de pasajeros bajo cada línea por_persona ("3 adultos ×
// PEN 70 + 1 niño × PEN 45"). Es UI sobre datos ya cargados (cotizacion.
// pasajeros + item.pax_incluidos + los precios por tipo que ya trae el
// item) — NO reimplementa el cálculo de precio, solo lo muestra desglosado
// con el mismo criterio de conteo que AlternativaItemController::
// contarPasajerosPorTipo() ya aplica en el backend.
const contarPasajerosDeItem = (item: AlternativaItem) => {
    const pax = cotizacion.value?.pasajeros ?? [];
    const filtrados = item.pax_incluidos && item.pax_incluidos.length
        ? pax.filter((p) => item.pax_incluidos!.includes(p.id))
        : pax;
    const conteo: Record<'adulto' | 'nino' | 'infante', number> = { adulto: 0, nino: 0, infante: 0 };
    filtrados.forEach((p) => { conteo[p.tipo_pax] += 1; });
    return conteo;
};

const desglosePasajerosTexto = (item: AlternativaItem): string | null => {
    if (item.modo_precio !== 'por_persona') return null;

    const precios = item.origen_tipo === 'pasaje_aereo' && item.cotizacion_pasaje_aereo
        ? {
            adulto: item.cotizacion_pasaje_aereo.tarifa_base_adulto,
            nino: item.cotizacion_pasaje_aereo.tarifa_base_nino,
            infante: item.cotizacion_pasaje_aereo.tarifa_base_infante,
        }
        : item.proveedor_tarifa
            ? {
                adulto: item.proveedor_tarifa.precio_venta_adulto,
                nino: item.proveedor_tarifa.precio_venta_nino,
                infante: item.proveedor_tarifa.precio_venta_infante,
            }
            : null;

    if (!precios) return null;

    const conteo = contarPasajerosDeItem(item);
    const etiquetas: Record<'adulto' | 'nino' | 'infante', string> = { adulto: 'adulto', nino: 'niño', infante: 'infante' };

    const partes = (['adulto', 'nino', 'infante'] as const)
        .filter((t) => conteo[t] > 0 && precios[t] != null)
        .map((t) => `${conteo[t]} ${etiquetas[t]}${conteo[t] > 1 ? 's' : ''} × ${item.moneda_costo} ${Number(precios[t]).toFixed(0)}`);

    return partes.length ? partes.join(' + ') : null;
};

// ── Panel de precio — grupos colapsables por tour ──────────────────────
const gruposExpandido = ref<Set<number>>(new Set());
const toggleGrupoExpandido = (tourOrigenId: number) => {
    if (gruposExpandido.value.has(tourOrigenId)) gruposExpandido.value.delete(tourOrigenId);
    else gruposExpandido.value.add(tourOrigenId);
};

watch(modo, (m) => { if (m === 'intl') cargarOpcionesMayorista(); });
watch(alternativaActivaId, () => {
    inicializarEdicionItems();
    inicializarDias();
    vistaPreviaCombo.value = null;
    drawerBibliotecaAbierto.value = false;
    volverAGridDrawer();
    if (modo.value === 'intl') cargarOpcionesMayorista();
});

onMounted(async () => {
    // proveedor_tipos: catálogo real (editable desde el panel superadmin,
    // NO los 4 valores del seeder original) — alimenta tanto los chips de
    // la biblioteca (Sesión 11b3) como la búsqueda de proveedores
    // mayoristas de acá abajo (sin duplicar la llamada).
    const tipos = await proveedorTipoService.listar();
    proveedorTipos.value = tipos.proveedor_tipos;

    // Antes de cargarCotizacion(): inicializarEdicionItems() (dentro de
    // cargarCotizacion) necesita configAgencia.value ya resuelto para
    // derivar monto_descuento por ítem (Punto B).
    const configRes = await configuracionAgenciaService.obtener();
    configAgencia.value = configRes.configuracion_agencia;

    await cargarCotizacion();
    await cargarBiblioteca();

    const tipoMayorista = tipos.proveedor_tipos.find((t) => t.slug === 'mayorista');
    if (tipoMayorista) {
        const res = await httpClient.get('/proveedores', { params: { tipo_id: tipoMayorista.id } });
        proveedoresMayoristas.value = res.data.proveedores ?? [];
    }

    // Sesión 11k, Fix 9 — proveedores tipo Hotel, para "usar tarifa registrada"
    // al armar un hotel de opcion_mayorista. slug='alojamiento-hoteles' es
    // el slug REAL (ver mismo hallazgo documentado en
    // ProveedorController::tarifasHotel(), backend — 'hotel' no matchea
    // nada).
    const tipoHotel = tipos.proveedor_tipos.find((t) => t.slug === 'alojamiento-hoteles');
    if (tipoHotel) {
        const res = await httpClient.get('/proveedores', { params: { tipo_id: tipoHotel.id, estado: true } });
        proveedoresHotel.value = res.data.proveedores ?? [];
    }
});
</script>

<style scoped>
.price-panel { position: sticky; top: 1rem; }
.lib-item:hover { background: #eef2ff; border-color: #6366f1 !important; }
.canvas-item-nombre { white-space: normal; word-break: break-word; }

/* Tacho de la alternativa activa — oculto hasta hover del pill, para no
   saturar la fila cuando hay varias alternativas (Parte B). */
.alt-pill-delete { opacity: 0; transition: opacity .15s; }
.alt-pill:hover .alt-pill-delete { opacity: 1; }

/* Tabs de día — nav-tabs subrayadas a propósito, para distinguirse
   visualmente de las pills sólidas (alternativas arriba, chips de
   biblioteca en el drawer). */
.dia-tabs .nav-link {
    padding: 0.35rem 0.75rem;
    font-size: 0.875rem;
    color: var(--bs-secondary-color, #6c757d);
}
.dia-tabs .nav-link.active {
    font-weight: 600;
}

/* Drawer de biblioteca (Punto A) — panel centrado (Sesión 11j: antes era un
   "bottom sheet" anclado abajo, se veía pegado al borde inferior en desktop). */
.drawer-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, .5);
    z-index: 1080;
    display: flex;
    align-items: center;
    justify-content: center;
}
.drawer-panel {
    background: #fff;
    width: 100%;
    max-width: 960px;
    max-height: 82vh;
    border-radius: 12px;
    display: flex;
    flex-direction: column;
    animation: drawer-fade-in .15s ease-out;
    box-shadow: 0 8px 24px rgba(0, 0, 0, .2);
}
.drawer-header {
    padding: .75rem 1rem;
    border-bottom: 1px solid #eee;
    flex-shrink: 0;
}
.drawer-body {
    padding: .75rem 1rem;
    overflow-y: auto;
}
@keyframes drawer-fade-in {
    from { opacity: 0; transform: scale(.97); }
    to { opacity: 1; transform: scale(1); }
}

.biblioteca-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.5rem;
}
@media (max-width: 767px) {
    .biblioteca-grid { grid-template-columns: 1fr; }
}
</style>
