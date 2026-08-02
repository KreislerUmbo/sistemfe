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
            <span v-if="(cotizacion.alternativas?.length ?? 0) < 5" class="badge rounded-pill px-3 py-2 bg-light text-dark border"
                style="cursor:pointer;border-style:dashed" @click="mostrarFormAlternativa = true">
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
            <!-- ═══ COLUMNA IZQUIERDA: biblioteca / comparador ═══ -->
            <div class="col-12 col-lg-3">
                <div class="d-flex gap-1 mb-2">
                    <button class="btn btn-sm flex-fill" :class="modo === 'local' ? 'btn-primary' : 'btn-outline-secondary'" @click="modo = 'local'">Local / Nacional</button>
                    <button class="btn btn-sm flex-fill" :class="modo === 'intl' ? 'btn-primary' : 'btn-outline-secondary'" @click="modo = 'intl'">Internacional</button>
                </div>

                <!-- Biblioteca local -->
                <div v-if="modo === 'local'" class="card border-0 shadow-sm">
                    <div class="card-body p-2">
                        <div class="d-flex flex-wrap align-items-center gap-1 mb-2">
                            <span v-for="chip in chipsFijos" :key="chip.tipo"
                                class="badge rounded-pill px-2 py-1" style="cursor:pointer;font-weight:500;"
                                :class="chipActivo(chip) ? 'bg-primary' : 'bg-light text-dark border'"
                                @click="seleccionarChip(chip)">
                                {{ chip.nombre }}
                            </span>
                            <div class="position-relative">
                                <span class="badge rounded-pill px-2 py-1" style="cursor:pointer;font-weight:500;"
                                    :class="chipMasActivo ? 'bg-primary' : 'bg-light text-dark border'"
                                    @click="mostrarMasChips = !mostrarMasChips">
                                    {{ chipMasActivo ? chipMasActivo.nombre : 'Más' }} <i class="fas fa-caret-down ms-1"></i>
                                </span>
                                <div v-if="mostrarMasChips" class="border rounded shadow-sm bg-white p-1 position-absolute"
                                    style="z-index:1050;min-width:180px;top:100%;left:0;">
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
                        <div class="d-flex flex-column gap-2" style="max-height:480px;overflow-y:auto;">
                            <div v-for="t in bibliotecaProveedorAgrupada" :key="'pt-' + t.id" class="border rounded p-2 small lib-item" style="cursor:pointer"
                                @click="clicBibliotecaItem(t)">
                                <div class="d-flex justify-content-between">
                                    <span>
                                        <i class="fas me-1 text-primary" :class="t.tipo_habitacion ? 'fa-bed' : 'fa-concierge-bell'"></i>
                                        {{ t.proveedor_servicio?.proveedor?.razon_social }}
                                        <span v-if="t.proveedor_servicio?.proveedor?.es_referencial" class="badge bg-secondary-subtle text-secondary border ms-1" style="font-size:10px">Referencial</span>
                                    </span>
                                    <span class="text-muted">{{ t._rangoHabitaciones ? 'desde ' : '' }}{{ t.moneda }} {{ Number(t.precio_venta_adulto).toFixed(0) }}</span>
                                </div>
                                <div class="text-muted" style="font-size:11px">
                                    {{ t.proveedor_servicio?.destino_servicio?.servicio?.nombre }}
                                    <span v-if="t.tipo_habitacion"> · {{ t._rangoHabitaciones ? 'varias habitaciones' : t.tipo_habitacion }}</span>
                                </div>
                            </div>
                            <div v-for="p in bibliotecaToursPaquetes" :key="'tp-' + p.id" class="border rounded p-2 small lib-item" style="cursor:pointer"
                                @click="clicResultadoPlantilla(p)">
                                <div class="d-flex justify-content-between align-items-start">
                                    <span>
                                        <i class="fas me-1 text-primary" :class="p.tipo_resultado === 'paquete' ? 'fa-layers' : 'fa-suitcase-rolling'"></i>
                                        {{ p.nombre }}
                                    </span>
                                    <span class="badge bg-info-subtle text-info border" style="font-size:10px;white-space:nowrap">
                                        {{ p.resumen_items.tours != null ? `${p.resumen_items.tours} tours · ${p.resumen_items.items} ítems` : `${p.resumen_items.items} ítems` }}
                                    </span>
                                </div>
                                <div class="text-muted" style="font-size:11px">
                                    {{ etiquetaCategoriaPaquete(p.categoria) }}<span v-if="p.codigo"> · {{ p.codigo }}</span>
                                </div>
                            </div>
                            <div v-if="bibliotecaProveedorAgrupada.length === 0 && bibliotecaToursPaquetes.length === 0" class="text-muted small text-center py-3">Sin resultados.</div>
                        </div>
                        <small class="text-muted d-block mt-2"><i class="fas fa-hand-pointer me-1"></i>Clic para agregar al día activo</small>
                    </div>
                    <div class="card-footer bg-white border-0 p-2">
                        <button class="btn btn-outline-secondary btn-sm w-100" @click="mostrarFormManual = true"><i class="fas fa-plus me-1"></i>Ítem manual</button>
                    </div>
                </div>

                <!-- Comparador de mayoristas -->
                <div v-else class="d-flex flex-column gap-2">
                    <div v-for="op in opcionesMayorista" :key="op.id" class="card border p-2 small mayorista-card"
                        :class="{ 'border-primary border-2': op.estado === 'elegida' }">
                        <strong>{{ op.proveedor?.razon_social }}</strong>
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
                                <input type="text" class="form-control form-control-sm mb-1" placeholder="Nombre del hotel" v-model="formHotel.nombre_hotel">
                                <div v-for="(tf, idx) in formHotel.tarifas" :key="idx" class="row g-1 mb-1">
                                    <div class="col-4">
                                        <select class="form-select form-select-sm" v-model="tf.tipo_habitacion">
                                            <option value="simple">Simple</option>
                                            <option value="matrimonial">Matrimonial</option>
                                            <option value="doble">Doble</option>
                                            <option value="triple">Triple</option>
                                            <option value="familiar">Familiar</option>
                                        </select>
                                    </div>
                                    <div class="col-4"><input type="number" class="form-control form-control-sm" placeholder="Costo" v-model.number="tf.precio_costo"></div>
                                    <div class="col-4"><input type="number" class="form-control form-control-sm" placeholder="Venta" v-model.number="tf.precio_venta"></div>
                                </div>
                                <button class="btn btn-sm btn-outline-secondary mb-1" @click="formHotel.tarifas.push({ tipo_habitacion: 'doble', precio_costo: 0, precio_venta: 0 })">+ tipo de habitación</button>
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
                            <option v-for="p in proveedoresMayoristas" :key="p.id" :value="p.id">{{ p.razon_social }}</option>
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
            </div>

            <!-- ═══ COLUMNA CENTRO: lienzo ═══ -->
            <div class="col-12 col-lg-6">
                <!-- Tabs de día — nav-tabs subrayadas a propósito, para leerse como
                     un nivel de navegación DISTINTO de las pestañas de alternativa
                     de arriba (esas son pills sólidas) y de las de la biblioteca
                     de la izquierda (esas son pills chip). -->
                <ul class="nav nav-tabs dia-tabs mb-2">
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

                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div v-if="bloquesDelDiaActivo.length === 0" class="drop-hint text-center text-muted py-4 border rounded" style="border-style:dashed">
                            Agregá un servicio desde la biblioteca de la izquierda
                        </div>

                        <div v-for="bloque in bloquesDelDiaActivo" :key="bloque.tourOrigenId ?? 'sueltos'" class="mb-3">
                            <div v-if="bloque.tourOrigenId" class="d-flex justify-content-between align-items-center mb-1">
                                <span class="small fw-semibold text-dark"><i class="fas fa-route me-1 text-primary"></i>{{ bloque.tourNombre ?? 'Tour' }}</span>
                                <select class="form-select form-select-sm" style="width:auto;font-size:11px" :value="diaActivo" @change="onMoverBloque(bloque, $event)">
                                    <option v-for="d in diasCreados" :key="d" :value="d">Mover a Día {{ d }}</option>
                                    <option :value="diaSiguiente">Mover a Día {{ diaSiguiente }} (nuevo)</option>
                                </select>
                            </div>

                            <div v-for="item in bloque.items" :key="item.id" class="canvas-item border rounded p-2 mb-2 small" :class="{ 'ms-3': bloque.tourOrigenId }">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span>
                                        <i class="fas me-2 text-primary" :class="iconoItem(item)"></i>
                                        {{ etiquetaItem(item) }}
                                        <span v-if="item.cantidad > 1 && item.modo_precio === 'tarifa_fija' && item.origen_tipo !== 'manual'" class="text-muted"> × {{ item.cantidad }}</span>
                                    </span>
                                    <span class="d-flex align-items-center gap-2">
                                        <strong>{{ alternativaActiva.moneda_cotizacion }} {{ Number(item.total_convertido).toFixed(2) }}</strong>
                                        <select v-if="!bloque.tourOrigenId" class="form-select form-select-sm" style="width:auto;font-size:11px" :value="item.dia_referencial ?? ''" @change="onReasignarDiaItem(item, $event)">
                                            <option value="" disabled>Sin día</option>
                                            <option v-for="d in diasCreados" :key="d" :value="d">Día {{ d }}</option>
                                        </select>
                                        <i class="fas fa-times text-danger" style="cursor:pointer" @click="eliminarItem(item)"></i>
                                    </span>
                                </div>
                                <div class="text-muted mt-1" style="font-size:11px" v-if="item.origen_tipo === 'pasaje_aereo' && item.cotizacion_pasaje_aereo">
                                    {{ item.cotizacion_pasaje_aereo.aerolinea }}
                                </div>
                            </div>
                        </div>

                        <div v-if="mostrarFormManual" class="border rounded p-2 mt-2">
                            <ItemManualForm :alternativa-id="alternativaActiva.id" :dia-activo="diaActivoParaAgregar" @agregado="onItemAgregado" />
                        </div>
                        <div v-if="mostrarFormPasajeAereo" class="border rounded p-2 mt-2">
                            <PasajeAereoForm :alternativa-id="alternativaActiva.id" :dia-activo="diaActivoParaAgregar" @agregado="onItemAgregado" />
                        </div>
                        <button class="btn btn-outline-secondary btn-sm mt-2" @click="mostrarFormPasajeAereo = !mostrarFormPasajeAereo">
                            <i class="fas fa-plane me-1"></i>{{ mostrarFormPasajeAereo ? 'Cerrar' : 'Agregar pasaje aéreo suelto' }}
                        </button>
                    </div>
                </div>

                <!-- Matriz de habitación (biblioteca local, tipo Hotel) -->
                <div v-if="matrizHotelActiva" class="card border-0 shadow-sm mt-3">
                    <div class="card-header bg-white small fw-semibold d-flex justify-content-between">
                        <span>Elegí la habitación — {{ matrizHotelActiva.nombreProveedor }}</span>
                        <i class="fas fa-times text-danger" style="cursor:pointer" @click="matrizHotelActiva = null"></i>
                    </div>
                    <div class="card-body p-0">
                        <HabitacionMatrixPicker :tarifas="matrizHotelActiva.tarifas" :moneda="matrizHotelActiva.moneda"
                            @seleccionar="({ id, cantidad }) => agregarItemProveedorHotel(id, cantidad)" />
                    </div>
                </div>

                <!-- Selección de modo_precio para ítems no-hotel -->
                <div v-if="modoPrecioPendiente" class="card border-0 shadow-sm mt-3">
                    <div class="card-body small">
                        <p class="fw-semibold mb-2">¿Cómo se cobra "{{ modoPrecioPendiente.nombre }}"?</p>
                        <div class="d-flex gap-2 mb-2">
                            <button class="btn btn-sm btn-outline-primary flex-fill" @click="confirmarModoPrecio('tarifa_fija')">Tarifa fija (total)</button>
                            <button class="btn btn-sm btn-outline-primary flex-fill" @click="confirmarModoPrecio('por_persona')">Por persona (adulto/niño/infante)</button>
                        </div>
                        <button class="btn btn-sm btn-outline-secondary w-100" @click="modoPrecioPendiente = null">Cancelar</button>
                    </div>
                </div>
            </div>

            <!-- ═══ COLUMNA DERECHA: precio en vivo ═══ -->
            <div class="col-12 col-lg-3">
                <div class="card border-0 shadow-sm price-panel">
                    <div class="card-body">
                        <p class="small fw-semibold text-secondary mb-2">Alternativa {{ alternativaActiva.nombre }}</p>
                        <div class="small mb-2" style="max-height:340px;overflow-y:auto;">
                            <div v-for="item in alternativaActiva.items" :key="item.id" class="mb-2 pb-2 border-bottom">
                                <div class="d-flex justify-content-between gap-2">
                                    <span class="text-muted" style="word-break:break-word;">{{ etiquetaItem(item) }}</span>
                                    <span class="text-nowrap">{{ Number(item.total_convertido).toFixed(2) }}</span>
                                </div>
                                <div class="row g-1 mt-1" v-if="item.origen_tipo !== 'manual' && item.proveedor_tarifa_id">
                                    <div class="col-5">
                                        <label class="form-label mb-0 text-muted" style="font-size:10px">Desc. %</label>
                                        <input type="number" class="form-control form-control-sm"
                                            v-model.number="edicionItems[item.id].descuento_pct" @input="onEditarDescuento(item)">
                                    </div>
                                    <div class="col-7">
                                        <label class="form-label mb-0 text-muted" style="font-size:10px">Precio final</label>
                                        <input type="number" class="form-control form-control-sm" :class="{ 'border-danger text-danger': alertasPiso[item.id] }"
                                            v-model.number="edicionItems[item.id].precio_convertido" @input="onEditarPrecio(item)">
                                    </div>
                                </div>
                                <small v-if="alertasPiso[item.id]" class="text-danger"><i class="fas fa-exclamation-triangle me-1"></i>Por debajo del piso permitido</small>
                            </div>
                            <div v-if="(alternativaActiva.items?.length ?? 0) === 0" class="text-muted">Sin ítems todavía</div>
                        </div>

                        <div class="border-top pt-2 mb-2" v-if="(alternativaActiva.items?.length ?? 0) > 0">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Descuento global %</label>
                            <div class="d-flex align-items-center gap-1">
                                <input type="number" min="0" max="100" class="form-control form-control-sm"
                                    v-model.number="descuentoGlobalLocal" @change="onEditarDescuentoGlobal">
                                <span class="small text-muted">%</span>
                            </div>
                            <small v-if="lineasFueraDePiso.length" class="text-danger d-block mt-1">
                                <i class="fas fa-exclamation-triangle me-1"></i>{{ lineasFueraDePiso.length }} línea(s) quedaron bajo el piso permitido — revisalas arriba.
                            </small>
                        </div>

                        <hr>
                        <div class="d-flex justify-content-between align-items-baseline">
                            <span class="small text-secondary">Total</span>
                            <span class="fs-4 fw-semibold">{{ alternativaActiva.moneda_cotizacion }} {{ Number(alternativaActiva.total).toFixed(2) }}</span>
                        </div>
                        <div class="mt-3" v-if="alternativaActiva.estado !== 'aceptada'">
                            <button class="btn btn-success btn-sm w-100" @click="marcarAceptada" :disabled="(alternativaActiva.items?.length ?? 0) === 0">
                                <i class="fas fa-check me-1"></i>Aceptar
                            </button>
                            <small v-if="(alternativaActiva.items?.length ?? 0) === 0" class="text-muted d-block text-center mt-1">
                                Agregá al menos un ítem para poder aceptar
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-else-if="cotizacion" class="text-center text-muted py-5">
            Esta cotización todavía no tiene alternativas — creá la primera con el botón "+ Nueva" de arriba.
        </div>
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
import { cotizacionService } from '@/services/admin/cotizacionService';
import { alternativaService } from '@/services/admin/alternativaService';
import { alternativaItemService } from '@/services/admin/alternativaItemService';
import { opcionMayoristaService } from '@/services/admin/opcionMayoristaService';
import { proveedorService, proveedorTipoService } from '@/services/admin/proveedorService';
import { bibliotecaCotizadorService, type BibliotecaTipo } from '@/services/admin/bibliotecaCotizadorService';
import { reservaService } from '@/services/admin/reservaService';
import type { Cotizacion, Alternativa, AlternativaItem, ProveedorTarifa, OpcionMayorista, Proveedor, ProveedorTipo, BibliotecaResultado } from '@/types/agencia-viajes';
import type { Client } from '@/types/clients';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const route = useRoute();
const router = useRouter();
const cotizacionId = Number(route.params.id);

