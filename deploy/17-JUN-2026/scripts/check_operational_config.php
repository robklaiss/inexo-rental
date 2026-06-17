<?php
declare(strict_types=1);

putenv('INEXO_SKIP_DISPATCH=1');
require dirname(__DIR__) . '/index.php';

init_db();

$errors = [];
$warnings = [];
$origin = freight_settings();
$smtp = smtp_config();
$truckTypes = freight_truck_types(true);

if (
    trim((string) $origin['origin_address']) === ''
    && (trim((string) $origin['origin_lat']) === '' || trim((string) $origin['origin_lng']) === '')
) {
    $errors[] = 'Falta configurar la direccion o coordenadas de origen de Inexo.';
}

if ($smtp === null) {
    $errors[] = 'El proceso CLI no puede ver el SMTP productivo. Debe cargar el mismo .env o entorno que la aplicacion.';
} else {
    if (!filter_var(mail_from_email(), FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'INEXO_MAIL_FROM no es un email valido.';
    }
    if (!in_array((string) $smtp['encryption'], ['ssl', 'tls', 'none', ''], true)) {
        $errors[] = 'INEXO_SMTP_ENCRYPTION debe ser ssl, tls o none.';
    }
}

if ($truckTypes === []) {
    $errors[] = 'No hay tipos de camion activos.';
}

foreach ($truckTypes as $truck) {
    $name = (string) ($truck['name'] ?? 'Camion');
    if ((float) ($truck['cost_per_km'] ?? 0) <= 0) {
        $errors[] = $name . ': falta costo por km.';
    }
    if ((float) ($truck['max_weight_kg'] ?? 0) <= 0) {
        $errors[] = $name . ': falta capacidad maxima en kg.';
    }
    if ((float) ($truck['max_volume_m3'] ?? 0) <= 0) {
        $errors[] = $name . ': falta capacidad maxima en volumen.';
    }
}

if (app_setting('google_maps_browser_key') === '' && env_value(['INEXO_GOOGLE_MAPS_BROWSER_KEY']) === '') {
    $warnings[] = 'Google Maps no tiene browser key; la distancia debera cargarse manualmente.';
}

$result = [
    'ready' => $errors === [],
    'origin' => [
        'address' => (string) $origin['origin_address'],
        'lat' => (string) $origin['origin_lat'],
        'lng' => (string) $origin['origin_lng'],
    ],
    'smtp' => $smtp === null ? 'disabled' : [
        'host' => (string) $smtp['host'],
        'port' => (int) $smtp['port'],
        'username' => (string) $smtp['username'],
        'encryption' => (string) $smtp['encryption'],
        'from' => mail_from_email(),
    ],
    'active_trucks' => array_map(
        static fn(array $truck): array => [
            'name' => (string) $truck['name'],
            'max_weight_kg' => (float) $truck['max_weight_kg'],
            'max_volume_m3' => (float) $truck['max_volume_m3'],
            'cost_per_km' => (float) $truck['cost_per_km'],
        ],
        $truckTypes
    ),
    'errors' => $errors,
    'warnings' => $warnings,
];

fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($errors === [] ? 0 : 1);
