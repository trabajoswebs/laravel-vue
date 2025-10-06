<template>
    <div ref="root" class="relative inline-flex items-center" aria-live="polite">
        <!-- Trigger: botón del selector -->
        <button ref="trigger" :id="btnId" type="button" :disabled="isChangingLocal"
            class="cursor-pointer inline-flex items-center justify-center gap-2 rounded-md p-2 text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground focus:outline-none focus:ring-1 focus:ring-ring/15 focus:ring-offset-1/15"
            :class="{
                'opacity-70 cursor-wait': isChangingLocal,
                'hover:bg-accent': !isChangingLocal
            }" :aria-expanded="isOpen" :aria-controls="panelId" aria-haspopup="menu" @click="toggle"
            @keydown.arrow-down.prevent="openAndFocusFirst" :aria-label="ariaLabel">

            <img :src="flagPathFor(current)" :alt="`Bandera de ${initialsFor(current)}`"
                class="w-5 h-4 object-cover rounded-sm" aria-hidden="true" />

            <!-- ✅ SPINNER cuando está cambiando -->
            <div v-if="isChangingLocal" class="w-4 h-4 animate-spin" aria-hidden="true">
                <svg class="w-full h-full text-muted-foreground" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                </svg>
            </div>
            <svg v-else class="w-4 h-4 text-muted-foreground transition-transform" :class="{ 'rotate-180': isOpen }"
                viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
        </button>

        <!-- Panel desplegable -->
        <transition name="fade-scale">
            <div v-if="isOpen && !isChangingLocal" ref="panel" :id="panelId"
                class="absolute right-1 top-6 z-50 mt-2 w-48 origin-top-right rounded-md border-border border bg-popover shadow-lg ring-1 ring-border focus:outline-none"
                role="menu" aria-orientation="vertical" :aria-labelledby="btnId">
                <ul class="p-1">
                    <li v-for="(opt, index) in options" :key="opt.code">
                        <button :ref="el => setOptionRef(el, index)"
                            class="group flex w-full items-center gap-3 px-3 py-2 text-sm rounded-md transition-colors hover:bg-accent focus:bg-accent cursor-pointer"
                            :class="{
                                'bg-accent font-semibold': opt.code === current,
                                'text-popover-foreground': opt.code !== current
                            }" role="menuitemradio" :aria-checked="(opt.code === current)" @click="select(opt.code)"
                            @keydown.enter.prevent="select(opt.code)" @keydown.arrow-down.prevent="focusNext(index)"
                            @keydown.arrow-up.prevent="focusPrev(index)">

                            <img :src="opt.flagPath" :alt="`Bandera de ${opt.initials}`"
                                class="w-5 h-4 object-cover rounded-sm" />
                            <span class="flex-grow text-left">{{ opt.name }}</span>
                            <span class="text-xs text-muted-foreground">{{ opt.initials }}</span>
                            <svg v-if="opt.code === current" class="w-4 h-4 text-primary" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" aria-hidden="true">
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
import { ref, computed, onMounted, onUnmounted, nextTick, watch } from 'vue'
import { useLanguage } from '@/composables/useLanguage'
import type { Locale } from '@/i18n'

/* ---------- TYPES ---------- */
interface LanguageChangeError {
    locale: Locale
    reason: 'timeout' | 'error' | 'cancelled' | 'aborted'
    error?: Error
}

interface LanguageChangeSuccess {
    from: Locale
    to: Locale
}

/* ---------- EMITS ---------- */
const emit = defineEmits<{
    languageChangeFailed: [LanguageChangeError]
    languageChanged: [LanguageChangeSuccess]
}>()

/* ---------- CONFIG / ESTADO ---------- */
const CHANGE_LANG_TIMEOUT_MS = 5000
const isOpen = ref<boolean>(false)

const root = ref<HTMLElement | null>(null)
const panel = ref<HTMLElement | null>(null)
const trigger = ref<HTMLButtonElement | null>(null)

const optionRefs = ref<Array<HTMLButtonElement | null>>([])

// ✅ MEJORADO: gestión de requests con AbortController
let currentAbortController: AbortController | null = null
let lastRequestId = 0
const isChangingLocal = ref(false)

/* ---------- HELPERS ---------- */
function generateId(): string {
    if (typeof window === 'undefined') return Math.random().toString(36).slice(2, 9)
    if (typeof window.crypto?.randomUUID === 'function') return window.crypto.randomUUID()
    return Math.random().toString(36).slice(2, 9)
}

