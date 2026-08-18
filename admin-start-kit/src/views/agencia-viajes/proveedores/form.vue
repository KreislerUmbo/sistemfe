<template>
    <DefaultLayout>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-truck-loading me-2 text-primary"></i>
                    {{ esEdicion ? 'Editar Proveedor' : 'Nuevo Proveedor' }}
                </h5>
                <small class="text-muted">Completa los datos del proveedor</small>
            </div>
            <router-link to="/agencia-viajes/proveedores" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Volver
            </router-link>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2">
                <span class="badge bg-primary rounded-pill">1</span>
                <span class="fw-semibold text-dark">Datos Generales</span>
            </div>
            <div class="card-body py-3">
                <div class="row g-3">
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Tipo de Documento</label>
                        <select class="form-select form-select-sm" v-model="form.tipo_documento">
                            <option :value="null">—</option>
                            <option value="DNI">DNI</option>
                            <option value="RUC">RUC</option>
                            <option value="CE">Carné Extranjería</option>
                            <option value="PASAPORTE">Pasaporte</option>
                        </select>
                    </div>
                     <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">N° Documento</label>
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control form-control-sm" v-model="form.numero_documento">
                            <button class="btn btn-outline-primary" type="button" title="Buscar en RENIEC/SUNAT"
                                :disabled="!form.numero_documento || buscandoDocumento || !['DNI', 'RUC'].includes(form.tipo_documento ?? '')"
                                @click="buscarDocumento">
                                <span v-if="buscandoDocumento" class="spinner-border spinner-border-sm"></span>
                                <i v-else class="fas fa-search"></i>
                            </button>
                        </div>
                        <small class="text-muted" v-if="form.tipo_documento && !['DNI', 'RUC'].includes(form.tipo_documento)">
                            La búsqueda automática solo está disponible para DNI/RUC — completa los datos a mano.
                        </small>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Tipo de Proveedor *</label>
                        <select class="form-select form-select-sm" v-model="form.tipo_id">
                            <option :value="null">— Selecciona —</option>
                            <option v-for="tipo in proveedorTiposHabilitados" :key="tipo.id" :value="tipo.id">{{ tipo.nombre }}</option>
                        </select>
                        <small class="text-muted" v-if="proveedorTiposHabilitados.length === 0">
                            No hay tipos habilitados — habilítalos en <router-link to="/agencia-viajes/proveedores">Configuración de tipos</router-link>.
                        </small>
                    </div>
                    <div class="col-6 col-md-3 d-flex align-items-center">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="proveedor-es-referencial" v-model="form.es_referencial">
                            <label class="form-check-label small" for="proveedor-es-referencial">
                                Solo referencial
                                <i class="fas fa-circle-info ms-1 text-muted"
                                   title="No emite comprobante fiscal a la agencia — solo para control interno de costos"></i>
                            </label>
                        </div>
                    </div>

                    <div class="col-12 col-md-5">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Razón Social *</label>
                        <input type="text" class="form-control form-control-sm" v-model="form.razon_social">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Nombre Comercial</label>
                        <input type="text" class="form-control form-control-sm" v-model="form.nombre_comercial">
                    </div>

                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Tipo de Persona</label>
                        <select class="form-select form-select-sm" v-model="form.tipo_persona">
                            <option :value="null">—</option>
                            <option value="natural">Natural</option>
                            <option value="juridica">Jurídica</option>
                        </select>
                    </div>
                    
                    <div class="col-12">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Descripción</label>
                        <RichTextEditor v-model="form.descripcion" />
                    </div>





                    <div class="col-6 col-md-3 d-flex align-items-end">
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="estado-c" id="estado-activo" :checked="form.estado === true" @click="form.estado = true" autocomplete="off">
                            <label class="btn btn-outline-success btn-sm" for="estado-activo">Activo</label>
                            <input type="radio" class="btn-check" name="estado-c" id="estado-inactivo" :checked="form.estado === false" @click="form.estado = false" autocomplete="off">
                            <label class="btn btn-outline-secondary btn-sm" for="estado-inactivo">Inactivo</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2">
                <span class="badge bg-primary rounded-pill">2</span>
                <span class="fw-semibold text-dark">Contacto</span>
            </div>
            <div class="card-body py-3">
                <div class="row g-3">
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Teléfono</label>
                        <input type="text" class="form-control form-control-sm" v-model="form.telefono">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Celular</label>
                        <input type="text" class="form-control form-control-sm" v-model="form.celular">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">WhatsApp</label>
                        <input type="text" class="form-control form-control-sm" v-model="form.whatsapp">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Email</label>
                        <input type="email" class="form-control form-control-sm" v-model="form.email">
                    </div>
                    <div class="col-12">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Dirección</label>
                        <input type="text" class="form-control form-control-sm" v-model="form.direccion">
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3" v-if="esMayorista">
            <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2">
                <span class="badge bg-primary rounded-pill">3</span>
                <span class="fw-semibold text-dark">Margen automático (mayorista)</span>
            </div>
            <div class="card-body py-3">
                <div class="row g-3">
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Tipo de margen</label>
                        <select class="form-select form-select-sm" v-model="form.margen_default_tipo">
                            <option :value="null">—</option>
                            <option value="porcentaje">Porcentaje</option>
                            <option value="fijo">Fijo</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Valor</label>
                        <input type="number" step="0.01" class="form-control form-control-sm" v-model.number="form.margen_default_valor">
                    </div>
                </div>
                <small class="text-muted">Se aplica automático al cargar precios de costo de este mayorista — editable línea por línea.</small>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3" v-if="esAlojamiento">
            <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2">
                <span class="badge bg-primary rounded-pill">3</span>
                <span class="fw-semibold text-dark">Detalle de Alojamiento</span>
            </div>
            <div class="card-body py-3">
                <div class="row g-3">
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Hora check-in</label>
                        <input type="time" class="form-control form-control-sm" v-model="formAlojamiento.hora_checkin">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Hora check-out</label>
                        <input type="time" class="form-control form-control-sm" v-model="formAlojamiento.hora_checkout">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">
                            Edad máx. infante gratis
                            <i class="fas fa-info-circle text-muted" title="Hasta esta edad (inclusive) el pasajero no requiere cama adicional."></i>
                        </label>
                        <input type="number" min="0" class="form-control form-control-sm"
                            :placeholder="String(configAgencia?.edad_max_infante_gratis_hotel_default ?? 4)"
                            v-model.number="formAlojamiento.edad_max_infante_gratis">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">
                            Edad máx. cama adicional
                            <i class="fas fa-info-circle text-muted" title="Hasta esta edad (inclusive) el pasajero puede requerir cama adicional."></i>
                        </label>
                        <input type="number" min="0" class="form-control form-control-sm"
                            :placeholder="String(configAgencia?.edad_max_nino_cama_adicional_hotel_default ?? 12)"
                            v-model.number="formAlojamiento.edad_max_nino_cama_adicional">
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2">
                <span class="badge bg-primary rounded-pill">{{ badgeFotos }}</span>
                <span class="fw-semibold text-dark">Fotos</span>
                <small class="text-muted ms-auto">{{ totalFotos }}/{{ MAX_FOTOS }} · máx. 5MB por foto</small>
            </div>
            <div class="card-body py-3">
                <div v-if="fotosExistentes.length > 0" class="mb-3">
                    <small class="text-muted d-block mb-2">Fotos guardadas</small>
                    <div class="d-flex flex-wrap gap-2">
                        <img v-for="path in fotosExistentes" :key="path" :src="path" class="img-thumbnail" style="width:100px;height:100px;object-fit:cover;">
                    </div>
                </div>

                <div v-if="archivosNuevos.length > 0" class="mb-3">
                    <small class="text-muted d-block mb-2">Fotos nuevas (se suben al guardar)</small>
                    <div class="d-flex flex-wrap gap-2">
                        <div v-for="(item, index) in archivosNuevos" :key="item.previewUrl" class="position-relative">
                            <img :src="item.previewUrl" class="img-thumbnail" style="width:100px;height:100px;object-fit:cover;">
                            <button class="btn btn-danger btn-sm position-absolute top-0 end-0 m-1" title="Quitar" @click="quitarPendiente(index)">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <input type="file" class="form-control form-control-sm" accept="image/*" multiple
                    :disabled="limiteFotosAlcanzado" @change="onArchivosSeleccionados">
                <small v-if="limiteFotosAlcanzado" class="text-danger d-block mt-1">
                    Ya alcanzaste el máximo de {{ MAX_FOTOS }} fotos.
                </small>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2">
                <span class="badge bg-primary rounded-pill">{{ badgeAmenidades }}</span>
                <span class="fw-semibold text-dark">Amenidades</span>
            </div>
            <div class="card-body py-3">
                <div class="row g-2">
                    <div class="col-6 col-md-3" v-for="amenidad in amenidades" :key="amenidad.id">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" :id="`amenidad-${amenidad.id}`"
                                :value="amenidad.id" v-model="form.amenidad_ids">
                            <label class="form-check-label small" :for="`amenidad-${amenidad.id}`">
                                <i :class="amenidad.icono" class="me-1 text-primary"></i>{{ amenidad.nombre }}
                            </label>
                        </div>
                    </div>
                    <div v-if="amenidades.length === 0" class="col-12 text-muted small fst-italic">Sin amenidades en el catálogo.</div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2">
                <span class="badge bg-primary rounded-pill">{{ badgeObservaciones }}</span>
                <span class="fw-semibold text-dark">Observaciones</span>
            </div>
            <div class="card-body py-3">
                <textarea class="form-control form-control-sm" rows="3" v-model="form.observaciones"></textarea>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2 mb-4">
            <router-link to="/agencia-viajes/proveedores" class="btn btn-outline-secondary">Cancelar</router-link>
            <button class="btn btn-primary fw-semibold" @click="guardar" :disabled="guardando">
                <span v-if="guardando" class="spinner-border spinner-border-sm me-2"></span>
                <i v-else class="fas fa-save me-2"></i>
                {{ esEdicion ? 'Actualizar' : 'Registrar' }}
            </button>
        </div>
    </DefaultLayout>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import RichTextEditor from '@/components/RichTextEditor.vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import httpClient from '@/helpers/http-client';