const cotizacion = ref<Cotizacion | null>(null);
const alternativaActivaId = ref<number | null>(null);
const modo = ref<'local' | 'intl'>('local');

const alternativaActiva = computed<Alternativa | null>(() =>
    cotizacion.value?.alternativas?.find((a) => a.id === alternativaActivaId.value) ?? null
);

const resumenPax = computed(() => {
    const pax = cotizacion.value?.pasajeros ?? [];
    const counts: Record<string, number> = {};
    pax.forEach((p) => { counts[p.tipo_pax] = (counts[p.tipo_pax] ?? 0) + 1; });
    return Object.entries(counts).map(([t, n]) => `${n} ${t}`).join(', ') || 'sin pasajeros';
});

const formatFecha = (f?: string | null) => f ? new Date(f + 'T00:00:00').toLocaleDateString('es-PE', { day: '2-digit', month: 'short', year: 'numeric' }) : 'sin fecha';

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

// ── Lienzo día-por-día (Sesión 11b3, §7.1) ─────────────────────────────
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

const agregarDia = () => {
    diasCreados.value.push(diaSiguiente.value);
    diaActivo.value = diaSiguiente.value;
};

const itemsSinDia = computed(() => (alternativaActiva.value?.items ?? []).filter((i) => i.dia_referencial == null));

