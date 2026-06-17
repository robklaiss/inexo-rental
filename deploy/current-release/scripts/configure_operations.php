<?php
declare(strict_types=1);

putenv('INEXO_SKIP_DISPATCH=1');
require dirname(__DIR__) . '/index.php';

init_db();

$configPath = $argv[1] ?? '';
if ($configPath === '' || !is_file($configPath)) {
    fwrite(STDERR, "Uso: php scripts/configure_operations.php /ruta/operations.json\n");
    exit(2);
}

$config = json_decode((string) file_get_contents($configPath), true);
if (!is_array($config)) {
    fwrite(STDERR, "El archivo de configuracion no contiene JSON valido.\n");
    exit(2);
}

$origin = $config['origin'] ?? [];
$trucks = $config['trucks'] ?? [];
if (!is_array($origin) || !is_array($trucks) || $trucks === []) {
    fwrite(STDERR, "La configuracion requiere origin y al menos un camion.\n");
    exit(2);
}

$address = trim((string) ($origin['address'] ?? ''));
$lat = trim((string) ($origin['lat'] ?? ''));
$lng = trim((string) ($origin['lng'] ?? ''));
if ($address === '' && ($lat === '' || $lng === '')) {
    fwrite(STDERR, "El origen requiere direccion o coordenadas completas.\n");
    exit(2);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $settingStmt = $pdo->prepare(
        'INSERT INTO app_settings (name, value) VALUES (?, ?)
        ON CONFLICT(name) DO UPDATE SET value = excluded.value'
    );
    foreach ([
        'company_origin_address' => $address,
        'company_origin_lat' => $lat,
        'company_origin_lng' => $lng,
        'company_origin_place_id' => trim((string) ($origin['place_id'] ?? '')),
    ] as $name => $value) {
        $settingStmt->execute([$name, $value]);
    }

    $truckStmt = $pdo->prepare(
        'UPDATE freight_truck_types
        SET max_weight_kg = ?, max_volume_m3 = ?, cost_per_km = ?, description = ?, is_active = ?
        WHERE slug = ?'
    );
    foreach ($trucks as $truck) {
        if (!is_array($truck)) {
            throw new InvalidArgumentException('Cada camion debe ser un objeto.');
        }
        $slug = trim((string) ($truck['slug'] ?? ''));
        $weight = (float) ($truck['max_weight_kg'] ?? 0);
        $volume = (float) ($truck['max_volume_m3'] ?? 0);
        $cost = (float) ($truck['cost_per_km'] ?? 0);
        if ($slug === '' || $weight <= 0 || $volume <= 0 || $cost <= 0) {
            throw new InvalidArgumentException('Cada camion requiere slug, kg, volumen y costo por km mayores a cero.');
        }
        $truckStmt->execute([
            $weight,
            $volume,
            $cost,
            trim((string) ($truck['description'] ?? '')),
            !array_key_exists('is_active', $truck) || (bool) $truck['is_active'] ? 1 : 0,
            $slug,
        ]);
        if ($truckStmt->rowCount() !== 1) {
            throw new InvalidArgumentException('No existe el tipo de camion: ' . $slug);
        }
    }
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Configuracion operativa guardada.\n");
