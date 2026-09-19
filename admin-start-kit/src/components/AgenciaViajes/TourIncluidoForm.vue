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
        <!-- Guardrail (18-sep-2026) — hallazgo real del usuario: este mismo
             mini-form era la forma más rápida de armar el itinerario
             día-por-día para el PDF, pero cada "día" creaba SIEMPRE un
             PaquetePlantilla nuevo y permanente en el catálogo de
             Paquetes/Tours — incluso días de pura logística ("Arribo a
             Cusco", "Retorno") que nunca se revenden como tour. Con el
             tiempo, cada destino cotizado mintaría su propio "Arribo a
             X"/"Retorno" suelto, puro ruido creciente en el buscador de
             tours de CUALQUIER cotización futura. El toggle solo se
             ofrece al CREAR — cambiar un tour ya guardado de un tipo al
             otro es una operación distinta (crear/quitar), no una edición
             de campos, y queda fuera de alcance de este mini-form. -->
        <div v-if="!esEdicion" class="btn-group btn-group-sm w-100 mb-2" role="group">
            <input type="radio" class="btn-check" id="tour-tipo-real" :checked="!esAdhoc" @change="esAdhoc = false">
            <label class="btn btn-outline-secondary" for="tour-tipo-real">Tour real y reutilizable</label>
            <input type="radio" class="btn-check" id="tour-tipo-adhoc" :checked="esAdhoc" @change="esAdhoc = true">
            <label class="btn btn-outline-secondary" for="tour-tipo-adhoc">Solo texto de este itinerario</label>
        </div>
        <p v-if="esAdhoc" class="text-muted fst-italic mb-2" style="font-size:11px">
            Para logística sin producto vendible (Arribo, Retorno, traslado) — no crea nada en el catálogo de Paquetes/Tours, queda solo en este itinerario.
        </p>
        <input type="text" class="form-control form-control-sm mb-1" :placeholder="esAdhoc ? 'Nombre (ej. Arribo a Cusco)' : 'Nombre (ej. City Tour + Canal de Panamá)'"
            v-model="form.nombre">
        <template v-if="!esAdhoc">
        <label class="form-label mb-0 small text-secondary">Categoría de este tour</label>
        <select class="form-select form-select-sm mb-1" v-model="form.categoria">
            <option :value="null" disabled>Elegí una categoría...</option>
            <option value="local">Local</option>
            <option value="nacional">Nacional</option>
            <option value="internacional">Internacional</option>
        </select>
        <p class="text-muted fst-italic mb-1" style="font-size:11px">
            Un paquete internacional casi siempre incluye días de tours 100% nacionales (ej. "City Tour en Cusco" dentro de un paquete con vuelo a Panamá) — elegí la categoría real de ESTE tour, no la del paquete completo.
        </p>
        </template>
        <div class="mb-1">
            <RichTextEditor v-model="form.descripcion"
                placeholder="Descripción (narrativa del tour — es lo que se imprime en la sección Itinerario del PDF)" />
        </div>
        <div class="row g-1 mb-1">
            <div class="col-8" v-if="!esAdhoc">
                <label class="form-label mb-0 small text-secondary">Destino/atractivo</label>
                <DestinoTreeSelect v-model="form.destino_atractivo_id" placeholder="Zona o atractivo..." />
            </div>
            <div class="col-2" v-if="!esAdhoc">
                <label class="form-label mb-0 small text-secondary">Duración (h)</label>
                <input type="number" min="1" class="form-control form-control-sm" v-model.number="form.duracion_horas">
            </div>
            <div :class="esAdhoc ? 'col-12' : 'col-2'">
                <label class="form-label mb-0 small text-secondary" title="Posición de este tour en la secuencia de tours incluidos de este paquete">Día</label>
                <input type="number" min="1" class="form-control form-control-sm" :style="esAdhoc ? 'max-width:80px' : ''" v-model.number="form.dia">
            </div>
        </div>
        <!-- Hallazgo del usuario (05-sep-2026): en edición solo se podían
             AGREGAR fotos, nunca ver/borrar las ya guardadas — mismo patrón
             de grilla + botón de borrado que ya usa destinos/form.vue
             (sin "portada": acá las fotos no tienen un orden con significado,
             AlternativaController::itinerarioAlternativa() las imprime todas). -->
        <div v-if="esEdicion && fotosExistentes.length" class="d-flex flex-wrap gap-1 mb-1">
            <div v-for="path in fotosExistentes" :key="path" class="position-relative">
                <img :src="path" style="width:50px;height:50px;object-fit:cover;border:1px solid #ccc;border-radius:3px;cursor:zoom-in;" @click="verFotoGrande(fotosExistentes, fotosExistentes.indexOf(path))">
                <i class="fas fa-times-circle text-danger position-absolute" style="top:-6px;right:-6px;cursor:pointer;background:#fff;border-radius:50%" title="Eliminar foto" @click="eliminarFotoExistente(path)"></i>
            </div>
        </div>
        <!-- Mejora del PDF de cotización (05-sep-2026) — portada (una sola,
             sale a ancho completo antes del itinerario) + destacadas.
             Solo referencian fotos ya cargadas arriba, no suben archivos
             nuevos.
             Tope bajado de 4 a 2 (07-sep-2026): la "Galería de itinerario"
             que usaba hasta 4 destacadas se quitó del PDF (repetía las
             mismas fotos que ya salían en portada y por día) — hoy las
             destacadas solo alimentan las 2 fotos secundarias de la
             portada, marcar una 3ª o 4ª no tenía ningún efecto visible. -->
        <div v-if="esEdicion && fotosExistentes.length" class="mb-2">
            <label class="form-label mb-1 small text-secondary d-block">Portada y destacadas para el PDF</label>
            <div v-for="path in fotosExistentes" :key="'pdf-' + path" class="d-flex align-items-center gap-2 mb-1" style="font-size:11px;">
                <img :src="path" style="width:28px;height:28px;object-fit:cover;border:1px solid #ccc;border-radius:3px;">
                <label class="form-check-label mb-0"><input type="radio" class="form-check-input me-1" name="fotoPortada" :checked="fotoPortada === path" @change="fotoPortada = path; guardarFotosPdf()">Portada</label>
                <label class="form-check-label mb-0">
                    <input type="checkbox" class="form-check-input me-1" :checked="fotosDestacadas.includes(path)"
                        :disabled="!fotosDestacadas.includes(path) && fotosDestacadas.length >= 2"
                        @change="toggleFotoDestacada(path)">Destacada
                </label>
            </div>
        </div>
        <template v-if="!esAdhoc">
        <label class="form-label mb-1 small text-secondary">{{ esEdicion ? 'Agregar más fotos (opcional)' : 'Fotos (opcional)' }}</label>
        <input type="file" accept="image/*" multiple class="form-control form-control-sm mb-1" @change="onFotosSeleccionadas">
        <div v-if="fotosSeleccionadas.length" class="d-flex flex-wrap gap-1 mb-1">
            <img v-for="(foto, idx) in fotosSeleccionadas" :key="idx" :src="foto.previewUrl"
                style="width:50px;height:50px;object-fit:cover;border:1px solid #ccc;border-radius:3px;cursor:zoom-in;" @click="verFotoGrande(fotosSeleccionadas.map((f) => f.previewUrl), idx)">
        </div>
        </template>
        <div class="d-flex gap-2">
            <button class="btn btn-primary btn-sm w-100" @click="guardar" :disabled="guardando || !form.nombre.trim() || descripcionVacia || (!esAdhoc && (!form.destino_atractivo_id || !form.categoria))">
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
// una entidad completamente distinta).
// Guardrail (18-sep-2026) — antes filtraba por categoria='internacional'
// porque este form solo creaba tours con esa categoría a fuego (ver
// guardar() más abajo, bug real: un tour 100% doméstico dentro de un
// paquete internacional, ej. "City Tour en Cusco", quedaba mal-etiquetado).
// Ahora que la categoría se elige de verdad, el buscador ya no puede
// asumir cuál es — busca en TODAS, así encuentra un tour ya corregido a
// nacional/local igual que uno internacional real.
import { ref, computed, watch } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import DestinoTreeSelect from '@/components/AgenciaViajes/DestinoTreeSelect.vue';
import RichTextEditor from '@/components/RichTextEditor.vue';
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
    // Guardrail (18-sep-2026) — antes esto se mandaba SIEMPRE como
    // 'internacional' a fuego (ver guardar() más abajo), sin importar el
    // contenido real del tour. Es el mismo bug que ya había mal-etiquetado
    // tours 100% domésticos (Cusco) — sin default a propósito, para forzar
    // una elección activa en vez de repetir el error en silencio.
    categoria: null as 'local' | 'nacional' | 'internacional' | null,
});

