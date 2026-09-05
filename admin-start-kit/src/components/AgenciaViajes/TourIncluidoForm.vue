<template>
    <div class="card border p-2 small">
        <!-- Hallazgo del usuario (05-sep-2026): un tour incluido borrado del
             lienzo (quitarTour() solo desvincula, el PaquetePlantilla real
             queda intacto en el catálogo) no tenía forma de volver a
             agregarse sin re-escribirlo entero, duplicando el catálogo.
             Mismo patrón "buscar antes de crear" que ya usa el buscador de
             contenido reutilizable de OpcionMayoristaForm — acá busca
             directo en el catálogo de Paquetes/Tours (paquetes_plantilla),
             no en contenido_tours (que es solo texto suelto para "Incluye"). -->
        <template v-if="!esEdicion">
            <div class="position-relative mb-2">
                <input type="text" class="form-control form-control-sm" placeholder="Buscar un tour ya cargado (evita duplicarlo, ej. 'City Tour Panamá')..."
                    v-model="tourBuscarQuery" @input="onTourBuscarInput">
                <div v-if="tourBuscarResultados.length" class="list-group position-absolute w-100" style="z-index: 10;">
                    <button v-for="t in tourBuscarResultados" :key="t.id" type="button"
                        class="list-group-item list-group-item-action py-1 small" @click="seleccionarTourExistente(t)">
                        {{ t.nombre }}
                    </button>
                </div>
            </div>
            <div v-if="tourExistenteSeleccionado" class="border rounded p-2 mb-2 bg-light-subtle">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="text-success"><i class="fas fa-check-circle me-1"></i>{{ tourExistenteSeleccionado.nombre }}</span>
                    <i class="fas fa-times text-muted" style="cursor:pointer" title="Buscar otro / crear nuevo" @click="limpiarTourExistenteSeleccionado"></i>
                </div>
                <label class="form-label mb-0 small text-secondary mt-1" title="Posición de este tour en la secuencia de tours incluidos de este paquete">Día</label>
                <input type="number" min="1" class="form-control form-control-sm" style="max-width:80px" v-model.number="form.dia">
                <button class="btn btn-primary btn-sm w-100 mt-2" @click="vincularExistente" :disabled="guardando">
                    <span v-if="guardando" class="spinner-border spinner-border-sm me-1"></span>Vincular este tour
                </button>
            </div>
            <div v-if="!tourExistenteSeleccionado" class="text-muted text-center mb-2" style="font-size:11px">— o crear un tour nuevo —</div>
        </template>
        <template v-if="esEdicion || !tourExistenteSeleccionado">
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
        <!-- Hallazgo del usuario (05-sep-2026): en edición solo se podían
             AGREGAR fotos, nunca ver/borrar las ya guardadas — mismo patrón
             de grilla + botón de borrado que ya usa destinos/form.vue
             (sin "portada": acá las fotos no tienen un orden con significado,
             AlternativaController::itinerarioAlternativa() las imprime todas). -->
        <div v-if="esEdicion && fotosExistentes.length" class="d-flex flex-wrap gap-1 mb-2">
            <div v-for="path in fotosExistentes" :key="path" class="position-relative">
                <img :src="path" style="width:50px;height:50px;object-fit:cover;border:1px solid #ccc;border-radius:3px;cursor:zoom-in;" @click="verFotoGrande(fotosExistentes, fotosExistentes.indexOf(path))">
                <i class="fas fa-times-circle text-danger position-absolute" style="top:-6px;right:-6px;cursor:pointer;background:#fff;border-radius:50%" title="Eliminar foto" @click="eliminarFotoExistente(path)"></i>
            </div>
        </div>
        <label class="form-label mb-1 small text-secondary">{{ esEdicion ? 'Agregar más fotos (opcional)' : 'Fotos (opcional)' }}</label>
        <input type="file" accept="image/*" multiple class="form-control form-control-sm mb-1" @change="onFotosSeleccionadas">
        <div v-if="fotosSeleccionadas.length" class="d-flex flex-wrap gap-1 mb-1">
            <img v-for="(foto, idx) in fotosSeleccionadas" :key="idx" :src="foto.previewUrl"
                style="width:50px;height:50px;object-fit:cover;border:1px solid #ccc;border-radius:3px;cursor:zoom-in;" @click="verFotoGrande(fotosSeleccionadas.map((f) => f.previewUrl), idx)">
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-primary btn-sm w-100" @click="guardar" :disabled="guardando || !form.nombre.trim() || !form.descripcion.trim() || !form.destino_atractivo_id">
                <span v-if="guardando" class="spinner-border spinner-border-sm me-1"></span>{{ esEdicion ? 'Guardar' : 'Crear tour' }}
            </button>
            <button class="btn btn-outline-secondary btn-sm" @click="$emit('cancelar')"><i class="fas fa-times"></i></button>
        </div>
        </template>
        <button v-if="!esEdicion && tourExistenteSeleccionado" class="btn btn-outline-secondary btn-sm w-100 mt-1" @click="$emit('cancelar')"><i class="fas fa-times me-1"></i>Cancelar</button>
    </div>
