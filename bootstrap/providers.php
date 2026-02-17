<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\AuthServiceProvider::class,
    App\Providers\EventServiceProvider::class,
    App\Providers\HtmlPurifierServiceProvider::class,
    App\Infrastructure\Uploads\Providers\MediaLibraryBindingsServiceProvider::class,
    App\Infrastructure\Uploads\Providers\ImagePipelineServiceProvider::class,
    App\Modules\Tenancy\Providers\TenancyServiceProvider::class, // Registra bindings de multi-tenancy
    App\Infrastructure\Uploads\Providers\UploadsServiceProvider::class, // Registra perfiles de upload
];
