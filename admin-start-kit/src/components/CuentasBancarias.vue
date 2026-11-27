<template>
    <!-- T-136 — cuentas bancarias de la empresa (sistema base). Cada cuenta se guarda, edita y
         elimina sola. El botón «Agregar cuenta» lo pone quien lo usa, con abrirFormulario(). -->
    <div>
        <div v-if="mostrarFormulario" class="border rounded p-3 mb-3 bg-light-subtle">
            <div class="row g-2">
                <div class="col-6 col-md-3">
                    <label class="form-label mb-1 small fw-semibold text-secondary">Banco</label>
                    <input v-model="formulario.banco" type="text" class="form-control form-control-sm">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label mb-1 small fw-semibold text-secondary">Titular</label>
                    <input v-model="formulario.titular" type="text" class="form-control form-control-sm">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label mb-1 small fw-semibold text-secondary">N° de cuenta</label>
                    <input v-model="formulario.numero_cuenta" type="text" class="form-control form-control-sm">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label mb-1 small fw-semibold text-secondary">Moneda</label>
                    <select v-model="formulario.moneda" class="form-select form-select-sm">
                        <option :value="null">Sin indicar</option>
                        <option value="PEN">Soles</option>
                        <option value="USD">Dólares</option>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label mb-1 small fw-semibold text-secondary">CCI</label>
                    <input v-model="formulario.cci" type="text" class="form-control form-control-sm">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label mb-1 small fw-semibold text-secondary">Alias</label>
                    <input v-model="formulario.alias" type="text" class="form-control form-control-sm" placeholder="Ej. Yape/Plin">
                </div>
                <div class="col-6 col-md-3 d-flex align-items-center">
                    <div class="form-check form-switch mt-3">
                        <input id="cuenta-activa" v-model="formulario.activo" class="form-check-input" type="checkbox">
                        <label class="form-check-label small" for="cuenta-activa">Activo</label>
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-end gap-2 mt-3">
                <button type="button" class="btn btn-sm btn-outline-secondary" @click="cerrarFormulario">Cancelar</button>
                <button type="button" class="btn btn-sm btn-primary" :disabled="guardando" @click="guardar">
                    <span v-if="guardando" class="spinner-border spinner-border-sm me-1"></span>
                    {{ editandoId ? 'Actualizar' : 'Guardar' }}
                </button>
            </div>
        </div>

        <div v-if="cargando" class="text-center py-3 text-muted">
            <div class="spinner-border spinner-border-sm me-2"></div>Cargando...
        </div>
        <div v-else-if="cuentas.length === 0" class="text-muted small fst-italic">
            Sin cuentas bancarias registradas.
        </div>
        <div v-else class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr class="text-secondary small">
                        <th>Banco</th>
                        <th>Titular</th>
                        <th>N° cuenta</th>
                        <th>Moneda</th>
                        <th>CCI</th>
                        <th>Alias</th>
                        <th class="text-center">Activo</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="cuenta in cuentas" :key="cuenta.id">
                        <td>{{ cuenta.banco }}</td>
                        <td>{{ cuenta.titular }}</td>
                        <td>{{ cuenta.numero_cuenta }}</td>
                        <td>{{ cuenta.moneda || '—' }}</td>
                        <td>{{ cuenta.cci || '—' }}</td>
                        <td>{{ cuenta.alias || '—' }}</td>
                        <td class="text-center">
                            <span class="badge" :class="cuenta.activo ? 'bg-success' : 'bg-secondary'">
                                {{ cuenta.activo ? 'Sí' : 'No' }}
                            </span>
                        </td>
                        <td class="text-end text-nowrap">
                            <button type="button" class="btn btn-sm btn-outline-secondary me-1" title="Editar" @click="abrirFormulario(cuenta)">
                                <i class="fas fa-pen"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger" title="Eliminar" @click="eliminar(cuenta)">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { cuentaBancariaService } from '@/services/admin/cuentaBancariaService';
import type { CuentaBancaria } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const emit = defineEmits<{ (e: 'cambio'): void }>();

const cuentas = ref<CuentaBancaria[]>([]);
const cargando = ref(true);
const guardando = ref(false);
const mostrarFormulario = ref(false);
const editandoId = ref<number | null>(null);
const vacio = (): Partial<CuentaBancaria> => ({
    banco: '',
    titular: '',
    numero_cuenta: '',
    cci: '',
    alias: '',
    activo: true,
    moneda: 'PEN',
});
const formulario = ref<Partial<CuentaBancaria>>(vacio());

const error = (e: any, porDefecto: string) =>
    (Swal as TVueSwalInstance).fire('Error', e.response?.data?.message ?? porDefecto, 'error');

const cargar = async () => {
    cargando.value = true;
    try {
        cuentas.value = (await cuentaBancariaService.listar()).cuentas_bancarias;
    } catch (e) {
        error(e, 'No se pudieron cargar las cuentas');
    } finally {
        cargando.value = false;
    }
};

const abrirFormulario = (cuenta?: CuentaBancaria) => {
    editandoId.value = cuenta?.id ?? null;
    formulario.value = cuenta ? { ...cuenta } : vacio();
    mostrarFormulario.value = true;
};

const cerrarFormulario = () => {
    mostrarFormulario.value = false;
    editandoId.value = null;
    formulario.value = vacio();
};

const guardar = async () => {
    const { banco, titular, numero_cuenta } = formulario.value;
    if (!banco?.trim() || !titular?.trim() || !numero_cuenta?.trim()) {
        (Swal as TVueSwalInstance).fire('Error', 'Banco, titular y número de cuenta son obligatorios.', 'error');
        return;
    }

    guardando.value = true;
    try {
        const res = editandoId.value
            ? await cuentaBancariaService.actualizar(editandoId.value, formulario.value)
            : await cuentaBancariaService.crear(formulario.value);
        (Swal as TVueSwalInstance).fire('Listo', res.message, 'success');
        cerrarFormulario();
        await cargar();
        emit('cambio');
    } catch (e) {
        error(e, 'No se pudo guardar la cuenta');
    } finally {
        guardando.value = false;
    }
};

const eliminar = async (cuenta: CuentaBancaria) => {
    const confirmacion = await (Swal as TVueSwalInstance).fire({
        title: '¿Eliminar cuenta bancaria?',
        text: `${cuenta.banco} — ${cuenta.numero_cuenta}`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Eliminar',
        cancelButtonText: 'Cancelar',
    });
    if (!confirmacion.isConfirmed) return;

    try {
        const res = await cuentaBancariaService.eliminar(cuenta.id);
        (Swal as TVueSwalInstance).fire('Listo', res.message, 'success');
        await cargar();
        emit('cambio');
    } catch (e) {
        error(e, 'No se pudo eliminar la cuenta');
    }
};

defineExpose({ abrirFormulario });

onMounted(cargar);
</script>
