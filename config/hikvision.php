<?php

return [
    'hikcentral' => [
        'url' => env('HIKCENTRAL_URL'),
        'key' => env('HIKCENTRAL_KEY'),
        'secret' => env('HIKCENTRAL_SECRET'),
        'ca_bundle' => env('HIKCENTRAL_CA_BUNDLE'),
        'people_path' => '/api/resource/v1/person/personList',
    ],
    'device_ca_bundle' => env('HIKVISION_CA_BUNDLE'),
    'events_path' => '/ISAPI/AccessControl/AcsEvent?format=json',
    'user_search_path' => '/ISAPI/AccessControl/UserInfo/Search?format=json',
    'card_path' => '/ISAPI/AccessControl/CardInfo/SetUp?format=json',
    'card_search_path' => '/ISAPI/AccessControl/CardInfo/Search?format=json',
    'delete_card_path' => '/ISAPI/AccessControl/CardInfo/Delete?format=json',
];
