<template>
    <div class="habitacion-matrix">
        <!-- Sesión M4 — atajo para ofrecer varias opciones de hotel como
             grupo (el cliente elige después, ver Alternativa::tieneGruposSinResolver()
             y AlternativaItemController::elegirOpcionGrupo()). Opt-in: si
             nadie lo toca, el picker se comporta exactamente igual que antes. -->
        <div class="d-flex justify-content-end mb-1" v-if="tarifas.length > 1">
            <button v-if="!modoGrupo" class="btn btn-sm btn-link text-decoration-none" type="button" @click="activarModoGrupo">
                <i class="fas fa-layer-group me-1"></i>Comparar varias opciones
            </button>
            <button v-else class="btn btn-sm btn-link text-decoration-none text-secondary" type="button" @click="cancelarModoGrupo">
                Cancelar comparación
            </button>
        </div>

        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr class="small text-secondary text-uppercase">
                    <th v-if="modoGrupo" style="width:36px"></th>
                    <th>Habitación</th>
                    <th class="text-end">Precio</th>
                    <th class="text-center" style="width:150px" v-if="seleccionadaId && !modoGrupo">{{ cantidadLabel || 'Noches' }}</th>
                    <th class="text-end" :style="{ width: permitirGestionHoteles ? '160px' : '100px' }"></th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="tarifas.length === 0">
                    <td :colspan="modoGrupo ? 5 : 4" class="text-center text-muted fst-italic py-3">Sin tarifas de habitación cargadas.</td>
                </tr>

                <template v-for="fila in filasParaRenderizar" :key="fila.key">
                    <!-- Simulación Panamá (04-sep-2026) — fila "cabecera" liviana por
                         hotel, solo cuando permitirGestionHoteles=true: reemplaza el
                         bloque separado "Hoteles cargados en esta opción" que antes
                         duplicaba los mismos hoteles/tarifas en un formato aparte
                         (hallazgo del usuario). Sin este prop, esta rama nunca corre —
                         Local/Nacional ve exactamente la misma tabla plana de siempre. -->
                    <tr v-if="fila.tipo === 'header'" class="table-light">
                        <td v-if="modoGrupo"></td>
                        <td colspan="2">
                            <template v-if="hotelEnEdicionId === fila.hotelId">
                                <div class="d-flex gap-1">
                                    <input type="text" class="form-control form-control-sm" v-model="formHotel.nombre_hotel" @keyup.enter="guardarHotelInterno(fila.hotelId)">
                                    <button class="btn btn-sm btn-primary text-nowrap" @click="guardarHotelInterno(fila.hotelId)">OK</button>
                                </div>
                            </template>
                            <strong v-else>{{ fila.hotelNombre }}</strong>
                        </td>
                        <td v-if="seleccionadaId && !modoGrupo"></td>
                        <td class="text-end">
                            <i v-if="hotelEnEdicionId !== fila.hotelId" class="fas fa-pen text-muted me-2" style="cursor:pointer;font-size:11px" title="Editar nombre del hotel" @click="abrirEdicionHotelInterna(fila.hotelId, fila.hotelNombre)"></i>
                            <i class="fas fa-trash text-muted" style="cursor:pointer;font-size:11px" title="Eliminar hotel" @click="$emit('eliminarHotel', fila.hotelId)"></i>
                        </td>
                    </tr>

                    <tr v-else-if="fila.tipo === 'tarifa'" :class="{ 'table-primary': seleccionadaId === fila.data.id || idsGrupo.includes(fila.data.id) }">
                        <td v-if="modoGrupo">
                            <input class="form-check-input" type="checkbox" :value="fila.data.id" v-model="idsGrupo">
                        </td>
                        <template v-if="tarifaEnEdicionId === fila.data.id">
                            <td colspan="2">
                                <div class="d-flex gap-1">
                                    <select class="form-select form-select-sm" v-model="formTarifa.tipo_habitacion">
                                        <option value="simple">Simple</option>
                                        <option value="matrimonial">Matrimonial</option>
                                        <option value="doble">Doble</option>
                                        <option value="triple">Triple</option>
                                        <option value="familiar">Familiar</option>
                                    </select>
                                    <input type="number" class="form-control form-control-sm" placeholder="Costo total paquete" v-model.number="formTarifa.precio_costo">
                                    <input type="number" class="form-control form-control-sm" placeholder="Venta total paquete" v-model.number="formTarifa.precio_venta">
                                </div>
                            </td>
                            <td v-if="seleccionadaId && !modoGrupo"></td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-primary" @click="guardarTarifaInterna(fila.data.id)">OK</button>
                            </td>
                        </template>
                        <template v-else>
                            <td class="text-capitalize">
                                <i class="fas fa-bed me-1 text-primary"></i>{{ fila.data.tipo_habitacion }}
                                <i v-if="fila.data.registrada" class="fas fa-link text-primary ms-1" style="font-size:10px" title="Tarifa registrada de un proveedor"></i>
                            </td>
                            <td class="text-end">{{ moneda }} {{ fila.data.precio.toFixed(2) }}</td>
                            <td class="text-center" v-if="seleccionadaId === fila.data.id && !modoGrupo">
                                <div class="input-group input-group-sm">
                                    <button class="btn btn-outline-secondary" type="button" @click="cantidad = Math.max(1, cantidad - 1)">-</button>
                                    <input type="text" class="form-control text-center" :value="cantidad" readonly>
                                    <button class="btn btn-outline-secondary" type="button" @click="cantidad++">+</button>
                                </div>
                            </td>
                            <td v-else-if="seleccionadaId && !modoGrupo"></td>
                            <td class="text-end text-nowrap">
                                <template v-if="!modoGrupo">
                                    <button v-if="seleccionadaId !== fila.data.id" class="btn btn-sm btn-outline-success" @click="elegir(fila.data.id)">
                                        Elegir
                                    </button>
                                    <button v-else class="btn btn-sm btn-primary" @click="confirmar(fila.data.id)"
                                        :disabled="deshabilitarConfirmar" :title="deshabilitarConfirmar ? motivoDeshabilitado : ''">
                                        <i class="fas fa-check me-1"></i>Agregar
                                    </button>
                                </template>
                                <template v-if="permitirGestionHoteles">
                                    <i class="fas fa-pen text-muted ms-2" style="cursor:pointer;font-size:11px" title="Editar" @click="abrirEdicionTarifaInterna(fila.data)"></i>
                                    <i class="fas fa-trash text-muted ms-2" style="cursor:pointer;font-size:11px" title="Eliminar" @click="$emit('eliminarTarifa', fila.data.id)"></i>
                                </template>
                            </td>
                        </template>
                    </tr>

                    <!-- "+ tipo de habitación" al cierre de cada grupo de hotel. -->
                    <tr v-else-if="fila.tipo === 'agregar'">
                        <td v-if="modoGrupo"></td>
                        <td colspan="3">
                            <button v-if="hotelConFormNuevaTarifa !== fila.hotelId" class="btn btn-sm btn-link p-0" @click="hotelConFormNuevaTarifa = fila.hotelId">+ tipo de habitación</button>
                            <div v-else class="d-flex gap-1">
                                <select class="form-select form-select-sm" v-model="formNuevaTarifa.tipo_habitacion">
                                    <option value="simple">Simple</option>
                                    <option value="matrimonial">Matrimonial</option>
                                    <option value="doble">Doble</option>
                                    <option value="triple">Triple</option>
                                    <option value="familiar">Familiar</option>
                                </select>
                                <input type="number" class="form-control form-control-sm" placeholder="Costo total paquete" v-model.number="formNuevaTarifa.precio_costo">
                                <input type="number" class="form-control form-control-sm" placeholder="Venta total paquete" v-model.number="formNuevaTarifa.precio_venta">
                                <button class="btn btn-sm btn-primary text-nowrap" @click="guardarNuevaTarifaInterna(fila.hotelId)">OK</button>
                            </div>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>

        <div v-if="modoGrupo" class="d-flex justify-content-end align-items-center gap-2 pt-2 px-2">
            <span class="small text-secondary">{{ idsGrupo.length }} opción(es) seleccionada(s)</span>
            <button class="btn btn-sm btn-primary" type="button" :disabled="idsGrupo.length < 2 || deshabilitarConfirmar"
                :title="deshabilitarConfirmar ? motivoDeshabilitado : ''" @click="confirmarGrupo">
                <i class="fas fa-check me-1"></i>Agregar {{ idsGrupo.length }} opciones como grupo
            </button>
        </div>

        <!-- Sesión 11o — pax_incluidos + cama adicional, solo cuando el
             caller pasa `pasajeros` (hoy: solo el flujo hotel_plantilla del
             cotizador — el flujo mayorista sigue exactamente igual que
             antes, sin esta sección). -->
        <div v-if="seleccionadaId && pasajeros && pasajeros.length" class="border-top pt-2 mt-2 px-2">
            <span class="small text-secondary d-block mb-1">¿Quiénes ocupan esta habitación?</span>
            <div class="form-check form-check-inline small" v-for="p in pasajeros" :key="p.id">
                <input class="form-check-input" type="checkbox" :id="`pax-${p.id}`" :value="p.id" v-model="paxSeleccionados">
                <label class="form-check-label" :for="`pax-${p.id}`">{{ p.tipo_pax }} ({{ p.edad }} años)</label>
            </div>

            <div v-if="permitirCamaAdicional && paxEnTramoCamaAdicional.length" class="mt-2">
                <label class="form-label mb-1 small text-secondary">
                    Camas adicionales necesarias
                    <span v-if="precioVentaCamaAdicionalSeleccionada != null">(+{{ moneda }} {{ precioVentaCamaAdicionalSeleccionada.toFixed(2) }} c/u)</span>
                </label>
                <div class="input-group input-group-sm" style="max-width:150px">
                    <button class="btn btn-outline-secondary" type="button" @click="camasAdicionales = Math.max(0, camasAdicionales - 1)">-</button>
                    <input type="text" class="form-control text-center" :value="camasAdicionales" readonly>
                    <button class="btn btn-outline-secondary" type="button" @click="camasAdicionales++">+</button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
