<template>
    <DefaultLayout>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-map-marked-alt me-2 text-primary"></i>
                    {{ esEdicion ? 'Editar' : 'Nuevo' }} Destino/Atractivo
                </h5>
                <small class="text-muted">Árbol de zonas, lugares y atractivos — fotos, portada y orden se administran acá</small>
            </div>
            <router-link to="/agencia-viajes/destinos" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Volver
            </router-link>
        </div>

        <!-- ═══ Tipo: zona | lugar | atractivo ═══ -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <label class="form-label mb-2 small fw-semibold text-secondary">¿Qué estás creando? *</label>
                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <div class="card h-100 tipo-card-select" :class="{ 'border-primary border-2 bg-primary bg-opacity-10': form.tipo === 'zona' }"
                            style="cursor:pointer" @click="seleccionarTipo('zona')">
                            <div class="card-body text-center py-4">
                                <i class="fas fa-globe-americas fs-2 text-primary mb-2 d-block"></i>
                                <div class="fw-semibold text-dark">Zona</div>
                                <small class="text-muted">Nivel más alto del árbol — ej. "Alto Mayo"</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="card h-100 tipo-card-select" :class="{ 'border-primary border-2 bg-primary bg-opacity-10': form.tipo === 'lugar' }"
                            style="cursor:pointer" @click="seleccionarTipo('lugar')">
                            <div class="card-body text-center py-4">
                                <i class="fas fa-map-marker-alt fs-2 text-primary mb-2 d-block"></i>
                                <div class="fw-semibold text-dark">Lugar</div>
                                <small class="text-muted">Cuelga de una zona — ej. "Lamas"</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="card h-100 tipo-card-select" :class="{ 'border-primary border-2 bg-primary bg-opacity-10': form.tipo === 'atractivo' }"
                            style="cursor:pointer" @click="seleccionarTipo('atractivo')">
                            <div class="card-body text-center py-4">
                                <i class="fas fa-mountain fs-2 text-primary mb-2 d-block"></i>
                                <div class="fw-semibold text-dark">Atractivo</div>
                                <small class="text-muted">Cuelga de una zona o un lugar — ej. "Laguna Azul"</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ Datos generales ═══ -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12" v-if="form.tipo !== 'zona'">
                        <label class="form-label mb-1 small fw-semibold text-secondary">
                            {{ form.tipo === 'lugar' ? 'Zona padre *' : 'Zona o lugar padre *' }}
                        </label>
                        <DestinoTreeSelect
                            v-model="form.parent_id"
                            nivel-min="zona"
                            :nivel-max="form.tipo === 'lugar' ? 'zona' : 'lugar'"
                            :placeholder="form.tipo === 'lugar' ? 'Buscar zona...' : 'Buscar zona o lugar...'"
                        />
                    </div>
                    <div class="col-12">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Nombre *</label>
                        <input type="text" class="form-control form-control-sm" v-model="form.nombre" placeholder="Ej. Alto Mayo">
                    </div>
                    <div class="col-12">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Descripción</label>
                        <RichTextEditor v-model="form.descripcion" />
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ Fotos ═══ -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-bottom py-2 d-flex align-items-center justify-content-between">
                <span class="fw-semibold text-dark small">Fotos</span>
                <small class="text-muted">{{ totalFotos }}/{{ MAX_FOTOS }} · máx. 5MB por foto</small>
            </div>
            <div class="card-body py-3">
                <!-- Fotos ya guardadas (solo en edición) -->
                <div v-if="fotosExistentes.length > 0" class="mb-3">
                    <small class="text-muted d-block mb-2">Fotos guardadas — la primera es la portada</small>
                    <div class="d-flex flex-wrap gap-2">
                        <div v-for="(path, index) in fotosExistentes" :key="path" class="foto-item position-relative" :class="{ 'is-portada': index === 0 }">
                            <img :src="path" class="img-thumbnail" style="width:110px;height:110px;object-fit:cover;cursor:zoom-in;" @click="verFotoGrande(fotosExistentes, index)">
                            <button class="btn btn-danger btn-sm position-absolute top-0 end-0 m-1" title="Eliminar" @click="eliminarFotoExistente(path)">
                                <i class="fas fa-times"></i>
                            </button>
                            <span v-if="index === 0" class="badge bg-success position-absolute bottom-0 start-0 m-1">Portada</span>
                            <button v-else class="btn btn-primary btn-sm position-absolute bottom-0 start-0 m-1" title="Marcar como portada" @click="marcarComoPortada(path)">
                                <i class="fas fa-star"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Fotos nuevas seleccionadas, pendientes de subir -->
                <div v-if="archivosNuevos.length > 0" class="mb-3">
                    <small class="text-muted d-block mb-2">Fotos nuevas (se suben al guardar)</small>
                    <div class="d-flex flex-wrap gap-2">
                        <div v-for="(item, index) in archivosNuevos" :key="item.previewUrl" class="foto-item position-relative" :class="{ 'is-portada': fotosExistentes.length === 0 && index === 0 }">
                            <img :src="item.previewUrl" class="img-thumbnail" style="width:110px;height:110px;object-fit:cover;cursor:zoom-in;" @click="verFotoGrande(archivosNuevos.map((a) => a.previewUrl), index)">
                            <button class="btn btn-danger btn-sm position-absolute top-0 end-0 m-1" title="Quitar" @click="quitarPendiente(index)">
                                <i class="fas fa-times"></i>
                            </button>
                            <span class="badge bg-info position-absolute top-0 start-0 m-1" style="font-size:9px;">Nuevo</span>
                            <span v-if="fotosExistentes.length === 0 && index === 0" class="badge bg-success position-absolute bottom-0 start-0 m-1">Portada</span>
                            <button v-else-if="fotosExistentes.length === 0" class="btn btn-primary btn-sm position-absolute bottom-0 start-0 m-1" title="Mover al inicio" @click="moverPendienteAlInicio(index)">
                                <i class="fas fa-star"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <input type="file" class="form-control form-control-sm" accept="image/*" multiple
                    :disabled="limiteFotosAlcanzado" @change="onArchivosSeleccionados">
                <small v-if="limiteFotosAlcanzado" class="text-danger d-block mt-1">
                    Ya alcanzaste el máximo de {{ MAX_FOTOS }} fotos — elimina alguna para agregar otra.
                </small>
            </div>
        </div>

        <div class="d-flex justify-content-end mb-4">
            <button class="btn btn-primary fw-semibold" @click="guardar" :disabled="guardando">
                <span v-if="guardando" class="spinner-border spinner-border-sm me-2"></span>
                <i v-else class="fas fa-check me-2"></i>
                {{ esEdicion ? 'Guardar cambios' : 'Crear' }}
            </button>
        </div>
    </DefaultLayout>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import DestinoTreeSelect from '@/components/AgenciaViajes/DestinoTreeSelect.vue';