// Guardrail (18-sep-2026) — ver comentario del template. true = logística
// de solo texto para ESTE itinerario (nunca crea PaquetePlantilla); false =
// tour real y reutilizable, comportamiento original sin cambios.
const esAdhoc = ref(false);

// Editor de texto enriquecido (07-sep-2026) — Quill nunca deja el v-model
// en '' cuando está "vacío" a la vista, emite '<p><br></p>'. Mismo guard
// que paquetes/detalle.vue::descripcionPasoVacia().
const descripcionVacia = computed(() => form.value.descripcion.replace(/<[^>]*>/g, '').trim().length === 0);

// Solo se resuelve en modo edición — el paso de itinerario real que hay
// que actualizar (no crear uno nuevo). Un tour armado por este mini-form
// siempre tiene exactamente 1 paso (dia_relativo=1), ver guardar() de alta.
const pasoItinerarioId = ref<number | null>(null);

const fotosExistentes = ref<string[]>([]);
// Mejora del PDF de cotización (05-sep-2026) — ver plan-mejora-pdf-cotizacion-cliente.md
// §4.5. paqueteIdActual guarda el id real (no siempre disponible como prop
// suelta) para poder llamar a actualizarFotosPdf() desde los handlers.
const fotoPortada = ref<string | null>(null);
const fotosDestacadas = ref<string[]>([]);
const paqueteIdActual = ref<number | null>(null);

