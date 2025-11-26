<?php

namespace App\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use HTMLPurifier;
use HTMLPurifier_Config;
use Exception;
use Illuminate\Support\Facades\Log;

class HtmlPurifierServiceProvider extends ServiceProvider
{
    /**
     * Configuraciones disponibles para HTMLPurifier
     */
    private const PURIFIER_CONFIGS = [
        'htmlpurifier' => 'default',
        'htmlpurifier.strict' => 'strict',
        'htmlpurifier.permissive' => 'permissive',
        'htmlpurifier.translations' => 'translations'
    ];

    public function register(): void
    {
        // Registrar cada configuración de HTMLPurifier
        foreach (self::PURIFIER_CONFIGS as $serviceName => $configKey) {
            $this->app->singleton($serviceName, function ($app) use ($configKey) {
                return $this->createHtmlPurifier($configKey);
            });
        }
    }

    /**
     * Crear instancia de HTMLPurifier con configuración específica
     */
    private function createHtmlPurifier(string $configKey): HTMLPurifier
    {
        try {
            $cachePath = $this->ensureCacheDirectory();
            $config = $this->createPurifierConfig($cachePath, $configKey);

            return new HTMLPurifier($config);
        } catch (Exception $e) {
            Log::error('Error creating HTMLPurifier instance', [
                'config_key' => $configKey,
                'error' => $e->getMessage()
            ]);

            // Fallback: crear instancia básica sin cache
            return new HTMLPurifier(HTMLPurifier_Config::createDefault());
        }
    }

    /**
     * Asegurar que el directorio de cache existe
     */
    private function ensureCacheDirectory(): string
    {
        $cachePath = storage_path('app/cache/htmlpurifier');

        if (!is_dir($cachePath)) {
            if (!mkdir($cachePath, 0750, true)) {
                throw new Exception("No se pudo crear el directorio de cache: {$cachePath}");
            }
        }

        if (!is_writable($cachePath)) {
            throw new Exception("El directorio de cache no es escribible: {$cachePath}");
        }

        return $cachePath;
    }

    /**
     * Crear y configurar HTMLPurifier_Config
     */
    private function createPurifierConfig(string $cachePath, string $configKey): HTMLPurifier_Config
    {
        $config = HTMLPurifier_Config::createDefault();

        // Configurar cache
        $config->set('Cache.SerializerPath', $cachePath);

        // Aplicar configuración específica
        $customConfig = config("htmlpurifier.{$configKey}", []);

        foreach ($customConfig as $key => $value) {
            try {
                $config->set($key, $value);
            } catch (Exception $e) {
                Log::warning('Error aplicando configuración HTMLPurifier', [
                    'config_key' => $configKey,
                    'setting' => $key,
                    'value' => $value,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $config;
    }

    public function boot(): void
    {
        // Validar que HTMLPurifier esté disponible
        if (!class_exists('HTMLPurifier')) {
            Log::error('HTMLPurifier no está instalado. Ejecute: composer require ezyang/htmlpurifier');
        }

        // Publicar configuración si no existe
        $this->publishes([
            __DIR__ . '/../../config/htmlpurifier.php' => config_path('htmlpurifier.php'),
        ], 'htmlpurifier-config');
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return array_keys(self::PURIFIER_CONFIGS);
    }
}
