<?php
// Configuration for WS relay. Override via getenv('WS_RELAY_URL') or getenv('WS_RELAY_SECRET')
return [
    'url'    => getenv('WS_RELAY_URL') ?: 'http://127.0.0.1:3000',
    'secret' => getenv('WS_RELAY_SECRET') ?: 'dev_secret_change_me',
];
