<?php

return [
    'cleanup' => [
        'state_persistence_failed' => 'Could not save cleanup progress.',
        'pending_flagged'          => 'Pending images marked for deletion.',
        'conversions_progress'     => 'Image processing updated.',
        'degraded_dispatch'        => 'Cleanup started in emergency mode.',
        'states_expired_purged'    => 'Expired temporary files removed.',
        'dispatched'               => 'Image cleanup scheduled.',
        'deferred'                 => 'Cleanup postponed until image processing completes.',
        'conversion_status_unavailable' => 'Cannot verify image status.',
        'state_unavailable'        => 'Cannot load cleanup progress.',
        'state_save_failed'        => 'Error saving progress.',
        'state_lock_unavailable'   => 'Cleanup system is busy.',
        'state_lock_timeout'       => 'Timeout while accessing the system.',
        'state_delete_failed'      => 'Error deleting temporary files.',
    ],

    'errors' => [
        'signed_serve_disabled' => 'Signed avatar service is not available.',
        'invalid_signature'     => 'The request signature is invalid or has expired.',
        'invalid_conversion'    => 'The requested conversion is not valid.',
        'not_avatar_collection' => 'The requested resource does not belong to the avatar collection.',
        'missing_conversion'    => 'The requested conversion is not available.',
    ],

    'uploads' => [
        'scan_unavailable'          => 'Upload scanning service is currently unavailable.',
        'scan_blocked'              => 'The file was blocked by the security scanner.',
        'source_unreadable'         => 'The uploaded file could not be read.',
        'quarantine_persist_failed' => 'Failed to persist quarantine artifact.',
        'quarantine_local_disk_required' => 'The quarantine repository requires a local disk. Update MEDIA_QUARANTINE_DISK accordingly.',
        'quarantine_root_missing'        => 'Quarantine disk :disk must define a root path.',
        'quarantine_empty_content'       => 'Cannot quarantine empty content.',
        'quarantine_path_failed'         => 'Failed to generate a quarantine path after :attempts attempts.',
        'quarantine_artifact_missing'    => 'Quarantine artifact vanished after creation.',
        'quarantine_delete_outside'      => 'Cannot delete files outside quarantine.',
        'quarantine_delete_failed'       => 'Failed to delete quarantine file: :error',
        'quarantine_promote_outside'     => 'Cannot promote files outside quarantine.',
        'quarantine_promote_missing'     => 'Quarantine file does not exist.',
        'invalid_image'                  => 'Invalid image file.',
        'max_size_exceeded'              => 'The file exceeds the maximum allowed size (:bytes bytes).',
    ],
];
