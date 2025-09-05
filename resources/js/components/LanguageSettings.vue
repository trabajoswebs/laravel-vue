<template>
    <div class="bg-white shadow rounded-lg p-6">

        <div class="flex items-center justify-between mb-6">
            <div>
                <h3 class="text-lg font-medium text-gray-900">
                    {{ t('settings.language') }}
                </h3>
                <p class="text-sm text-gray-500">
                    {{ t('common.language') }}: {{ currentLanguageMeta.name }}
                </p>
            </div>
            <LanguageSelector />
        </div>

        <!-- Información del idioma del navegador -->
        <div v-if="isBrowserSupported" class="mb-6 p-4 bg-blue-50 rounded-lg">
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div class="ml-3">
                    <h4 class="text-sm font-medium text-blue-800">
                        {{ t('browser_language.detected') }}
                    </h4>
                    <p class="text-sm text-blue-700 mt-1">
                        {{ t('browser_language.configured_in') }} {{ browserLanguage === 'es' ? t('common.spanish') :
                            browserLanguage === 'en' ? t('common.english') : t('common.language') }}.

                    </p>

                </div>
            </div>
        </div>

        <!-- Lista de idiomas disponibles -->
        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <h4 class="text-sm font-medium text-gray-900">
                    {{ t('language.available_languages') }}
                </h4>
                <Button @click="toggleLanguage">
                    {{ t('language.switch') }}
                </Button>
            </div>
            <!-- Español -->
            <div class="flex items-center justify-between p-4 border border-gray-200 rounded-lg">
                <div class="flex items-center space-x-3">
                    <span class="text-2xl">🇪🇸</span>
                    <div>
                        <h5 class="text-sm font-medium text-gray-900">{{ t('common.spanish') }}</h5>
                        <p class="text-xs text-gray-500">{{ t('language.native_language') }}</p>
                    </div>
                </div>
                <div class="flex items-center space-x-2">
                    <span v-if="currentLanguage === 'es'"
                        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                        {{ t('language.active') }}
                    </span>
                    <button v-else @click="changeLanguage('es')"
                        class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors duration-200">
                        {{ t('language.select') }}
                    </button>
                </div>
            </div>

            <!-- Inglés -->
            <div class="flex items-center justify-between p-4 border border-gray-200 rounded-lg">
                <div class="flex items-center space-x-3">
                    <span class="text-2xl">🇺🇸</span>
                    <div>
                        <h5 class="text-sm font-medium text-gray-900">{{ t('common.english') }}</h5>
                        <p class="text-xs text-gray-500">{{ t('common.english') }}</p>
                    </div>
                </div>
                <div class="flex items-center space-x-2">
                    <span v-if="currentLanguage === 'en'"
                        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                        {{ t('language.active') }}
                    </span>
                    <button v-else @click="changeLanguage('en')"
                        class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors duration-200">
                        {{ t('language.select') }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Información adicional -->
        <div class="mt-6 p-4 bg-gray-50 rounded-lg">
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div class="ml-3">
                    <h4 class="text-sm font-medium text-gray-800">
                        {{ t('language.language_preference_saved') }}
                    </h4>
                    <p class="text-sm text-gray-600 mt-1">
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
