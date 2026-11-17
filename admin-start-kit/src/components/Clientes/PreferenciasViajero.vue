<template>
    <!-- T-060 (CRM C5, solo Agencia): preferencias del viajero en la ficha del cliente. Pide sus datos
         solo y se guarda aparte del formulario del cliente (botón propio). Está dentro del <form> de
         la ficha: Enter en un campo no envía «Guardar cambios» del cliente. -->
    <div class="card border-0 shadow-sm mb-3" @keydown.enter="evitarEnvio">
        <div class="card-header bg-white border-bottom py-2 d-flex align-items-center justify-content-between">
            <span class="fw-semibold text-dark"><i class="fas fa-suitcase-rolling me-2 text-primary"></i>Preferencias del viajero</span>
            <small v-if="perfil?.actualizado_en" class="text-muted">Actualizado {{ formatFechaHora(perfil.actualizado_en) }}</small>
        </div>
        <div class="card-body">
            <div v-if="cargando" class="text-muted small"><span class="spinner-border spinner-border-sm me-2"></span>Cargando…</div>
            <div v-else-if="errorCarga" class="text-danger small">{{ errorCarga }}</div>
            <template v-else>
                <label class="form-label small fw-semibold text-secondary mb-1">Tipo de viaje</label>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <template v-for="t in tiposVisibles" :key="t.id">
                        <input :id="`pref-tipo-${t.id}`" v-model="form.tipos_viaje" type="checkbox" class="btn-check" :value="t.id"
                            autocomplete="off" :disabled="!puedeEditar">
                        <label class="btn btn-outline-primary btn-sm" :for="`pref-tipo-${t.id}`">{{ t.nombre }}{{ t.activo ? '' : ' (desactivado)' }}</label>
                    </template>
                    <span v-if="!tiposVisibles.length" class="small text-muted">No hay tipos de viaje en Configuración de la Agencia.</span>
                </div>

                <div class="row g-2">
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-semibold text-secondary mb-1" for="pref-presupuesto">Presupuesto aprox. por persona</label>
                        <div class="input-group input-group-sm">
                            <select v-model="form.moneda" class="form-select form-select-sm flex-grow-0 w-auto" :disabled="!puedeEditar"
                                aria-label="Moneda del presupuesto">
                                <option value="PEN">S/</option>
                                <option value="USD">US$</option>
                            </select>
                            <input id="pref-presupuesto" v-model="presupuestoTexto" type="number" min="0" step="0.01" class="form-control form-control-sm"
                                :class="{ 'is-invalid': errores.presupuesto_por_persona }" placeholder="Sin indicar" :disabled="!puedeEditar">
                            <div v-if="errores.presupuesto_por_persona" class="invalid-feedback">{{ errores.presupuesto_por_persona }}</div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-semibold text-secondary mb-1" for="pref-hotel">Hotel preferido</label>
                        <input id="pref-hotel" v-model="form.hotel_preferido" type="text" maxlength="150" class="form-control form-control-sm"
                            :class="{ 'is-invalid': errores.hotel_preferido }" placeholder="Boutique, 4 estrellas, con piscina…" :disabled="!puedeEditar">
                        <div v-if="errores.hotel_preferido" class="invalid-feedback">{{ errores.hotel_preferido }}</div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-semibold text-secondary mb-1" for="pref-alimentacion">Alimentación</label>
                        <input id="pref-alimentacion" v-model="form.alimentacion" type="text" maxlength="150" class="form-control form-control-sm"
                            :class="{ 'is-invalid': errores.alimentacion }" placeholder="Vegetariana, sin gluten, alergias…" :disabled="!puedeEditar">
                        <div v-if="errores.alimentacion" class="invalid-feedback">{{ errores.alimentacion }}</div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-semibold text-secondary mb-1" for="pref-movilidad">Movilidad</label>
                        <input id="pref-movilidad" v-model="form.movilidad" type="text" maxlength="150" class="form-control form-control-sm"
                            :class="{ 'is-invalid': errores.movilidad }" placeholder="Silla de ruedas, poca caminata, traslado privado…" :disabled="!puedeEditar">
                        <div v-if="errores.movilidad" class="invalid-feedback">{{ errores.movilidad }}</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold text-secondary mb-1" for="pref-notas">Notas</label>
                        <textarea id="pref-notas" v-model="form.notas" rows="3" maxlength="2000" class="form-control form-control-sm"
                            :class="{ 'is-invalid': errores.notas }" placeholder="Lo que conviene recordar al cotizarle" :disabled="!puedeEditar"></textarea>
                        <div v-if="errores.notas" class="invalid-feedback">{{ errores.notas }}</div>
                    </div>
                </div>

                <div v-if="puedeEditar" class="d-flex justify-content-end mt-3">
                    <button type="button" class="btn btn-outline-primary btn-sm" :disabled="guardando || !hayCambios" @click="guardar">
                        <span v-if="guardando" class="spinner-border spinner-border-sm me-1"></span>
                        <i v-else class="fas fa-save me-1"></i>Guardar preferencias
                    </button>
                </div>
            </template>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useToast } from '@/composables/useToast';