import RichTextEditor from '@/components/RichTextEditor.vue';
import { destinoAtractivoService } from '@/services/admin/destinoAtractivoService';
import type { DestinoAtractivo } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;
type Nivel = 'zona' | 'lugar' | 'atractivo';
type ArchivoPendiente = { file: File; previewUrl: string };

const MAX_KB_POR_FOTO = 5120;
const MAX_FOTOS = 10;

const route = useRoute();
const router = useRouter();

const esEdicion = computed(() => !!route.params.id);
const destinoId = computed(() => Number(route.params.id));

const guardando = ref<boolean>(false);

const form = ref<{ nombre: string; tipo: Nivel; parent_id: number | null; descripcion: string | null }>({
    nombre: '',
    tipo: 'zona',
    parent_id: null,
    descripcion: null,
});

const fotosExistentes = ref<string[]>([]);
const archivosNuevos = ref<ArchivoPendiente[]>([]);

const totalFotos = computed(() => fotosExistentes.value.length + archivosNuevos.value.length);
const limiteFotosAlcanzado = computed(() => totalFotos.value >= MAX_FOTOS);

const seleccionarTipo = (tipo: Nivel) => {
    if (form.value.tipo === tipo) return;
    form.value.tipo = tipo;
    form.value.parent_id = null;
};

// Sin endpoint "show" dedicado — el árbol completo (destinoAtractivoService.arbol())
// ya trae todos los campos de cada nodo (mismo criterio que index.vue), alcanza con
// aplanarlo y buscar por id en vez de agregar un endpoint nuevo solo para esto.
const aplanarArbol = (nodos: DestinoAtractivo[]): DestinoAtractivo[] => {
    let resultado: DestinoAtractivo[] = [];
    for (const nodo of nodos) {
        resultado.push(nodo);
        if (nodo.hijos && nodo.hijos.length > 0) {
            resultado = resultado.concat(aplanarArbol(nodo.hijos));
        }
    }
    return resultado;
};

const cargarDatosIniciales = async () => {
    try {
        const res = await destinoAtractivoService.arbol();
        const todos = aplanarArbol(res.destinos_atractivos);

        if (esEdicion.value) {
            const encontrado = todos.find((d) => d.id === destinoId.value);
            if (!encontrado) {
                await (Swal as TVueSwalInstance).fire('Error', 'No se encontró el destino/atractivo.', 'error');
                router.push('/agencia-viajes/destinos');
                return;
            }
            form.value = {
                nombre: encontrado.nombre,
                tipo: encontrado.tipo,
                parent_id: encontrado.parent_id ?? null,
                descripcion: encontrado.descripcion ?? null,
            };
            fotosExistentes.value = encontrado.fotos ?? [];
            return;
        }

        // Creación con padre sugerido (ej. botón "+" de una fila en index.vue).
        const parentIdQuery = route.query.parent_id ? Number(route.query.parent_id) : null;
        if (parentIdQuery) {
            const padre = todos.find((d) => d.id === parentIdQuery);
            if (padre) {
                form.value.parent_id = padre.id;
                form.value.tipo = padre.tipo === 'zona' ? 'lugar' : 'atractivo';
            }
        }
    } catch (error) {
        console.log(error);
    }
};