const resetearCampos = async () => {
    const t = props.tourExistente;
    if (t?.paquete_plantilla) {
        esAdhoc.value = false;
        const pp = t.paquete_plantilla;
        form.value = {
            nombre: pp.nombre, descripcion: pp.descripcion ?? '', destino_atractivo_id: pp.destino_atractivo_id,
            duracion_horas: pp.duracion_horas, dia: t.orden, categoria: pp.categoria,
        };
        fotosExistentes.value = pp.fotos ?? [];
        fotoPortada.value = pp.foto_portada ?? null;
        fotosDestacadas.value = pp.fotos_destacadas_pdf ?? [];
        paqueteIdActual.value = pp.id;
        pasoItinerarioId.value = null;
        try {
            const res = await paquetePlantillaService.listarItinerario(pp.id);
            pasoItinerarioId.value = res.tour_itinerario_items?.[0]?.id ?? null;
        } catch {
            // Sin bloquear la edición si esto falla — guardar() más abajo
            // ya contempla pasoItinerarioId nulo (no actualiza el paso).
        }
    } else if (t) {
        // Editando un tour ad-hoc (sin paquete_plantilla) — nombre/descripcion
        // viven directo en OpcionMayoristaTour, no hay itinerario/fotos/destino
        // que cargar.
        esAdhoc.value = true;
        form.value = {
            nombre: t.nombre ?? '', descripcion: t.descripcion ?? '', destino_atractivo_id: null,
            duracion_horas: 8, dia: t.orden, categoria: null,
        };
        fotosExistentes.value = [];
        fotoPortada.value = null;
        fotosDestacadas.value = [];
        paqueteIdActual.value = null;
        pasoItinerarioId.value = null;
    } else {
        esAdhoc.value = false;
        form.value = {
            nombre: '', descripcion: '', destino_atractivo_id: props.destinoAtractivoId,
            duracion_horas: 8, dia: props.diaSugerido, categoria: null,
        };
        fotosExistentes.value = [];
        fotoPortada.value = null;
        fotosDestacadas.value = [];
        paqueteIdActual.value = null;
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
            if (fotoPortada.value === path) fotoPortada.value = null;
            fotosDestacadas.value = fotosDestacadas.value.filter((p) => p !== path);
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo eliminar la foto', 'error');
        }
    });
};

