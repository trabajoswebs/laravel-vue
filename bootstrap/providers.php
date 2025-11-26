<?php

return [
    App\Infrastructure\Providers\AppServiceProvider::class,
    App\Infrastructure\Providers\AuthServiceProvider::class,
    App\Infrastructure\Providers\EventServiceProvider::class,
    App\Infrastructure\Providers\HtmlPurifierServiceProvider::class,
    App\Infrastructure\Media\Providers\MediaLibraryBindingsServiceProvider::class,
    App\Infrastructure\Media\Providers\ImagePipelineServiceProvider::class,
];