const onArchivosSeleccionados = (event: Event) => {
    const input = event.target as HTMLInputElement;
    const archivos = Array.from(input.files ?? []);
    input.value = '';

    const disponibles = MAX_FOTOS - totalFotos.value;
    if (disponibles <= 0) {
        (Swal as TVueSwalInstance).fire('Límite alcanzado', `Ya tienes el máximo de ${MAX_FOTOS} fotos.`, 'warning');
        return;
    }

    const rechazadas: string[] = [];
    let agregadas = 0;
    for (const archivo of archivos) {
        if (agregadas >= disponibles) {
            rechazadas.push(`${archivo.name}: no se agregó — ya alcanzaste el máximo de ${MAX_FOTOS} fotos.`);
            continue;
        }
        if (archivo.size > MAX_KB_POR_FOTO * 1024) {
            rechazadas.push(`${archivo.name}: supera el máximo de 5MB.`);
            continue;
        }
        archivosNuevos.value.push({ file: archivo, previewUrl: URL.createObjectURL(archivo) });
        agregadas++;
    }

    if (rechazadas.length > 0) {
        (Swal as TVueSwalInstance).fire({ icon: 'warning', title: 'Algunas fotos no se agregaron', html: rechazadas.join('<br>') });
    }
};

const quitarPendiente = (index: number) => {
    URL.revokeObjectURL(archivosNuevos.value[index].previewUrl);
    archivosNuevos.value.splice(index, 1);
};

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

const moverPendienteAlInicio = (index: number) => {
    if (index === 0) return;
    const [item] = archivosNuevos.value.splice(index, 1);
    archivosNuevos.value.unshift(item);
};

const eliminarFotoExistente = (path: string) => {
    (Swal as TVueSwalInstance).fire({
        title: 'Confirmar eliminación', text: '¿Eliminar esta foto?', icon: 'warning',
        showCancelButton: true, confirmButtonColor: '#d33', confirmButtonText: 'Sí, eliminar',
    }).then(async (result: any) => {
        if (!result.isConfirmed) return;
        try {
            await destinoAtractivoService.eliminarFoto(destinoId.value, path);
            fotosExistentes.value = fotosExistentes.value.filter((p) => p !== path);
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo eliminar la foto', 'error');
        }
    });
};

const marcarComoPortada = async (path: string) => {
    const nuevoOrden = [path, ...fotosExistentes.value.filter((p) => p !== path)];
    try {
        await destinoAtractivoService.ordenarFotos(destinoId.value, nuevoOrden);
        fotosExistentes.value = nuevoOrden;
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo actualizar el orden', 'error');
    }
};

const guardar = async () => {
    if (!form.value.nombre?.trim()) {
        (Swal as TVueSwalInstance).fire('Error', 'El nombre es obligatorio.', 'error');
        return;
    }
    if (form.value.tipo !== 'zona' && !form.value.parent_id) {
        (Swal as TVueSwalInstance).fire('Error', 'Selecciona el padre correspondiente.', 'error');
        return;
    }

    guardando.value = true;
    try {
        let payload: FormData | Record<string, any>;
        if (archivosNuevos.value.length > 0) {
            const fd = new FormData();
            fd.append('nombre', form.value.nombre);
            fd.append('tipo', form.value.tipo);
            if (form.value.parent_id) fd.append('parent_id', String(form.value.parent_id));
            if (form.value.descripcion) fd.append('descripcion', form.value.descripcion);
            archivosNuevos.value.forEach((item) => fd.append('fotos[]', item.file));
            payload = fd;
        } else {
            payload = {
                nombre: form.value.nombre,
                tipo: form.value.tipo,
                parent_id: form.value.parent_id,
                descripcion: form.value.descripcion,
            };
        }

        const res = esEdicion.value
            ? await destinoAtractivoService.actualizar(destinoId.value, payload)
            : await destinoAtractivoService.crear(payload);

        if (res.fotos_rechazadas?.length) {
            const detalle = res.fotos_rechazadas.map((r: { nombre: string; motivo: string }) => `${r.nombre}: ${r.motivo}`).join('<br>');
            await (Swal as TVueSwalInstance).fire({ icon: 'warning', title: 'Guardado con observaciones', html: `${res.message}<br><br>Fotos no subidas:<br>${detalle}` });
        } else {
            await (Swal as TVueSwalInstance).fire('Listo', res.message, 'success');
        }
        router.push('/agencia-viajes/destinos');
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo guardar', 'error');
    } finally {
        guardando.value = false;
    }
};

onMounted(async () => {
    await cargarDatosIniciales();
});
</script>

<style scoped>
.tipo-card-select {
    transition: border-color 0.15s ease, background-color 0.15s ease;
}
.tipo-card-select:hover {
    border-color: var(--bs-primary);
}
.foto-item {
    border-radius: 8px;
    overflow: hidden;
    border: 2px solid transparent;
}
.foto-item.is-portada {
    border-color: #28a745;
    box-shadow: 0 0 0 2px rgba(40, 167, 69, 0.3);
}
.foto-item .btn {
    border-radius: 50%;
    width: 26px;
    height: 26px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0;
}
</style>
