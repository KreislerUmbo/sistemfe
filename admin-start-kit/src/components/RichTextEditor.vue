<template>
    <div class="rich-text-editor">
        <QuillEditor
            ref="quillRef"
            :content="modelValue ?? ''"
            content-type="html"
            theme="snow"
            :toolbar="toolbar"
            :placeholder="placeholder"
            @update:content="onUpdate"
        />
    </div>
</template>

<script setup lang="ts">
// Wrapper reusable del editor de texto enriquecido (@vueup/vue-quill, ya
// instalado — CSS snow importado globalmente en main.ts). Toolbar reducida
// a propósito (negrita, cursiva, listas, títulos básicos) — sin imágenes ni
// video, no hay endpoint de subida de archivos para contenido embebido en
// este editor todavía.
import { ref, watch } from 'vue';
import { QuillEditor } from '@vueup/vue-quill';

const props = withDefaults(defineProps<{
    modelValue?: string | null;
    placeholder?: string;
}>(), {
    placeholder: 'Escribe aquí...',
});

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
}>();

const toolbar = [
    [{ header: [1, 2, 3, false] }],
    ['bold', 'italic', 'underline'],
    [{ list: 'ordered' }, { list: 'bullet' }],
    ['clean'],
];

const onUpdate = (html: string) => {
    emit('update:modelValue', html);
};

// @vueup/vue-quill ignora el prop `content` cuando el valor nuevo es falsy
// (su watcher interno corta con `!newContent` antes de limpiar el editor),
// así que resetear modelValue a '' desde afuera (ej. después de guardar un
// formulario que reusa este editor) nunca se reflejaba visualmente — el
// texto tipeado quedaba pegado en pantalla aunque el modelo ya estuviera
// vacío. Se fuerza la limpieza acá con el método expuesto setHTML().
const quillRef = ref<InstanceType<typeof QuillEditor> | null>(null);
watch(() => props.modelValue, (value) => {
    if (!value) {
        quillRef.value?.setHTML('');
    }
});
</script>

<style scoped>
.rich-text-editor :deep(.ql-container) {
    min-height: 120px;
    font-size: 0.875rem;
}

.rich-text-editor :deep(.ql-editor) {
    min-height: 120px;
}
</style>
