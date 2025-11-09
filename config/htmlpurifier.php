<?php

return [
    'default' => [
        'HTML.Allowed' => 'p,br,strong,em,u,ol,ul,li,a[href],h1,h2,h3,h4,h5,h6',
        'HTML.AllowedAttributes' => 'href,title',
        'AutoFormat.RemoveEmpty' => true,
        'AutoFormat.AutoParagraph' => true,
        'Cache.DefinitionImpl' => null,
        'Cache.SerializerPath' => storage_path('app/htmlpurifier'),
        'Cache.SerializerPermissions' => 0755,
        // endurecer por defecto
        'HTML.SafeIframe' => false,
        'HTML.Trusted' => false,
    ],

    'strict' => [
        'HTML.Allowed' => 'p,br,strong,em',
        'HTML.AllowedAttributes' => '',
        'AutoFormat.RemoveEmpty' => true,
        'Cache.SerializerPath' => storage_path('app/htmlpurifier'),
        'Cache.SerializerPermissions' => 0755,
    ],

    'permissive' => [
        'HTML.Allowed' => 'p,br,strong,em,u,ol,ul,li,a[href|title],h1,h2,h3,h4,h5,h6,img[src|alt|width|height],table,tr,td,th',
        'HTML.AllowedAttributes' => 'href,title,src,alt,width,height',
        'AutoFormat.RemoveEmpty' => true,
        'Cache.SerializerPath' => storage_path('app/htmlpurifier'),
        'Cache.SerializerPermissions' => 0755,
    ],

    'translations' => [
        'HTML.Allowed' => 'p,br,strong,em,u,ol,ul,li,a[href],h1,h2,h3,h4,h5,h6',
        'HTML.AllowedAttributes' => 'href,title',
        'AutoFormat.RemoveEmpty' => true,
        'AutoFormat.AutoParagraph' => false,
        'Cache.SerializerPath' => storage_path('app/htmlpurifier'),
        'Cache.SerializerPermissions' => 0755,
    ],
];