import { proveedorService, proveedorTipoService } from '@/services/admin/proveedorService';
import { amenidadService } from '@/services/admin/amenidadService';
import { configuracionAgenciaService } from '@/services/admin/configuracionAgenciaService';
import type { Proveedor, ProveedorTipo, Amenidad, ConfiguracionAgencia } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;
type ArchivoPendiente = { file: File; previewUrl: string };

const MAX_KB_POR_FOTO = 5120;
const MAX_FOTOS = 10;

const route = useRoute();
const router = useRouter();

const esEdicion = computed(() => !!route.params.id);
const proveedorId = computed(() => Number(route.params.id));

const proveedorTipos = ref<ProveedorTipo[]>([]);
const proveedorTiposHabilitados = computed(() => proveedorTipos.value.filter((t) => t.habilitado));
const amenidades = ref<Amenidad[]>([]);
const configAgencia = ref<ConfiguracionAgencia | null>(null);

const guardando = ref<boolean>(false);

const form = ref<
    Partial<Omit<Proveedor, 'tipo_id' | 'fotos' | 'amenidades' | 'alojamiento_detalle'>>
    & { tipo_id: number | null; amenidad_ids: number[] }
>({
    razon_social: '',
    nombre_comercial: null,
    descripcion: null,
    tipo_id: null,
    tipo_persona: null,
    tipo_documento: null,
    numero_documento: null,
    telefono: null,
    celular: null,
    whatsapp: null,
    email: null,
    direccion: null,
    observaciones: null,
    estado: true,
    es_referencial: false,
    margen_default_tipo: null,
    margen_default_valor: null,
    amenidad_ids: [],
});

