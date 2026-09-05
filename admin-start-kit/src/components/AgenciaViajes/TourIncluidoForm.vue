<template>
    <div class="card border p-2 small">
        <input type="text" class="form-control form-control-sm mb-1" placeholder="Nombre (ej. City Tour + Canal de Panamá)"
            v-model="form.nombre">
        <textarea class="form-control form-control-sm mb-1" rows="4"
            placeholder="Descripción (narrativa del tour — es lo que se imprime en la sección Itinerario del PDF)"
            v-model="form.descripcion"></textarea>
        <div class="row g-1 mb-1">
            <div class="col-8">
                <label class="form-label mb-0 small text-secondary">Destino/atractivo</label>
                <DestinoTreeSelect v-model="form.destino_atractivo_id" placeholder="Zona o atractivo..." />
            </div>
            <div class="col-2">
                <label class="form-label mb-0 small text-secondary">Duración (h)</label>
                <input type="number" min="1" class="form-control form-control-sm" v-model.number="form.duracion_horas">
            </div>
            <div class="col-2">
                <label class="form-label mb-0 small text-secondary" title="Posición de este tour en la secuencia de tours incluidos de este paquete">Día</label>
                <input type="number" min="1" class="form-control form-control-sm" v-model.number="form.dia">
            </div>
        </div>
        <label class="form-label mb-1 small text-secondary">{{ esEdicion ? 'Agregar más fotos (opcional)' : 'Fotos (opcional)' }}</label>
        <input type="file" accept="image/*" multiple class="form-control form-control-sm mb-1" @change="onFotosSeleccionadas">
        <div v-if="fotosSeleccionadas.length" class="d-flex flex-wrap gap-1 mb-1">
            <img v-for="(foto, idx) in fotosSeleccionadas" :key="idx" :src="foto.previewUrl"
                style="width:50px;height:50px;object-fit:cover;border:1px solid #ccc;border-radius:3px;">
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-primary btn-sm w-100" @click="guardar" :disabled="guardando || !form.nombre.trim() || !form.descripcion.trim() || !form.destino_atractivo_id">
                <span v-if="guardando" class="spinner-border spinner-border-sm me-1"></span>{{ esEdicion ? 'Guardar' : 'Crear tour' }}
            </button>
            <button class="btn btn-outline-secondary btn-sm" @click="$emit('cancelar')"><i class="fas fa-times"></i></button>
        </div>
    </div>
</template>

<script setup lang="ts">
// Simulación Panamá (04-sep-2026) — tour incluido con itinerario real, para
// paquetes de mayorista que hoy solo tienen `incluye` (texto plano, sin
// días/horas). Crea un PaquetePlantilla "solo itinerario" (sin precio — el
// campo es nullable, confirmado en PaquetePlantillaController) + un único
// paso de itinerario, y lo vincula a la OpcionMayorista con un "Día" (orden).
// No hay buscador de "tour ya existente" (deferido, ver memoria de
// proyecto) — cada alta crea un PaquetePlantilla nuevo. `publicado_web`
// nunca se manda acá — nace en `false` por default en la migración, no
// hace falta blindaje adicional.
//
// Modo edición (04-sep-2026, mismo contrato `opcionExistente` que
// OpcionMayoristaForm bajo el nombre `tourExistente`): reusa el update()
// que YA existe de Paquetes/Tours (paquetePlantillaService.actualizar())
// para el contenido, y el PUT nuevo de la orden ('Día') del vínculo. La
// descripción mostrada al entrar en edición es la de PaquetePlantilla.
// descripcion (no un fetch aparte del paso de itinerario) — ambas se
// escriben iguales al crear, así que quedan sincronizadas mientras nadie
// edite el paso directamente desde Paquetes/Tours; si eso pasa, esta
// mini-form no lo detecta (limitación conocida y aceptada, no reemplaza al
// editor completo de itinerario).
import { ref, computed, watch } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import DestinoTreeSelect from '@/components/AgenciaViajes/DestinoTreeSelect.vue';
import { paquetePlantillaService } from '@/services/admin/paquetePlantillaService';
import { opcionMayoristaService } from '@/services/admin/opcionMayoristaService';
import type { OpcionMayoristaTour, TourItinerarioItem } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const props = defineProps<{
    opcionMayoristaId: number;
    // Ojo: es destino_atractivo_id (una fila real de destinos_atractivos,
    // para el árbol de DestinoTreeSelect) — NO el id de alternativa_destinos
    // que usa OpcionMayoristaForm bajo el nombre parecido "destinoActivoId".
    destinoAtractivoId: number | null;
    diaSugerido: number;
    tourExistente?: OpcionMayoristaTour | null;
}>();
const emit = defineEmits<{
    (e: 'agregado', payload: OpcionMayoristaTour): void;
    (e: 'actualizado', payload: OpcionMayoristaTour): void;
    (e: 'cancelar'): void;
}>();