const itemsDelDiaActivo = computed(() => {
    const items = alternativaActiva.value?.items ?? [];
    return diaActivo.value === 0
        ? items.filter((i) => i.dia_referencial == null)
        : items.filter((i) => i.dia_referencial === diaActivo.value);
});

// Agrupa por tour_origen_id (Sesión 11b4a) dentro del día activo — mismo
// patrón visual que paquetes/detalle.vue::itemsPorTourAgrupados. Los ítems
// sin tour_origen_id ("sueltos") van en un bloque final sin encabezado.
type BloqueItem = { tourOrigenId: number | null; tourNombre: string | null; items: AlternativaItem[] };

const bloquesDelDiaActivo = computed<BloqueItem[]>(() => {
    const bloques = new Map<number, BloqueItem>();
    const sueltos: AlternativaItem[] = [];

    for (const item of itemsDelDiaActivo.value) {
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
});

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

// ── Biblioteca unificada (Sesión 11b3, §7.1) — un único endpoint decide
// contra qué tabla(s) consulta según el chip activo, ver
// BibliotecaCotizadorController en el backend. "Todos"/"Tour"/"Paquete" son
// fijos; el resto de chips sale del catálogo real de proveedor_tipos (NO
// una lista hardcodeada — ese catálogo cambia por tenant/sesión).
const proveedorTipos = ref<ProveedorTipo[]>([]);

type ChipBiblioteca = { tipo: BibliotecaTipo; proveedorTipoId: number | null; nombre: string };

// "Todos"/"Tour"/"Paquete" siempre visibles; el resto del catálogo de
// proveedor_tipos entra en el desplegable "Más ▾" — Parte B, punto (3)
// confirmado con el usuario (comprimir sin esconder los 3 fijos).
const chipsFijos = computed<ChipBiblioteca[]>(() => [
    { tipo: 'todos', proveedorTipoId: null, nombre: 'Todos' },
    { tipo: 'tour', proveedorTipoId: null, nombre: 'Tour' },
    { tipo: 'paquete', proveedorTipoId: null, nombre: 'Paquete' },
]);

const chipsProveedores = computed<ChipBiblioteca[]>(() =>
    proveedorTipos.value.map((t) => ({ tipo: 'proveedor' as BibliotecaTipo, proveedorTipoId: t.id, nombre: t.nombre }))
);

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
};