</template>

<script setup lang="ts">
// Simulación Panamá (04-sep-2026) — tour incluido con itinerario real, para
// paquetes de mayorista que hoy solo tienen `incluye` (texto plano, sin
// días/horas). Crea un PaquetePlantilla "solo itinerario" (sin precio — el
// campo es nullable, confirmado en PaquetePlantillaController) + un único
// paso de itinerario, y lo vincula a la OpcionMayorista con un "Día" (orden).
// `publicado_web` nunca se manda acá — nace en `false` por default en la
// migración, no hace falta blindaje adicional.
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
//
// Buscador "tour ya existente" (05-sep-2026, hallazgo real del usuario):
// quitarTour() (ver OpcionMayoristaController) solo borra el VÍNCULO — el
// PaquetePlantilla real queda intacto en el catálogo de Paquetes/Tours. Sin
// esto, la única forma de "recuperar" un tour quitado por error (o de
// reusar uno ya cargado en otra opción de mayorista) era re-escribirlo
// entero, duplicando el catálogo. Mismo patrón "buscar antes de crear" que
// ya usa el buscador de contenido reutilizable de OpcionMayoristaForm, pero
// acá busca en paquetes_plantilla (PaquetePlantillaController::index()),
// no en contenido_tours (que es solo texto suelto para el campo "Incluye",
// una entidad completamente distinta). Filtra por categoria='internacional'
// porque es lo único que este form crea/consume — un tour Local/Nacional no
// aplica al itinerario de un paquete de mayorista.
import { ref, computed, watch } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import DestinoTreeSelect from '@/components/AgenciaViajes/DestinoTreeSelect.vue';
import { paquetePlantillaService } from '@/services/admin/paquetePlantillaService';
import { opcionMayoristaService } from '@/services/admin/opcionMayoristaService';
import type { OpcionMayoristaTour, PaquetePlantilla, TourItinerarioItem } from '@/types/agencia-viajes';

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

const fotosExistentes = ref<string[]>([]);

const resetearCampos = async () => {
    const t = props.tourExistente;
    if (t?.paquete_plantilla) {
        const pp = t.paquete_plantilla;
        form.value = {
            nombre: pp.nombre, descripcion: pp.descripcion ?? '', destino_atractivo_id: pp.destino_atractivo_id,
            duracion_horas: pp.duracion_horas, dia: t.orden,
        };
        fotosExistentes.value = pp.fotos ?? [];
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
        fotosExistentes.value = [];
        pasoItinerarioId.value = null;
    }
};
watch(() => props.tourExistente, resetearCampos, { immediate: true });

const eliminarFotoExistente = (path: string) => {
    if (!props.tourExistente) return;
    (Swal as TVueSwalInstance).fire({
        title: 'Confirmar eliminación', text: '¿Eliminar esta foto?', icon: 'warning',
        showCancelButton: true, confirmButtonColor: '#d33', confirmButtonText: 'Sí, eliminar',
    }).then(async (result: any) => {
        if (!result.isConfirmed || !props.tourExistente) return;
        try {
            await paquetePlantillaService.eliminarFoto(props.tourExistente.paquete_plantilla_id, path);
            fotosExistentes.value = fotosExistentes.value.filter((p) => p !== path);
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo eliminar la foto', 'error');
        }
    });
};

