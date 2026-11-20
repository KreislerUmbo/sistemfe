<template>
    <div class="card border shadow-sm mb-3">
        <div class="card-body pb-2">
            <div class="row g-3">
                <div class="col-12 col-md-4 col-lg-2">
                    <label class="form-label small fw-semibold text-secondary mb-1">Moneda</label>
                    <div class="btn-group btn-group-sm w-100" role="group">
                        <button type="button" class="btn" :class="moneda === 'PEN' ? 'btn-success' : 'btn-outline-secondary'"
                            @click="moneda = 'PEN'">Soles</button>
                        <button type="button" class="btn" :class="moneda === 'USD' ? 'btn-success' : 'btn-outline-secondary'"
                            @click="moneda = 'USD'">Dólares</button>
                    </div>
                </div>
                <div class="col-12 col-md-4 col-lg-2">
                    <label class="form-label small fw-semibold text-secondary mb-1" for="dv-fecha">Fecha de emisión</label>
                    <CampoFecha id="dv-fecha" v-model="fecha" etiqueta="emisión" />
                </div>
                <div v-if="mostrarNTransaccion" class="col-12 col-md-4 col-lg-2">
                    <label class="form-label small fw-semibold text-secondary mb-1" for="dv-ntrans">N.º de documento</label>
                    <input id="dv-ntrans" v-model="nTransaccion" type="text" class="form-control form-control-sm" placeholder="000000" />
                </div>
                <div v-if="puedeCambiarSucursal" class="col-12 col-md-4 col-lg-2">
                    <label class="form-label small fw-semibold text-secondary mb-1" for="dv-sucursal">Sucursal</label>
                    <select id="dv-sucursal" v-model="sucursalId" class="form-select form-select-sm" :disabled="sucursalBloqueada">
                        <option v-for="b in sucursales" :key="b.id" :value="b.id">{{ b.name }}</option>
                    </select>
                </div>
                <div v-if="esFiscal && condicion !== 'exportacion'" class="col-12 col-md-4 col-lg-3">
                    <label class="form-label small fw-semibold text-secondary mb-1">Destino de la mercadería</label>
                    <div class="btn-group btn-group-sm w-100" role="group">
                        <button type="button" class="btn" :class="destino === 'amazonia' ? 'btn-success' : 'btn-outline-secondary'"
                            @click="destino = 'amazonia'">Amazonía</button>
                        <button type="button" class="btn" :class="destino === 'nacional' ? 'btn-success' : 'btn-outline-secondary'"
                            @click="destino = 'nacional'">Fuera</button>
                    </div>
                    <div class="small text-muted mt-1">{{ destino === 'amazonia' ? 'Los bienes salen exonerados (Ley 27037).' : 'Los bienes salen gravados con IGV.' }}</div>
                </div>
                <div v-if="esFiscal" class="col-12 col-md-4 col-lg-3">
                    <label class="form-label small fw-semibold text-secondary mb-1" for="dv-cond">Condición especial</label>
                    <select id="dv-cond" v-model="condicion" class="form-select form-select-sm">
                        <option value="0">Ninguna</option>
                        <option value="anticipo">Aplicar adelanto del cliente</option>
                        <option value="exportacion">Exportación (0% IGV)</option>
                        <option value="1">Retención IGV 3%</option>
                        <option value="2">Detracción SPOT</option>
                        <option value="3">Percepción</option>
                    </select>
                    <div class="small text-muted mt-1">{{ ayudaCondicion }}</div>
                </div>
                <div v-if="esFiscal && condicion === '2'" class="col-12 col-md-8 col-lg-6">
                    <label class="form-label small fw-semibold text-secondary mb-1" for="dv-detraccion">Código de detracción (Anexo SUNAT)</label>
                    <select id="dv-detraccion" v-model="codigoDetraccion" class="form-select form-select-sm"
                        @change="emit('detraccion-cambio')">
                        <option value="">— Seleccionar código —</option>
                        <option v-for="d in codigosDetraccion" :key="d.codigo" :value="d.codigo">
                            [{{ d.codigo }}] {{ d.nombre }} ({{ Number(d.tasa_porcentaje).toFixed(1) }}%)
                        </option>
                    </select>
                    <div v-if="montoMinimoDetraccion != null" class="small text-muted mt-1">
                        Monto mínimo para aplicar detracción: S/ {{ montoMinimoDetraccion }}
                    </div>
                </div>
            </div>
        </div>
        <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap px-3 pb-3">
            <span class="small text-muted">Se eligen antes de cargar productos: el destino y la exportación cambian la afectación de todas las líneas.</span>
            <button type="button" class="btn btn-outline-secondary btn-sm" @click="emit('listo')">Listo</button>
        </div>
    </div>
</template>

<script setup lang="ts">
// Panel «Datos de la venta» (T-106): moneda, fecha, sucursal, destino, condición especial y código de detracción.
// Solo muestra y edita; los efectos (recalcular afectaciones, USD en exportación…) viven en los watchers de register.vue.
import { computed } from 'vue';
import CampoFecha from '@/components/CampoFecha.vue';
import type { Branch } from '@/types/cash-session';

const props = defineProps<{
    puedeCambiarSucursal: boolean;
    /** Edición (T-110): la venta ya tiene correlativo, la sucursal no se puede cambiar. */
    sucursalBloqueada?: boolean;
    /** Edición (T-110): muestra el campo «N.º de documento» interno de la venta. */
    mostrarNTransaccion?: boolean;
    sucursales: Branch[];
    esFiscal: boolean;
    codigosDetraccion: { codigo: string; nombre: string; tasa_porcentaje: number | string; monto_minimo?: number | string }[];
}>();
const emit = defineEmits<{ (e: 'listo'): void; (e: 'detraccion-cambio'): void }>();

const moneda = defineModel<string>('moneda', { required: true });
const fecha = defineModel<string>('fecha', { required: true });
const sucursalId = defineModel<number | undefined>('sucursalId');
const destino = defineModel<string>('destino', { required: true });
const condicion = defineModel<string>('condicion', { required: true });
const codigoDetraccion = defineModel<string>('codigoDetraccion', { default: '' });
const nTransaccion = defineModel<string>('nTransaccion', { default: '' });

const AYUDAS: Record<string, string> = {
    anticipo: 'Se mostrarán los adelantos del cliente para elegir.',
    exportacion: 'Todas las líneas pasan a exportación (0% IGV).',
    '1': 'El cliente retiene el 3% del total.',
    '2': 'Se pedirá el código del Anexo SUNAT.',
    '3': 'Se suma la percepción al total.',
};
const ayudaCondicion = computed(() => AYUDAS[condicion.value] ?? '');
const montoMinimoDetraccion = computed(() => {
    const d = props.codigosDetraccion.find((c) => c.codigo === codigoDetraccion.value);
    return d ? (d.monto_minimo ?? 700) : null;
});
</script>