// Mejora del PDF de cotización (05-sep-2026) — inmediato (sin "guardar" que
// las agrupe), mismo criterio que las fotos en sí: portada/destacada se
// persiste apenas el vendedor la marca, plan-mejora-pdf-cotizacion-cliente.md
// §4.5.
const guardarFotosPdf = async () => {
    if (!paqueteIdActual.value) return;
    try {
        await paquetePlantillaService.actualizarFotosPdf(paqueteIdActual.value, {
            foto_portada: fotoPortada.value,
            fotos_destacadas_pdf: fotosDestacadas.value,
        });
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo actualizar la portada/destacadas', 'error');
    }
};

const toggleFotoDestacada = (path: string) => {
    if (fotosDestacadas.value.includes(path)) {
        fotosDestacadas.value = fotosDestacadas.value.filter((p) => p !== path);
    } else {
        if (fotosDestacadas.value.length >= 2) return;
        fotosDestacadas.value = [...fotosDestacadas.value, path];
    }
    guardarFotosPdf();
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
    const res = await paquetePlantillaService.listar({ search: tourBuscarQuery.value });
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
    if (esAdhoc.value) {
        guardando.value = true;
        try {
            if (esEdicion.value && props.tourExistente) {
                const res = await opcionMayoristaService.actualizarOrdenTour(props.tourExistente.id, form.value.dia, {
                    nombre: form.value.nombre,
                    descripcion: form.value.descripcion,
                });
                emit('actualizado', res.opcion_mayorista_tour);
            } else {
                const res = await opcionMayoristaService.vincularTour(props.opcionMayoristaId, {
                    nombre: form.value.nombre,
                    descripcion: form.value.descripcion,
                    orden: form.value.dia,
                });
                emit('agregado', res.opcion_mayorista_tour);
            }
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo guardar el tour', 'error');
        } finally {
            guardando.value = false;
        }
        return;
    }

    if (!form.value.destino_atractivo_id) return;
    guardando.value = true;
    try {
        if (!form.value.categoria) return;
        const fd = new FormData();
        fd.append('nombre', form.value.nombre);
        fd.append('categoria', form.value.categoria);
        fd.append('destino_atractivo_id', String(form.value.destino_atractivo_id));
        fd.append('duracion_horas', String(form.value.duracion_horas));
        // Guardrail (18-sep-2026) — antes esto mandaba form.value.descripcion
        // TAMBIÉN acá (PaquetePlantilla.descripcion, "Descripción comercial"
        // de Datos generales), duplicando el mismo texto largo que ya se
        // manda abajo al paso de Itinerario. Hallazgo real del usuario: el
        // PDF SOLO lee la descripción del paso de itinerario
        // (AlternativaPdfService::itinerarioAlternativa(), $paso->descripcion)
        // — descripcion a nivel de PaquetePlantilla no se imprime en ningún
        // lado del PDF, así que duplicarla acá no sumaba nada, solo ensuciaba
        // "Datos generales" con el itinerario completo. Se deja sin mandar:
        // si el vendedor quiere un resumen corto ahí, lo escribe aparte
        // desde Paquetes/Tours > Datos generales > Editar (no se toca acá).
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
