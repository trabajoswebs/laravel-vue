<template>
    <nav class="bg-white shadow-sm border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <!-- Logo y navegación principal -->
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <h1 class="text-xl font-bold text-gray-900">
                            {{ t('navigation.home') }}
                        </h1>
                    </div>

                    <div class="hidden sm:ml-6 sm:flex sm:space-x-8">
                        <a href="#"
                            class="text-gray-900 inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium hover:border-gray-300 hover:text-gray-700 transition-colors duration-200">
                            {{ t('navigation.home') }}
                        </a>
                        <a href="#"
                            class="text-gray-900 inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium hover:border-gray-300 hover:text-gray-700 transition-colors duration-200">
                            {{ t('navigation.profile') }}
                        </a>
                        <a href="#"
                            class="text-gray-900 inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium hover:border-gray-300 hover:text-gray-700 transition-colors duration-200">
                            {{ t('navigation.settings') }}
                        </a>
                    </div>
                </div>

                <!-- Lado derecho: Selector de idioma y usuario -->
                <div class="flex items-center space-x-4">
                    <!-- Selector de idioma -->
                    <LanguageSelector />

                    <!-- Información del usuario -->
                    <div class="flex items-center space-x-3">
                        <div class="text-sm text-gray-700">
                            <span class="font-medium">{{ t('common.language') }}:</span>
                            <span class="ml-1">{{ getCurrentLanguageName }}</span>
                        </div>

                        <!-- Botón de logout -->
                        <button class="text-gray-500 hover:text-gray-700 transition-colors duration-200">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </nav>
</template>

<script setup lang="ts">
import { useLanguage } from '../composables/useLanguage'
import LanguageSelector from './LanguageSelector.vue'

const {
    t,
    getCurrentLanguageName
} = useLanguage()
</script>
















