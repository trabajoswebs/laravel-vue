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
    'extension_not_available' => 'La extensión PHP "imagick" no está disponible.',
    'file_not_valid' => 'El archivo subido no es válido.',
    'file_size_invalid' => 'Tamaño de archivo fuera de límites.',
    'file_not_readable' => 'No se pudo leer el archivo temporal.',
    'mime_not_allowed' => 'Tipo MIME no permitido: :mime',
    'image_load_failed' => 'No se pudo validar la imagen cargada.',
    'gif_clone_failed' => 'No se pudo extraer el primer frame del GIF.',
    'gif_frame_invalid' => 'Frame GIF inválido en índice :index.',
    'dimensions_too_small' => 'Dimensiones mínimas no alcanzadas.',
    'megapixels_exceeded' => 'La imagen supera el límite de megapíxeles permitido.',
    'write_failed' => 'Error al escribir la imagen procesada.',
    'temp_file_invalid' => 'El archivo temporal resultante es inválido.',
    'processing_failed' => 'Error en el procesamiento de imagen.',
    'cleanup_failed' => 'No se pudo limpiar el archivo temporal.',

    // Mensajes de log
    'gif_clone_failed' => 'Error al clonar frame GIF',
    'gif_invalid_frame' => 'Frame GIF inválido detectado',
    'tmp_unlink_failed' => 'Error al eliminar archivo temporal',
    'cmyk_to_srgb' => 'Conversión de CMYK a sRGB detectada',
    'srgb_failed' => 'Error en conversión a sRGB',
    'image_pipeline_failed' => 'Error general en pipeline de imagen',

    // Mensajes de éxito
    'image_processed' => 'Imagen procesada exitosamente',
    'format_converted' => 'Formato convertido a :format',

];
