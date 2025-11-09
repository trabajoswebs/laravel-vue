<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Image Pipeline Language Lines
    |--------------------------------------------------------------------------
    |
    | Mensajes de error y éxito para el servicio ImagePipeline
    |
    */

    // Mensajes de error
    'extension_not_available' => 'El sistema de procesamiento de imágenes no está disponible.',
    'file_not_valid' => 'El archivo no es válido.',
    'file_size_invalid' => 'El archivo es demasiado grande.',
    'file_not_readable' => 'No se puede leer el archivo.',
    'mime_not_allowed' => 'Tipo de archivo no permitido: :mime',
    'image_load_failed' => 'No se puede cargar la imagen.',
    'gif_clone_failed' => 'No se puede procesar el GIF.',
    'gif_frame_invalid' => 'Error en el GIF en el frame :index.',
    'dimensions_too_small' => 'La imagen es demasiado pequeña.',
    'dimensions_too_large' => 'La imagen excede las dimensiones máximas permitidas.',
    'megapixels_exceeded' => 'La imagen tiene demasiados megapíxeles.',
    'gif_too_many_frames' => 'El GIF supera el número máximo de fotogramas (:max).',
    'write_failed' => 'Error al guardar la imagen.',
    'temp_file_invalid' => 'El archivo temporal no es válido.',
    'content_hash_failed' => 'Error al verificar la imagen.',
    'processing_failed' => 'Error al procesar la imagen.',
    'cleanup_failed' => 'Error al limpiar archivos temporales.',
    'output_too_large' => 'La imagen procesada excede el tamaño máximo permitido.',
    'resource_limits_failed' => 'No se pudieron aplicar los límites de procesamiento de imágenes.',
    'suspicious_payload' => 'Se detectó contenido sospechoso en la imagen.',

    // Mensajes de log
    'gif_clone_failed' => 'Error al procesar frame GIF',
    'gif_invalid_frame' => 'Frame GIF incorrecto',
    'tmp_unlink_failed' => 'Error al eliminar archivo temporal',
    'cmyk_to_srgb' => 'Conversión de CMYK a sRGB',
    'srgb_failed' => 'Error en conversión de color',
    'image_pipeline_failed' => 'Error en procesamiento de imagen',
    'image_pipeline_resource_limits_failed' => 'Error por límites de recursos',

    // Mensajes de éxito
    'image_processed' => 'Imagen procesada correctamente',
    'format_converted' => 'Formato convertido a :format',

    // Mensajes de validación
    'validation' => [
        'avatar_required' => 'Elige una imagen para tu perfil.',
        'avatar_file' => 'El avatar debe ser una imagen.',
        'avatar_mime' => 'Formatos permitidos: JPEG, PNG, GIF, WebP o AVIF.',
        'dimensions' => 'La imagen no tiene el tamaño correcto.',
    ],

];