// Matriz hotel × tipo de habitación — compartida entre local (proveedor_tarifas
// tipo Hotel) e internacional (opciones_hotel_tarifas de un mayorista), Sesión
// 11b. Recibe la lista YA normalizada (id/tipo_habitacion/precio) — cada
// caller adapta su propio origen de datos antes de pasarla acá, este
// componente no sabe de dónde viene.
import { ref, computed } from 'vue';

// registrada: Sesión 11k, Fix 9 — true si esta tarifa viene de un
// proveedor_tarifa real (opcion_hotel_tarifa.proveedor_tarifa_id), no
// tipeada a mano. Cada caller decide si la marca.
// precioVentaCamaAdicional: Sesión 11o — nullable, solo poblado por el
// caller del flujo hotel_plantilla (ver tarifasHotelPlantillaPlanas() en
// editar.vue) — usado únicamente para mostrar "+S/{precio} c/u" en el
// control de camas adicionales.
// hotelId/hotelNombre/precioCosto (04-sep-2026): solo se leen cuando el
// caller pasa permitirGestionHoteles=true — el resto de callers (Local/
// Nacional) nunca los manda, así que quedan undefined sin cambiar nada.
type TarifaHabitacion = {
    id: number; tipo_habitacion: string; precio: number; registrada?: boolean;
    precioVentaCamaAdicional?: number | null;
    hotelId?: number;
    hotelNombre?: string;
    precioCosto?: number;
};