// Mismo lightbox con teclado (flechas)/contador que destinos/form.vue —
// duplicado a propósito (es autocontenido, sin estado compartido) en vez de
// extraer un composable para 2 usos.
const verFotoGrande = (fotos: string[], indexInicial: number) => {
    let index = indexInicial;
    const mostrar = (i: number) => {
        const img = document.getElementById('swal-foto-grande') as HTMLImageElement | null;
        if (img) img.src = fotos[i];
        const contador = document.getElementById('swal-foto-contador');
        if (contador) contador.textContent = `${i + 1} / ${fotos.length}`;
    };
    const onKeydown = (e: KeyboardEvent) => {
        if (e.key === 'ArrowLeft') { index = (index - 1 + fotos.length) % fotos.length; mostrar(index); }
        if (e.key === 'ArrowRight') { index = (index + 1) % fotos.length; mostrar(index); }
    };

    (Swal as TVueSwalInstance).fire({
        html: `
            <div style="position:relative;display:flex;align-items:center;justify-content:center;">
                ${fotos.length > 1 ? '<button id="swal-foto-prev" type="button" style="position:absolute;left:0;background:rgba(0,0,0,0.5);color:#fff;border:none;border-radius:50%;width:40px;height:40px;font-size:18px;cursor:pointer;">‹</button>' : ''}
                <img id="swal-foto-grande" src="${fotos[index]}" style="max-width:100%;max-height:75vh;border-radius:4px;">
                ${fotos.length > 1 ? '<button id="swal-foto-next" type="button" style="position:absolute;right:0;background:rgba(0,0,0,0.5);color:#fff;border:none;border-radius:50%;width:40px;height:40px;font-size:18px;cursor:pointer;">›</button>' : ''}
            </div>
            ${fotos.length > 1 ? `<div id="swal-foto-contador" style="color:#fff;margin-top:0.5rem;font-size:13px;">${index + 1} / ${fotos.length}</div>` : ''}
        `,
        showConfirmButton: false,
        showCloseButton: true,
        width: 'auto',
        padding: '0.5rem',
        background: 'transparent',
        didOpen: () => {
            document.getElementById('swal-foto-prev')?.addEventListener('click', () => { index = (index - 1 + fotos.length) % fotos.length; mostrar(index); });
            document.getElementById('swal-foto-next')?.addEventListener('click', () => { index = (index + 1) % fotos.length; mostrar(index); });
            document.addEventListener('keydown', onKeydown);
        },
        willClose: () => document.removeEventListener('keydown', onKeydown),
    });
};

// ── Buscador "tour ya existente" (05-sep-2026) — ver comentario de arriba.
const tourBuscarQuery = ref('');
const tourBuscarResultados = ref<PaquetePlantilla[]>([]);
const tourExistenteSeleccionado = ref<PaquetePlantilla | null>(null);
let tourBuscarTimeout: any = null;

const buscarTourExistente = async () => {
    if (!tourBuscarQuery.value.trim()) {
        tourBuscarResultados.value = [];
        return;
    }
    const res = await paquetePlantillaService.listar({ search: tourBuscarQuery.value, categoria: 'internacional' });
    tourBuscarResultados.value = res.paquetes_plantilla ?? [];
};

const onTourBuscarInput = () => {
    clearTimeout(tourBuscarTimeout);
    tourBuscarTimeout = setTimeout(buscarTourExistente, 300);
};

const seleccionarTourExistente = (paquete: PaquetePlantilla) => {
    tourExistenteSeleccionado.value = paquete;
    tourBuscarResultados.value = [];
    tourBuscarQuery.value = paquete.nombre;
};

const limpiarTourExistenteSeleccionado = () => {
    tourExistenteSeleccionado.value = null;
    tourBuscarQuery.value = '';
    tourBuscarResultados.value = [];
};

const vincularExistente = async () => {
    if (!tourExistenteSeleccionado.value) return;
    guardando.value = true;
    try {
        const res = await opcionMayoristaService.vincularTour(props.opcionMayoristaId, {
            paquete_plantilla_id: tourExistenteSeleccionado.value.id,
            orden: form.value.dia,
        });
        emit('agregado', res.opcion_mayorista_tour);
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo vincular el tour', 'error');
    } finally {
        guardando.value = false;
    }
};

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
