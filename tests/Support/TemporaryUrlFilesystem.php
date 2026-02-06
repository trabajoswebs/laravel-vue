<?php

namespace Tests\Support;

use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Sistema de archivos falso para pruebas que simula operaciones de archivo con URLs temporales
 * Útil para testing sin interactuar con el sistema de archivos real
 */
final class TemporaryUrlFilesystem implements Filesystem
{
    /**
     * Constructor que inicializa el filesystem temporal
     * 
     * @param string $temporaryUrl URL base para archivos temporales
     * @param string[] $existingPaths Lista de rutas que existen (por defecto vacía)
     */
    public function __construct(
        private string $temporaryUrl,      // URL base para archivos temporales
        private array $existingPaths = [], // Rutas de archivos que se consideran "existentes"
    ) {
    }

    /**
     * Verifica si un archivo existe en la lista de rutas existentes
     * 
     * @param string $path Ruta del archivo a verificar
     * @return bool True si el archivo existe, false en caso contrario
     */
    public function exists($path): bool
    {
        // Comprueba si la ruta está en la lista de rutas existentes
        return in_array((string) $path, $this->existingPaths, true);
    }

    /**
     * Verifica si un archivo NO existe (inverso de exists)
     * 
     * @param string $path Ruta del archivo a verificar
     * @return bool True si el archivo no existe, false si existe
     */
    public function missing($path): bool
    {
        // Devuelve el opuesto de exists()
        return !$this->exists($path);
    }

    /**
     * Obtiene el contenido del archivo (simulado como cadena vacía)
     * 
     * @param string $path Ruta del archivo
     * @return string Contenido del archivo (vacío en este mock)
     */
    public function get($path): string
    {
        // Devuelve contenido vacío simulando lectura de archivo
        return '';
    }

    /**
     * Lee el archivo como stream (simulado con php://temp)
     * 
     * @param string $path Ruta del archivo
     * @return resource Stream de lectura temporal
     */
    public function readStream($path)
    {
        // Crea un stream temporal para lectura
        return fopen('php://temp', 'rb');
    }

    /**
     * Guarda contenido en un archivo (simulado como operación exitosa)
     * 
     * @param string $path Ruta donde guardar
     * @param mixed $contents Contenido a guardar
     * @param array $options Opciones adicionales
     * @return bool Siempre devuelve true (operación simulada)
     */
    public function put($path, $contents, $options = []): bool
    {
        // Simula escritura exitosa
        return true;
    }

    /**
     * Sube un archivo y genera un nombre único (simulado)
     * 
     * @param string $path Directorio de destino
     * @param mixed $file Archivo a subir (opcional)
     * @param array $options Opciones adicionales
     * @return string Nombre del archivo generado ('file')
     */
    public function putFile($path, $file = null, $options = []): string
    {
        // Simula subida exitosa devolviendo nombre fijo
        return 'file';
    }

    /**
     * Sube un archivo con nombre específico (simulado)
     * 
     * @param string $path Directorio de destino
     * @param mixed $file Archivo a subir (opcional)
     * @param string $name Nombre deseado para el archivo (opcional)
     * @param array $options Opciones adicionales
     * @return string Nombre del archivo generado ('file')
     */
    public function putFileAs($path, $file = null, $name = null, $options = []): string
    {
        // Simula subida con nombre específico
        return 'file';
    }

    /**
     * Obtiene visibilidad del archivo (siempre 'private' en simulación)
     * 
     * @param string $path Ruta del archivo
     * @return string Visibilidad ('private')
     */
    public function getVisibility($path): string
    {
        // Devuelve visibilidad predeterminada
        return 'private';
    }

    /**
     * Establece visibilidad del archivo (simulado como exitoso)
     * 
     * @param string $path Ruta del archivo
     * @param string $visibility Nueva visibilidad
     * @return bool Siempre devuelve true
     */
    public function setVisibility($path, $visibility): bool
    {
        // Simula cambio de visibilidad exitoso
        return true;
    }

    /**
     * Elimina archivos (simulado como exitoso)
     * 
     * @param string|array $paths Ruta(s) a eliminar
     * @return bool Siempre devuelve true
     */
    public function delete($paths): bool
    {
        // Simula eliminación exitosa
        return true;
    }

    /**
     * Elimina directorio (simulado como exitoso)
     * 
     * @param string $directory Directorio a eliminar
     * @return bool Siempre devuelve true
     */
    public function deleteDirectory($directory): bool
    {
        // Simula eliminación de directorio exitosa
        return true;
    }

    /**
     * Copia archivo (simulado como exitoso)
     * 
     * @param string $from Ruta origen
     * @param string $to Ruta destino
     * @return bool Siempre devuelve true
     */
    public function copy($from, $to): bool
    {
        // Simula copia exitosa
        return true;
    }

    /**
     * Mueve archivo (simulado como exitoso)
     * 
     * @param string $from Ruta origen
     * @param string $to Ruta destino
     * @return bool Siempre devuelve true
     */
    public function move($from, $to): bool
    {
        // Simula movimiento exitoso
        return true;
    }