type PasajeroPickable = { id: number; tipo_pax: string; edad: number };

const props = defineProps<{
    tarifas: TarifaHabitacion[];
    moneda?: string;
    // Sesión 11o — todos opcionales: sin `pasajeros`, el componente se
    // comporta EXACTAMENTE igual que antes (flujo mayorista, sin tocar).
    pasajeros?: PasajeroPickable[];
    permitirCamaAdicional?: boolean;
    edadMaxInfanteGratis?: number;
    edadMaxNinoCamaAdicional?: number;
    // Cantidad "Adultos" en mayorista (el precio ya es el paquete completo
    // por persona) vs. "Noches" por defecto en hotel local (tarifa por
    // noche real) — cada caller decide, el componente no sabe de dónde
    // viene el precio.
    cantidadLabel?: string;
    cantidadDefault?: number;
    // El caller mayorista deshabilita confirmar mientras la OpcionMayorista
    // no esté 'elegida' (el backend ya lo bloquea con 422 — esto solo evita
    // que el usuario llegue al error después de clickear).
    deshabilitarConfirmar?: boolean;
    motivoDeshabilitado?: string;
    // Simulación Panamá (04-sep-2026) — activa la vista agrupada por hotel
    // con edición/borrado inline (hallazgo del usuario: la tabla de
    // "elegir" y la lista de gestión mostraban los mismos hoteles dos
    // veces). Apagado por defecto — Local/Nacional no lo manda, así que
    // sigue viendo la tabla plana de siempre, sin ningún cambio.
    permitirGestionHoteles?: boolean;
}>();

