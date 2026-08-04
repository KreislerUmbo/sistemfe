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
    </div>
</template>

<script setup lang="ts">
// Matriz hotel × tipo de habitación — compartida entre local (proveedor_tarifas
// tipo Hotel) e internacional (opciones_hotel_tarifas de un mayorista), Sesión
// 11b. Recibe la lista YA normalizada (id/tipo_habitacion/precio) — cada
// caller adapta su propio origen de datos antes de pasarla acá, este
// componente no sabe de dónde viene.
import { ref } from 'vue';

// registrada: Sesión 11k, Fix 9 — true si esta tarifa viene de un
// proveedor_tarifa real (opcion_hotel_tarifa.proveedor_tarifa_id), no
// tipeada a mano. Cada caller decide si la marca.
type TarifaHabitacion = { id: number; tipo_habitacion: string; precio: number; registrada?: boolean };

defineProps<{
    tarifas: TarifaHabitacion[];
    moneda?: string;
}>();

const emit = defineEmits<{ (e: 'seleccionar', payload: { id: number; cantidad: number }): void }>();

const seleccionadaId = ref<number | null>(null);
const cantidad = ref<number>(1);

const elegir = (id: number) => {
    seleccionadaId.value = id;
    cantidad.value = 1;
};

const confirmar = (id: number) => {
    emit('seleccionar', { id, cantidad: cantidad.value });
    seleccionadaId.value = null;
    cantidad.value = 1;
};
</script>