const idBase = generateId()
const panelId = `lang-panel-${idBase}`
const btnId = `lang-btn-${idBase}`

/* ---------- COMPOSABLE ---------- */
const { currentLanguage, changeLanguage, supportedLanguages } = useLanguage()

/* ---------- FALLBACKS / CONFIG ---------- */
const DEFAULT_LOCALE: Locale = 'es'
const DEFAULT_FLAG_PATH = '/images/flags/default.svg'

const languageConfig: Record<string, { name: string; initials: string; flagPath: string }> = {
    es: { name: 'Español', initials: 'ESP', flagPath: '/images/flags/es.svg' },
    en: { name: 'English', initials: 'GB', flagPath: '/images/flags/en.svg' }
}

/* ---------- COMPUTED ---------- */
const current = computed<Locale>(() => (currentLanguage?.value ?? DEFAULT_LOCALE))
const options = computed(() => {
    const avail = (supportedLanguages?.value?.length ? supportedLanguages.value : [DEFAULT_LOCALE]) as Locale[]
    return avail.filter(c => c in languageConfig).map(code => ({
        code,
        name: languageConfig[code].name,
        initials: languageConfig[code].initials,
        flagPath: languageConfig[code].flagPath
    }))
})
const ariaLabel = computed(() => `Selector de idioma. Actual: ${languageConfig[current.value]?.name ?? current.value}`)

/* ---------- UTILITY FUNCTIONS ---------- */
function initialsFor(loc: Locale): string {
    return languageConfig[loc]?.initials ?? loc.toUpperCase().slice(0, 3)
}

function flagPathFor(loc: Locale): string {
    return languageConfig[loc]?.flagPath ?? DEFAULT_FLAG_PATH
}

/* ---------- ✅ REFS MANAGEMENT MEJORADO ---------- */
function setOptionRef(el: HTMLButtonElement | null, idx: number) {
    // ✅ OPTIMIZADO: expansión eficiente del array
    if (idx >= optionRefs.value.length) {
        optionRefs.value.length = idx + 1
    }
    optionRefs.value[idx] = el
}

// sincronizar length de optionRefs con options
const stopWatchOptions = watch(options, (newOptions) => {
    const n = newOptions.length
    if (optionRefs.value.length > n) {
        optionRefs.value.splice(n)
    }
}, { immediate: true })

/* ---------- INTERACTION ---------- */
function toggle(): void {
    if (isChangingLocal.value) return // No permitir toggle durante cambio
    isOpen.value ? close() : open()
}

function open(): void {
    if (isChangingLocal.value) return
    isOpen.value = true
    nextTick(() => optionRefs.value[0]?.focus())
}

function openAndFocusFirst() {
    if (!isOpen.value && !isChangingLocal.value) open()
}

function close(): void {
    isOpen.value = false
    //nextTick(() => trigger.value?.focus())
}

