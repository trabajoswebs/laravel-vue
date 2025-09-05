<template>
    <div ref="root" class="relative inline-flex items-center">
        <!-- Trigger (compacto) -->
        <button type="button"
            class="inline-flex items-center gap-2 rounded-full px-2 py-1 text-xs font-medium bg-white border border-gray-200 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 cursor-pointer"
            :aria-expanded="isOpen" aria-haspopup="menu" @click="toggle" :aria-label="ariaLabel">
            <!-- Bandera a la izquierda -->
            <span class="text-sm leading-none" aria-hidden="true">{{ flagFor(current) }}</span>

            <!-- Iniciales (sin nombre completo) -->
            <span class="uppercase tracking-wider" aria-hidden="true">{{ initialsFor(current) }}</span>

            <!-- pequeño chevron -->
            <svg class="w-3 h-3 text-gray-500 transition-transform" :class="{ 'rotate-180': isOpen }"
                viewBox="0 0 24 24" fill="none" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
        </button>

        <!-- Popover pequeño -->
        <transition name="fade-scale">
            <div v-if="isOpen" ref="panel"
                class="absolute right-0 z-50 mt-2 w-36 origin-top-right rounded-md border bg-white shadow-lg ring-1 ring-black/5 focus:outline-none"
                role="menu" aria-orientation="vertical">
                <ul class="p-1">
                    <li v-for="(opt, idx) in options" :key="opt.code">
                        <button
                            class="group flex w-full items-center gap-2 px-3 py-2 text-xs rounded-md hover:bg-gray-50 focus:bg-gray-50 cursor-pointer"
                            :class="{
                                'bg-blue-50 text-blue-700': opt.code === current,
                                'text-gray-700': opt.code !== current
                            }" role="menuitem" @click="select(opt.code)">
                            <span class="text-sm">{{ opt.flag }}</span>
                            <span class="uppercase tracking-wider">{{ initialsFor(opt.code) }}</span>

                            <svg v-if="opt.code === current" class="w-4 h-4 ml-auto text-blue-600" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M5 13l4 4L19 7" />
                            </svg>
                        </button>
                    </li>
                </ul>
            </div>
        </transition>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue'
import { useLanguage } from '@/composables/useLanguage' // ajusta la ruta si la tienes distinta

type Locale = string

const { currentLanguage, changeLanguage, supportedLanguages } = useLanguage()

const isOpen = ref(false)
const root = ref<HTMLElement | null>(null)
const panel = ref<HTMLElement | null>(null)

const options = computed(() => {
    // Construye opciones desde supportedLanguages, manteniendo orden simple
    const avail = (supportedLanguages?.value ?? ['es', 'en']) as Locale[]
    // Mapa simple de banderas por código; amplía según necesites
    const flagMap: Record<string, string> = {
        es: '🇪🇸',
        en: '🇺🇸',
    }
    return avail.map(code => ({
        code,
        flag: flagMap[code] ?? '🌐'
    }))
})

const current = computed(() => (currentLanguage?.value ?? 'es') as Locale)

const ariaLabel = computed(() => `Language selector. Current: ${initialsFor(current.value)}`)

function initialsFor(loc: Locale) {
    // Si es subtags tipo es-ES -> usar primer subtag 'es'
    const primary = loc.includes('-') ? loc.split('-', 1)[0] : loc
    return (primary || '').toUpperCase().slice(0, 2)
}

function flagFor(loc: Locale) {
    const opt = options.value.find(o => o.code === loc)
    return opt ? opt.flag : '🌐'
}

function toggle() {
    if (isOpen.value) close()
    else open()
}

function open() {
    isOpen.value = true
    // focus ligero
    nextTick(() => {
        const first = panel.value?.querySelector('button[role="menuitem"]') as HTMLElement | null
        first?.focus()
    })
}

function close() {
    isOpen.value = false
}

async function select(locale: Locale) {
    // Intentar cambiar idioma (composable maneja throttle y errores)
    const ok = await changeLanguage(locale)
    if (ok) {
        close()
    } else {
        // si falla, mantenemos abierto y puedes mostrar un toast (no implementado)
        console.warn('Failed to change language to', locale)
    }
}

// cerrar al click fuera
function onDocumentClick(e: MouseEvent) {
    const target = e.target as Node | null
    if (!root.value) return
    if (target && !root.value.contains(target)) {
        close()
    }
}

function onKeydown(e: KeyboardEvent) {
    if (e.key === 'Escape') close()
}

onMounted(() => {
    document.addEventListener('click', onDocumentClick, true)
    document.addEventListener('keydown', onKeydown)
})

onUnmounted(() => {
    document.removeEventListener('click', onDocumentClick, true)
    document.removeEventListener('keydown', onKeydown)
})
</script>

<style scoped>
.fade-scale-enter-active {
    transition: transform 0.12s cubic-bezier(.16, .84, .3, 1), opacity 0.12s ease;
}

.fade-scale-leave-active {
    transition: transform 0.10s cubic-bezier(.16, .84, .3, 1), opacity 0.10s ease;
}

.fade-scale-enter-from {
    transform: translateY(-6px) scale(0.98);
    opacity: 0;
}

.fade-scale-enter-to {
    transform: translateY(0) scale(1);
    opacity: 1;
}

.fade-scale-leave-from {
    transform: translateY(0) scale(1);
    opacity: 1;
}

.fade-scale-leave-to {
    transform: translateY(-6px) scale(0.98);
    opacity: 0;
}
</style>