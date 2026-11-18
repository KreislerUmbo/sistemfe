<template>
    <DefaultLayout>
        <div v-if="cargandoPagina" class="text-center py-5">
            <span class="spinner-border text-primary"></span>
        </div>

        <template v-else>
        <CabeceraCotizacion />

        <AvisosModificacion />

        <EdicionPasajeros />

        <EdicionCabecera />

        <PestanasAlternativas />

        <ChipsDestino />

        <div class="row g-3" v-if="alternativaActiva">
            <LienzoCotizador />

            <PanelPrecio />
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
import LienzoCotizador from '@/components/AgenciaViajes/cotizador/LienzoCotizador.vue';
import CargaTourLienzo from '@/components/AgenciaViajes/cotizador/CargaTourLienzo.vue';
import GrupoOpcionesLienzo from '@/components/AgenciaViajes/cotizador/GrupoOpcionesLienzo.vue';
import ItemLienzo from '@/components/AgenciaViajes/cotizador/ItemLienzo.vue';
import PanelPrecio from '@/components/AgenciaViajes/cotizador/PanelPrecio.vue';
import CabeceraCotizacion from '@/components/AgenciaViajes/cotizador/CabeceraCotizacion.vue';
import AvisosModificacion from '@/components/AgenciaViajes/cotizador/AvisosModificacion.vue';
import EdicionPasajeros from '@/components/AgenciaViajes/cotizador/EdicionPasajeros.vue';
import EdicionCabecera from '@/components/AgenciaViajes/cotizador/EdicionCabecera.vue';
import PestanasAlternativas from '@/components/AgenciaViajes/cotizador/PestanasAlternativas.vue';
import ChipsDestino from '@/components/AgenciaViajes/cotizador/ChipsDestino.vue';

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
.lib-item:hover { background: #eef2ff; border-color: #6366f1 !important; }

.biblioteca-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.5rem;
}
@media (max-width: 767px) {
    .biblioteca-grid { grid-template-columns: 1fr; }
}
</style>