const emit = defineEmits<{
    (e: 'seleccionar', payload: { id: number; cantidad: number; pax_incluidos: number[] | null; camas_adicionales_nino: number }): void;
    // Sesión M4 — "ids" en el orden en que el usuario las marcó; el caller
    // decide el grupo_opcion_id (uno solo, compartido por las N opciones).
    (e: 'agregarGrupo', payload: { ids: number[] }): void;
    // Simulación Panamá (04-sep-2026) — el picker solo junta los datos del
    // formulario inline y emite; el caller (editar.vue) sigue siendo dueño
    // de llamar al servicio real y refrescar, mismo criterio que ya usan
    // seleccionar()/agregarGrupo() de arriba.
    (e: 'guardarHotel', payload: { id: number; nombre_hotel: string }): void;
    (e: 'eliminarHotel', hotelId: number): void;
    (e: 'guardarTarifa', payload: { id: number; tipo_habitacion: string; precio_costo: number; precio_venta: number }): void;
    (e: 'eliminarTarifa', tarifaId: number): void;
    (e: 'agregarTarifa', payload: { hotelId: number; tipo_habitacion: string; precio_costo: number; precio_venta: number }): void;
}>();

const seleccionadaId = ref<number | null>(null);
const cantidad = ref<number>(props.cantidadDefault ?? 1);
const paxSeleccionados = ref<number[]>([]);
const camasAdicionales = ref<number>(0);

const modoGrupo = ref<boolean>(false);
const idsGrupo = ref<number[]>([]);

// ── Gestión inline (hotel/tarifa), solo con permitirGestionHoteles=true ──
const hotelEnEdicionId = ref<number | null>(null);
const formHotel = ref({ nombre_hotel: '' });
const abrirEdicionHotelInterna = (hotelId: number, nombreActual: string) => {
    hotelEnEdicionId.value = hotelEnEdicionId.value === hotelId ? null : hotelId;
    formHotel.value = { nombre_hotel: nombreActual };
};
const guardarHotelInterno = (hotelId: number) => {
    emit('guardarHotel', { id: hotelId, nombre_hotel: formHotel.value.nombre_hotel });
    hotelEnEdicionId.value = null;
};

const tarifaEnEdicionId = ref<number | null>(null);
const formTarifa = ref({ tipo_habitacion: 'doble', precio_costo: 0, precio_venta: 0 });
const abrirEdicionTarifaInterna = (t: TarifaHabitacion) => {
    tarifaEnEdicionId.value = tarifaEnEdicionId.value === t.id ? null : t.id;
    formTarifa.value = { tipo_habitacion: t.tipo_habitacion, precio_costo: t.precioCosto ?? 0, precio_venta: t.precio };
};
const guardarTarifaInterna = (tarifaId: number) => {
    emit('guardarTarifa', { id: tarifaId, ...formTarifa.value });
    tarifaEnEdicionId.value = null;
};