const onBibliotecaSearch = () => {
    clearTimeout(bibliotecaTimeout);
    bibliotecaTimeout = setTimeout(cargarBiblioteca, 300);
};

const etiquetaCategoriaPaquete = (c: string) => ({ local: 'Local', nacional: 'Nacional', internacional: 'Internacional' } as Record<string, string>)[c] ?? c;

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

// Click en una tarjeta de tour/paquete — explota TODOS sus ítems en la
// alternativa activa (AlternativaItemController::desdePlantilla()). Sin
// modal de confirmación intermedio: el badge de cantidad de ítems, visible
// ANTES del click en la tarjeta, ya es el requisito explícito de la spec
// para que el vendedor anticipe cuántas líneas va a inyectar.
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
        await cargarCotizacion();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo cargar la plantilla', 'error');
    }
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
            nombreProveedor: tarifa.proveedor_servicio?.proveedor?.razon_social ?? '',
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
        onItemAgregado(res.alternativa_item);
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
        onItemAgregado(res.alternativa_item);
        matrizHotelActiva.value = null;
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
const formHotel = ref<{ nombre_hotel: string; tarifas: Array<{ tipo_habitacion: string; precio_costo: number; precio_venta: number }> }>({
    nombre_hotel: '', tarifas: [{ tipo_habitacion: 'doble', precio_costo: 0, precio_venta: 0 }],
});

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
        formHotel.value = { nombre_hotel: '', tarifas: [{ tipo_habitacion: 'doble', precio_costo: 0, precio_venta: 0 }] };
        await cargarOpcionesMayorista();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo guardar', 'error');
    }
};