/* ---------- ✅ SELECT CON ABORTCONTROLLER ---------- */
async function select(locale: Locale): Promise<void> {
    if (isChangingLocal.value) return
    if (locale === current.value) {
        close()
        return
    }

    // ✅ CANCELAR REQUEST ANTERIOR
    if (currentAbortController) {
        currentAbortController.abort()
    }

    const previousLanguage = current.value
    isChangingLocal.value = true
    const reqId = ++lastRequestId

    // ✅ NUEVO ABORTCONTROLLER
    currentAbortController = new AbortController()
    const signal = currentAbortController.signal

    let timeoutId: ReturnType<typeof setTimeout> | null = null

    try {
        // ✅ TIMEOUT CON CLEANUP
        const timeoutPromise = new Promise<never>((_, reject) => {
            timeoutId = setTimeout(() => {
                reject(new Error(`Timeout after ${CHANGE_LANG_TIMEOUT_MS}ms`))
            }, CHANGE_LANG_TIMEOUT_MS)
        })

        // ✅ PROMISE CON ABORT SIGNAL (si changeLanguage lo soporta)
        const changePromise = (async () => {
            try {
                // Si changeLanguage soporta AbortSignal, pasárselo
                // const r = await changeLanguage(locale, { signal })
                const r = await changeLanguage(locale)
                return !!r
            } catch (err) {
                console.error('[LanguageSelector] changeLanguage error:', err)
                throw err
            }
        })()

        const result = await Promise.race([changePromise, timeoutPromise])

        // ✅ VERIFICAR SI REQUEST SIGUE SIENDO VÁLIDO
        if (signal.aborted) {
            emit('languageChangeFailed', {
                locale,
                reason: 'aborted'
            })
            return
        }

        if (reqId !== lastRequestId) {
            emit('languageChangeFailed', {
                locale,
                reason: 'cancelled'
            })
            return
        }

        if (result === true) {
            emit('languageChanged', {
                from: previousLanguage,
                to: locale
            })
            close()
        }

    } catch (err) {
        // ✅ MANEJAR DIFERENTES TIPOS DE ERROR
        if (signal.aborted) {
            // Request fue abortado
            if (reqId === lastRequestId) {
                emit('languageChangeFailed', {
                    locale,
                    reason: 'aborted'
                })
            }
        } else if (err instanceof Error && err.message.includes('Timeout')) {
            // Timeout
            if (reqId === lastRequestId) {
                emit('languageChangeFailed', {
                    locale,
                    reason: 'timeout'
                })
                console.warn(`[LanguageSelector] timeout cambiando a "${locale}"`)
            }
        } else {
            // Error real
            if (reqId === lastRequestId) {
                emit('languageChangeFailed', {
                    locale,
                    reason: 'error',
                    error: err instanceof Error ? err : new Error(String(err))
                })
                console.error('[LanguageSelector] error cambiando idioma:', err)
            }
        }
    } finally {
        // ✅ CLEANUP
        if (timeoutId) {
            clearTimeout(timeoutId)
        }

        if (reqId === lastRequestId) {
            isChangingLocal.value = false
            currentAbortController = null
        }
    }
}

/* ---------- FOCUS NAV ---------- */
function focusNext(idx: number) {
    if (optionRefs.value.length === 0) return
    const nextIndex = (idx + 1) % optionRefs.value.length
    optionRefs.value[nextIndex]?.focus()
}

function focusPrev(idx: number) {
    if (optionRefs.value.length === 0) return
    const prevIndex = idx === 0 ? optionRefs.value.length - 1 : idx - 1
    optionRefs.value[prevIndex]?.focus()
}

/* ---------- GLOBAL EVENTS ---------- */
function onDocumentClick(e: MouseEvent): void {
    if (!root.value) return
    if (!root.value.contains(e.target as Node)) close()
}

function onKeydown(e: KeyboardEvent): void {
    if (!isOpen.value) return

    switch (e.key) {
        case 'Escape':
            e.preventDefault()
            close()
            break
        case 'Tab':
            nextTick(() => {
                if (!root.value) return
                if (!root.value.contains(document.activeElement)) close()
            })
            break
    }
}

/* ---------- MOUNT / UNMOUNT ---------- */
onMounted(() => {
    document.addEventListener('click', onDocumentClick, true)
    document.addEventListener('keydown', onKeydown)
})

onUnmounted(() => {
    document.removeEventListener('click', onDocumentClick, true)
    document.removeEventListener('keydown', onKeydown)
    stopWatchOptions()

    // ✅ CANCELAR REQUEST EN CURSO
    if (currentAbortController) {
        currentAbortController.abort()
        currentAbortController = null
    }

    // limpiar refs
    optionRefs.value.length = 0
    root.value = null
    panel.value = null
    trigger.value = null

    // invalidar requests pendientes
    lastRequestId++
    isChangingLocal.value = false
})
</script>

<style scoped>
.fade-scale-enter-active {
    transition: transform 0.12s cubic-bezier(.16, .84, .3, 1), opacity 0.12s ease;
}

.fade-scale-leave-active {
    transition: transform 0.10s cubic-bezier(.16, .84, .3, 1), opacity 0.10s ease;
}

.fade-scale-enter-from,
.fade-scale-leave-to {
    transform: translateY(-6px) scale(0.98);
    opacity: 0;
}

.fade-scale-enter-to,
.fade-scale-leave-from {
    transform: translateY(0) scale(1);
    opacity: 1;
}

/* ✅ ANIMACIÓN DEL SPINNER */
@keyframes spin {
    from {
        transform: rotate(0deg);
    }

    to {
        transform: rotate(360deg);
    }
}

.animate-spin {
    animation: spin 1s linear infinite;
}
</style>
