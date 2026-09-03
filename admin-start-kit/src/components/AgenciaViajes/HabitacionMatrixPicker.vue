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
                    <th class="text-end" style="width:100px"></th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="tarifas.length === 0">
                    <td :colspan="modoGrupo ? 5 : 4" class="text-center text-muted fst-italic py-3">Sin tarifas de habitación cargadas.</td>
                </tr>
                <tr v-for="t in tarifas" :key="t.id" :class="{ 'table-primary': seleccionadaId === t.id || idsGrupo.includes(t.id) }">
                    <td v-if="modoGrupo">
                        <input class="form-check-input" type="checkbox" :value="t.id" v-model="idsGrupo">
                    </td>
                    <td class="text-capitalize">
                        <i class="fas fa-bed me-1 text-primary"></i>{{ t.tipo_habitacion }}
                        <i v-if="t.registrada" class="fas fa-link text-primary ms-1" style="font-size:10px" title="Tarifa registrada de un proveedor"></i>
                    </td>
                    <td class="text-end">{{ moneda }} {{ t.precio.toFixed(2) }}</td>
                    <td class="text-center" v-if="seleccionadaId === t.id && !modoGrupo">
                        <div class="input-group input-group-sm">
                            <button class="btn btn-outline-secondary" type="button" @click="cantidad = Math.max(1, cantidad - 1)">-</button>
                            <input type="text" class="form-control text-center" :value="cantidad" readonly>
                            <button class="btn btn-outline-secondary" type="button" @click="cantidad++">+</button>
                        </div>
                    </td>
                    <td class="text-end">
                        <template v-if="!modoGrupo">
                            <button v-if="seleccionadaId !== t.id" class="btn btn-sm btn-outline-success" @click="elegir(t.id)">
                                Elegir
                            </button>
                            <button v-else class="btn btn-sm btn-primary" @click="confirmar(t.id)"
                                :disabled="deshabilitarConfirmar" :title="deshabilitarConfirmar ? motivoDeshabilitado : ''">
                                <i class="fas fa-check me-1"></i>Agregar
                            </button>
                        </template>
                    </td>
                </tr>
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
type TarifaHabitacion = {
    id: number; tipo_habitacion: string; precio: number; registrada?: boolean;
    precioVentaCamaAdicional?: number | null;
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
}>();

const emit = defineEmits<{
    (e: 'seleccionar', payload: { id: number; cantidad: number; pax_incluidos: number[] | null; camas_adicionales_nino: number }): void;
    // Sesión M4 — "ids" en el orden en que el usuario las marcó; el caller
    // decide el grupo_opcion_id (uno solo, compartido por las N opciones).
    (e: 'agregarGrupo', payload: { ids: number[] }): void;
}>();

const seleccionadaId = ref<number | null>(null);
const cantidad = ref<number>(props.cantidadDefault ?? 1);
const paxSeleccionados = ref<number[]>([]);
const camasAdicionales = ref<number>(0);

const modoGrupo = ref<boolean>(false);
const idsGrupo = ref<number[]>([]);

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
