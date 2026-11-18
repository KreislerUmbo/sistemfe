<template>
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
                        <BibliotecaLocal v-if="modo === 'local'" />

                        <!-- Buscador de guías (Fix guia-como-item-real) — mismo patrón visual
                             que ya usa paquetes/detalle.vue: elegir guía, después su lista de
                             tarifas (cada una ya trae su destino/modalidad/costo_diario). -->
                        <BibliotecaGuia v-else-if="modo === 'guia'" />

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
</template>

<script setup lang="ts">
/*
 * Drawer de la biblioteca (Teleport a body): encabezado, selector de modo (local, mayorista, guías) y
 * los pasos del drawer: grid (BibliotecaLocal, BibliotecaGuia o el comparador de mayoristas), matriz
 * de habitación, modo de precio e ítem manual. Todo llega por inject desde cotizador/editar.vue
 * (T-105).
 */
import HabitacionMatrixPicker from '@/components/AgenciaViajes/HabitacionMatrixPicker.vue';
import ItemManualForm from '@/components/AgenciaViajes/ItemManualForm.vue';
import OpcionMayoristaForm from '@/components/AgenciaViajes/OpcionMayoristaForm.vue';
import ProveedorBuscador from '@/components/AgenciaViajes/ProveedorBuscador.vue';
import RichTextEditor from '@/components/RichTextEditor.vue';
import TourIncluidoForm from '@/components/AgenciaViajes/TourIncluidoForm.vue';
import { textoPlano } from '@/helpers/agencia-viajes/cotizador/presentacionItem';
import {
    CLAVE_NUCLEO, CLAVE_PASAJEROS, CLAVE_DESTINOS, CLAVE_DIAS, CLAVE_DRAWER, CLAVE_HOTELES_LOCALES,
    CLAVE_OPCIONES_MAYORISTA, CLAVE_HOTELES_MAYORISTA, CLAVE_OPCIONALES_MAYORISTA, CLAVE_ITEMS_SUELTOS,
    CLAVE_PAGINA, inyectar,
} from '@/composables/agencia-viajes/cotizador/claves';

const { alternativaActiva, alternativaSoloLectura, configAgencia, cotizacion } = inyectar(CLAVE_NUCLEO);
const { cantidadAdultosCotizacion } = inyectar(CLAVE_PASAJEROS);
const { destinoActivoId, destinoAtractivoIdActivo } = inyectar(CLAVE_DESTINOS);
const { diaActivoParaAgregar } = inyectar(CLAVE_DIAS);
const {
    cerrarDrawerBiblioteca, drawerBibliotecaAbierto, onServicioSueltoAgregado, volverAGridDrawer,
} = inyectar(CLAVE_DRAWER);
const {
    agregarGrupoHotelAdhocLocal, agregarGrupoProveedorHotel, agregarItemHotelAdhocLocal,
    agregarItemProveedorHotel, confirmandoModoPrecio, confirmarModoPrecio, matrizHotelActiva,
    modoPrecioPendiente, nochesHotelDefault, onEliminarFotoHotelAdhoc, onSubirFotoHotelAdhoc,
    subiendoFotoHotelAdhoc,
} = inyectar(CLAVE_HOTELES_LOCALES);
const {
    abrirFormEditarOpcionMayorista, agregarGrupoMayorista, agregarItemMayorista, descartandoOpcionId,
    descartarOpcion, elegirOpcion, eligiendoOpcionId, eliminandoOpcionMayoristaId, eliminarOpcionMayorista,
    mostrarFormMayorista, onOpcionMayoristaGuardada, opcionHotelesActivaId, opcionMayoristaEnEdicionId,
    opcionesMayorista, reactivandoOpcionId, reactivarOpcion, verHoteles,
} = inyectar(CLAVE_OPCIONES_MAYORISTA);
const {
    formHotel, fotosHotelNuevo, guardandoHotelMayorista, guardarHotel, mostrarFormHotel,
    onAgregarFotosHotelMatrix, onAgregarTarifaMatrix, onCambiarProveedorHotel, onElegirTarifaRegistrada,
    onEliminarFotoHotelMatrix, onEliminarHotelMatrix, onEliminarTarifaMatrix, onFotosHotelNuevoSeleccionadas,
    onGuardarHotelMatrix, onGuardarTarifaMatrix, onPromoverHotelMatrix, quitarFotoHotelNuevo,
    tarifasHotelParaTipo, tarifasHotelPlanas,
} = inyectar(CLAVE_HOTELES_MAYORISTA);
const {
    abrirEdicionOpcional, abrirEdicionTour, agregandoOpcionalAlLienzoId, agregarOpcionalAlLienzo,
    eliminandoOpcionalId, eliminarOpcionalCargado, formEdicionOpcional, formOpcional,
    guardandoEdicionOpcional, guardandoOpcional, guardarEdicionOpcional, guardarOpcional,
    mostrarFormOpcional, mostrarFormTourIncluido, mostrarOpcionalesId, mostrarToursId,
    onTourIncluidoActualizado, onTourIncluidoAgregado, opcionalEnEdicionId, quitarTourIncluido,
    tourEnEdicionId, verOpcionales, verTours,
} = inyectar(CLAVE_OPCIONALES_MAYORISTA);
const {
    avisoItemReservado, formManualSoloLectura, itemManualEnEdicion, onServicioSueltoActualizado,
} = inyectar(CLAVE_ITEMS_SUELTOS);
const { modo, pasoDrawer, tipoHotelId, tipoMayoristaId, tituloDrawer } = inyectar(CLAVE_PAGINA);
</script>
