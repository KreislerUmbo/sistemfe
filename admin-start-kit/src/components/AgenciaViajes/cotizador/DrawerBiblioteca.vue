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
                        <ComparadorMayoristas v-else />
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
import {
    CLAVE_NUCLEO, CLAVE_DESTINOS, CLAVE_DIAS, CLAVE_DRAWER, CLAVE_HOTELES_LOCALES, CLAVE_ITEMS_SUELTOS,
    CLAVE_PAGINA, inyectar,
} from '@/composables/agencia-viajes/cotizador/claves';
import BibliotecaLocal from '@/components/AgenciaViajes/cotizador/BibliotecaLocal.vue';
import BibliotecaGuia from '@/components/AgenciaViajes/cotizador/BibliotecaGuia.vue';
import ComparadorMayoristas from '@/components/AgenciaViajes/cotizador/ComparadorMayoristas.vue';

const { alternativaActiva, alternativaSoloLectura, configAgencia, cotizacion } = inyectar(CLAVE_NUCLEO);
const { destinoActivoId } = inyectar(CLAVE_DESTINOS);
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
    avisoItemReservado, formManualSoloLectura, itemManualEnEdicion, onServicioSueltoActualizado,
} = inyectar(CLAVE_ITEMS_SUELTOS);
const { modo, pasoDrawer, tituloDrawer } = inyectar(CLAVE_PAGINA);
</script>
