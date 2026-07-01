<?php

return [

    'git' => [
        'default' => env('GIT_PROVIDER'),
    ],

    'gitea' => [
        'root_url' => env('GITEA_ROOT_URL'),
        'url' => str_replace('gitea:3000', 'localhost:3000', env('GITEA_API_URL')),
        'token' => env('GITEA_API_TOKEN'),
    ],

    'github' => [
        'root_url' => env('GITHUB_ROOT_URL'),
        'url' => env('GITHUB_API_URL'),
        'token' => env('GITHUB_API_TOKEN'),
    ],

];