// Consolidación de hoteles — 1:1 con proveedor_alojamiento_detalle, solo se
// envía si el tipo elegido es Alojamiento (ver esAlojamiento).
const formAlojamiento = ref<{
    hora_checkin: string | null; hora_checkout: string | null;
    edad_max_infante_gratis: number | null; edad_max_nino_cama_adicional: number | null;
}>({ hora_checkin: null, hora_checkout: null, edad_max_infante_gratis: null, edad_max_nino_cama_adicional: null });

const fotosExistentes = ref<string[]>([]);
const archivosNuevos = ref<ArchivoPendiente[]>([]);
const totalFotos = computed(() => fotosExistentes.value.length + archivosNuevos.value.length);
const limiteFotosAlcanzado = computed(() => totalFotos.value >= MAX_FOTOS);

// slug='agencia-mayorista' es el slug REAL del catálogo proveedor_tipos
// (cambiado a mano en producción, no 'mayorista') — mismo criterio que
// esAlojamiento() justo abajo.
const esMayorista = computed(() => {
    const tipo = proveedorTipos.value.find((t) => t.id === form.value.tipo_id);
    return tipo?.slug === 'agencia-mayorista';
});

// slug='alojamiento-hoteles' es el slug REAL del catálogo proveedor_tipos
// (confirmado contra datos reales) — NO 'hotel', mismo criterio ya usado en
// el resto del vertical (ProveedorTarifaController::proveedorEsHotel(),
// editar.vue::esHotel).
const esAlojamiento = computed(() => {
    const tipo = proveedorTipos.value.find((t) => t.id === form.value.tipo_id);
    return tipo?.slug === 'alojamiento-hoteles';
});