const tarifasHotelPlanas = (op: OpcionMayorista) => {
    const filas: Array<{ id: number; tipo_habitacion: string; precio: number }> = [];
    (op.opciones_hotel ?? []).forEach((h) => {
        (h.opciones_hotel_tarifas ?? []).forEach((t) => {
            filas.push({ id: t.id, tipo_habitacion: `${h.nombre_hotel} · ${t.tipo_habitacion}`, precio: Number(t.precio_venta) });
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
        onItemAgregado(res.alternativa_item);
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo agregar', 'error');
    }
};

// ── Ítem manual / pasaje aéreo ────────────────────────────────────────
const mostrarFormManual = ref(false);
const mostrarFormPasajeAereo = ref(false);

const onItemAgregado = async (_item: AlternativaItem) => {
    mostrarFormManual.value = false;
    mostrarFormPasajeAereo.value = false;
    await cargarCotizacion();
};

const eliminarItem = async (item: AlternativaItem) => {
    await alternativaItemService.eliminar(item.id);
    await cargarCotizacion();
};

// ── Edición en vivo (descuento_pct / precio_convertido) ────────────────
const edicionItems = ref<Record<number, { descuento_pct: number; precio_convertido: number }>>({});
const alertasPiso = ref<Record<number, boolean>>({});
const edicionTimeouts: Record<number, any> = {};

const inicializarEdicionItems = () => {
    (alternativaActiva.value?.items ?? []).forEach((item) => {
        edicionItems.value[item.id] = { descuento_pct: Number(item.descuento_pct ?? 0), precio_convertido: Number(item.precio_convertido) };
    });
    descuentoGlobalLocal.value = Number(alternativaActiva.value?.descuento_global_pct ?? 0);
    lineasFueraDePiso.value = [];
};

// ── Descuento global (Parte B, punto 3.1 del plan de dominio) — reparte el
// % a CADA alternativa_item respetando su piso individual
// (AlternativaController::aplicarDescuentoGlobal(), mismo PriceEngineService
// que ya usa la edición por ítem — NUNCA se recalcula acá, el backend
// siempre manda el resultado final). Antes de esta sesión el campo se
// guardaba pero no se aplicaba a ningún ítem — gap real cerrado en 11b3.
const descuentoGlobalLocal = ref(0);
const lineasFueraDePiso = ref<Array<{ alternativa_item_id: number; precio_minimo_permitido: number | null }>>([]);

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

const onEditarDescuento = (item: AlternativaItem) => {
    clearTimeout(edicionTimeouts[item.id]);
    edicionTimeouts[item.id] = setTimeout(() => enviarEdicion(item.id, { descuento_pct: edicionItems.value[item.id].descuento_pct }), 500);
};

const onEditarPrecio = (item: AlternativaItem) => {
    clearTimeout(edicionTimeouts[item.id]);
    edicionTimeouts[item.id] = setTimeout(() => enviarEdicion(item.id, { precio_convertido: edicionItems.value[item.id].precio_convertido }), 500);
};

const enviarEdicion = async (itemId: number, payload: { descuento_pct?: number; precio_convertido?: number }) => {
    try {
        const res = await alternativaItemService.actualizar(itemId, payload);
        alertasPiso.value[itemId] = !!res.alerta_piso;
        edicionItems.value[itemId] = {
            descuento_pct: Number(res.alternativa_item.descuento_pct ?? 0),
            precio_convertido: Number(res.alternativa_item.precio_convertido),
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
    if (item.proveedor_tarifa?.tipo_habitacion) return 'fa-bed';
    return 'fa-concierge-bell';
};

const etiquetaItem = (item: AlternativaItem) => {
    if (item.origen_tipo === 'manual') return item.descripcion_manual ?? 'Ítem manual';
    if (item.origen_tipo === 'pasaje_aereo') return item.cotizacion_pasaje_aereo?.aerolinea ?? 'Pasaje aéreo';
    if (item.origen_tipo === 'mayorista') return item.opcion_mayorista?.proveedor?.razon_social ?? 'Paquete mayorista';
    if (item.proveedor_tarifa?.tipo_habitacion) {
        // Hotel: la categoría genérica del servicio ("Alojamiento") no dice nada útil
        // acá — mismo formato "Proveedor · tipo_habitación" que ya usa clicBibliotecaItem.
        const proveedor = item.proveedor_tarifa.proveedor_servicio?.proveedor?.razon_social ?? 'Hotel';
        return `${proveedor} · ${item.proveedor_tarifa.tipo_habitacion}`;
    }
    return item.proveedor_tarifa?.proveedor_servicio?.destino_servicio?.servicio?.nombre ?? 'Servicio';
};

watch(modo, (m) => { if (m === 'intl') cargarOpcionesMayorista(); });
watch(alternativaActivaId, () => {
    inicializarEdicionItems();
    inicializarDias();
    if (modo.value === 'intl') cargarOpcionesMayorista();
});

onMounted(async () => {
    // proveedor_tipos: catálogo real (editable desde el panel superadmin,
    // NO los 4 valores del seeder original) — alimenta tanto los chips de
    // la biblioteca (Sesión 11b3) como la búsqueda de proveedores
    // mayoristas de acá abajo (sin duplicar la llamada).
    const tipos = await proveedorTipoService.listar();
    proveedorTipos.value = tipos.proveedor_tipos;

    await cargarCotizacion();
    await cargarBiblioteca();

    const tipoMayorista = tipos.proveedor_tipos.find((t) => t.slug === 'mayorista');
    if (tipoMayorista) {
        const res = await httpClient.get('/proveedores', { params: { tipo_id: tipoMayorista.id } });
        proveedoresMayoristas.value = res.data.proveedores ?? [];
    }
});
</script>

<style scoped>
.price-panel { position: sticky; top: 1rem; }
.lib-item:hover { background: #eef2ff; border-color: #6366f1 !important; }

/* Tacho de la alternativa activa — oculto hasta hover del pill, para no
   saturar la fila cuando hay varias alternativas (Parte B). */
.alt-pill-delete { opacity: 0; transition: opacity .15s; }
.alt-pill:hover .alt-pill-delete { opacity: 1; }

/* Tabs de día — nav-tabs subrayadas a propósito, para distinguirse
   visualmente de las pills sólidas (alternativas arriba, chips de
   biblioteca a la izquierda). */
.dia-tabs .nav-link {
    padding: 0.35rem 0.75rem;
    font-size: 0.875rem;
    color: var(--bs-secondary-color, #6c757d);
}
.dia-tabs .nav-link.active {
    font-weight: 600;
}
</style>
