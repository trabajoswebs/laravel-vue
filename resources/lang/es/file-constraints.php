<?php

return [

    /*
    |--------------------------------------------------------------------------
    | File Constraints Language Lines
    |--------------------------------------------------------------------------
    |
    | Mensajes de error utilizados por el value object FileConstraints.
    |
    */

    'upload' => [
        'invalid_file' => 'El archivo subido no es válido.',
        'unreadable_temp' => 'No se ha podido leer el archivo temporal.',
        'mime_detection_failed' => 'No se ha podido determinar el tipo MIME del archivo.',
        'mime_not_allowed' => 'El tipo de archivo ":mime" no está permitido. Tipos admitidos: :allowed.',
        'size_exceeded' => 'El archivo supera el tamaño máximo permitido de :max bytes.',
        'unknown_mime' => 'desconocido',
    ],

    'dimensions' => [
        'too_small' => 'La imagen es demasiado pequeña (mínimo :min x :min píxeles).',
        'too_large' => 'La imagen supera las dimensiones máximas permitidas (:max píxeles).',
        'megapixels_exceeded' => 'La imagen excede el límite máximo de :max megapíxeles.',
    ],

    'probe' => [
        'read_error' => 'Error al leer la imagen: :error.',
        'invalid_dimensions' => 'La imagen tiene dimensiones no válidas o está dañada.',
        'image_bomb' => 'Se ha detectado una posible “image bomb” (archivo malicioso).',
    ],

];