const esEdicion = computed(() => !!props.tourExistente);

const form = ref({
    nombre: '', descripcion: '', destino_atractivo_id: props.destinoAtractivoId as number | null,
    duracion_horas: 8, dia: props.diaSugerido,
});

// Solo se resuelve en modo edición — el paso de itinerario real que hay
// que actualizar (no crear uno nuevo). Un tour armado por este mini-form
// siempre tiene exactamente 1 paso (dia_relativo=1), ver guardar() de alta.
const pasoItinerarioId = ref<number | null>(null);

const resetearCampos = async () => {
    const t = props.tourExistente;
    if (t?.paquete_plantilla) {
        const pp = t.paquete_plantilla;
        form.value = {
            nombre: pp.nombre, descripcion: pp.descripcion ?? '', destino_atractivo_id: pp.destino_atractivo_id,
            duracion_horas: pp.duracion_horas, dia: t.orden,
        };
        pasoItinerarioId.value = null;
        try {
            const res = await paquetePlantillaService.listarItinerario(pp.id);
            pasoItinerarioId.value = res.tour_itinerario_items?.[0]?.id ?? null;
        } catch {
            // Sin bloquear la edición si esto falla — guardar() más abajo
            // ya contempla pasoItinerarioId nulo (no actualiza el paso).
        }
    } else {
        form.value = {
            nombre: '', descripcion: '', destino_atractivo_id: props.destinoAtractivoId,
            duracion_horas: 8, dia: props.diaSugerido,
        };
        pasoItinerarioId.value = null;
    }
};
watch(() => props.tourExistente, resetearCampos, { immediate: true });

const fotosSeleccionadas = ref<Array<{ file: File; previewUrl: string }>>([]);
const onFotosSeleccionadas = (event: Event) => {
    const archivos = (event.target as HTMLInputElement).files;
    if (!archivos) return;
    fotosSeleccionadas.value = Array.from(archivos).map((file) => ({ file, previewUrl: URL.createObjectURL(file) }));
};

const guardando = ref(false);
const guardar = async () => {
    if (!form.value.destino_atractivo_id) return;
    guardando.value = true;
    try {
        const fd = new FormData();
        fd.append('nombre', form.value.nombre);
        fd.append('categoria', 'internacional');
        fd.append('destino_atractivo_id', String(form.value.destino_atractivo_id));
        fd.append('duracion_horas', String(form.value.duracion_horas));
        fd.append('descripcion', form.value.descripcion);
        fotosSeleccionadas.value.forEach((item) => fd.append('fotos[]', item.file));

        if (esEdicion.value && props.tourExistente) {
            const paqueteId = props.tourExistente.paquete_plantilla_id;
            await paquetePlantillaService.actualizar(paqueteId, fd);

            if (pasoItinerarioId.value) {
                await paquetePlantillaService.actualizarPasoItinerario(pasoItinerarioId.value, {
                    dia_relativo: 1,
                    descripcion: form.value.descripcion,
                } as Partial<TourItinerarioItem>);
            }

            let tourActualizado = props.tourExistente;
            if (form.value.dia !== props.tourExistente.orden) {
                const resOrden = await opcionMayoristaService.actualizarOrdenTour(props.tourExistente.id, form.value.dia);
                tourActualizado = resOrden.opcion_mayorista_tour;
            }

            emit('actualizado', tourActualizado);
        } else {
            const resTour = await paquetePlantillaService.crear(fd);
            const paqueteId = resTour.paquete_plantilla.id;

            await paquetePlantillaService.agregarPasoItinerario(paqueteId, {
                dia_relativo: 1,
                descripcion: form.value.descripcion,
            });

            const resVinculo = await opcionMayoristaService.vincularTour(props.opcionMayoristaId, {
                paquete_plantilla_id: paqueteId,
                orden: form.value.dia,
            });

            emit('agregado', resVinculo.opcion_mayorista_tour);
        }
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo guardar el tour', 'error');
    } finally {
        guardando.value = false;
    }
};
</script>
