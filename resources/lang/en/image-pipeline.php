<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Image Pipeline Language Lines
    |--------------------------------------------------------------------------
    |
    | Error and success messages for the ImagePipeline service
    |
    */

    // Error messages
    'extension_not_available' => 'The PHP "imagick" extension is not available.',
    'file_not_valid' => 'The uploaded file is not valid.',
    'file_size_invalid' => 'File size is out of limits.',
    'file_not_readable' => 'Could not read the temporary file.',
    'mime_not_allowed' => 'MIME type not allowed: :mime',
    'image_load_failed' => 'Could not validate the loaded image.',
    'gif_clone_failed' => 'Could not extract the first frame from the GIF.',
    'gif_frame_invalid' => 'Invalid GIF frame at index :index.',
    'dimensions_too_small' => 'Minimum dimensions not reached.',
    'megapixels_exceeded' => 'The image exceeds the allowed megapixel limit.',
    'write_failed' => 'Error writing the processed image.',
    'temp_file_invalid' => 'The resulting temporary file is invalid.',
    'processing_failed' => 'Error in image processing.',
    'cleanup_failed' => 'Could not clean up the temporary file.',

    // Log messages
    'gif_clone_failed' => 'Error cloning GIF frame',
    'gif_invalid_frame' => 'Invalid GIF frame detected',
    'tmp_unlink_failed' => 'Error removing temporary file',
    'cmyk_to_srgb' => 'CMYK to sRGB conversion detected',
    'srgb_failed' => 'Error in sRGB conversion',
    'image_pipeline_failed' => 'General error in image pipeline',

    // Success messages
    'image_processed' => 'Image processed successfully',
    'format_converted' => 'Format converted to :format',

    // Validation messages
    'validation' => [
        'avatar_required' => 'Select an image for your avatar.',
        'avatar_file' => 'The avatar must be a file.',
        'avatar_mime' => 'Allowed formats: JPEG, PNG, GIF, WebP, or AVIF.',
        'dimensions' => 'Image dimensions are outside the allowed range.',
    ],

];
