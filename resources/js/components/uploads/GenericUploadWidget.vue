<script setup lang="ts">
import { computed, ref } from 'vue'; // Ej.: ref("document_pdf")
import { Upload, X } from 'lucide-vue-next'; // Ej.: iconos
import { useUploads } from '@/composables/useUploads'; // Ej.: upload() API-first
import InputError from '@/components/InputError.vue'; // Ej.: muestra error
import { Button } from '@/components/ui/button'; // Ej.: botón shadcn
import { route } from 'ziggy-js'; // Ej.: route('uploads.store')

type UploadProfileOption = { id: string; label: string }; // Ej.: { id:"document_pdf", label:"PDF" }

interface Props {
    profiles?: UploadProfileOption[]; // Ej.: lista de perfiles permitidos
}

const props = withDefaults(defineProps<Props>(), {
    profiles: () => [
        { id: 'document_pdf', label: 'PDF' },
        { id: 'spreadsheet_xlsx', label: 'Spreadsheet' },
        { id: 'import_csv', label: 'CSV' },
    ],
});

const { upload, isUploading, uploadProgress, errors, generalError, lastResult, cancelUpload } = useUploads(); // Ej.: API
const selectedProfile = ref(props.profiles[0]?.id ?? ''); // Ej.: "document_pdf"
const fileInput = ref<File | null>(null); // Ej.: File
const note = ref(''); // Ej.: "Factura enero"
const submitting = computed(() => isUploading.value); // Ej.: true mientras sube
const hasResult = computed(() => Boolean(lastResult.value)); // Ej.: true si 201 ok

const handleFileChange = (event: Event) => {
    const target = event.target as HTMLInputElement | null; // Ej.: input file
    fileInput.value = target?.files?.[0] ?? null; // Ej.: primer archivo
};

const clearSelection = () => {
    fileInput.value = null; // Ej.: limpia file
    lastResult.value = null; // Ej.: limpia success
};

const submit = async () => {
    if (!fileInput.value || !selectedProfile.value) return; // Ej.: guard

    try {
        await upload(
            {
                file: fileInput.value, // Ej.: doc.pdf
                profileId: selectedProfile.value, // Ej.: "document_pdf"
                metadata: { note: note.value }, // Ej.: meta[note]="..."
            },
            {
                urlOverride: route('uploads.store'), // Ej.: "/uploads"
            }
        );
    } catch {
        // Ej.: el composable ya gestiona toasts y estados
    }
};
</script>

<template>
    <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
        <div class="flex items-center gap-2">
            <Upload class="h-5 w-5 text-indigo-600 dark:text-indigo-300" />
            <div>
                <p class="text-sm font-semibold text-slate-900 dark:text-white">Subir archivo genérico</p>
                <p class="text-xs text-slate-600 dark:text-slate-400">Endpoint API-first /uploads</p>
            </div>
        </div>

        <div class="mt-4 grid gap-3 sm:grid-cols-2">
            <label class="text-sm font-medium text-slate-700 dark:text-slate-200">
                Perfil
                <select v-model="selectedProfile"
                    class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white">
                    <option v-for="profile in props.profiles" :key="profile.id" :value="profile.id">
                        {{ profile.label }}
                    </option>
                </select>
            </label>

            <label class="text-sm font-medium text-slate-700 dark:text-slate-200">
                Nota (opcional)
                <input v-model="note" type="text"
                    class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                    placeholder="Descripción breve" />
            </label>
        </div>

        <div class="mt-4 flex flex-col gap-2">
            <label class="text-sm font-medium text-slate-700 dark:text-slate-200">
                Archivo
                <input type="file" class="mt-1 block w-full text-sm" @change="handleFileChange" />
            </label>

            <div class="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-400">
                <span v-if="fileInput?.name">Seleccionado: {{ fileInput.name }}</span>
                <Button v-if="fileInput" variant="ghost" size="sm" class="h-7 px-2" @click="clearSelection">
                    <X class="mr-1 h-4 w-4" /> Quitar
                </Button>
            </div>
        </div>

        <div v-if="uploadProgress !== null" class="mt-3">
            <div class="flex justify-between text-xs text-slate-600 dark:text-slate-400">
                <span>Progreso</span>
                <span>{{ Math.round(uploadProgress) }}%</span>
            </div>
            <div class="mt-1 h-2 rounded bg-slate-200 dark:bg-slate-800">
                <div class="h-2 rounded bg-indigo-500 transition-[width]"
                    :style="{ width: `${Math.min(uploadProgress ?? 0, 100)}%` }"></div>
            </div>
        </div>

        <div v-if="generalError" class="mt-3">
            <InputError :message="generalError" />
        </div>
        <div v-if="errors.file" class="mt-1">
            <InputError :message="errors.file[0]" />
        </div>
        <div v-if="errors.profile_id" class="mt-1">
            <InputError :message="errors.profile_id[0]" />
        </div>

        <div v-if="hasResult"
            class="mt-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">
            <p class="font-semibold">Subida creada</p>
            <p>ID: {{ lastResult?.id }} · Estado: {{ lastResult?.status }}</p>
            <p v-if="lastResult?.correlation_id">Correlation: {{ lastResult.correlation_id }}</p>
        </div>

        <div class="mt-4 flex items-center gap-2">
            <Button :disabled="submitting || !fileInput || !selectedProfile" @click="submit">
                <Upload class="mr-2 h-4 w-4" />
                {{ submitting ? 'Subiendo…' : 'Subir' }}
            </Button>
            <Button v-if="submitting" variant="outline" @click="cancelUpload">Cancelar</Button>
        </div>
    </div>
</template>
