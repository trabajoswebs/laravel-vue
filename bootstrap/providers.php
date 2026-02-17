<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\AuthServiceProvider::class,
    App\Providers\EventServiceProvider::class,
    App\Providers\HtmlPurifierServiceProvider::class,
    App\Modules\Uploads\Providers\MediaLibraryBindingsServiceProvider::class,
    App\Modules\Uploads\Providers\ImagePipelineServiceProvider::class,
    App\Modules\Tenancy\Providers\TenancyServiceProvider::class, // Registra bindings de multi-tenancy
    App\Modules\Uploads\Providers\UploadsServiceProvider::class, // Registra perfiles de upload
];
