<?php

declare(strict_types=1);

namespace App\Services\Upload\Contracts;

/**
 * Contrato para pipelines de análisis/normalización de subidas.
 *
 * Permite coordinar etapas reutilizables para distintos tipos de archivos.
 */
interface UploadPipeline
{
    /**
     * Ejecuta análisis defensivos (firma, heurísticas, metadatos).
     *
     * Debe devolver los bytes originales o lanzar en caso de bloqueo.
     */
    public function analyze(string $bytes): string;

    /**
     * Ejecuta normalización opcional (re-encode, compresión, stripping).
     *
     * Debe devolver los bytes normalizados que continuarán en la pipeline.
     */
    public function normalize(string $bytes): string;

    /**
     * Devuelve el resultado final de la pipeline (DTO con metadata, flags, etc.).
     */
    public function result(): UploadResult;
}
