<template>
    <!-- Layout raíz que proporciona elementos globales -->
    <div class="root-layout">
        <!-- Slot para el contenido específico de cada página -->
        <slot />

        <!-- El Toaster ya está montado globalmente por el plugin -->
        <!-- No necesitamos <Toaster /> aquí -->
    </div>
</template>

<script setup lang="ts">
// RootLayout es el componente más alto en la jerarquía
// Su función es proporcionar elementos globales que deben estar
// disponibles en toda la aplicación sin duplicarse
// El Toaster se monta automáticamente por el plugin
</script>

<style scoped>
.root-layout {
    /* Estilos base para el layout raíz */
    min-height: 100vh;
}
</style>
