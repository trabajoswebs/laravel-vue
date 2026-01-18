<?php

return [
    App\Infrastructure\Providers\AppServiceProvider::class,
    App\Infrastructure\Providers\AuthServiceProvider::class,
    App\Infrastructure\Providers\EventServiceProvider::class,
    App\Infrastructure\Providers\HtmlPurifierServiceProvider::class,
    App\Infrastructure\Uploads\Providers\MediaLibraryBindingsServiceProvider::class,
    App\Infrastructure\Uploads\Providers\ImagePipelineServiceProvider::class,
    App\Infrastructure\Tenancy\Providers\TenancyServiceProvider::class, // Registra bindings de multi-tenancy
    App\Infrastructure\Uploads\Providers\UploadsServiceProvider::class, // Registra perfiles de upload
];