    /**
     * Obtiene tamaño del archivo (simulado como 1 byte)
     * 
     * @param string $path Ruta del archivo
     * @return int Tamaño del archivo en bytes (1 en simulación)
     */
    public function size($path): int
    {
        // Simula tamaño fijo de 1 byte
        return 1;
    }

    /**
     * Obtiene última modificación (simulado como tiempo actual)
     * 
     * @param string $path Ruta del archivo
     * @return int Timestamp Unix de última modificación
     */
    public function lastModified($path): int
    {
        // Devuelve timestamp actual como última modificación
        return time();
    }

    /**
     * Genera URL para archivo (devuelve URL temporal configurada)
     * 
     * @param string $path Ruta del archivo
     * @return string URL temporal configurada
     */
    public function url($path): string
    {
        // Devuelve la URL temporal configurada
        return $this->temporaryUrl;
    }

    /**
     * Genera URL temporal con expiración (devuelve URL temporal configurada)
     * 
     * @param string $path Ruta del archivo
     * @param \DateTimeInterface $expiration Fecha de expiración
     * @param array $options Opciones adicionales
     * @return string URL temporal configurada
     */
    public function temporaryUrl($path, $expiration, array $options = []): string
    {
        // Devuelve la misma URL temporal configurada
        return $this->temporaryUrl;
    }

    /**
     * Devuelve la ruta del archivo (conversión a string)
     * 
     * @param string $path Ruta del archivo
     * @return string Ruta convertida a string
     */
    public function path($path): string
    {
        // Convierte y devuelve la ruta como string
        return (string) $path;
    }

    /**
     * Escribe stream en archivo (simulado como exitoso)
     * 
     * @param string $path Ruta donde escribir
     * @param resource $resource Stream a escribir
     * @param array $options Opciones adicionales
     * @return bool Siempre devuelve true
     */
    public function writeStream($path, $resource, array $options = []): bool
    {
        // Simula escritura de stream exitosa
        return true;
    }

    /**
     * Prepend data to file (simulado como exitoso)
     * 
     * @param string $path Ruta del archivo
     * @param string $data Datos a añadir al principio
     * @param string $separator Separador entre datos (opcional)
     * @return bool Siempre devuelve true
     */
    public function prepend($path, $data, $separator = ''): bool
    {
        // Simula adición exitosa al inicio del archivo
        return true;
    }

    /**
     * Append data to file (simulado como exitoso)
     * 
     * @param string $path Ruta del archivo
     * @param string $data Datos a añadir al final
     * @param string $separator Separador entre datos (opcional)
     * @return bool Siempre devuelve true
     */
    public function append($path, $data, $separator = ''): bool
    {
        // Simula adición exitosa al final del archivo
        return true;
    }

    /**
     * Obtiene lista de archivos en directorio (simulado como vacío)
     * 
     * @param string|null $directory Directorio a escanear (opcional)
     * @param bool $recursive Si incluir subdirectorios (por defecto false)
     * @return array Lista de archivos (vacía en simulación)
     */
    public function files($directory = null, $recursive = false): array
    {
        // Devuelve lista vacía simulando directorio sin archivos
        return [];
    }

    /**
     * Obtiene todos los archivos recursivamente (simulado como vacío)
     * 
     * @param string|null $directory Directorio a escanear (opcional)
     * @return array Lista de todos los archivos (vacía en simulación)
     */
    public function allFiles($directory = null): array
    {
        // Devuelve lista vacía simulando directorio sin archivos
        return [];
    }

    /**
     * Obtiene lista de directorios (simulado como vacío)
     * 
     * @param string|null $directory Directorio a escanear (opcional)
     * @param bool $recursive Si incluir subdirectorios (por defecto false)
     * @return array Lista de directorios (vacía en simulación)
     */
    public function directories($directory = null, $recursive = false): array
    {
        // Devuelve lista vacía simulando directorio sin subdirectorios
        return [];
    }

    /**
     * Obtiene todos los directorios recursivamente (simulado como vacío)
     * 
     * @param string|null $directory Directorio a escanear (opcional)
     * @return array Lista de todos los directorios (vacía en simulación)
     */
    public function allDirectories($directory = null): array
    {
        // Devuelve lista vacía simulando directorio sin subdirectorios
        return [];
    }

    /**
     * Crea directorio (simulado como exitoso)
     * 
     * @param string $path Ruta del directorio a crear
     * @return bool Siempre devuelve true
     */
    public function makeDirectory($path): bool
    {
        // Simula creación de directorio exitosa
        return true;
    }

    /**
     * Configura callback para construir URLs temporales (no-op en simulación)
     * 
     * @param callable $callback Callback para construcción de URLs
     * @return void
     */
    public function buildTemporaryUrlsUsing($callback): void
    {
        // No hace nada en la simulación
    }
}