// Margen (mayorista) y Alojamiento son mutuamente excluyentes (tipo_id es
// único) — nunca ocupan el mismo badge "3" a la vez.
const badgeFotos = computed(() => (esMayorista.value || esAlojamiento.value ? 4 : 3));
const badgeAmenidades = computed(() => badgeFotos.value + 1);
const badgeObservaciones = computed(() => badgeAmenidades.value + 1);

const cargarProveedor = async () => {
    if (!esEdicion.value) return;
    try {
        const res = await proveedorService.obtener(proveedorId.value);
        const { fotos, amenidades: amenidadesProveedor, alojamiento_detalle, ...datos } = res.proveedor;
        form.value = { ...datos, amenidad_ids: (amenidadesProveedor ?? []).map((a) => a.id) };
        fotosExistentes.value = fotos ?? [];
        if (alojamiento_detalle) {
            formAlojamiento.value = {
                hora_checkin: alojamiento_detalle.hora_checkin ?? null,
                hora_checkout: alojamiento_detalle.hora_checkout ?? null,
                edad_max_infante_gratis: alojamiento_detalle.edad_max_infante_gratis,
                edad_max_nino_cama_adicional: alojamiento_detalle.edad_max_nino_cama_adicional,
            };
        }
    } catch (error) {
        console.log(error);
    }
};

const onArchivosSeleccionados = (event: Event) => {
    const input = event.target as HTMLInputElement;
    const archivos = Array.from(input.files ?? []);
    input.value = '';

    const disponibles = MAX_FOTOS - totalFotos.value;
    if (disponibles <= 0) {
        (Swal as TVueSwalInstance).fire('Límite alcanzado', `Ya tienes el máximo de ${MAX_FOTOS} fotos.`, 'warning');
        return;
    }

    const rechazadas: string[] = [];
    let agregadas = 0;
    for (const archivo of archivos) {
        if (agregadas >= disponibles) {
            rechazadas.push(`${archivo.name}: no se agregó — ya alcanzaste el máximo de ${MAX_FOTOS} fotos.`);
            continue;
        }
        if (archivo.size > MAX_KB_POR_FOTO * 1024) {
            rechazadas.push(`${archivo.name}: supera el máximo de 5MB.`);
            continue;
        }
        archivosNuevos.value.push({ file: archivo, previewUrl: URL.createObjectURL(archivo) });
        agregadas++;
    }

    if (rechazadas.length > 0) {
        (Swal as TVueSwalInstance).fire({ icon: 'warning', title: 'Algunas fotos no se agregaron', html: rechazadas.join('<br>') });
    }
};

const quitarPendiente = (index: number) => {
    URL.revokeObjectURL(archivosNuevos.value[index].previewUrl);
    archivosNuevos.value.splice(index, 1);
};