const hotelConFormNuevaTarifa = ref<number | null>(null);
const formNuevaTarifa = ref({ tipo_habitacion: 'doble', precio_costo: 0, precio_venta: 0 });
const guardarNuevaTarifaInterna = (hotelId: number) => {
    emit('agregarTarifa', { hotelId, ...formNuevaTarifa.value });
    hotelConFormNuevaTarifa.value = null;
    formNuevaTarifa.value = { tipo_habitacion: 'doble', precio_costo: 0, precio_venta: 0 };
};

// Aplana tarifas -> filas de tabla, intercalando una fila "header" por cada
// hotel nuevo y una fila "agregar" al cierre de su grupo — solo cuando
// permitirGestionHoteles=true. Sin ese prop, es un pass-through 1:1 a
// `tarifas` (comportamiento idéntico al de antes de este cambio).
type FilaTabla =
    | { tipo: 'header'; key: string; hotelId: number; hotelNombre: string }
    | { tipo: 'tarifa'; key: string; data: TarifaHabitacion }
    | { tipo: 'agregar'; key: string; hotelId: number };

const filasParaRenderizar = computed<FilaTabla[]>(() => {
    if (!props.permitirGestionHoteles) {
        return props.tarifas.map((t) => ({ tipo: 'tarifa', key: 't' + t.id, data: t }));
    }

    const filas: FilaTabla[] = [];
    let hotelActual: number | null = null;
    for (const t of props.tarifas) {
        const hid = t.hotelId ?? 0;
        if (hid !== hotelActual) {
            if (hotelActual !== null) filas.push({ tipo: 'agregar', key: 'a' + hotelActual, hotelId: hotelActual });
            filas.push({ tipo: 'header', key: 'h' + hid, hotelId: hid, hotelNombre: t.hotelNombre ?? '' });
            hotelActual = hid;
        }
        filas.push({ tipo: 'tarifa', key: 't' + t.id, data: t });
    }
    if (hotelActual !== null) filas.push({ tipo: 'agregar', key: 'a' + hotelActual, hotelId: hotelActual });

    return filas;
});

// Edad REAL del pasajero (no tipo_pax) — un pasajero de 10 años con
// tipo_pax='adulto' (umbral general de la agencia) igual puede caer en el
// tramo de cama adicional de ESTE hotel si edadMaxNinoCamaAdicional es mayor.
const paxEnTramoCamaAdicional = computed(() => {
    if (props.edadMaxInfanteGratis == null || props.edadMaxNinoCamaAdicional == null) return [];
    return (props.pasajeros ?? []).filter((p) => paxSeleccionados.value.includes(p.id)
        && p.edad > props.edadMaxInfanteGratis!
        && p.edad <= props.edadMaxNinoCamaAdicional!);
});

const precioVentaCamaAdicionalSeleccionada = computed(() => {
    const tarifa = props.tarifas.find((t) => t.id === seleccionadaId.value);
    return tarifa?.precioVentaCamaAdicional ?? null;
});

const elegir = (id: number) => {
    seleccionadaId.value = id;
    cantidad.value = props.cantidadDefault ?? 1;
    paxSeleccionados.value = [];
    camasAdicionales.value = 0;
};

const confirmar = (id: number) => {
    emit('seleccionar', {
        id,
        cantidad: cantidad.value,
        pax_incluidos: paxSeleccionados.value.length ? paxSeleccionados.value : null,
        camas_adicionales_nino: camasAdicionales.value,
    });
    seleccionadaId.value = null;
    cantidad.value = props.cantidadDefault ?? 1;
    paxSeleccionados.value = [];
    camasAdicionales.value = 0;
};

const activarModoGrupo = () => {
    seleccionadaId.value = null;
    modoGrupo.value = true;
    idsGrupo.value = [];
};

const cancelarModoGrupo = () => {
    modoGrupo.value = false;
    idsGrupo.value = [];
};

const confirmarGrupo = () => {
    if (idsGrupo.value.length < 2) return;
    emit('agregarGrupo', { ids: [...idsGrupo.value] });
    cancelarModoGrupo();
};
</script>
