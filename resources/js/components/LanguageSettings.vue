<template>
    <div class="flex flex-col space-y-6">
        <!-- Header -->
        <div class="flex items-start justify-between gap-4">
            <div>
                <h3 class="text-base sm:text-lg font-semibold text-foreground">
                    {{ t('settings.language') }}
                </h3>
                <p class="text-sm text-muted-foreground">
                    {{ t('common.language') }}: {{ currentLanguageMeta.name }}
                </p>
            </div>
            <LanguageSelector />
        </div>

        <!-- Info: navegador -->
        <div v-if="isBrowserSupported"
            class="rounded-xl border border-blue-200/60 bg-blue-50 text-blue-900 p-4 dark:border-blue-900/40 dark:bg-blue-950/40 dark:text-blue-100"
            role="note" aria-live="polite">
            <div class="flex items-start gap-3">
                <div
                    class="flex h-6 w-6 items-center justify-center rounded-lg bg-blue-100 text-blue-600 dark:bg-blue-900/60 dark:text-blue-200">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div>
                    <h4 class="text-sm font-medium">
                        {{ t('browser_language.detected') }}
                    </h4>
                    <p class="mt-1 text-sm/6">
                        {{ t('browser_language.configured_in') }}
                        {{
                            browserLanguage === 'es'
                                ? t('common.spanish')
                                : browserLanguage === 'en'
                                    ? t('common.english')
                        : t('common.language')
                        }}.
                    </p>
                </div>
            </div>
        </div>

        <!-- Lista de idiomas -->
        <section class="space-y-4">
            <div class="flex items-center justify-between">
                <h4 class="text-sm font-semibold text-foreground">
                    {{ t('language.available_languages') }}
                </h4>
                <Button size="sm" variant="secondary" @click="toggleLanguage">
                    {{ t('language.switch') }}
                </Button>
            </div>

            <!-- Español -->
            <article class="group flex items-center justify-between gap-4 rounded-xl border border-border bg-card p-4 shadow-sm transition
               hover:shadow-md hover:border-primary/40 focus-within:ring-2 focus-within:ring-primary/30">
                <div class="flex items-center gap-3">
                    <span class="text-2xl" aria-hidden="true">🇪🇸</span>
                    <div>
                        <h5 class="text-sm font-medium text-foreground">{{ t('common.spanish') }}</h5>
                        <p class="text-xs text-muted-foreground">{{ t('language.native_language') }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <span v-if="currentLanguage === 'es'"
                        class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900/30 dark:text-green-200">
                        {{ t('language.active') }}
                    </span>
                    <Button v-else size="sm" variant="outline" class="cursor-pointer" @click="changeLanguage('es')">
                        {{ t('language.select') }}
                    </Button>
                </div>
            </article>

            <!-- Inglés -->
            <article class="group flex items-center justify-between gap-4 rounded-xl border border-border bg-card p-4 shadow-sm transition
               hover:shadow-md hover:border-primary/40 focus-within:ring-2 focus-within:ring-primary/30">
                <div class="flex items-center gap-3">
                    <span class="text-2xl" aria-hidden="true">🇺🇸</span>
                    <div>
                        <h5 class="text-sm font-medium text-foreground">{{ t('common.english') }}</h5>
                        <p class="text-xs text-muted-foreground">{{ t('common.english') }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <span v-if="currentLanguage === 'en'"
                        class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900/30 dark:text-green-200">
                        {{ t('language.active') }}
                    </span>
                    <Button v-else size="sm" variant="outline" class="cursor-pointer" @click="changeLanguage('en')">
                        {{ t('language.select') }}
                    </Button>
                </div>
            </article>
        </section>

        <!-- Mensaje de guardado -->
        <div class="rounded-xl border border-border bg-muted/30 p-4 text-foreground dark:bg-muted/20" role="status"
            aria-live="polite">
            <div class="flex items-start gap-3">
                <div class="flex h-6 w-6 items-center justify-center rounded-lg bg-foreground/10 text-foreground">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div>
                    <h4 class="text-sm font-medium">
                        {{ t('language.language_preference_saved') }}
                    </h4>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ t('language.language_preference_description') }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { useLanguage } from '../composables/useLanguage'
import LanguageSelector from './LanguageSelector.vue'
import { Button } from './ui/button'

const {
    currentLanguage,
    browserLanguage,
    isBrowserSupported,
    currentLanguageMeta,
    changeLanguage,
    toggleLanguage,
    t
} = useLanguage()
</script>