import { formatFechaHora } from '@/helpers/fecha';
import {
    crmTipoViajeService,
    fichaClienteAgenciaService,
    type DatosPerfilViajero,
    type PerfilViajero,
    type TipoViaje,
} from '@/services/admin/fichaClienteAgenciaService';

const props = withDefaults(defineProps<{ clienteId: number; puedeEditar?: boolean }>(), { puedeEditar: false });

const toast = useToast();
const cargando = ref(true);
const errorCarga = ref<string | null>(null);
const guardando = ref(false);
const errores = ref<Record<string, string>>({});
const tipos = ref<TipoViaje[]>([]);
const perfil = ref<PerfilViajero | null>(null);

type Form = Omit<DatosPerfilViajero, 'presupuesto_por_persona'>;
const formVacio = (): Form => ({ tipos_viaje: [], moneda: 'PEN', alimentacion: '', movilidad: '', hotel_preferido: '', notas: '' });
const form = ref<Form>(formVacio());
// El input number entrega '' al borrar: se guarda como texto y se convierte al enviar.
const presupuestoTexto = ref<string | number>('');
const guardado = ref('');

const instantanea = () => JSON.stringify({ ...form.value, tipos_viaje: [...form.value.tipos_viaje].sort(), p: String(presupuestoTexto.value ?? '') });
const hayCambios = computed(() => instantanea() !== guardado.value);

// Activos más los desactivados que el cliente ya tiene (se siguen mostrando).
const tiposVisibles = computed(() => tipos.value.filter((t) => t.activo || form.value.tipos_viaje.includes(t.id)));

const aplicar = (p: PerfilViajero | null) => {
    perfil.value = p;
    form.value = p
        ? {
            tipos_viaje: [...p.tipos_viaje],
            moneda: p.moneda ?? 'PEN',
            alimentacion: p.alimentacion ?? '',
            movilidad: p.movilidad ?? '',
            hotel_preferido: p.hotel_preferido ?? '',
            notas: p.notas ?? '',
        }
        : formVacio();
    presupuestoTexto.value = p?.presupuesto_por_persona ?? '';
    guardado.value = instantanea();
};

const cargar = async () => {
    cargando.value = true;
    errorCarga.value = null;
    try {
        const [catalogo, datos] = await Promise.all([crmTipoViajeService.listar(), fichaClienteAgenciaService.perfil(props.clienteId)]);
        tipos.value = catalogo;
        aplicar(datos);
    } catch (e: any) {
        errorCarga.value = e?.response?.data?.message ?? 'No se pudieron cargar las preferencias.';
    } finally {
        cargando.value = false;
    }
};

onMounted(cargar);
watch(() => props.clienteId, cargar);

const evitarEnvio = (e: KeyboardEvent) => {
    if ((e.target as HTMLElement | null)?.tagName !== 'TEXTAREA') e.preventDefault();
};

const guardar = async () => {
    guardando.value = true;
    errores.value = {};
    const texto = String(presupuestoTexto.value ?? '').trim();
    try {
        const resultado = await fichaClienteAgenciaService.guardarPerfil(props.clienteId, {
            ...form.value,
            presupuesto_por_persona: texto === '' ? null : Number(texto),
            moneda: texto === '' ? null : form.value.moneda,
        });
        aplicar(resultado.perfil);
        toast.success(resultado.message);
    } catch (e: any) {
        const errs = e?.response?.data?.errors as Record<string, string[]> | undefined;
        if (errs) errores.value = Object.fromEntries(Object.entries(errs).map(([k, v]) => [k.split('.')[0], v[0]]));
        toast.error(e?.response?.data?.message ?? 'No se pudieron guardar las preferencias.');
    } finally {
        guardando.value = false;
    }
};
</script>
