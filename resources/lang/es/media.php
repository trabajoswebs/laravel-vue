<?php

return [
    'cleanup' => [
        'state_persistence_failed' => 'No se ha podido guardar el progreso de la limpieza.',
        'pending_flagged'          => 'Imágenes pendientes marcadas para eliminar.',
        'conversions_progress'     => 'Procesamiento de imágenes actualizado.',
        'degraded_dispatch'        => 'Limpieza iniciada en modo de emergencia.',
        'states_expired_purged'    => 'Archivos temporales caducados eliminados.',
        'dispatched'               => 'Limpieza de imágenes programada.',
        'deferred'                 => 'Limpieza pospuesta hasta que terminen de procesarse las imágenes.',
        'conversion_status_unavailable' => 'No se puede verificar el estado de las imágenes.',
        'state_unavailable'        => 'No se puede cargar el progreso de limpieza.',
        'state_save_failed'        => 'Error al guardar el progreso.',
        'state_lock_unavailable'   => 'El sistema de limpieza está ocupado.',
        'state_lock_timeout'       => 'Tiempo de espera agotado al acceder al sistema.',
        'state_delete_failed'      => 'Error al eliminar los archivos temporales.',
    ],
    'errors' => [
        'signed_serve_disabled' => 'El servicio de avatares firmados no está disponible.',
        'invalid_signature'     => 'La firma de la solicitud no es válida o ha expirado.',
        'invalid_conversion'    => 'La conversión solicitada no es válida.',
        'not_avatar_collection' => 'El recurso solicitado no corresponde a la colección de avatares.',
        'missing_conversion'    => 'La conversión solicitada no está disponible.',
    ],

    'uploads' => [
        'scan_unavailable'          => 'El servicio de escaneo de archivos no está disponible en este momento.',
        'scan_blocked'              => 'El archivo fue bloqueado por el escáner de seguridad.',
        'source_unreadable'         => 'No se pudo leer el archivo de origen.',
        'quarantine_persist_failed' => 'No se pudo guardar el artefacto en cuarentena.',
        'quarantine_local_disk_required' => 'El repositorio de cuarentena requiere un disco local. Ajusta MEDIA_QUARANTINE_DISK.',
        'quarantine_root_missing'        => 'El disco de cuarentena :disk debe definir una ruta raíz.',
        'quarantine_empty_content'       => 'No es posible poner en cuarentena un contenido vacío.',
        'quarantine_path_failed'         => 'No se pudo generar una ruta de cuarentena tras :attempts intentos.',
        'quarantine_artifact_missing'    => 'El artefacto en cuarentena desapareció tras crearse.',
        'quarantine_delete_outside'      => 'No se pueden eliminar archivos fuera de la cuarentena.',
        'quarantine_delete_failed'       => 'Error al eliminar el archivo de cuarentena: :error',
        'quarantine_promote_outside'     => 'No se pueden promover archivos fuera de la cuarentena.',
        'quarantine_promote_missing'     => 'El archivo de cuarentena no existe.',
        'invalid_image'                  => 'Archivo de imagen inválido.',
        'max_size_exceeded'              => 'El archivo excede el tamaño máximo permitido (:bytes bytes).',
    ],
];
