<template>
    <div class="habitacion-matrix">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr class="small text-secondary text-uppercase">
                    <th>Habitación</th>
                    <th class="text-end">Precio</th>
                    <th class="text-center" style="width:150px" v-if="seleccionadaId">Noches</th>
                    <th class="text-end" style="width:100px"></th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="tarifas.length === 0">
                    <td colspan="4" class="text-center text-muted fst-italic py-3">Sin tarifas de habitación cargadas.</td>
                </tr>
                <tr v-for="t in tarifas" :key="t.id" :class="{ 'table-primary': seleccionadaId === t.id }">
                    <td class="text-capitalize">
                        <i class="fas fa-bed me-1 text-primary"></i>{{ t.tipo_habitacion }}
                        <i v-if="t.registrada" class="fas fa-link text-primary ms-1" style="font-size:10px" title="Tarifa registrada de un proveedor"></i>
                    </td>
                    <td class="text-end">{{ moneda }} {{ t.precio.toFixed(2) }}</td>
                    <td class="text-center" v-if="seleccionadaId === t.id">
                        <div class="input-group input-group-sm">
                            <button class="btn btn-outline-secondary" type="button" @click="cantidad = Math.max(1, cantidad - 1)">-</button>
                            <input type="text" class="form-control text-center" :value="cantidad" readonly>
                            <button class="btn btn-outline-secondary" type="button" @click="cantidad++">+</button>
                        </div>
                    </td>
                    <td class="text-end">
                        <button v-if="seleccionadaId !== t.id" class="btn btn-sm btn-outline-primary" @click="elegir(t.id)">
                            Elegir
                        </button>
                        <button v-else class="btn btn-sm btn-primary" @click="confirmar(t.id)">
                            <i class="fas fa-check me-1"></i>Agregar
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>

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
}>();

const emit = defineEmits<{
    (e: 'seleccionar', payload: { id: number; cantidad: number; pax_incluidos: number[] | null; camas_adicionales_nino: number }): void;
}>();

const seleccionadaId = ref<number | null>(null);
const cantidad = ref<number>(1);
const paxSeleccionados = ref<number[]>([]);
const camasAdicionales = ref<number>(0);

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
    cantidad.value = 1;
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
    cantidad.value = 1;
    paxSeleccionados.value = [];
    camasAdicionales.value = 0;
};
</script>
