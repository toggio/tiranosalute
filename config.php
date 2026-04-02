<?php

// Parametri principali del progetto:
// database SQLite, sicurezza, categorie visita,
// timezone condivisa e durata degli slot.
return [
    'app_name' => 'Tirano Salute',
    'timezone' => 'Europe/Rome',
    'db_path' => __DIR__ . '/data/tiranosalute.sqlite',
    // Master key per la codifica dei referti. Non utilizzare in produzione!
    'report_master_key_b64' => 'VGlyYW5vU2FsdXRlTWFzdGVyS2V5RGVtbzIwMjYhISE=',
    'token_ttl_hours' => 24,
    'web_session_ttl_hours' => 12,
    'auth_cookie_name' => 'ts_auth',
    'csrf_cookie_name' => 'ts_csrf',
    'cors' => [
        // Demo pensata per uso same-origin; abilitare origin esplicite solo se servono integrazioni browser cross-site.
        'allow_origins' => [],
        'allow_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        'allow_headers' => ['Content-Type', 'Authorization', 'X-CSRF-Token'],
    ],
    'visit_categories' => [
        'prima visita',
        'prescrizione',
        'certificato',
        'controllo esami',
        'visita di controllo',
    ],
    'appointment_slot_minutes' => 15,
];