// ── Búsqueda online DNI/RUC (RENIEC/SUNAT vía apisperu) — opcional, nunca bloquea el guardado ──
const buscandoDocumento = ref(false);
const buscarDocumento = async () => {
    if (!form.value.numero_documento || !form.value.tipo_documento) return;
    const tipo = form.value.tipo_documento.toLowerCase();
    if (!['dni', 'ruc'].includes(tipo)) return;

    buscandoDocumento.value = true;
    try {
        const res = await httpClient.get(`/search-document/${tipo}/${form.value.numero_documento}`);
        if (res.data.success === false) {
            (Swal as TVueSwalInstance).fire('Sin resultados', 'No se encontró información para ese documento.', 'warning');
            return;
        }
        if (tipo === 'dni') {
            const nombres = `${res.data.nombres ?? ''} ${res.data.apellidoPaterno ?? ''} ${res.data.apellidoMaterno ?? ''}`.trim();
            form.value.razon_social = nombres || form.value.razon_social;
            form.value.tipo_persona = 'natural';
        } else {
            form.value.razon_social = res.data.razonSocial || form.value.razon_social;
            form.value.nombre_comercial = res.data.nombreComercial || form.value.nombre_comercial;
            form.value.direccion = res.data.direccion || form.value.direccion;
            form.value.tipo_persona = 'juridica';
        }
    } catch (error) {
        (Swal as TVueSwalInstance).fire('Error', 'No se pudo consultar el documento.', 'error');
    } finally {
        buscandoDocumento.value = false;
    }
};

const guardar = async () => {
    if (!form.value.razon_social?.trim()) {
        (Swal as TVueSwalInstance).fire('Error', 'La razón social es obligatoria.', 'error');
        return;
    }
    if (!form.value.tipo_id) {
        (Swal as TVueSwalInstance).fire('Error', 'Selecciona el tipo de proveedor.', 'error');
        return;
    }

    guardando.value = true;
    try {
        const datosAlojamiento = esAlojamiento.value ? formAlojamiento.value : {};
        let payload: FormData | Record<string, any>;

        if (archivosNuevos.value.length > 0) {
            const fd = new FormData();
            Object.entries(form.value).forEach(([key, value]) => {
                if (key === 'amenidad_ids' || value === null || value === undefined) return;
                fd.append(key, String(value));
            });
            form.value.amenidad_ids.forEach((id) => fd.append('amenidad_ids[]', String(id)));
            Object.entries(datosAlojamiento).forEach(([key, value]) => {
                if (value === null || value === undefined) return;
                fd.append(key, String(value));
            });
            archivosNuevos.value.forEach((item) => fd.append('fotos[]', item.file));
            payload = fd;
        } else {
            payload = { ...form.value, ...datosAlojamiento };
        }

        const res = esEdicion.value
            ? await proveedorService.actualizar(proveedorId.value, payload)
            : await proveedorService.crear(payload);

        if (res.fotos_rechazadas?.length) {
            const detalle = res.fotos_rechazadas.map((r: { nombre: string; motivo: string }) => `${r.nombre}: ${r.motivo}`).join('<br>');
            await (Swal as TVueSwalInstance).fire({ icon: 'warning', title: 'Guardado con observaciones', html: `${res.message}<br><br>Fotos no subidas:<br>${detalle}` });
        } else {
            await (Swal as TVueSwalInstance).fire('Listo', res.message, 'success');
        }
        router.push(`/agencia-viajes/proveedores/${res.proveedor.id}`);
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo guardar', 'error');
    } finally {
        guardando.value = false;
    }
};

onMounted(async () => {
    const [tiposRes, amenidadesRes] = await Promise.all([
        proveedorTipoService.listar(),
        amenidadService.listar(),
    ]);
    proveedorTipos.value = tiposRes.proveedor_tipos;
    amenidades.value = amenidadesRes.amenidades;

    configuracionAgenciaService.obtener().then((res) => { configAgencia.value = res.configuracion_agencia; });

    await cargarProveedor();
});
</script>
