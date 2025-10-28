<?php

return [

    /*
    |--------------------------------------------------------------------------
    | File Constraints Language Lines
    |--------------------------------------------------------------------------
    |
    | Error messages used by the FileConstraints value object.
    |
    */

    'upload' => [
        'invalid_file' => 'The uploaded file is not valid.',
        'unreadable_temp' => 'The temporary file could not be read.',
        'mime_detection_failed' => 'Could not determine the file’s MIME type.',
        'mime_not_allowed' => 'The MIME type ":mime" is not permitted. Allowed types: :allowed.',
        'size_exceeded' => 'The file exceeds the maximum allowed size of :max bytes.',
        'unknown_mime' => 'unknown',
    ],

    'dimensions' => [
        'too_small' => 'The image does not meet the minimum required dimensions (:min x :min pixels).',
        'too_large' => 'The image exceeds the maximum permitted dimensions (:max pixels).',
        'megapixels_exceeded' => 'The image exceeds the allowed limit of :max megapixels.',
    ],

    'probe' => [
        'read_error' => 'Error reading the image: :error.',
        'invalid_dimensions' => 'Invalid image dimensions or the file is corrupted.',
        'image_bomb' => 'Possible “image bomb” detected (potentially malicious file).',
    ],

];
