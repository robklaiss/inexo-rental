<?php
declare(strict_types=1);

session_start();

const DB_PATH = __DIR__ . '/inexo_rental.sqlite3';
const ASSET_BASE = '/inexo-rental---tu-partner-en-cada-obra.webflow';
const UPLOAD_DIR = __DIR__ . '/uploads/products';
const UPLOAD_BASE = '/uploads/products';
const BRAND_UPLOAD_DIR = __DIR__ . '/uploads/brands';
const BRAND_UPLOAD_BASE = '/uploads/brands';
const SPECIALIZATION_UPLOAD_DIR = __DIR__ . '/uploads/specializations';
const SPECIALIZATION_UPLOAD_BASE = '/uploads/specializations';
const DEFAULT_CONTACT_EMAIL = 'info@inexo.com.do';
const DEFAULT_MAIL_FROM_EMAIL = 'info@inexo.com.do';
const DEFAULT_MAIL_FROM_NAME = 'Inexo Rental';
const DEFAULT_META_DESCRIPTION = 'Alquiler de equipos y herramientas para construccion. Tu partner, en cada obra.';
const DEFAULT_META_IMAGE = ASSET_BASE . '/images/inexo-meta-image.jpg';
const FAVICON_IMAGE = ASSET_BASE . '/images/favicon.png';
const WEBCLIP_IMAGE = ASSET_BASE . '/images/webclip.png';
const ADMIN_PASSWORD_USERNAME = 'adminexo';
const ADMIN_PASSWORD_HASH = '$2y$12$4Iv3JyClC4Dp5sI51qN0EOr9u9PPjywIi1jTJ7yY0rbwRSP81MZqG';

$dominicanCities = [
    'Santo Domingo',
    'Santiago de los Caballeros',
    'Punta Cana',
    'La Romana',
    'San Pedro de Macoris',
    'San Francisco de Macoris',
    'La Vega',
    'Puerto Plata',
    'Bonao',
    'Moca',
    'Bani',
    'Azua',
    'Barahona',
    'Higuey',
    'San Cristobal',
];

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    return $pdo;
}

function h(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function public_base_path(): string
{
    static $base = null;

    if ($base !== null) {
        return $base;
    }

    $configured = getenv('INEXO_BASE_PATH') ?: getenv('APP_BASE_PATH') ?: '';
    if ($configured !== '') {
        $base = '/' . trim($configured, '/');
        return $base === '/' ? '' : $base;
    }

    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');
    $scriptDir = str_replace('\\', '/', dirname($scriptName));
    $base = $scriptDir === '/' || $scriptDir === '.' ? '' : rtrim($scriptDir, '/');

    return $base;
}

function public_path(string $path): string
{
    if ($path === '' || $path === '#') {
        return $path;
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $path) || str_starts_with($path, '//')) {
        return $path;
    }

    $base = public_base_path();
    if (!str_starts_with($path, '/')) {
        $path = '/' . $path;
    }
    if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
        return $path;
    }

    return $base . $path;
}

function route_path_from_request(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = public_base_path();

    if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
        $path = substr($path, strlen($base)) ?: '/';
    }

    return rtrim($path, '/') ?: '/';
}

function prefix_public_paths(string $html): string
{
    $base = public_base_path();
    if ($base === '') {
        return $html;
    }

    return preg_replace_callback(
        '/\b(href|src|action|content|data-product-url|data-image|data-placeholder-image)=(["\'])\/(?!\/)([^"\']*)\2/i',
        static function (array $match) use ($base): string {
            $path = '/' . $match[3];
            if ($path === $base || str_starts_with($path, $base . '/')) {
                return $match[0];
            }

            return $match[1] . '=' . $match[2] . $base . $path . $match[2];
        },
        $html
    ) ?? $html;
}

function money(mixed $value): string
{
    return '$ ' . number_format((float) $value, 0, '.', ',');
}

function slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = iconv('UTF-8', 'ASCII//TRANSLIT', $value) ?: $value;
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');

    return $value !== '' ? $value : 'producto';
}

function init_db(): void
{
    $pdo = db();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            code TEXT NOT NULL,
            brand TEXT NOT NULL DEFAULT '',
            category TEXT NOT NULL DEFAULT '',
            specialization TEXT NOT NULL DEFAULT '',
            short_description TEXT NOT NULL DEFAULT '',
            description TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'En stock',
            price_sale_used REAL NOT NULL DEFAULT 0,
            price_sale_new REAL NOT NULL DEFAULT 0,
            rental_daily REAL NOT NULL DEFAULT 0,
            rental_weekly REAL NOT NULL DEFAULT 0,
            rental_monthly REAL NOT NULL DEFAULT 0,
            images TEXT NOT NULL DEFAULT '[]',
            specs TEXT NOT NULL DEFAULT '[]',
            is_featured INTEGER NOT NULL DEFAULT 0,
            is_new INTEGER NOT NULL DEFAULT 0,
            deleted_at TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
    ensure_column('products', 'deleted_at', 'TEXT');
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS reservations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            rental_plan TEXT NOT NULL,
            start_date TEXT NOT NULL,
            end_date TEXT NOT NULL,
            city TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(product_id) REFERENCES products(id)
        )"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS brands (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            slug TEXT NOT NULL UNIQUE,
            logo TEXT NOT NULL DEFAULT '',
            description TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
    ensure_column('brands', 'logo', "TEXT NOT NULL DEFAULT ''");
    ensure_column('brands', 'description', "TEXT NOT NULL DEFAULT ''");
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS specializations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            slug TEXT NOT NULL UNIQUE,
            icon TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
    ensure_column('specializations', 'icon', "TEXT NOT NULL DEFAULT ''");
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL DEFAULT '',
            name TEXT NOT NULL DEFAULT '',
            phone TEXT NOT NULL DEFAULT '',
            company TEXT NOT NULL DEFAULT '',
            address TEXT NOT NULL DEFAULT '',
            city TEXT NOT NULL DEFAULT '',
            is_verified INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_login_at TEXT
        )"
    );
    ensure_column('users', 'password_hash', "TEXT NOT NULL DEFAULT ''");
    ensure_column('users', 'is_admin', 'INTEGER NOT NULL DEFAULT 0');
    sync_configured_admin_users();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS login_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            token TEXT NOT NULL UNIQUE,
            expires_at TEXT NOT NULL,
            used_at TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(user_id) REFERENCES users(id)
        )"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'pendiente_validacion',
            customer_snapshot TEXT NOT NULL DEFAULT '{}',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(user_id) REFERENCES users(id)
        )"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS order_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL,
            product_id INTEGER,
            product_name TEXT NOT NULL,
            product_url TEXT NOT NULL DEFAULT '',
            mode TEXT NOT NULL DEFAULT 'rental',
            quantity INTEGER NOT NULL DEFAULT 1,
            rental_plan TEXT NOT NULL DEFAULT '',
            start_date TEXT NOT NULL DEFAULT '',
            end_date TEXT NOT NULL DEFAULT '',
            city TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(order_id) REFERENCES orders(id),
            FOREIGN KEY(product_id) REFERENCES products(id)
        )"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS contact_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            phone TEXT NOT NULL DEFAULT '',
            company TEXT NOT NULL DEFAULT '',
            subject TEXT NOT NULL DEFAULT '',
            message TEXT NOT NULL,
            email_sent INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );

    $count = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    if ($count > 0) {
        seed_lookup_tables_from_products();
        return;
    }

    $generic = ASSET_BASE . '/images/imagen-producto-generico.avif';
    $images = json_encode([
        $generic,
        ASSET_BASE . '/images/imagen-banner-central.avif',
        ASSET_BASE . '/images/fondo-banner-central.avif',
        ASSET_BASE . '/images/trabajadores-de-obra.avif',
    ], JSON_UNESCAPED_SLASHES);

    $products = [
        [
            'hyundai-miniexcavadora-hx35az',
            'Hyundai Miniexcavadora HX35AZ',
            'INX-213-24-C2',
            'Hyundai',
            'Productos',
            'Movimiento de suelo',
            'Miniexcavadora compacta para trabajos en espacios reducidos.',
            "La HX35AZ es una excavadora compacta disenada para trabajos en espacios reducidos, ofreciendo una combinacion perfecta de potencia, versatilidad y eficiencia. Con su motor de 26 HP, torque de 97 Nm, una cuchara de 0.11 m3 y sistema hidraulico avanzado, esta excavadora es ideal para aplicaciones en construccion, excavacion y mantenimiento.\n\nLa HX35AZ cuenta con caracteristicas innovadoras como su diseno compacto, su sistema de estabilizacion automatico y su cabina amplia y ergonomica. Ademas, su bajo consumo de combustible y su mantenimiento reducido la hacen una opcion rentable y sostenible.",
            'En stock',
            245000,
            285000,
            350,
            4200,
            12500,
            $images,
            json_encode([
                ['Motor', 'Hyundai QSM11 Tier 4 Final'],
                ['Potencia', '140 HP (128 kW) @ 1800 RPM'],
                ['Peso', '85,000 lbs (38,739 Kg.)'],
                ['Excavadora', 'Capacidad 2.5 - 3.8 yd3'],
                ['Hidraulica', '2 x 95 gpm (360 L/min)'],
                ['Tanque', '625 Lt.'],
            ]),
            1,
            1,
        ],
        [
            'cortadora-de-hierro-sima-cel-36',
            'Cortadora de hierro Sima Cel 36',
            'INX-102-18-A1',
            'Sima',
            'Productos',
            'Corte y preparacion',
            'Cortadora robusta para obra y taller.',
            'Equipo de corte para varillas de hierro con operacion estable, mantenimiento simple y rendimiento continuo en obra.',
            'En stock',
            1800,
            2600,
            55,
            320,
            820,
            $images,
            json_encode([['Diametro maximo', '36 mm'], ['Voltaje', '220 V'], ['Peso', '60 Kg.']]),
            1,
            0,
        ],
        [
            'compactador-vertical',
            'Compactador vertical',
            'INX-442-21-B4',
            'Wacker Neuson',
            'Productos',
            'Compactacion',
            'Compactador para zanjas y rellenos.',
            'Compactador vertical para lograr alta densidad en suelos granulares y mixtos, con estructura reforzada para uso continuo.',
            'En stock',
            1200,
            2100,
            45,
            280,
            760,
            $images,
            json_encode([['Motor', 'Gasolina'], ['Impactos', '680/min'], ['Peso', '72 Kg.']]),
            0,
            1,
        ],
        [
            'generador-electrico-6500w',
            'Generador electrico 6500W',
            'INX-650-19-G6',
            'Honda',
            'Productos',
            'Energia',
            'Generador portatil para alimentar herramientas en obra.',
            'Generador confiable para respaldo electrico y alimentacion de herramientas, con tanque amplio y controles protegidos.',
            'En stock',
            980,
            1750,
            38,
            240,
            690,
            $images,
            json_encode([['Potencia', '6500 W'], ['Combustible', 'Gasolina'], ['Tanque', '25 Lt.']]),
            1,
            1,
        ],
        [
            'andamio-multidireccional',
            'Andamio multidireccional',
            'INX-335-20-D8',
            'Layher',
            'Productos',
            'Acceso y altura',
            'Sistema modular para trabajo seguro en altura.',
            'Andamio multidireccional adaptable a frentes de obra, fachadas e instalaciones industriales.',
            'En stock',
            900,
            1500,
            30,
            210,
            610,
            $images,
            json_encode([['Material', 'Acero galvanizado'], ['Altura', 'Configurable'], ['Uso', 'Exterior']]),
            0,
            1,
        ],
    ];

    $stmt = $pdo->prepare(
        "INSERT INTO products (
            slug, name, code, brand, category, specialization, short_description,
            description, status, price_sale_used, price_sale_new, rental_daily,
            rental_weekly, rental_monthly, images, specs, is_featured, is_new
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    foreach ($products as $product) {
        $stmt->execute($product);
    }
    seed_lookup_tables_from_products();
}

function ensure_column(string $table, string $column, string $definition): void
{
    $columns = db()->query("PRAGMA table_info({$table})")->fetchAll();
    foreach ($columns as $existing) {
        if (($existing['name'] ?? '') === $column) {
            return;
        }
    }

    db()->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
}

function decode_product(array $product): array
{
    $product['images'] = json_decode($product['images'] ?: '[]', true) ?: [];
    $product['specs'] = json_decode($product['specs'] ?: '[]', true) ?: [];

    return $product;
}

function admin_product_thumbnail(array $product): string
{
    $generic = ASSET_BASE . '/images/imagen-producto-generico.avif';

    foreach ($product['images'] as $image) {
        $image = trim((string) $image);
        if ($image !== '' && $image !== $generic) {
            return $image;
        }
    }

    return ASSET_BASE . '/images/inexo-x-gris.png';
}

function specialization_display_name(array $specialization): string
{
    $slug = (string) ($specialization['slug'] ?? slugify((string) ($specialization['name'] ?? '')));
    $names = [
        'acceso-y-altura' => 'Acceso y altura',
        'casetones' => 'Casetones',
        'compactacion' => 'Compactación',
        'corte-y-preparacion' => 'Corte y preparación',
        'energia' => 'Energía',
        'movimiento-de-suelo' => 'Movimiento de suelo',
    ];

    return $names[$slug] ?? (string) ($specialization['name'] ?? '');
}

function specialization_image_url(array $specialization): string
{
    $slug = (string) ($specialization['slug'] ?? slugify((string) ($specialization['name'] ?? '')));
    $images = [
        'acceso-y-altura' => ASSET_BASE . '/images/especializaciones-acceso-y-altura.jpg',
        'casetones' => ASSET_BASE . '/images/especializaciones-casetones.jpg',
        'compactacion' => ASSET_BASE . '/images/especializaciones-compactacion.jpg',
        'corte-y-preparacion' => ASSET_BASE . '/images/especializaciones-corte-y-preparacion.jpg',
        'energia' => ASSET_BASE . '/images/especializaciones-energia.jpg',
        'movimiento-de-suelo' => ASSET_BASE . '/images/especializaciones-movimiento-de-suelo.jpg',
    ];

    if (isset($images[$slug])) {
        return $images[$slug];
    }

    $icon = trim((string) ($specialization['icon'] ?? ''));

    return $icon !== '' ? $icon : ASSET_BASE . '/images/inexo-x-gris.png';
}

function seed_lookup_tables_from_products(): void
{
    $pdo = db();
    $brandStmt = $pdo->prepare('INSERT OR IGNORE INTO brands (name, slug) VALUES (?, ?)');
    $specStmt = $pdo->prepare('INSERT OR IGNORE INTO specializations (name, slug) VALUES (?, ?)');

    foreach ($pdo->query("SELECT DISTINCT brand FROM products WHERE brand <> '' AND deleted_at IS NULL") as $row) {
        $brandStmt->execute([$row['brand'], slugify($row['brand'])]);
    }
    foreach ($pdo->query("SELECT DISTINCT specialization FROM products WHERE specialization <> '' AND deleted_at IS NULL") as $row) {
        $specStmt->execute([$row['specialization'], slugify($row['specialization'])]);
    }
}

function lookup_rows(string $table): array
{
    $allowed = ['brands', 'specializations'];
    if (!in_array($table, $allowed, true)) {
        return [];
    }

    return db()->query("SELECT * FROM {$table} ORDER BY name ASC")->fetchAll();
}

function specialization_product_counts(): array
{
    $counts = [];
    $rows = db()->query(
        "SELECT specialization, COUNT(*) AS total
        FROM products
        WHERE specialization <> '' AND deleted_at IS NULL
        GROUP BY specialization"
    )->fetchAll();

    foreach ($rows as $row) {
        $counts[(string) $row['specialization']] = (int) $row['total'];
    }

    return $counts;
}

function brand_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM brands WHERE id = ?');
    $stmt->execute([$id]);
    $brand = $stmt->fetch();

    return $brand ?: null;
}

function specialization_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM specializations WHERE id = ?');
    $stmt->execute([$id]);
    $specialization = $stmt->fetch();

    return $specialization ?: null;
}

function create_lookup(string $table, string $name): void
{
    $name = trim($name);
    if ($name === '') {
        return;
    }
    $allowed = ['brands', 'specializations'];
    if (!in_array($table, $allowed, true)) {
        return;
    }

    db()->prepare("INSERT OR IGNORE INTO {$table} (name, slug) VALUES (?, ?)")->execute([$name, slugify($name)]);
}

function save_brand(?int $brandId = null): void
{
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        return;
    }

    $currentLogo = '';
    if ($brandId) {
        $current = brand_by_id($brandId);
        $currentLogo = (string) ($current['logo'] ?? '');
    }

    $logo = uploaded_brand_logo() ?: trim((string) ($_POST['logo'] ?? '')) ?: $currentLogo;
    $values = [
        ':name' => $name,
        ':slug' => slugify($name),
        ':logo' => $logo,
        ':description' => trim((string) ($_POST['description'] ?? '')),
    ];

    if ($brandId) {
        $values[':id'] = $brandId;
        db()->prepare(
            "UPDATE brands SET
                name = :name, slug = :slug, logo = :logo, description = :description
            WHERE id = :id"
        )->execute($values);
        return;
    }

    db()->prepare(
        "INSERT OR IGNORE INTO brands (name, slug, logo, description)
        VALUES (:name, :slug, :logo, :description)"
    )->execute($values);
}

function save_specialization(?int $specializationId = null): void
{
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        return;
    }

    $currentIcon = '';
    if ($specializationId) {
        $current = specialization_by_id($specializationId);
        $currentIcon = (string) ($current['icon'] ?? '');
    }

    $icon = uploaded_specialization_icon() ?: trim((string) ($_POST['icon'] ?? '')) ?: $currentIcon;
    $values = [
        ':name' => $name,
        ':slug' => slugify($name),
        ':icon' => $icon,
    ];

    if ($specializationId) {
        $values[':id'] = $specializationId;
        db()->prepare(
            "UPDATE specializations SET
                name = :name, slug = :slug, icon = :icon
            WHERE id = :id"
        )->execute($values);
        return;
    }

    db()->prepare(
        "INSERT OR IGNORE INTO specializations (name, slug, icon)
        VALUES (:name, :slug, :icon)"
    )->execute($values);
}

function delete_lookup(string $table, int $id): void
{
    $allowed = ['brands', 'specializations'];
    if (!in_array($table, $allowed, true)) {
        return;
    }

    db()->prepare("DELETE FROM {$table} WHERE id = ?")->execute([$id]);
}

function admin_nav(string $active = 'productos'): string
{
    $links = [
        'inicio' => ['/admin', 'Inicio'],
        'productos' => ['/admin/productos', 'Productos'],
        'especializaciones' => ['/admin/especializaciones', 'Especializacion'],
        'marcas' => ['/admin/marcas', 'Marcas'],
        'pedidos' => ['/admin/pedidos', 'Pedidos'],
        'contacto' => ['/admin/contacto', 'Contacto'],
        'usuarios' => ['/admin/usuarios', 'Usuarios'],
    ];
    $html = '<nav class="app-admin-tabs">';
    foreach ($links as $key => [$url, $label]) {
        $html .= '<a class="' . ($active === $key ? 'is-active' : '') . '" href="' . h($url) . '">' . h($label) . '</a>';
    }
    return $html . '</nav>';
}

function select_options(array $rows, string $selected): string
{
    $html = '<option value="">Seleccionar</option>';
    foreach ($rows as $row) {
        $value = $row['name'];
        $html .= '<option value="' . h($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . h($value) . '</option>';
    }

    return $html;
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([(int) $_SESSION['user_id']]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function configured_admin_emails(): array
{
    $emails = getenv('INEXO_ADMIN_EMAILS') ?: getenv('ADMIN_EMAILS') ?: '';
    if ($emails === '') {
        return [];
    }

    return array_values(array_filter(array_map(
        static fn (string $email): string => strtolower(trim($email)),
        preg_split('/[,;\s]+/', $emails) ?: []
    )));
}

function sync_configured_admin_users(): void
{
    $emails = configured_admin_emails();
    if ($emails === []) {
        return;
    }

    $stmt = db()->prepare('UPDATE users SET is_admin = 1 WHERE lower(email) = ?');
    foreach ($emails as $email) {
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $stmt->execute([$email]);
        }
    }
}

function is_admin_password_authenticated(): bool
{
    return (int) ($_SESSION['admin_password_auth'] ?? 0) === 1;
}

function is_admin_user(?array $user = null): bool
{
    if (is_admin_password_authenticated()) {
        return true;
    }

    $user ??= current_user();
    if (!$user) {
        return false;
    }
    if ((int) ($user['is_admin'] ?? 0) === 1) {
        return true;
    }

    return in_array(strtolower((string) ($user['email'] ?? '')), configured_admin_emails(), true);
}

function is_admin_path(string $path): bool
{
    return $path === '/admin' || str_starts_with($path, '/admin/');
}

function is_safe_redirect_path(string $path): bool
{
    return str_starts_with($path, '/') && !str_starts_with($path, '//') && !preg_match('#^/[a-z][a-z0-9+.-]*:#i', $path);
}

function password_validation_error(string $password, string $passwordConfirm): string
{
    if ($password === '') {
        return 'Ingresa una contrasena.';
    }
    if (strlen($password) < 8) {
        return 'La contrasena debe tener al menos 8 caracteres.';
    }
    if ($password !== $passwordConfirm) {
        return 'Las contrasenas no coinciden.';
    }

    return '';
}

function require_admin(string $path): void
{
    if (is_admin_password_authenticated()) {
        return;
    }

    $user = current_user();
    if (!$user) {
        $_SESSION['login_redirect'] = is_safe_redirect_path($path) ? $path : '/admin';
        redirect_to('/admin/login');
    }
    if (!is_admin_user($user)) {
        http_response_code(403);
        layout('Acceso restringido', '
<main class="app-admin-shell">
  <div class="app-admin-header">
    <div><p class="app-kicker">Admin</p><h1>Acceso restringido</h1></div>
    <a href="/salir" class="button w-button">salir</a>
  </div>
  <div class="app-admin-card app-auth-message"><p>Tu cuenta no tiene permisos para entrar al administrador.</p></div>
</main>');
        exit;
    }
}

function create_login_token(int $userId): string
{
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 minutes'));
    db()->prepare('INSERT INTO login_tokens (user_id, token, expires_at) VALUES (?, ?, ?)')->execute([$userId, $token, $expiresAt]);

    return '/auth/verificar?token=' . $token;
}

function log_mail_link(string $email, string $link, ?bool $sent = null): void
{
    $absolute = absolute_url($link);
    $line = '[' . date('c') . '] ' . $email . ' -> ' . $absolute;
    if ($sent !== null) {
        $line .= ' sent=' . ($sent ? '1' : '0');
    }
    $line .= PHP_EOL;
    file_put_contents(__DIR__ . '/mail.log', $line, FILE_APPEND);
}

function contact_recipient_email(): string
{
    $configured = getenv('INEXO_CONTACT_EMAIL') ?: getenv('CONTACT_EMAIL') ?: '';

    return filter_var($configured, FILTER_VALIDATE_EMAIL) ? $configured : DEFAULT_CONTACT_EMAIL;
}

function env_value(array $names, string $default = ''): string
{
    foreach ($names as $name) {
        $value = getenv($name);
        if ($value !== false && trim($value) !== '') {
            return trim($value);
        }
    }

    return $default;
}

function mail_from_email(): string
{
    $configured = env_value(['INEXO_MAIL_FROM', 'MAIL_FROM', 'SMTP_FROM'], DEFAULT_MAIL_FROM_EMAIL);

    return filter_var($configured, FILTER_VALIDATE_EMAIL) ? $configured : DEFAULT_MAIL_FROM_EMAIL;
}

function mail_from_name(): string
{
    return env_value(['INEXO_MAIL_FROM_NAME', 'MAIL_FROM_NAME'], DEFAULT_MAIL_FROM_NAME);
}

function smtp_config(): ?array
{
    $password = env_value(['INEXO_SMTP_PASSWORD', 'SMTP_PASSWORD']);
    if ($password === '') {
        return null;
    }
    $encryption = strtolower(env_value(['INEXO_SMTP_ENCRYPTION', 'SMTP_ENCRYPTION'], 'ssl'));
    $encryption = match ($encryption) {
        'ssl/tls', 'smtps' => 'ssl',
        'starttls' => 'tls',
        default => $encryption,
    };

    return [
        'host' => env_value(['INEXO_SMTP_HOST', 'SMTP_HOST'], 'mail.inexo.com.do'),
        'port' => max(1, (int) env_value(['INEXO_SMTP_PORT', 'SMTP_PORT'], '465')),
        'username' => env_value(['INEXO_SMTP_USERNAME', 'SMTP_USERNAME'], DEFAULT_MAIL_FROM_EMAIL),
        'password' => $password,
        'encryption' => $encryption,
        'timeout' => max(5, (int) env_value(['INEXO_SMTP_TIMEOUT', 'SMTP_TIMEOUT'], '20')),
    ];
}

function clean_mail_header(string $value): string
{
    return trim(preg_replace('/[\r\n]+/', ' ', $value) ?? '');
}

function encode_mail_header(string $value): string
{
    $value = clean_mail_header($value);
    if ($value === '' || preg_match('/^[\x20-\x7E]+$/', $value)) {
        return $value;
    }
    if (function_exists('mb_encode_mimeheader')) {
        return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
    }

    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function email_address_header(string $email, string $name = ''): string
{
    $email = clean_mail_header($email);
    $name = encode_mail_header($name);

    return $name !== '' ? $name . ' <' . $email . '>' : $email;
}

function email_message_id(): string
{
    $host = preg_replace('/[^a-z0-9.-]+/i', '', parse_url(base_url(), PHP_URL_HOST) ?: 'inexo.com.do') ?: 'inexo.com.do';

    return bin2hex(random_bytes(16)) . '@' . $host;
}

function build_email_headers(string $recipient, string $subject, string $contentType, ?string $replyTo = null): array
{
    $from = mail_from_email();
    $headers = [
        'Date' => date(DATE_RFC2822),
        'To' => $recipient,
        'From' => email_address_header($from, mail_from_name()),
        'Reply-To' => filter_var((string) $replyTo, FILTER_VALIDATE_EMAIL) ? (string) $replyTo : $from,
        'Subject' => encode_mail_header($subject),
        'Message-ID' => '<' . email_message_id() . '>',
        'MIME-Version' => '1.0',
        'Content-Type' => clean_mail_header($contentType),
        'X-Mailer' => 'Inexo Rental',
    ];

    return $headers;
}

function headers_to_string(array $headers, array $exclude = []): string
{
    $lines = [];
    foreach ($headers as $name => $value) {
        if (in_array($name, $exclude, true)) {
            continue;
        }
        $lines[] = $name . ': ' . $value;
    }

    return implode("\r\n", $lines);
}

function normalize_email_body(string $body): string
{
    return preg_replace("/(?<!\r)\n/", "\r\n", str_replace("\r\n", "\n", $body)) ?? $body;
}

function smtp_read_response($socket): array
{
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }

    return [(int) substr($response, 0, 3), trim($response)];
}

function smtp_command($socket, string $command, array $expectedCodes, string &$error): bool
{
    if (fwrite($socket, $command . "\r\n") === false) {
        $error = 'No se pudo escribir comando SMTP.';
        return false;
    }
    [$code, $response] = smtp_read_response($socket);
    if (!in_array($code, $expectedCodes, true)) {
        $error = $response !== '' ? $response : 'Respuesta SMTP inesperada para ' . strtok($command, ' ');
        return false;
    }

    return true;
}

function smtp_data_body(string $message): string
{
    $message = normalize_email_body($message);
    $lines = explode("\r\n", $message);
    foreach ($lines as &$line) {
        if (str_starts_with($line, '.')) {
            $line = '.' . $line;
        }
    }
    unset($line);

    return implode("\r\n", $lines);
}

function smtp_send_email(string $recipient, string $subject, string $body, string $contentType, ?string $replyTo, string &$error): bool
{
    $config = smtp_config();
    if ($config === null) {
        $error = 'SMTP no configurado: falta INEXO_SMTP_PASSWORD.';
        return false;
    }

    $host = (string) $config['host'];
    $port = (int) $config['port'];
    $encryption = (string) $config['encryption'];
    $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
            'SNI_enabled' => true,
        ],
    ]);
    $socket = @stream_socket_client($remote, $errno, $errstr, (int) $config['timeout'], STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        $error = 'Conexion SMTP fallida: ' . trim($errstr ?: (string) $errno);
        return false;
    }
    stream_set_timeout($socket, (int) $config['timeout']);

    [$code, $response] = smtp_read_response($socket);
    if ($code !== 220) {
        fclose($socket);
        $error = $response !== '' ? $response : 'El servidor SMTP no acepto la conexion.';
        return false;
    }

    $helloHost = preg_replace('/[^a-z0-9.-]+/i', '', parse_url(base_url(), PHP_URL_HOST) ?: gethostname() ?: 'localhost') ?: 'localhost';
    if (!smtp_command($socket, 'EHLO ' . $helloHost, [250], $error)) {
        fclose($socket);
        return false;
    }
    if ($encryption === 'tls') {
        if (!smtp_command($socket, 'STARTTLS', [220], $error)) {
            fclose($socket);
            return false;
        }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            $error = 'No se pudo activar TLS para SMTP.';
            return false;
        }
        if (!smtp_command($socket, 'EHLO ' . $helloHost, [250], $error)) {
            fclose($socket);
            return false;
        }
    }

    if (!smtp_command($socket, 'AUTH LOGIN', [334], $error)
        || !smtp_command($socket, base64_encode((string) $config['username']), [334], $error)
        || !smtp_command($socket, base64_encode((string) $config['password']), [235], $error)) {
        fclose($socket);
        return false;
    }

    $from = mail_from_email();
    $headers = build_email_headers($recipient, $subject, $contentType, $replyTo);
    $message = headers_to_string($headers) . "\r\n\r\n" . normalize_email_body($body);
    if (!smtp_command($socket, 'MAIL FROM:<' . $from . '>', [250], $error)
        || !smtp_command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251], $error)
        || !smtp_command($socket, 'DATA', [354], $error)) {
        fclose($socket);
        return false;
    }

    if (fwrite($socket, smtp_data_body($message) . "\r\n.\r\n") === false) {
        fclose($socket);
        $error = 'No se pudo enviar el contenido SMTP.';
        return false;
    }
    [$code, $response] = smtp_read_response($socket);
    smtp_command($socket, 'QUIT', [221], $quitError);
    fclose($socket);
    if ($code !== 250) {
        $error = $response !== '' ? $response : 'El servidor SMTP rechazo el mensaje.';
        return false;
    }

    return true;
}

function send_email(string $recipient, string $subject, string $body, string $contentType = 'text/plain; charset=UTF-8', ?string $replyTo = null): bool
{
    $recipient = filter_var($recipient, FILTER_VALIDATE_EMAIL);
    if (!$recipient) {
        return false;
    }

    $error = '';
    if (smtp_config() !== null) {
        $sent = smtp_send_email((string) $recipient, $subject, $body, $contentType, $replyTo, $error);
        if (!$sent) {
            file_put_contents(__DIR__ . '/mail.log', '[' . date('c') . '] smtp-error -> ' . $recipient . ' ' . $error . PHP_EOL, FILE_APPEND);
        }

        return $sent;
    }

    $headers = build_email_headers((string) $recipient, $subject, $contentType, $replyTo);
    $sent = @mail((string) $recipient, encode_mail_header($subject), normalize_email_body($body), headers_to_string($headers, ['To', 'Subject']));
    if (!$sent) {
        file_put_contents(__DIR__ . '/mail.log', '[' . date('c') . '] mail-error -> ' . $recipient . ' SMTP no configurado y mail() fallo.' . PHP_EOL, FILE_APPEND);
    }

    return $sent;
}

function order_statuses(): array
{
    return [
        'pendiente_validacion' => 'Pendiente de validacion',
        'validado' => 'Validado',
        'en_revision' => 'En revision',
        'confirmado' => 'Confirmado',
        'preparando' => 'Preparando',
        'entregado' => 'Entregado',
        'finalizado' => 'Finalizado',
        'cancelado' => 'Cancelado',
    ];
}

function order_status_label(string $status): string
{
    $statuses = order_statuses();

    return $statuses[$status] ?? $status;
}

function order_mode_label(string $mode): string
{
    return match ($mode) {
        'purchase' => 'Compra',
        'rental' => 'Alquiler',
        default => $mode,
    };
}

function order_status_options(string $selected): string
{
    $html = '';
    foreach (order_statuses() as $value => $label) {
        $html .= '<option value="' . h($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . h($label) . '</option>';
    }

    return $html;
}

function order_by_id(int $orderId): ?array
{
    $stmt = db()->prepare(
        "SELECT orders.*, users.email, users.name, users.phone, users.company, users.address, users.city, users.is_verified
        FROM orders
        JOIN users ON users.id = orders.user_id
        WHERE orders.id = ?"
    );
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();

    return $order ?: null;
}

function order_items(int $orderId): array
{
    $stmt = db()->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC');
    $stmt->execute([$orderId]);

    return $stmt->fetchAll();
}

function order_customer_snapshot(array $order): array
{
    $snapshot = json_decode((string) ($order['customer_snapshot'] ?? '{}'), true);

    return is_array($snapshot) ? $snapshot : [];
}

function clean_email_subject(string $subject): string
{
    $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject) ?? '');

    return $subject !== '' ? $subject : 'Actualizacion de tu pedido - Inexo Rental';
}

function send_order_customer_email(array $order, array $items, string $subject, string $message): bool
{
    $recipient = filter_var((string) ($order['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    if (!$recipient) {
        return false;
    }

    $customerName = trim((string) ($order['name'] ?? ''));
    $logoUrl = absolute_url(ASSET_BASE . '/images/inexo-rental-logo-footer.png');
    $subject = clean_email_subject($subject);
    $message = trim($message);
    $messageHtml = $message !== ''
        ? nl2br(h($message))
        : 'Te compartimos una actualizacion sobre tu pedido.';
    $rows = '';
    foreach ($items as $item) {
        $detailParts = array_filter([
            'Modalidad: ' . order_mode_label((string) ($item['mode'] ?? '')),
            trim((string) ($item['rental_plan'] ?? '')) !== '' ? 'Plan: ' . (string) $item['rental_plan'] : '',
            trim((string) ($item['start_date'] ?? '')) !== '' ? 'Desde: ' . (string) $item['start_date'] : '',
            trim((string) ($item['end_date'] ?? '')) !== '' ? 'Hasta: ' . (string) $item['end_date'] : '',
            trim((string) ($item['city'] ?? '')) !== '' ? 'Ciudad: ' . (string) $item['city'] : '',
        ]);
        $rows .= '
          <tr>
            <td style="padding:12px 0;border-bottom:1px solid #eeeeee;">
              <strong style="display:block;color:#111111;">' . h($item['product_name']) . '</strong>
              <span style="display:block;color:#666666;font-size:13px;">Cantidad: ' . (int) $item['quantity'] . '</span>
              <span style="display:block;color:#666666;font-size:13px;">' . h(implode(' - ', $detailParts)) . '</span>
            </td>
          </tr>';
    }

    $html = '<!DOCTYPE html>
<html lang="es">
<body style="margin:0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif;color:#222222;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f4f4;padding:28px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border-radius:8px;overflow:hidden;">
          <tr>
            <td style="background:#111111;padding:24px 28px;">
              <img src="' . h($logoUrl) . '" width="170" alt="Inexo Rental" style="display:block;max-width:170px;height:auto;">
            </td>
          </tr>
          <tr>
            <td style="padding:28px;">
              <p style="margin:0 0 6px;color:#f28c18;font-size:12px;font-weight:bold;letter-spacing:.08em;text-transform:uppercase;">Pedido #' . (int) $order['id'] . '</p>
              <h1 style="margin:0 0 16px;font-size:24px;line-height:1.25;color:#111111;">Actualizacion de tu pedido</h1>
              <p style="margin:0 0 18px;font-size:15px;line-height:1.55;">Hola' . ($customerName !== '' ? ' ' . h($customerName) : '') . ',</p>
              <p style="margin:0 0 22px;font-size:15px;line-height:1.55;">' . $messageHtml . '</p>
              <div style="margin:0 0 22px;padding:14px 16px;background:#f7f7f7;border-left:4px solid #f28c18;">
                <strong style="display:block;color:#111111;">Estado actual</strong>
                <span style="color:#333333;">' . h(order_status_label((string) $order['status'])) . '</span>
              </div>
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                ' . $rows . '
              </table>
              <p style="margin:24px 0 0;font-size:14px;line-height:1.55;color:#555555;">Si tenes alguna consulta, podes responder este email y el equipo de Inexo Rental te va a ayudar.</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';

    $sent = send_email((string) $recipient, $subject, $html, 'text/html; charset=UTF-8', contact_recipient_email());
    $line = '[' . date('c') . '] order-email #' . (int) $order['id'] . ' -> ' . $recipient . ' sent=' . ($sent ? '1' : '0') . ' subject=' . $subject . PHP_EOL;
    file_put_contents(__DIR__ . '/mail.log', $line, FILE_APPEND);

    return $sent;
}

function send_login_link_email(string $email, string $link): bool
{
    $recipient = filter_var($email, FILTER_VALIDATE_EMAIL);
    if (!$recipient) {
        return false;
    }

    $absolute = absolute_url($link);
    $logoUrl = absolute_url(ASSET_BASE . '/images/inexo-rental-logo-footer.png');
    $html = '<!DOCTYPE html>
<html lang="es">
<body style="margin:0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif;color:#222222;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f4f4;padding:28px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border-radius:8px;overflow:hidden;">
          <tr>
            <td style="background:#111111;padding:24px 28px;">
              <img src="' . h($logoUrl) . '" width="170" alt="Inexo Rental" style="display:block;max-width:170px;height:auto;">
            </td>
          </tr>
          <tr>
            <td style="padding:28px;">
              <p style="margin:0 0 6px;color:#f28c18;font-size:12px;font-weight:bold;letter-spacing:.08em;text-transform:uppercase;">Acceso seguro</p>
              <h1 style="margin:0 0 16px;font-size:24px;line-height:1.25;color:#111111;">Tu enlace de ingreso</h1>
              <p style="margin:0 0 22px;font-size:15px;line-height:1.55;">Usa este enlace para validar tu email e ingresar a tu cuenta. El enlace vence en 30 minutos.</p>
              <p style="margin:0 0 24px;"><a href="' . h($absolute) . '" style="display:inline-block;background:#f28c18;color:#111111;text-decoration:none;font-weight:bold;padding:12px 18px;border-radius:4px;">Ingresar a mi cuenta</a></p>
              <p style="margin:0;font-size:13px;line-height:1.55;color:#666666;">Si no solicitaste este acceso, podes ignorar este email.</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';

    $sent = send_email((string) $recipient, 'Tu enlace de ingreso - Inexo Rental', $html, 'text/html; charset=UTF-8', contact_recipient_email());
    $line = '[' . date('c') . '] login-email -> ' . $recipient . ' sent=' . ($sent ? '1' : '0') . PHP_EOL;
    file_put_contents(__DIR__ . '/mail.log', $line, FILE_APPEND);

    return $sent;
}

function send_contact_email(array $message): bool
{
    $recipient = contact_recipient_email();
    $subject = trim((string) ($message['subject'] ?? ''));
    $subject = $subject !== '' ? 'Consulta web: ' . $subject : 'Nueva consulta web - Inexo Rental';
    $replyTo = filter_var((string) ($message['email'] ?? ''), FILTER_VALIDATE_EMAIL)
        ? (string) $message['email']
        : $recipient;

    $body = implode("\n", [
        'Nueva consulta desde inexo Rental',
        '',
        'Nombre: ' . (string) ($message['name'] ?? ''),
        'Email: ' . (string) ($message['email'] ?? ''),
        'Telefono: ' . (string) ($message['phone'] ?? ''),
        'Empresa: ' . (string) ($message['company'] ?? ''),
        'Asunto: ' . (string) ($message['subject'] ?? ''),
        '',
        'Consulta:',
        (string) ($message['message'] ?? ''),
        '',
        'Fecha: ' . date('c'),
    ]);

    $sent = send_email($recipient, $subject, $body, 'text/plain; charset=UTF-8', $replyTo);
    $line = '[' . date('c') . '] contacto -> ' . $recipient . ' sent=' . ($sent ? '1' : '0') . ' from=' . $replyTo . ' subject=' . $subject . PHP_EOL;
    file_put_contents(__DIR__ . '/mail.log', $line, FILE_APPEND);

    return $sent;
}

function base_url(): string
{
    $forwardedProto = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]);
    $scheme = $forwardedProto !== ''
        ? $forwardedProto
        : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8000');

    return $scheme . '://' . $host;
}

function absolute_url(string $url): string
{
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }

    return rtrim(base_url(), '/') . public_path($url);
}

function current_url(): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';

    return rtrim(base_url(), '/') . '/' . ltrim($uri, '/');
}

function products(string $where = '', array $params = [], ?int $limit = null, bool $includeDeleted = false): array
{
    $sql = 'SELECT * FROM products';
    $clauses = [];
    if (!$includeDeleted) {
        $clauses[] = 'deleted_at IS NULL';
    }
    if ($where !== '') {
        $clauses[] = '(' . $where . ')';
    }
    if ($clauses) {
        $sql .= ' WHERE ' . implode(' AND ', $clauses);
    }
    $sql .= ' ORDER BY created_at DESC, id DESC';
    if ($limit !== null) {
        $sql .= ' LIMIT ' . (int) $limit;
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return array_map('decode_product', $stmt->fetchAll());
}

function product_by_slug(string $slug): ?array
{
    $stmt = db()->prepare('SELECT * FROM products WHERE slug = ? AND deleted_at IS NULL');
    $stmt->execute([$slug]);
    $product = $stmt->fetch();

    return $product ? decode_product($product) : null;
}

function product_by_id(int $id, bool $includeDeleted = false): ?array
{
    $sql = 'SELECT * FROM products WHERE id = ?';
    if (!$includeDeleted) {
        $sql .= ' AND deleted_at IS NULL';
    }
    $stmt = db()->prepare($sql);
    $stmt->execute([$id]);
    $product = $stmt->fetch();

    return $product ? decode_product($product) : null;
}

function layout(string $title, string $content, string $active = ''): void
{
    $isAdmin = $active === 'admin';
    $fullTitle = $title . ' - Inexo Rental';
    $metaDescription = DEFAULT_META_DESCRIPTION;
    $metaImage = absolute_url(DEFAULT_META_IMAGE);
    $canonicalUrl = current_url();
    $basePath = public_base_path();
    $html = '<!DOCTYPE html>
<html lang="es" data-base-path="' . h($basePath) . '">
<head>
  <meta charset="utf-8">
  <title>' . h($fullTitle) . '</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="' . h($metaDescription) . '">
  <link rel="canonical" href="' . h($canonicalUrl) . '">
  <meta property="og:type" content="website">
  <meta property="og:locale" content="es_PY">
  <meta property="og:site_name" content="Inexo Rental">
  <meta property="og:title" content="' . h($fullTitle) . '">
  <meta property="og:description" content="' . h($metaDescription) . '">
  <meta property="og:url" content="' . h($canonicalUrl) . '">
  <meta property="og:image" content="' . h($metaImage) . '">
  <meta property="og:image:secure_url" content="' . h($metaImage) . '">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta property="og:image:alt" content="Inexo Rental">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="' . h($fullTitle) . '">
  <meta name="twitter:description" content="' . h($metaDescription) . '">
  <meta name="twitter:image" content="' . h($metaImage) . '">
  <link href="' . FAVICON_IMAGE . '" rel="icon" type="image/png" sizes="32x32">
  <link href="' . FAVICON_IMAGE . '" rel="shortcut icon" type="image/png">
  <link href="' . WEBCLIP_IMAGE . '" rel="apple-touch-icon" sizes="512x512">
  <link href="' . ASSET_BASE . '/css/normalize.css" rel="stylesheet" type="text/css">
  <link href="' . ASSET_BASE . '/css/components.css" rel="stylesheet" type="text/css">
  <link href="' . ASSET_BASE . '/css/inexo-rental---tu-partner-en-cada-obra.css" rel="stylesheet" type="text/css">
  <link href="/app.css" rel="stylesheet" type="text/css">
</head>
<body class="body" data-active="' . h($active) . '">
  ' . site_header(!$isAdmin, $isAdmin) . '
  ' . $content . '
  ' . site_footer() . '
  <script>window.INEXO_BASE_PATH = ' . json_encode($basePath, JSON_UNESCAPED_SLASHES) . ';</script>
  <script src="/app.js"></script>
</body>
</html>';

    echo prefix_public_paths($html);
}

function site_header(bool $showNavBar = true, bool $isAdmin = false): string
{
    $navBar = $showNavBar ? '
  <section class="nav-barra">
    <div class="contendor-busca-por">
      <div class="busca-por">Busca por</div><img src="' . ASSET_BASE . '/images/doble-chevron.png" loading="eager" width="22.5" alt="" class="image-2">
    </div>
    <button class="dropdown-menu-movil app-mobile-toggle" type="button" data-open-menu>
      <div class="enlace-navbar">navegar</div><img src="' . ASSET_BASE . '/images/flecha-simple.png" loading="lazy" width="22" alt="" class="flecha-abajo">
    </button>
    <div class="div-block">
      <a href="/productos" class="enlace-barra-central w-inline-block"><div class="enlace-navbar">Productos</div><img src="' . ASSET_BASE . '/images/flecha-simple.png" loading="eager" width="22" alt="" class="flecha-abajo"></a>
      <a href="/especializacion" class="enlace-barra-central w-inline-block"><div class="enlace-navbar">Especializacion</div><img src="' . ASSET_BASE . '/images/flecha-simple.png" loading="eager" width="22" alt="" class="flecha-abajo"></a>
      <a href="/marca" class="enlace-barra-central w-inline-block"><div class="enlace-navbar">Marca</div><img src="' . ASSET_BASE . '/images/flecha-simple.png" loading="eager" width="22" alt="" class="flecha-abajo"></a>
    </div>
    <div class="contendor-busqueda">
      <form action="/productos" method="get" class="form app-search-form">
        <input class="text-field w-input" maxlength="256" name="q" placeholder="Escribi tu busqueda aqui" type="text">
        <button class="search-go-btn app-icon-button" type="submit"><img src="' . ASSET_BASE . '/images/flecha-simple.png" loading="lazy" width="14" alt=""></button>
      </form>
    </div>
  </section>' : '';

    $rightBlock = $isAdmin ? '
    <div class="app-backend-label">Backend</div>' : '
    <div class="fondo-carrito">
      <a href="/carrito" class="link-block-2 w-inline-block" aria-label="Ver carrito">
        <div class="indicador-carrito" data-cart-count>(0)</div>
        <img src="' . ASSET_BASE . '/images/icono-carrito.png" loading="eager" width="36.5" alt="carrito de compras" class="image-7">
      </a>
    </div>';

    return '
<div class="header">
  <div class="fondo-nav" id="mobile-menu" aria-hidden="true">
    <div class="contendor-menu-movil">
      <a href="/productos" class="enlace-barra-central menu-movil w-inline-block">
        <div class="enlace-navbar">Productos</div><img src="' . ASSET_BASE . '/images/flecha-simple.png" loading="lazy" width="14" alt="">
      </a>
      <a href="/especializacion" class="enlace-barra-central menu-movil w-inline-block">
        <div class="enlace-navbar">Especializacion</div><img src="' . ASSET_BASE . '/images/flecha-simple.png" loading="lazy" width="14" alt="">
      </a>
      <a href="/marca" class="enlace-barra-central menu-movil w-inline-block">
        <div class="enlace-navbar">Marca</div><img src="' . ASSET_BASE . '/images/flecha-simple.png" loading="lazy" width="14" alt="">
      </a>
      <div class="contendor-busqueda-movil">
        <form action="/productos" method="get" class="form app-search-form">
          <input class="text-field menu-movil w-input" maxlength="256" name="q" placeholder="Escribi tu busqueda aqui" type="text">
          <button class="search-go-btn menu-movil app-icon-button" type="submit"><img src="' . ASSET_BASE . '/images/flecha-simple.png" loading="lazy" width="14" alt=""></button>
        </form>
      </div>
    </div>
    <button class="btn-cerrar-menu-movil w-button" type="button" data-close-menu>Cerrar menu</button>
  </div>
  <section class="nav">
    <a href="/" class="contenedor-logo app-logo-link">
      <img src="' . ASSET_BASE . '/images/inexo-pos.png" loading="eager" width="192" alt="inexo logotipo" class="image">
      <img src="' . ASSET_BASE . '/images/inexo-rental.png" loading="eager" alt="rental" class="rental">
      <div class="inexo-slogan">Tu partner, en cada obra.</div>
    </a>
    ' . $rightBlock . '
  </section>
  ' . $navBar . '
</div>';
}

function site_footer(): string
{
    return '
<section class="footer">
  <div class="contenedor-footer">
    <a href="/"><img src="' . ASSET_BASE . '/images/inexo-rental-logo-footer.png" loading="lazy" width="155" alt="inexo Rental logotipo" class="image-5"></a>
    <div class="contenedor-costado-nav-footer">
      <div class="nav-footer">
        <div class="contenedor-ver"><div class="enlace-navbar naranja">ver</div><img src="' . ASSET_BASE . '/images/doble-chevron-footer.png" loading="lazy" width="20" alt="" class="image-3"></div>
        <a href="/productos" class="enlace-barra-central w-inline-block"><div class="enlace-navbar blanco">Productos</div></a>
        <a href="/especializacion" class="enlace-barra-central w-inline-block"><div class="enlace-navbar blanco">Especializaciones</div></a>
        <a href="/marca" class="enlace-barra-central w-inline-block"><div class="enlace-navbar blanco">Marcas</div></a>
        <a href="/contacto" class="btn-contacto w-button">Contacto</a>
      </div>
      <div class="nav-footer-2">
        <div class="enlaces-adicionales">
          <a href="/quienes-somos" class="enlace-barra-central footer primero w-inline-block"><div class="enlace-navbar blanco footer">Quienes somos</div></a>
          <a href="/terminos-y-condiciones" class="enlace-barra-central footer w-inline-block"><div class="enlace-navbar blanco footer">Terminos y condiciones</div></a>
        </div>
        <div class="redes-footer">
          <div class="enlace-navbar blanco footer">Seguinos en redes:</div>
          <div class="redes-agrupador">
            <a href="#" class="enlaces-redes w-inline-block"><img src="' . ASSET_BASE . '/images/icono-linked-in.png" loading="lazy" width="27" alt="LinkedIn"></a>
            <a href="#" class="enlaces-redes w-inline-block"><img src="' . ASSET_BASE . '/images/icono-facebook.png" loading="lazy" width="15" alt="Facebook"></a>
            <a href="#" class="enlaces-redes w-inline-block"><img src="' . ASSET_BASE . '/images/icono-instagram.png" loading="lazy" width="27" alt="Instagram"></a>
            <a href="#" class="enlaces-redes w-inline-block"><img src="' . ASSET_BASE . '/images/icono-x.png" loading="lazy" width="27" alt="X"></a>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="div-block-4">
    <div class="text-block-4">(c) inexo - la marca inexo y su logotipo son marcas registradas de inexo S.A.<br>Todos los derechos reservados. Utilizamos Cookies propios, de terceros y tambien tecnologias de medicion.</div>
    <div class="trabajadores-de-obra"><img src="' . ASSET_BASE . '/images/trabajadores-de-obra.avif" loading="lazy" width="426" alt="grupo de trabajadores de la construccion" class="image-4"></div>
    <a href="https://vinculo.com.py" target="_blank" class="link-block w-inline-block"><img src="' . ASSET_BASE . '/images/vinculo-com-py.png" loading="lazy" width="60" alt="Diseno y desarrollo vinculo.com.py"></a>
  </div>
</section>';
}

function product_card(array $product): string
{
    $image = $product['images'][0] ?? ASSET_BASE . '/images/imagen-producto-generico.avif';

    return '
<div class="w-layout-cell">
  <article class="card-producto">
    <a href="/producto/' . h($product['slug']) . '" class="app-card-link">
      <div class="titulo-producto">' . h($product['name']) . '</div>
      <div class="contenedor-imagen-producto"><img src="' . h($image) . '" loading="lazy" alt="' . h($product['name']) . '" class="imagen-producto"></div>
      <div class="contenedor-en-stock"><div class="texto-en-stock">' . h($product['status']) . '</div></div>
      <div class="contendor-precio-plazo">
        <div class="titulo-producto">Desde ' . money($product['rental_monthly']) . '</div>
        <div class="tiempo-alquiler-por-precio-indicado">Por 28 dias</div>
      </div>
    </a>
    <a href="/producto/' . h($product['slug']) . '" class="button alquilar w-button">Alquilar</a>
  </article>
</div>';
}

function listing_count_label(int $count): string
{
    return $count === 1 ? '1 item' : $count . ' items';
}

function listing_top_bar(string $title, ?int $itemCount = null, string $cta = '', string $ctaHref = '/productos'): string
{
    $rightSide = '';
    if ($itemCount !== null) {
        $countLabel = listing_count_label($itemCount);
        $rightSide = '<div class="app-listing-count" aria-label="' . h($countLabel . ' mostrados') . '">' . h($countLabel) . '</div>';
    } elseif ($cta !== '') {
        $rightSide = '<a href="' . h($ctaHref) . '" class="button w-button">' . h($cta) . '</a>';
    }

    return '
  <div class="barra-top-listados">
    <h2 class="titulo-seccion-producto">' . h($title) . '</h2>
    ' . $rightSide . '
  </div>';
}

function product_section(string $title, array $products, string $className = 'productos-destacados', string $cta = '', ?int $itemCount = null, string $ctaHref = '/productos'): string
{
    $cards = '';
    foreach ($products as $product) {
        $cards .= product_card($product);
    }
    if ($cards === '') {
        $cards = '<p class="app-empty">No hay productos cargados.</p>';
    }

    return '
<section class="' . h($className) . '">
' . listing_top_bar($title, $itemCount, $cta, $ctaHref) . '
  <div class="w-layout-layout quick-stack-2 wf-layout-layout app-product-grid" data-fill-product-row data-placeholder-image="' . ASSET_BASE . '/images/inexo-x-gris.png">
    ' . $cards . '
  </div>
</section>';
}

function home_page(): void
{
    $featured = products('is_featured = 1', [], 5);
    $newProducts = products('is_new = 1', [], 5);
    $fallbackProducts = products('', [], 5);

    if ($featured === []) {
        $featured = $fallbackProducts;
    }
    if ($newProducts === []) {
        $newProducts = $fallbackProducts;
    }

    $content = '
<section class="banner-central">
  <div class="w-layout-layout quick-stack wf-layout-layout app-hero-grid">
    <div class="w-layout-cell cell"><div class="contendor-lado-izquierda"><h1><strong class="bold-text">Hola. Estamos listos para ayudarte en tu proximo proyecto.</strong></h1></div></div>
    <div class="w-layout-cell"><img src="' . ASSET_BASE . '/images/imagen-banner-central.avif" loading="lazy" alt="Operador de construccion" class="contenedor-derecho"></div>
  </div>
</section>
' . product_section('Productos destacados', $featured, 'productos-novedades', 'todos los destacados', null, '/productos-destacados') . '
' . product_section('Productos novedades', $newProducts, 'productos-destacados', 'todas las novedades', null, '/productos-novedades');

    layout('Inicio', $content, 'inicio');
}

function placeholder_page(string $title): void
{
    $content = '
<main class="app-placeholder-page">
  <section class="app-placeholder-grid">
    <div class="app-placeholder-title">
      <h1>' . h($title) . '</h1>
    </div>
    <div class="app-placeholder-copy">
      <p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Integer vitae justo nec sem facilisis aliquet. Suspendisse potenti. Donec non risus sed urna dignissim gravida.</p>
      <p>Praesent commodo, mauris at cursus vulputate, lorem ipsum feugiat nibh, vitae volutpat ipsum lectus sed ante. Nulla facilisi. Sed at magna non ipsum posuere viverra.</p>
    </div>
  </section>
</main>';

    layout($title, $content);
}

function listing_page(string $kind): void
{
    $query = trim((string) ($_GET['q'] ?? ''));
    if ($query !== '') {
        $like = '%' . $query . '%';
        $items = products('(name LIKE ? OR code LIKE ? OR brand LIKE ? OR specialization LIKE ?)', [$like, $like, $like, $like]);
        $title = 'Resultados para "' . $query . '"';
    } else {
        $items = products();
        $title = match ($kind) {
            'especializacion' => 'Especializacion',
            'marca' => 'Marca',
            default => 'Productos',
        };
    }

    layout($title, product_section($title, $items, 'productos-destacados', '', count($items)), $kind);
}

function filtered_product_listing_page(string $kind): void
{
    $config = match ($kind) {
        'destacados' => [
            'where' => 'is_featured = 1',
            'title' => 'Productos destacados',
            'active' => 'productos-destacados',
        ],
        'novedades' => [
            'where' => 'is_new = 1',
            'title' => 'Productos novedades',
            'active' => 'productos-novedades',
        ],
    };

    $items = products($config['where']);

    layout(
        $config['title'],
        product_section($config['title'], $items, 'productos-destacados', '', count($items)),
        $config['active']
    );
}

function brand_listing_page(): void
{
    $cards = '';
    $brands = lookup_rows('brands');
    foreach ($brands as $brand) {
        $logo = trim((string) ($brand['logo'] ?? ''));
        $description = trim((string) ($brand['description'] ?? ''));
        $logoHtml = $logo !== ''
            ? '<img src="' . h($logo) . '" loading="lazy" alt="' . h($brand['name']) . '" class="app-brand-logo">'
            : '<div class="app-brand-logo-placeholder">' . h(substr((string) $brand['name'], 0, 1)) . '</div>';
        $descriptionHtml = $description !== ''
            ? '<p>' . nl2br(h($description)) . '</p>'
            : '<p>Conoce los equipos disponibles de esta marca.</p>';

        $cards .= '
<article class="app-brand-card">
  <div class="app-brand-logo-box">' . $logoHtml . '</div>
  <div class="app-brand-copy">
    <h2>' . h($brand['name']) . '</h2>
    ' . $descriptionHtml . '
    <a href="/productos?q=' . rawurlencode((string) $brand['name']) . '" class="button alquilar w-button">Ver productos</a>
  </div>
</article>';
    }

    if ($cards === '') {
        $cards = '<p class="app-empty">Todavia no hay marcas cargadas.</p>';
    }

    layout('Marca', '
<main class="app-brand-page">
  ' . listing_top_bar('Marca', count($brands)) . '
  <section class="app-brand-grid">' . $cards . '</section>
</main>', 'marca');
}

function specialization_listing_page(): void
{
    $cards = '';
    $specializations = lookup_rows('specializations');
    foreach ($specializations as $specialization) {
        $name = (string) $specialization['name'];
        $title = specialization_display_name($specialization);
        $image = specialization_image_url($specialization);
        $url = '/productos?q=' . rawurlencode($name);

        $cards .= '
<div class="w-layout-cell">
  <article class="card-producto app-specialization-name-card">
    <a href="' . h($url) . '" class="app-card-link app-specialization-name-link">
      <div class="titulo-producto">' . h($title) . '</div>
      <div class="app-specialization-image-box">
        <img src="' . h($image) . '" loading="lazy" alt="' . h($title) . '" class="app-specialization-image">
      </div>
    </a>
    <a href="' . h($url) . '" class="button alquilar w-button">Ver productos</a>
  </article>
</div>';
    }

    if ($cards === '') {
        $cards = '<p class="app-empty">Todavia no hay especializaciones cargadas.</p>';
    }

    layout('Especializaciones', '
<section class="productos-destacados app-specialization-page">
  ' . listing_top_bar('Especializaciones', count($specializations)) . '
  <div class="w-layout-layout quick-stack-2 wf-layout-layout app-product-grid">
    ' . $cards . '
  </div>
</section>', 'especializacion');
}

function detail_page(string $slug): void
{
    global $dominicanCities;

    $product = product_by_slug($slug);
    if (!$product) {
        not_found();
        return;
    }

    $images = $product['images'] ?: [ASSET_BASE . '/images/imagen-producto-generico.avif'];
    $thumbs = '';
    foreach (array_slice($images, 0, 4) as $index => $image) {
        $thumbs .= '<button type="button" class="app-thumb' . ($index === 0 ? ' is-active' : '') . '" data-image="' . h($image) . '"><img src="' . h($image) . '" alt="' . h($product['name']) . ' miniatura ' . ($index + 1) . '"></button>';
    }

    $description = '';
    foreach (preg_split('/\R+/', $product['description']) ?: [] as $paragraph) {
        if (trim($paragraph) !== '') {
            $description .= '<p>' . h($paragraph) . '</p>';
        }
    }

    $specs = '';
    foreach ($product['specs'] as $spec) {
        $specs .= '<tr><th>' . h($spec[0] ?? '') . '</th><td>' . h($spec[1] ?? '') . '</td></tr>';
    }

    $cities = '';
    foreach ($dominicanCities as $city) {
        $cities .= '<option value="' . h($city) . '">' . h($city) . '</option>';
    }

    $start = date('Y-m-d');
    $end = date('Y-m-d', strtotime('+10 days'));
    $content = '
<main class="app-detail">
  <section class="app-detail-grid">
    <div class="app-detail-media">
      <div class="app-stock-badge">' . h($product['status']) . '</div>
      <img src="' . h($images[0]) . '" alt="' . h($product['name']) . '" class="app-main-product-image" data-main-image>
      <div class="app-thumbs">' . $thumbs . '</div>
      <article class="app-product-copy">
        <h1>' . h($product['name']) . '</h1>
        ' . $description . '
      </article>
    </div>
    <aside class="app-product-panel">
      <section class="app-card app-title-card">
        <h2>' . h($product['name']) . '</h2>
        <div class="app-code">' . h($product['code']) . '</div>
      </section>
      <section class="app-price-card">
        <div><span>Precio de venta (usado)</span><strong>' . money($product['price_sale_used']) . '</strong></div>
        <button type="button" data-add-cart data-product-id="' . (int) $product['id'] . '" data-product-name="' . h($product['name']) . '" data-product-url="/producto/' . h($product['slug']) . '" data-price-label="Compra usado" data-unit-price="' . h($product['price_sale_used']) . '">Comprar</button>
      </section>
      <section class="app-price-card">
        <div><span>Precio de venta (nuevo)</span><strong>' . money($product['price_sale_new']) . '</strong></div>
        <button type="button" data-add-cart data-product-id="' . (int) $product['id'] . '" data-product-name="' . h($product['name']) . '" data-product-url="/producto/' . h($product['slug']) . '" data-price-label="Compra nuevo" data-unit-price="' . h($product['price_sale_new']) . '">Comprar</button>
      </section>
      <form class="app-rental-card" data-reservation-form>
        <input type="hidden" name="product_id" value="' . (int) $product['id'] . '">
        <div class="app-panel-heading">Configurar alquiler</div>
        <div class="app-rental-body">
          <label class="app-field-label">Cuanto tiempo necesita usar el equipo</label>
          <div class="app-plan-grid">
            <label class="app-plan"><input type="radio" name="rental_plan" value="diario" data-unit-price="' . h($product['rental_daily']) . '" checked><span>Diario</span><strong>' . money($product['rental_daily']) . '</strong></label>
            <label class="app-plan"><input type="radio" name="rental_plan" value="semanal" data-unit-price="' . h($product['rental_weekly']) . '"><span>Semanal</span><strong>' . money($product['rental_weekly']) . '</strong></label>
            <label class="app-plan"><input type="radio" name="rental_plan" value="mensual" data-unit-price="' . h($product['rental_monthly']) . '"><span>Mensual</span><strong>' . money($product['rental_monthly']) . '</strong></label>
          </div>
          <label class="app-field-label" for="start_date">Periodo de alquiler</label>
          <div class="app-date-row">
            <input id="start_date" name="start_date" type="date" value="' . h($start) . '" required>
            <input name="end_date" type="date" value="' . h($end) . '" required>
          </div>
          <label class="app-field-label" for="city">Ciudad de entrega</label>
          <select id="city" name="city" required>
            <option value="">Por favor elija una ciudad</option>
            ' . $cities . '
          </select>
          <button class="app-reserve-button" type="submit" data-product-id="' . (int) $product['id'] . '" data-product-name="' . h($product['name']) . '" data-product-url="/producto/' . h($product['slug']) . '">Reserve equipo ahora</button>
          <p class="app-disclaimer"><strong>Importante:</strong> Esta solicitud no garantiza la disponibilidad inmediata del equipo. Si otra empresa lo reservo minutos antes, nos pondremos en contacto con usted lo antes posible para confirmar una nueva fecha o una alternativa disponible.</p>
          <div class="app-form-message" data-form-message></div>
        </div>
      </form>
      <section class="app-spec-card">
        <div class="app-panel-heading">Especificaciones tecnicas</div>
        <table>' . $specs . '</table>
      </section>
    </aside>
  </section>
</main>';

    layout($product['name'], $content, 'producto');
}

function cart_page(): void
{
    $accountLink = current_user() ? '<a href="/cuenta" class="button w-button">Mi cuenta</a>' : '<a href="/ingresar" class="button w-button">Ingresar</a>';

    layout('Carrito', '
<main class="app-admin-shell app-cart-shell">
  <div class="app-admin-header">
    <div>
      <p class="app-kicker">Carrito</p>
      <h1>Equipos seleccionados</h1>
    </div>
    <div class="app-header-actions"><a href="/productos" class="button w-button">seguir viendo</a>' . $accountLink . '<a href="/checkout" class="btn-contacto w-button">checkout</a></div>
  </div>
  <div class="app-cart-list" data-cart-page></div>
</main>', 'carrito');
}

function checkout_page(): void
{
    global $dominicanCities;

    $cities = '';
    foreach ($dominicanCities as $city) {
        $cities .= '<option value="' . h($city) . '">' . h($city) . '</option>';
    }

    layout('Checkout', '
<main class="app-admin-shell app-checkout-shell">
  <div class="app-admin-header">
    <div>
      <p class="app-kicker">Checkout</p>
      <h1>Confirmar solicitud</h1>
    </div>
  </div>
  <div class="app-checkout-grid">
    <section class="app-admin-card app-checkout-summary">
      <h2>Equipos seleccionados</h2>
      <div class="app-cart-list" data-checkout-cart></div>
    </section>
    <form class="app-admin-form app-checkout-form" data-checkout-form>
      <label>Nombre y apellido<input name="name" required></label>
      <label>Email<input name="email" type="email" required></label>
      <label>Contrasena<input name="password" type="password" autocomplete="new-password" minlength="8" required></label>
      <label>Repetir contrasena<input name="password_confirm" type="password" autocomplete="new-password" minlength="8" required></label>
      <label>Telefono<input name="phone" required></label>
      <label>Empresa<input name="company"></label>
      <label class="app-form-wide">Direccion de entrega<input name="address" required></label>
      <label>Ciudad<select name="city" required><option value="">Seleccionar ciudad</option>' . $cities . '</select></label>
      <div class="app-form-actions">
        <button class="btn-contacto w-button" type="submit">Crear cuenta y enviar pedido</button>
        <div class="app-form-message" data-checkout-message></div>
      </div>
    </form>
  </div>
</main>', 'checkout');
}

function contact_page(array $values = [], array $errors = [], string $status = ''): void
{
    $values = array_merge([
        'name' => '',
        'email' => '',
        'phone' => '',
        'company' => '',
        'subject' => '',
        'message' => '',
    ], $values);
    $success = $status === 'ok' || (string) ($_GET['enviado'] ?? '') === '1';
    $feedback = '';

    if ($success) {
        $feedback = '<div class="app-contact-feedback is-success">Recibimos tu consulta. Te vamos a responder lo antes posible.</div>';
    } elseif ($errors !== []) {
        $feedback = '<div class="app-contact-feedback is-error">' . h(implode(' ', $errors)) . '</div>';
    }

    layout('Contacto', '
<main class="app-contact-page">
  <section class="app-contact-hero">
    <div>
      <p class="app-kicker">Contacto</p>
      <h1>Contanos que equipo necesitas o que consulta tenes.</h1>
    </div>
    <p>Completá el formulario y el equipo de inexo Rental recibirá tu consulta por email. También quedará registrada en el backend para seguimiento.</p>
  </section>
  <form action="/contacto" method="post" class="app-admin-form app-contact-form">
    ' . $feedback . '
    <label>Nombre y apellido<input name="name" value="' . h($values['name']) . '" required></label>
    <label>Email<input name="email" type="email" value="' . h($values['email']) . '" required></label>
    <label>Telefono<input name="phone" value="' . h($values['phone']) . '"></label>
    <label>Empresa<input name="company" value="' . h($values['company']) . '"></label>
    <label class="app-form-wide">Asunto<input name="subject" value="' . h($values['subject']) . '" placeholder="Alquiler, compra, soporte o consulta general"></label>
    <label class="app-form-wide">Consulta<textarea name="message" rows="7" required>' . h($values['message']) . '</textarea></label>
    <div class="app-form-actions"><button class="btn-contacto w-button" type="submit">Enviar consulta</button></div>
  </form>
</main>', 'contacto');
}

function login_page(string $message = '', string $redirectTo = '', string $email = ''): void
{
    $safeRedirect = is_safe_redirect_path($redirectTo) ? $redirectTo : '';
    $redirectField = $safeRedirect !== ''
        ? '<input name="redirect" type="hidden" value="' . h($safeRedirect) . '">'
        : '';
    layout('Ingresar', '
<main class="app-admin-shell app-login-shell">
  <div class="app-admin-header"><div><p class="app-kicker">Cuenta</p><h1>Ingresar a mi cuenta</h1></div></div>
  <form action="/ingresar" method="post" class="app-admin-form app-login-form">
    ' . $redirectField . '
    <label class="app-form-wide">Email<input name="email" type="email" autocomplete="username" value="' . h($email) . '" required></label>
    <label class="app-form-wide">Contrasena<input name="password" type="password" autocomplete="current-password" required></label>
    <div class="app-form-actions"><button class="btn-contacto w-button" type="submit">Ingresar</button></div>
  </form>
  ' . ($message !== '' ? '<div class="app-admin-card app-auth-message">' . $message . '</div>' : '') . '
</main>', 'cuenta');
}

function admin_password_login_page(string $message = '', string $redirectTo = '/admin'): void
{
    $safeRedirect = is_safe_redirect_path($redirectTo) && is_admin_path($redirectTo) && $redirectTo !== '/admin/login'
        ? $redirectTo
        : '/admin';
    layout('Ingresar admin', '
<main class="app-admin-shell app-login-shell">
  <div class="app-admin-header"><div><p class="app-kicker">Admin</p><h1>Ingresar al administrador</h1></div></div>
  <form action="/admin/login" method="post" class="app-admin-form app-login-form">
    <input name="redirect" type="hidden" value="' . h($safeRedirect) . '">
    <label class="app-form-wide">Usuario<input name="username" type="text" autocomplete="username" required></label>
    <label class="app-form-wide">Contrasena<input name="password" type="password" autocomplete="current-password" required></label>
    <div class="app-form-actions"><button class="btn-contacto w-button" type="submit">Ingresar</button></div>
  </form>
  ' . ($message !== '' ? '<div class="app-admin-card app-auth-message">' . $message . '</div>' : '') . '
</main>', 'admin');
}

function account_page(): void
{
    $user = current_user();
    if (!$user) {
        redirect_to('/ingresar');
    }

    $stmt = db()->prepare('SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC');
    $stmt->execute([(int) $user['id']]);
    $orders = $stmt->fetchAll();
    $rows = '';
    foreach ($orders as $order) {
        $items = db()->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC');
        $items->execute([(int) $order['id']]);
        $summary = [];
        foreach ($items->fetchAll() as $item) {
            $summary[] = h($item['product_name']) . ' x ' . (int) $item['quantity'];
        }
        $rows .= '<tr><td>#' . (int) $order['id'] . '</td><td>' . h($order['status']) . '</td><td>' . h($order['created_at']) . '</td><td>' . implode('<br>', $summary) . '</td></tr>';
    }

    layout('Mi cuenta', '
<main class="app-admin-shell">
  <div class="app-admin-header">
    <div><p class="app-kicker">Cuenta</p><h1>' . h($user['name'] ?: $user['email']) . '</h1></div>
    <a href="/salir" class="button w-button">salir</a>
  </div>
  <div class="app-admin-card">
    <table class="app-admin-table">
      <thead><tr><th>Pedido</th><th>Estado</th><th>Fecha</th><th>Items</th></tr></thead>
      <tbody>' . ($rows ?: '<tr><td colspan="4">Todavia no tenes pedidos.</td></tr>') . '</tbody>
    </table>
  </div>
</main>', 'cuenta');
}

function admin_count(string $table, string $where = ''): int
{
    $allowed = ['products', 'brands', 'specializations', 'orders', 'contact_messages', 'users', 'reservations'];
    if (!in_array($table, $allowed, true)) {
        return 0;
    }

    $sql = 'SELECT COUNT(*) FROM ' . $table;
    $clauses = [];
    if ($table === 'products') {
        $clauses[] = 'deleted_at IS NULL';
    }
    if ($where !== '') {
        $clauses[] = '(' . $where . ')';
    }
    if ($clauses) {
        $sql .= ' WHERE ' . implode(' AND ', $clauses);
    }

    return (int) db()->query($sql)->fetchColumn();
}

function trashed_product_count(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM products WHERE deleted_at IS NOT NULL')->fetchColumn();
}

function admin_metric_card(string $title, int|string $value, string $meta, string $href): string
{
    return '
    <a href="' . h($href) . '" class="app-admin-metric">
      <span>' . h($title) . '</span>
      <strong>' . h($value) . '</strong>
      <small>' . h($meta) . '</small>
    </a>';
}

function admin_bar_chart(array $rows, string $emptyMessage): string
{
    if (!$rows) {
        return '<div class="app-admin-empty-chart">' . h($emptyMessage) . '</div>';
    }

    $max = max(array_column($rows, 'total')) ?: 1;
    $html = '<div class="app-admin-chart-list">';
    foreach ($rows as $row) {
        $total = (int) $row['total'];
        $width = $total > 0 ? max(4, (int) round(($total / $max) * 100)) : 0;
        $detail = trim((string) ($row['detail'] ?? ''));
        $html .= '
        <div class="app-admin-chart-row">
          <div class="app-admin-chart-label"><span>' . h($row['label']) . '</span><strong>' . h((string) $total) . '</strong></div>
          <div class="app-admin-chart-track"><div style="width: ' . $width . '%"></div></div>
          ' . ($detail !== '' ? '<small>' . h($detail) . '</small>' : '') . '
        </div>';
    }

    return $html . '</div>';
}

function admin_dashboard_page(): void
{
    $productCount = admin_count('products');
    $brandCount = admin_count('brands');
    $specializationCount = admin_count('specializations');
    $orderCount = admin_count('orders');
    $contactCount = admin_count('contact_messages');
    $userCount = admin_count('users');
    $reservationCount = admin_count('reservations');
    $featuredCount = admin_count('products', 'is_featured = 1');
    $newCount = admin_count('products', 'is_new = 1');
    $pendingOrders = admin_count('orders', "status = 'pendiente_validacion'");
    $unsentContact = admin_count('contact_messages', 'email_sent = 0');
    $verifiedUsers = admin_count('users', 'is_verified = 1');
    $recentReservations = admin_count('reservations', "created_at >= datetime('now', '-30 days')");

    $metrics = implode('', [
        admin_metric_card('Productos', $productCount, $featuredCount . ' destacados - ' . $newCount . ' novedades', '/admin/productos'),
        admin_metric_card('Especializaciones', $specializationCount, 'Secciones de rubros disponibles', '/admin/especializaciones'),
        admin_metric_card('Marcas', $brandCount, 'Catalogo de fabricantes', '/admin/marcas'),
        admin_metric_card('Pedidos', $orderCount, $pendingOrders . ' pendientes de validacion', '/admin/pedidos'),
        admin_metric_card('Contacto', $contactCount, $unsentContact . ' consultas sin email enviado', '/admin/contacto'),
        admin_metric_card('Usuarios', $userCount, $verifiedUsers . ' verificados', '/admin/usuarios'),
        admin_metric_card('Reservas', $reservationCount, $recentReservations . ' en los ultimos 30 dias', '/admin/pedidos'),
    ]);

    $specializationRows = [];
    foreach (db()->query(
        "SELECT COALESCE(NULLIF(specialization, ''), 'Sin especializacion') AS label, COUNT(*) AS total
        FROM products
        WHERE deleted_at IS NULL
        GROUP BY label
        ORDER BY total DESC, label ASC
        LIMIT 7"
    )->fetchAll() as $row) {
        $specializationRows[] = ['label' => $row['label'], 'total' => (int) $row['total']];
    }

    $orderRows = [];
    foreach (db()->query(
        "SELECT COALESCE(NULLIF(status, ''), 'Sin estado') AS label, COUNT(*) AS total
        FROM orders
        GROUP BY label
        ORDER BY total DESC, label ASC"
    )->fetchAll() as $row) {
        $orderRows[] = ['label' => $row['label'], 'total' => (int) $row['total']];
    }

    $monthStats = [];
    for ($index = 5; $index >= 0; $index--) {
        $key = date('Y-m', strtotime('-' . $index . ' months'));
        $monthStats[$key] = ['orders' => 0, 'contact' => 0, 'reservations' => 0];
    }
    $monthQueries = [
        'orders' => 'orders',
        'contact' => 'contact_messages',
        'reservations' => 'reservations',
    ];
    foreach ($monthQueries as $key => $table) {
        foreach (db()->query(
            "SELECT strftime('%Y-%m', created_at) AS month, COUNT(*) AS total
            FROM {$table}
            WHERE created_at >= date('now', '-6 months')
            GROUP BY month"
        )->fetchAll() as $row) {
            if (isset($monthStats[$row['month']])) {
                $monthStats[$row['month']][$key] = (int) $row['total'];
            }
        }
    }
    $activityRows = [];
    foreach ($monthStats as $month => $counts) {
        $total = array_sum($counts);
        $activityRows[] = [
            'label' => date('M Y', strtotime($month . '-01')),
            'total' => $total,
            'detail' => 'Pedidos ' . $counts['orders'] . ' - Contacto ' . $counts['contact'] . ' - Reservas ' . $counts['reservations'],
        ];
    }

    $recentItems = [];
    foreach (db()->query('SELECT id, status, created_at FROM orders ORDER BY created_at DESC LIMIT 4')->fetchAll() as $row) {
        $recentItems[] = ['date' => $row['created_at'], 'type' => 'Pedido', 'title' => '#' . (int) $row['id'], 'meta' => $row['status']];
    }
    foreach (db()->query('SELECT id, name, subject, created_at FROM contact_messages ORDER BY created_at DESC LIMIT 4')->fetchAll() as $row) {
        $subject = trim((string) $row['subject']);
        $recentItems[] = ['date' => $row['created_at'], 'type' => 'Contacto', 'title' => $row['name'], 'meta' => $subject !== '' ? $subject : 'Sin asunto'];
    }
    foreach (db()->query('SELECT id, name, created_at FROM products WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 4')->fetchAll() as $row) {
        $recentItems[] = ['date' => $row['created_at'], 'type' => 'Producto', 'title' => $row['name'], 'meta' => '#' . (int) $row['id']];
    }
    usort($recentItems, fn(array $a, array $b): int => strcmp((string) $b['date'], (string) $a['date']));
    $recentItems = array_slice($recentItems, 0, 8);
    $recentHtml = '';
    foreach ($recentItems as $item) {
        $recentHtml .= '
          <li>
            <span>' . h($item['type']) . '</span>
            <strong>' . h($item['title']) . '</strong>
            <small>' . h($item['meta']) . ' - ' . h($item['date']) . '</small>
          </li>';
    }

    layout('Admin', '
<main class="app-admin-shell app-admin-dashboard">
  <div class="app-admin-header">
    <div>
      <h1>Inicio de admin</h1>
    </div>
    <a href="/admin/productos/nuevo" class="btn-contacto w-button">Nuevo producto</a>
  </div>
  ' . admin_nav('inicio') . '
  <section class="app-admin-metrics">' . $metrics . '</section>
  <section class="app-admin-dashboard-grid">
    <div class="app-admin-panel">
      <div class="app-admin-panel-title"><h2>Productos por especializacion</h2><a href="/admin/productos">Ver productos</a></div>
      ' . admin_bar_chart($specializationRows, 'Todavia no hay productos para graficar.') . '
    </div>
    <div class="app-admin-panel">
      <div class="app-admin-panel-title"><h2>Pedidos por estado</h2><a href="/admin/pedidos">Ver pedidos</a></div>
      ' . admin_bar_chart($orderRows, 'Todavia no hay pedidos.') . '
    </div>
    <div class="app-admin-panel app-admin-panel-wide">
      <div class="app-admin-panel-title"><h2>Actividad de los ultimos meses</h2></div>
      ' . admin_bar_chart($activityRows, 'Todavia no hay actividad.') . '
    </div>
    <div class="app-admin-panel">
      <div class="app-admin-panel-title"><h2>Actividad reciente</h2></div>
      <ul class="app-admin-activity">' . ($recentHtml ?: '<li><strong>Sin actividad reciente</strong><small>Cuando haya pedidos, consultas o productos nuevos van a aparecer aca.</small></li>') . '</ul>
    </div>
  </section>
</main>', 'admin');
}

function admin_products_page(): void
{
    $rows = '';
    foreach (products() as $product) {
        $thumbnail = admin_product_thumbnail($product);
        $rows .= '
<tr>
  <td>
    <div class="app-admin-product-cell">
      <img src="' . h($thumbnail) . '" loading="lazy" alt="" class="app-admin-product-thumb">
      <div><strong>' . h($product['name']) . '</strong><span>' . h($product['code']) . '</span></div>
    </div>
  </td>
  <td>' . h($product['brand']) . '</td>
  <td>' . h($product['specialization']) . '</td>
  <td>' . money($product['rental_monthly']) . '</td>
  <td>' . ((int) $product['is_featured'] === 1 ? 'Si' : 'No') . '</td>
  <td>' . ((int) $product['is_new'] === 1 ? 'Si' : 'No') . '</td>
  <td class="app-admin-actions">
    <a href="/admin/productos/' . (int) $product['id'] . '/editar">Editar</a>
    <form action="/admin/productos/' . (int) $product['id'] . '/eliminar" method="post" onsubmit="return confirm(\'¿Mover este producto a la papelera?\');"><button type="submit">Eliminar</button></form>
  </td>
</tr>';
    }
    $trashCount = trashed_product_count();

    layout('Productos admin', '
<main class="app-admin-shell">
  <div class="app-admin-header">
    <div>
      <h1>Administrar productos</h1>
    </div>
    <div class="app-header-actions">
      <a href="/admin/productos/papelera" class="button w-button">Papelera (' . (int) $trashCount . ')</a>
      <a href="/admin/productos/nuevo" class="btn-contacto w-button">Nuevo producto</a>
    </div>
  </div>
  ' . admin_nav('productos') . '
  <div class="app-admin-card">
    <table class="app-admin-table">
      <thead><tr><th>Producto</th><th>Marca</th><th>Especializacion</th><th>Alquiler mensual</th><th>Destacado</th><th>Novedad</th><th></th></tr></thead>
      <tbody>' . ($rows ?: '<tr><td colspan="7">Todavia no hay productos activos.</td></tr>') . '</tbody>
    </table>
  </div>
</main>', 'admin');
}

function admin_product_trash_page(): void
{
    $rows = '';
    foreach (products('deleted_at IS NOT NULL', [], null, true) as $product) {
        $thumbnail = admin_product_thumbnail($product);
        $rows .= '
<tr>
  <td>
    <div class="app-admin-product-cell">
      <img src="' . h($thumbnail) . '" loading="lazy" alt="" class="app-admin-product-thumb">
      <div><strong>' . h($product['name']) . '</strong><span>' . h($product['code']) . '</span></div>
    </div>
  </td>
  <td>' . h($product['brand']) . '</td>
  <td>' . h($product['specialization']) . '</td>
  <td>' . h((string) ($product['deleted_at'] ?? '')) . '</td>
  <td class="app-admin-actions">
    <form action="/admin/productos/' . (int) $product['id'] . '/restaurar" method="post"><button type="submit">Restaurar</button></form>
    <form action="/admin/productos/' . (int) $product['id'] . '/eliminar-definitivo" method="post" onsubmit="return confirm(\'¿Eliminar definitivamente este producto? Esta accion no se puede deshacer.\');"><button type="submit" class="app-danger-button">Eliminar definitivo</button></form>
  </td>
</tr>';
    }

    layout('Papelera de productos', '
<main class="app-admin-shell">
  <div class="app-admin-header">
    <div>
      <p class="app-kicker">Papelera</p>
      <h1>Productos eliminados</h1>
    </div>
    <a href="/admin/productos" class="button w-button">Volver a productos</a>
  </div>
  ' . admin_nav('productos') . '
  <div class="app-admin-card">
    <table class="app-admin-table">
      <thead><tr><th>Producto</th><th>Marca</th><th>Especializacion</th><th>Eliminado</th><th></th></tr></thead>
      <tbody>' . ($rows ?: '<tr><td colspan="5">La papelera esta vacia.</td></tr>') . '</tbody>
    </table>
  </div>
</main>', 'admin');
}

function admin_lookup_page(string $type): void
{
    $isBrand = $type === 'marcas';
    $table = $isBrand ? 'brands' : 'specializations';
    $title = $isBrand ? 'Marcas' : 'Especializaciones';
    $active = $isBrand ? 'marcas' : 'especializaciones';
    $rows = '';

    if ($isBrand) {
        foreach (lookup_rows('brands') as $row) {
            $logo = trim((string) ($row['logo'] ?? ''));
            $logoPreview = $logo !== ''
                ? '<img src="' . h($logo) . '" alt="' . h($row['name']) . '" class="app-admin-brand-logo">'
                : '<span class="app-admin-logo-empty">Sin logo</span>';
            $description = trim((string) ($row['description'] ?? ''));
            $rows .= '
<tr>
  <td>' . $logoPreview . '</td>
  <td><strong>' . h($row['name']) . '</strong><span>' . h($row['slug']) . '</span></td>
  <td>' . ($description !== '' ? h($description) : '<span>Sin descripcion</span>') . '</td>
  <td class="app-admin-actions">
    <a href="/admin/marcas/' . (int) $row['id'] . '/editar">Editar</a>
    <form action="/admin/marcas/' . (int) $row['id'] . '/eliminar" method="post"><button type="submit">Eliminar</button></form>
  </td>
</tr>';
        }

        layout($title, '
<main class="app-admin-shell">
  <div class="app-admin-header">
    <div>
      <h1>Administrar marcas</h1>
    </div>
  </div>
  ' . admin_nav($active) . '
  <form action="/admin/marcas/crear" method="post" class="app-admin-form app-brand-admin-form" enctype="multipart/form-data">
    <label>Nombre<input name="name" required></label>
    <label>Logo<input name="brand_logo" type="file" accept="image/*"></label>
    <label class="app-form-wide">URL del logo actual o externo<input name="logo" placeholder="/uploads/brands/logo.png"></label>
    <label class="app-form-wide">Descripcion<textarea name="description" rows="4"></textarea></label>
    <div class="app-form-actions"><button class="btn-contacto w-button" type="submit">Agregar marca</button></div>
  </form>
  <div class="app-admin-card">
    <table class="app-admin-table">
      <thead><tr><th>Logo</th><th>Nombre</th><th>Descripcion</th><th></th></tr></thead>
      <tbody>' . ($rows ?: '<tr><td colspan="4">Todavia no hay marcas.</td></tr>') . '</tbody>
    </table>
  </div>
</main>', 'admin');
        return;
    }

    foreach (lookup_rows($table) as $row) {
        $icon = trim((string) ($row['icon'] ?? ''));
        $iconPreview = $icon !== ''
            ? '<img src="' . h($icon) . '" alt="' . h($row['name']) . '" class="app-admin-specialization-icon">'
            : '<span class="app-admin-logo-empty">Sin icono</span>';
        $rows .= '
<tr>
  <td>' . $iconPreview . '</td>
  <td><strong>' . h($row['name']) . '</strong><span>' . h($row['slug']) . '</span></td>
  <td class="app-admin-actions">
    <a href="/admin/' . h($type) . '/' . (int) $row['id'] . '/editar">Editar</a>
    <form action="/admin/' . h($type) . '/' . (int) $row['id'] . '/eliminar" method="post"><button type="submit">Eliminar</button></form>
  </td>
</tr>';
    }

    layout($title, '
<main class="app-admin-shell">
  <div class="app-admin-header">
    <div>
      <h1>Administrar ' . h(strtolower($title)) . '</h1>
    </div>
  </div>
  ' . admin_nav($active) . '
  <form action="/admin/' . h($type) . '/crear" method="post" class="app-admin-form app-specialization-admin-form" enctype="multipart/form-data">
    <label>Nombre<input name="name" required></label>
    <label>Icono<input name="specialization_icon" type="file" accept="image/*"></label>
    <label class="app-form-wide">URL del icono actual o externo<input name="icon" placeholder="/uploads/specializations/icono.png"></label>
    <div class="app-form-actions"><button class="btn-contacto w-button" type="submit">Agregar especializacion</button></div>
  </form>
  <div class="app-admin-card">
    <table class="app-admin-table">
      <thead><tr><th>Icono</th><th>Nombre</th><th></th></tr></thead>
      <tbody>' . ($rows ?: '<tr><td colspan="3">Todavia no hay especializaciones.</td></tr>') . '</tbody>
    </table>
  </div>
</main>', 'admin');
}

function admin_orders_page(): void
{
    $orders = db()->query(
        "SELECT orders.*, users.email, users.name
        FROM orders
        JOIN users ON users.id = orders.user_id
        ORDER BY orders.created_at DESC"
    )->fetchAll();
    $rows = '';

    foreach ($orders as $order) {
        $items = db()->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC');
        $items->execute([(int) $order['id']]);
        $summary = [];
        foreach ($items->fetchAll() as $item) {
            $summary[] = h($item['product_name']) . ' x ' . (int) $item['quantity'] . ' (' . h(order_mode_label((string) $item['mode'])) . ')';
        }
        $rows .= '
<tr>
  <td><strong>#' . (int) $order['id'] . '</strong><span>' . h($order['created_at']) . '</span></td>
  <td>' . h($order['name'] ?: $order['email']) . '<span>' . h($order['email']) . '</span></td>
  <td>' . h(order_status_label((string) $order['status'])) . '</td>
  <td>' . implode('<br>', $summary) . '</td>
  <td class="app-admin-actions"><a href="/admin/pedidos/' . (int) $order['id'] . '">Ver detalle</a></td>
</tr>';
    }

    layout('Pedidos', '
<main class="app-admin-shell">
  <div class="app-admin-header"><div><h1>Pedidos</h1></div></div>
  ' . admin_nav('pedidos') . '
  <div class="app-admin-card">
    <table class="app-admin-table">
      <thead><tr><th>Pedido</th><th>Cliente</th><th>Estado</th><th>Items</th><th></th></tr></thead>
      <tbody>' . ($rows ?: '<tr><td colspan="5">Todavia no hay pedidos.</td></tr>') . '</tbody>
    </table>
  </div>
</main>', 'admin');
}

function admin_order_notice(): string
{
    $status = (string) ($_GET['status'] ?? '');
    if ($status === 'updated') {
        return '<div class="app-contact-feedback is-success">Estado actualizado.</div>';
    }
    if ($status === 'sent') {
        return '<div class="app-contact-feedback is-success">Email enviado al cliente.</div>';
    }
    if ($status === 'mail_failed') {
        return '<div class="app-contact-feedback is-error">No se pudo enviar el email. Revisá la configuracion de correo del servidor.</div>';
    }

    return '';
}

function admin_order_detail_page(int $orderId): void
{
    $order = order_by_id($orderId);
    if (!$order) {
        not_found();
        return;
    }

    $items = order_items($orderId);
    $snapshot = order_customer_snapshot($order);
    $itemRows = '';
    foreach ($items as $item) {
        $productName = h($item['product_name']);
        $productUrl = trim((string) ($item['product_url'] ?? ''));
        $productLabel = $productUrl !== ''
            ? '<a href="' . h($productUrl) . '">' . $productName . '</a>'
            : '<strong>' . $productName . '</strong>';
        $details = array_filter([
            'Modalidad: ' . order_mode_label((string) ($item['mode'] ?? '')),
            trim((string) ($item['rental_plan'] ?? '')) !== '' ? 'Plan: ' . (string) $item['rental_plan'] : '',
            trim((string) ($item['start_date'] ?? '')) !== '' ? 'Desde: ' . (string) $item['start_date'] : '',
            trim((string) ($item['end_date'] ?? '')) !== '' ? 'Hasta: ' . (string) $item['end_date'] : '',
            trim((string) ($item['city'] ?? '')) !== '' ? 'Ciudad: ' . (string) $item['city'] : '',
        ]);
        $itemRows .= '
        <tr>
          <td>' . $productLabel . '<span>' . h(implode(' - ', $details)) . '</span></td>
          <td>' . (int) $item['quantity'] . '</td>
        </tr>';
    }

    $snapshotRows = '';
    $snapshotLabels = [
        'name' => 'Nombre',
        'email' => 'Email',
        'phone' => 'Telefono',
        'company' => 'Empresa',
        'address' => 'Direccion',
        'city' => 'Ciudad',
    ];
    foreach ($snapshotLabels as $key => $label) {
        $value = trim((string) ($snapshot[$key] ?? ''));
        if ($value !== '') {
            $snapshotRows .= '<tr><th>' . h($label) . '</th><td>' . h($value) . '</td></tr>';
        }
    }

    $defaultSubject = 'Actualizacion de tu pedido #' . (int) $order['id'] . ' - Inexo Rental';
    $defaultMessage = 'Te escribimos para compartir una actualizacion sobre tu pedido #' . (int) $order['id'] . '. El estado actual es: ' . order_status_label((string) $order['status']) . '.';

    layout('Pedido #' . (int) $order['id'], '
<main class="app-admin-shell">
  <div class="app-admin-header">
    <div>
      <p class="app-kicker">Pedido</p>
      <h1>#' . (int) $order['id'] . '</h1>
    </div>
    <a href="/admin/pedidos" class="button w-button">volver</a>
  </div>
  ' . admin_nav('pedidos') . '
  ' . admin_order_notice() . '
  <section class="app-order-detail-grid">
    <div class="app-admin-card app-order-detail-main">
      <div class="app-order-detail-head">
        <div>
          <strong>' . h($order['name'] ?: $order['email']) . '</strong>
          <span>' . h($order['email']) . '</span>
        </div>
        <div>
          <strong>' . h(order_status_label((string) $order['status'])) . '</strong>
          <span>' . h($order['created_at']) . '</span>
        </div>
      </div>
      <h2>Items del pedido</h2>
      <table class="app-admin-table app-order-items-table">
        <thead><tr><th>Producto</th><th>Cantidad</th></tr></thead>
        <tbody>' . ($itemRows ?: '<tr><td colspan="2">Este pedido no tiene items.</td></tr>') . '</tbody>
      </table>
      <h2>Datos del checkout</h2>
      <table class="app-order-meta-table">
        <tbody>' . ($snapshotRows ?: '<tr><td>No hay datos adicionales guardados.</td></tr>') . '</tbody>
      </table>
    </div>
    <aside class="app-order-actions">
      <form action="/admin/pedidos/' . (int) $order['id'] . '/estado" method="post" class="app-admin-form app-order-action-form">
        <h2 class="app-form-wide">Cambiar estado</h2>
        <label class="app-form-wide">Estado<select name="status" required>' . order_status_options((string) $order['status']) . '</select></label>
        <div class="app-form-actions"><button class="btn-contacto w-button" type="submit">Actualizar estado</button></div>
      </form>
      <form action="/admin/pedidos/' . (int) $order['id'] . '/email" method="post" class="app-admin-form app-order-action-form">
        <h2 class="app-form-wide">Enviar email al cliente</h2>
        <label class="app-form-wide">Para<input value="' . h($order['email']) . '" type="email" disabled></label>
        <label class="app-form-wide">Asunto<input name="subject" value="' . h($defaultSubject) . '" required></label>
        <label class="app-form-wide">Mensaje<textarea name="message" rows="7" required>' . h($defaultMessage) . '</textarea></label>
        <div class="app-form-actions"><button class="btn-contacto w-button" type="submit">Enviar email</button></div>
      </form>
    </aside>
  </section>
</main>', 'admin');
}

function update_order_status(int $orderId): void
{
    $status = (string) ($_POST['status'] ?? '');
    if (!array_key_exists($status, order_statuses())) {
        redirect_to('/admin/pedidos/' . $orderId);
    }

    db()->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$status, $orderId]);
    redirect_to('/admin/pedidos/' . $orderId . '?status=updated');
}

function send_admin_order_email(int $orderId): void
{
    $order = order_by_id($orderId);
    if (!$order) {
        not_found();
        return;
    }

    $subject = clean_email_subject((string) ($_POST['subject'] ?? ''));
    $message = trim((string) ($_POST['message'] ?? ''));
    $sent = send_order_customer_email($order, order_items($orderId), $subject, $message);

    redirect_to('/admin/pedidos/' . $orderId . '?status=' . ($sent ? 'sent' : 'mail_failed'));
}

function admin_contact_page(): void
{
    $messages = db()->query('SELECT * FROM contact_messages ORDER BY created_at DESC')->fetchAll();
    $rows = '';

    foreach ($messages as $message) {
        $subject = trim((string) ($message['subject'] ?? ''));
        $phone = trim((string) ($message['phone'] ?? ''));
        $company = trim((string) ($message['company'] ?? ''));
        $meta = array_filter([$phone, $company]);
        $rows .= '
<tr>
  <td><strong>#' . (int) $message['id'] . '</strong><span>' . h($message['created_at']) . '</span></td>
  <td>' . h($message['name']) . '<span>' . h($message['email']) . '</span>' . ($meta ? '<span>' . h(implode(' · ', $meta)) . '</span>' : '') . '</td>
  <td><strong>' . h($subject !== '' ? $subject : 'Sin asunto') . '</strong><span>' . nl2br(h($message['message'])) . '</span></td>
  <td>' . ((int) $message['email_sent'] === 1 ? 'Enviado' : 'No enviado') . '</td>
</tr>';
    }

    layout('Contacto', '
<main class="app-admin-shell">
  <div class="app-admin-header"><div><h1>Consultas de contacto</h1></div></div>
  ' . admin_nav('contacto') . '
  <div class="app-admin-card">
    <table class="app-admin-table">
      <thead><tr><th>ID</th><th>Contacto</th><th>Consulta</th><th>Email</th></tr></thead>
      <tbody>' . ($rows ?: '<tr><td colspan="4">Todavia no hay consultas.</td></tr>') . '</tbody>
    </table>
  </div>
</main>', 'admin');
}

function brand_form(array $brand): void
{
    $logo = trim((string) ($brand['logo'] ?? ''));
    $preview = $logo !== ''
        ? '<div class="app-brand-logo-preview"><img src="' . h($logo) . '" alt="' . h($brand['name']) . '"></div>'
        : '';

    layout('Marca admin', '
<main class="app-admin-shell">
  <div class="app-admin-header">
    <div>
      <h1>Editar marca</h1>
    </div>
    <a href="/admin/marcas" class="button w-button">volver</a>
  </div>
  ' . admin_nav('marcas') . '
  <form action="/admin/marcas/' . (int) $brand['id'] . '/actualizar" method="post" class="app-admin-form app-brand-admin-form" enctype="multipart/form-data">
    <label>Nombre<input name="name" value="' . h($brand['name']) . '" required></label>
    <label>Reemplazar logo<input name="brand_logo" type="file" accept="image/*"></label>
    <label class="app-form-wide">URL del logo actual o externo<input name="logo" value="' . h($logo) . '"></label>
    ' . $preview . '
    <label class="app-form-wide">Descripcion<textarea name="description" rows="5">' . h($brand['description'] ?? '') . '</textarea></label>
    <div class="app-form-actions"><button class="btn-contacto w-button" type="submit">Guardar marca</button></div>
    </form>
</main>', 'admin');
}

function specialization_form(array $specialization): void
{
    $icon = trim((string) ($specialization['icon'] ?? ''));
    $preview = $icon !== ''
        ? '<div class="app-brand-logo-preview"><img src="' . h($icon) . '" alt="' . h($specialization['name']) . '"></div>'
        : '';

    layout('Especializacion admin', '
<main class="app-admin-shell">
  <div class="app-admin-header">
    <div>
      <h1>Editar especializacion</h1>
    </div>
    <a href="/admin/especializaciones" class="button w-button">volver</a>
  </div>
  ' . admin_nav('especializaciones') . '
  <form action="/admin/especializaciones/' . (int) $specialization['id'] . '/actualizar" method="post" class="app-admin-form app-specialization-admin-form" enctype="multipart/form-data">
    <label>Nombre<input name="name" value="' . h($specialization['name']) . '" required></label>
    <label>Reemplazar icono<input name="specialization_icon" type="file" accept="image/*"></label>
    <label class="app-form-wide">URL del icono actual o externo<input name="icon" value="' . h($icon) . '"></label>
    ' . $preview . '
    <div class="app-form-actions"><button class="btn-contacto w-button" type="submit">Guardar especializacion</button></div>
  </form>
</main>', 'admin');
}

function admin_users_page(): void
{
    $rows = '';
    foreach (db()->query('SELECT * FROM users ORDER BY created_at DESC')->fetchAll() as $user) {
        $rows .= '
<tr>
  <td><strong>' . h($user['name'] ?: $user['email']) . '</strong><span>' . h($user['email']) . '</span></td>
  <td>' . h($user['company']) . '</td>
  <td>' . h($user['phone']) . '</td>
  <td>' . ((int) $user['is_verified'] === 1 ? 'Verificado' : 'Pendiente') . '</td>
  <td>' . h($user['created_at']) . '</td>
</tr>';
    }

    layout('Usuarios', '
<main class="app-admin-shell">
  <div class="app-admin-header"><div><h1>Usuarios registrados</h1></div></div>
  ' . admin_nav('usuarios') . '
  <div class="app-admin-card">
    <table class="app-admin-table">
      <thead><tr><th>Usuario</th><th>Empresa</th><th>Telefono</th><th>Estado</th><th>Alta</th></tr></thead>
      <tbody>' . ($rows ?: '<tr><td colspan="5">Todavia no hay usuarios.</td></tr>') . '</tbody>
    </table>
  </div>
</main>', 'admin');
}

function product_form(?array $product = null): void
{
    $brands = lookup_rows('brands');
    $specializations = lookup_rows('specializations');
    $product ??= [
        'id' => '',
        'name' => '',
        'slug' => '',
        'code' => '',
        'brand' => '',
        'category' => 'Productos',
        'specialization' => '',
        'short_description' => '',
        'description' => '',
        'status' => 'En stock',
        'price_sale_used' => 0,
        'price_sale_new' => 0,
        'rental_daily' => 0,
        'rental_weekly' => 0,
        'rental_monthly' => 0,
        'images' => [ASSET_BASE . '/images/imagen-producto-generico.avif'],
        'specs' => [],
        'is_featured' => 1,
        'is_new' => 1,
    ];
    $isEdit = (int) ($product['id'] ?: 0) > 0;
    $action = $isEdit ? '/admin/productos/' . (int) $product['id'] . '/actualizar' : '/admin/productos/crear';
    $savedNotice = (string) ($_GET['guardado'] ?? '') === '1'
        ? '<div class="app-contact-feedback is-success app-product-save-feedback">¡Listo! Guardado.</div>'
        : '';
    $productUrl = trim((string) $product['slug']) !== '' ? '/producto/' . rawurlencode((string) $product['slug']) : '';
    $viewButton = $productUrl !== ''
        ? '<a href="' . h($productUrl) . '" class="button w-button" target="_blank" rel="noopener">Ver producto</a>'
        : '';
    $imagesText = implode("\n", $product['images']);
    $specsText = '';
    foreach ($product['specs'] as $spec) {
        $specsText .= ($spec[0] ?? '') . ': ' . ($spec[1] ?? '') . "\n";
    }

    layout('Producto admin', '
<main class="app-admin-shell">
  <div class="app-admin-header">
    <div>
      <h1>' . ($isEdit ? 'Editar producto' : 'Nuevo producto') . '</h1>
    </div>
    <a href="/admin/productos" class="button w-button">volver</a>
  </div>
  ' . admin_nav('productos') . '
  <form action="' . h($action) . '" method="post" class="app-admin-form" enctype="multipart/form-data">
    ' . $savedNotice . '
    <label>Nombre<input name="name" value="' . h($product['name']) . '" required></label>
    <label>Slug<input name="slug" value="' . h($product['slug']) . '" placeholder="se genera automaticamente si queda vacio"></label>
    <label>Codigo<input name="code" value="' . h($product['code']) . '" required></label>
    <label>Marca<select name="brand" required>' . select_options($brands, (string) $product['brand']) . '</select></label>
    <label>Categoria<input name="category" value="' . h($product['category']) . '"></label>
    <label>Especializacion<select name="specialization" required>' . select_options($specializations, (string) $product['specialization']) . '</select></label>
    <label>Estado<input name="status" value="' . h($product['status']) . '"></label>
    <label>Descripcion corta<input name="short_description" value="' . h($product['short_description']) . '"></label>
    <label class="app-form-wide">Descripcion<textarea name="description" rows="7">' . h($product['description']) . '</textarea></label>
    <label>Precio venta usado<input name="price_sale_used" type="number" step="0.01" value="' . h($product['price_sale_used']) . '"></label>
    <label>Precio venta nuevo<input name="price_sale_new" type="number" step="0.01" value="' . h($product['price_sale_new']) . '"></label>
    <label>Alquiler diario<input name="rental_daily" type="number" step="0.01" value="' . h($product['rental_daily']) . '"></label>
    <label>Alquiler semanal<input name="rental_weekly" type="number" step="0.01" value="' . h($product['rental_weekly']) . '"></label>
    <label>Alquiler mensual<input name="rental_monthly" type="number" step="0.01" value="' . h($product['rental_monthly']) . '"></label>
    <label class="app-form-wide">Subir imagenes<input name="product_images[]" type="file" accept="image/*" multiple></label>
    <label class="app-form-wide">Imagenes actuales o URLs externas, una por linea<textarea name="images" rows="4">' . h($imagesText) . '</textarea></label>
    <label class="app-form-wide">Especificaciones, formato Campo: Valor<textarea name="specs" rows="6">' . h(trim($specsText)) . '</textarea></label>
    <label class="app-check"><input type="checkbox" name="is_featured" value="1" ' . ((int) $product['is_featured'] === 1 ? 'checked' : '') . '> Producto destacado</label>
    <label class="app-check"><input type="checkbox" name="is_new" value="1" ' . ((int) $product['is_new'] === 1 ? 'checked' : '') . '> Producto novedad</label>
    <div class="app-form-actions"><button class="btn-contacto w-button" type="submit">Guardar producto</button>' . $viewButton . '</div>
  </form>
</main>', 'admin');
}

function save_product(?int $productId = null): array
{
    $name = trim((string) ($_POST['name'] ?? ''));
    $slug = trim((string) ($_POST['slug'] ?? '')) ?: slugify($name);
    $images = array_values(array_filter(array_map('trim', preg_split('/\R+/', (string) ($_POST['images'] ?? '')) ?: [])));
    $images = array_values(array_unique(array_merge($images, uploaded_product_images())));
    $specs = [];
    foreach (preg_split('/\R+/', (string) ($_POST['specs'] ?? '')) ?: [] as $line) {
        if (!str_contains($line, ':')) {
            continue;
        }
        [$key, $value] = explode(':', $line, 2);
        $specs[] = [trim($key), trim($value)];
    }

    $values = [
        ':slug' => $slug,
        ':name' => $name,
        ':code' => trim((string) ($_POST['code'] ?? '')),
        ':brand' => trim((string) ($_POST['brand'] ?? '')),
        ':category' => trim((string) ($_POST['category'] ?? '')),
        ':specialization' => trim((string) ($_POST['specialization'] ?? '')),
        ':short_description' => trim((string) ($_POST['short_description'] ?? '')),
        ':description' => trim((string) ($_POST['description'] ?? '')),
        ':status' => trim((string) ($_POST['status'] ?? 'En stock')) ?: 'En stock',
        ':price_sale_used' => (float) ($_POST['price_sale_used'] ?? 0),
        ':price_sale_new' => (float) ($_POST['price_sale_new'] ?? 0),
        ':rental_daily' => (float) ($_POST['rental_daily'] ?? 0),
        ':rental_weekly' => (float) ($_POST['rental_weekly'] ?? 0),
        ':rental_monthly' => (float) ($_POST['rental_monthly'] ?? 0),
        ':images' => json_encode($images ?: [ASSET_BASE . '/images/imagen-producto-generico.avif'], JSON_UNESCAPED_SLASHES),
        ':specs' => json_encode($specs, JSON_UNESCAPED_UNICODE),
        ':is_featured' => isset($_POST['is_featured']) ? 1 : 0,
        ':is_new' => isset($_POST['is_new']) ? 1 : 0,
    ];

    if ($productId) {
        $values[':id'] = $productId;
        db()->prepare(
            "UPDATE products SET
                slug = :slug, name = :name, code = :code, brand = :brand,
                category = :category, specialization = :specialization,
                short_description = :short_description, description = :description,
                status = :status, price_sale_used = :price_sale_used,
                price_sale_new = :price_sale_new, rental_daily = :rental_daily,
                rental_weekly = :rental_weekly, rental_monthly = :rental_monthly,
                images = :images, specs = :specs, is_featured = :is_featured,
                is_new = :is_new
            WHERE id = :id"
        )->execute($values);
    } else {
        db()->prepare(
            "INSERT INTO products (
                slug, name, code, brand, category, specialization, short_description,
                description, status, price_sale_used, price_sale_new, rental_daily,
                rental_weekly, rental_monthly, images, specs, is_featured, is_new
            ) VALUES (
                :slug, :name, :code, :brand, :category, :specialization, :short_description,
                :description, :status, :price_sale_used, :price_sale_new, :rental_daily,
                :rental_weekly, :rental_monthly, :images, :specs, :is_featured, :is_new
            )"
        )->execute($values);
        $productId = (int) db()->lastInsertId();
    }

    return ['id' => (int) $productId, 'slug' => $slug];
}

function move_product_to_trash(int $productId): void
{
    db()->prepare("UPDATE products SET deleted_at = datetime('now') WHERE id = ? AND deleted_at IS NULL")->execute([$productId]);
}

function restore_product_from_trash(int $productId): void
{
    db()->prepare('UPDATE products SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL')->execute([$productId]);
}

function delete_product_permanently(int $productId): void
{
    db()->prepare('DELETE FROM products WHERE id = ? AND deleted_at IS NOT NULL')->execute([$productId]);
}

function uploaded_product_images(): array
{
    if (empty($_FILES['product_images']) || !is_array($_FILES['product_images']['name'])) {
        return [];
    }
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0775, true);
    }

    $paths = [];
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/avif' => 'avif', 'image/gif' => 'gif'];
    $count = count($_FILES['product_images']['name']);
    for ($index = 0; $index < $count; $index++) {
        if ((int) $_FILES['product_images']['error'][$index] !== UPLOAD_ERR_OK) {
            continue;
        }
        $tmp = $_FILES['product_images']['tmp_name'][$index];
        $mime = mime_content_type($tmp) ?: '';
        if (!isset($allowed[$mime])) {
            continue;
        }
        $name = bin2hex(random_bytes(10)) . '.' . $allowed[$mime];
        $destination = UPLOAD_DIR . '/' . $name;
        if (move_uploaded_file($tmp, $destination)) {
            $paths[] = UPLOAD_BASE . '/' . $name;
        }
    }

    return $paths;
}

function uploaded_brand_logo(): string
{
    return uploaded_image_file('brand_logo', BRAND_UPLOAD_DIR, BRAND_UPLOAD_BASE);
}

function uploaded_specialization_icon(): string
{
    return uploaded_image_file('specialization_icon', SPECIALIZATION_UPLOAD_DIR, SPECIALIZATION_UPLOAD_BASE);
}

function uploaded_image_file(string $field, string $uploadDir, string $uploadBase): string
{
    if (empty($_FILES[$field]) || (int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return '';
    }
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    $tmp = (string) $_FILES[$field]['tmp_name'];
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/avif' => 'avif', 'image/gif' => 'gif'];
    $mime = mime_content_type($tmp) ?: '';
    if (!isset($allowed[$mime])) {
        return '';
    }

    $name = bin2hex(random_bytes(10)) . '.' . $allowed[$mime];
    $destination = $uploadDir . '/' . $name;
    if (!move_uploaded_file($tmp, $destination)) {
        return '';
    }

    return $uploadBase . '/' . $name;
}

function api_reservation(): void
{
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    db()->prepare(
        'INSERT INTO reservations (product_id, rental_plan, start_date, end_date, city) VALUES (?, ?, ?, ?, ?)'
    )->execute([
        (int) ($payload['product_id'] ?? 0),
        (string) ($payload['rental_plan'] ?? ''),
        (string) ($payload['start_date'] ?? ''),
        (string) ($payload['end_date'] ?? ''),
        (string) ($payload['city'] ?? ''),
    ]);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'message' => 'Solicitud recibida. Agregamos el equipo al carrito.']);
}

function api_checkout(): void
{
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $customer = $payload['customer'] ?? [];
    $items = $payload['items'] ?? [];
    $email = strtolower(trim((string) ($customer['email'] ?? '')));
    $password = (string) ($customer['password'] ?? '');
    $passwordConfirm = (string) ($customer['password_confirm'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !is_array($items) || $items === []) {
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'Necesitamos un email valido y al menos un producto.']);
        return;
    }

    $passwordError = password_validation_error($password, $passwordConfirm);
    if ($passwordError !== '') {
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => $passwordError]);
        return;
    }

    $customerSnapshot = $customer;
    unset($customerSnapshot['password'], $customerSnapshot['password_confirm']);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, password_hash, is_verified FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $existingUser = $stmt->fetch();
        $userFields = [
            trim((string) ($customer['name'] ?? '')),
            trim((string) ($customer['phone'] ?? '')),
            trim((string) ($customer['company'] ?? '')),
            trim((string) ($customer['address'] ?? '')),
            trim((string) ($customer['city'] ?? '')),
        ];

        if ($existingUser) {
            $userId = (int) $existingUser['id'];
            $storedHash = (string) ($existingUser['password_hash'] ?? '');
            if ($storedHash === '') {
                $orderCountStmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE user_id = ?');
                $orderCountStmt->execute([$userId]);
                $hasExistingHistory = (int) $orderCountStmt->fetchColumn() > 0 || (int) ($existingUser['is_verified'] ?? 0) === 1;
                if ($hasExistingHistory) {
                    $pdo->rollBack();
                    http_response_code(422);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['ok' => false, 'message' => 'Este email ya tiene una cuenta anterior sin contrasena. Contactanos para activar el acceso con contrasena.']);
                    return;
                }
            }
            if ($storedHash !== '' && !password_verify($password, $storedHash)) {
                $pdo->rollBack();
                http_response_code(422);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'message' => 'El email ya esta registrado. Ingresa la contrasena correcta para continuar.']);
                return;
            }

            $newHash = $storedHash === '' || password_needs_rehash($storedHash, PASSWORD_DEFAULT)
                ? password_hash($password, PASSWORD_DEFAULT)
                : $storedHash;
            $pdo->prepare(
                "UPDATE users
                SET name = ?, phone = ?, company = ?, address = ?, city = ?, password_hash = ?, is_verified = 1
                WHERE id = ?"
            )->execute([...$userFields, $newHash, $userId]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO users (email, password_hash, name, phone, company, address, city, is_verified)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1)"
            );
            $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT), ...$userFields]);
            $userId = (int) $pdo->lastInsertId();
        }

        $pdo->prepare('INSERT INTO orders (user_id, status, customer_snapshot) VALUES (?, ?, ?)')->execute([
            $userId,
            'validado',
            json_encode($customerSnapshot, JSON_UNESCAPED_UNICODE),
        ]);
        $orderId = (int) $pdo->lastInsertId();

        $itemStmt = $pdo->prepare(
            "INSERT INTO order_items (
                order_id, product_id, product_name, product_url, mode, quantity,
                rental_plan, start_date, end_date, city
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($items as $item) {
            $itemStmt->execute([
                $orderId,
                isset($item['id']) ? (int) $item['id'] : null,
                (string) ($item['name'] ?? 'Producto'),
                (string) ($item['url'] ?? ''),
                (string) ($item['mode'] ?? 'rental'),
                max(1, (int) ($item['qty'] ?? 1)),
                (string) ($item['rental_plan'] ?? ''),
                (string) ($item['start_date'] ?? ''),
                (string) ($item['end_date'] ?? ''),
                (string) ($item['city'] ?? ($customer['city'] ?? '')),
            ]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'message' => 'Pedido registrado. Tu cuenta quedo creada y ya estas ingresado.',
        'account_url' => public_path('/cuenta'),
    ]);
}

function save_contact_message(): void
{
    $values = [
        'name' => trim((string) ($_POST['name'] ?? '')),
        'email' => strtolower(trim((string) ($_POST['email'] ?? ''))),
        'phone' => trim((string) ($_POST['phone'] ?? '')),
        'company' => trim((string) ($_POST['company'] ?? '')),
        'subject' => trim((string) ($_POST['subject'] ?? '')),
        'message' => trim((string) ($_POST['message'] ?? '')),
    ];
    $errors = [];

    if ($values['name'] === '') {
        $errors[] = 'El nombre es obligatorio.';
    }
    if ($values['email'] === '' || !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Ingresa un email valido.';
    }
    if ($values['message'] === '') {
        $errors[] = 'La consulta es obligatoria.';
    }

    if ($errors !== []) {
        http_response_code(422);
        contact_page($values, $errors);
        return;
    }

    $sent = send_contact_email($values);
    db()->prepare(
        "INSERT INTO contact_messages (name, email, phone, company, subject, message, email_sent)
        VALUES (?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        $values['name'],
        $values['email'],
        $values['phone'],
        $values['company'],
        $values['subject'],
        $values['message'],
        $sent ? 1 : 0,
    ]);

    redirect_to('/contacto?enviado=1');
}

function request_customer_login(): void
{
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $redirectTo = (string) ($_POST['redirect'] ?? ($_SESSION['login_redirect'] ?? ''));
    if (is_safe_redirect_path($redirectTo)) {
        $_SESSION['login_redirect'] = $redirectTo;
    } else {
        unset($_SESSION['login_redirect']);
        $redirectTo = '';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        login_page('<p>Ingresa un email valido.</p>', $redirectTo, $email);
        return;
    }

    $stmt = db()->prepare('SELECT id, password_hash FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    $passwordHash = (string) ($user['password_hash'] ?? '');
    if (!$user || $passwordHash === '' || !password_verify($password, $passwordHash)) {
        login_page('<p>Usuario o contrasena incorrectos.</p>', $redirectTo, $email);
        return;
    }

    $userId = (int) $user['id'];
    if (password_needs_rehash($passwordHash, PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    db()->prepare("UPDATE users SET is_verified = 1, last_login_at = datetime('now') WHERE id = ?")->execute([$userId]);
    db()->prepare("UPDATE orders SET status = 'validado' WHERE user_id = ? AND status = 'pendiente_validacion'")->execute([$userId]);
    unset($_SESSION['login_redirect']);
    if (!is_safe_redirect_path($redirectTo)) {
        $redirectTo = '/cuenta';
    }
    if (is_admin_path($redirectTo) && !is_admin_user()) {
        $redirectTo = '/cuenta';
    }
    redirect_to($redirectTo !== '' ? $redirectTo : '/cuenta');
}

function request_admin_password_login(): void
{
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $redirectTo = (string) ($_POST['redirect'] ?? ($_SESSION['login_redirect'] ?? '/admin'));
    $redirectTo = is_safe_redirect_path($redirectTo) && is_admin_path($redirectTo) && $redirectTo !== '/admin/login'
        ? $redirectTo
        : '/admin';

    if (!hash_equals(ADMIN_PASSWORD_USERNAME, $username) || !password_verify($password, ADMIN_PASSWORD_HASH)) {
        admin_password_login_page('<p>Usuario o contrasena incorrectos.</p>', $redirectTo);
        return;
    }

    session_regenerate_id(true);
    $_SESSION['admin_password_auth'] = 1;
    unset($_SESSION['login_redirect']);
    redirect_to($redirectTo);
}

function valid_login_token_row(string $token): ?array
{
    $stmt = db()->prepare(
        "SELECT login_tokens.*, users.id AS account_id
        FROM login_tokens
        JOIN users ON users.id = login_tokens.user_id
        WHERE token = ? AND used_at IS NULL AND expires_at > datetime('now')"
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function confirm_login_token_page(): void
{
    $token = (string) ($_GET['token'] ?? '');
    $row = valid_login_token_row($token);
    if (!$row) {
        login_page('<p>El enlace ya expiro, ya fue usado o no es valido.</p><p>Ingresa tu email para recibir un enlace nuevo.</p>');
        return;
    }

    layout('Confirmar ingreso', '
<main class="app-admin-shell app-login-shell">
  <div class="app-admin-header"><div><p class="app-kicker">Cuenta</p><h1>Confirmar ingreso</h1></div></div>
  <form action="/auth/verificar" method="post" class="app-admin-form app-login-form">
    <input name="token" type="hidden" value="' . h($token) . '">
    <p>Presiona el boton para ingresar a tu cuenta.</p>
    <p>El enlace vence en 30 minutos y se usa una sola vez.</p>
    <div class="app-form-actions"><button class="btn-contacto w-button" type="submit">Ingresar</button></div>
  </form>
</main>', 'cuenta');
}

function verify_login_token(): void
{
    $token = (string) ($_POST['token'] ?? '');
    $row = valid_login_token_row($token);
    if (!$row) {
        login_page('<p>El enlace ya expiro, ya fue usado o no es valido.</p><p>Ingresa tu email para recibir un enlace nuevo.</p>');
        return;
    }

    db()->prepare("UPDATE login_tokens SET used_at = datetime('now') WHERE id = ?")->execute([(int) $row['id']]);
    db()->prepare("UPDATE users SET is_verified = 1, last_login_at = datetime('now') WHERE id = ?")->execute([(int) $row['account_id']]);
    db()->prepare("UPDATE orders SET status = 'validado' WHERE user_id = ? AND status = 'pendiente_validacion'")->execute([(int) $row['account_id']]);
    $_SESSION['user_id'] = (int) $row['account_id'];
    $redirectTo = (string) ($_SESSION['login_redirect'] ?? '/cuenta');
    unset($_SESSION['login_redirect']);
    if (!is_safe_redirect_path($redirectTo)) {
        $redirectTo = '/cuenta';
    }
    if (is_admin_path($redirectTo) && !is_admin_user()) {
        $redirectTo = '/cuenta';
    }
    redirect_to($redirectTo);
}

function redirect_to(string $path): void
{
    header('Location: ' . public_path($path), true, 303);
    exit;
}

function serve_image_file(string $path, string $contentType): void
{
    if (!is_file($path)) {
        not_found();
        return;
    }

    header('Content-Type: ' . $contentType);
    header('Cache-Control: public, max-age=604800');
    readfile($path);
    exit;
}

function not_found(): void
{
    http_response_code(404);
    layout('No encontrado', '<main class="app-admin-shell"><h1>Pagina no encontrada</h1><p>No encontramos el recurso solicitado.</p><a href="/" class="button w-button">Volver al inicio</a></main>');
}

function dispatch(): void
{
    init_db();

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = route_path_from_request();

    if (is_admin_path($path) && $path !== '/admin/login') {
        require_admin($path);
    }

    if ($path === '/favicon.ico') {
        serve_image_file(__DIR__ . FAVICON_IMAGE, 'image/png');
        return;
    }
    if ($path === '/apple-touch-icon.png' || $path === '/apple-touch-icon-precomposed.png') {
        serve_image_file(__DIR__ . WEBCLIP_IMAGE, 'image/png');
        return;
    }

    if ($method === 'POST') {
        if ($path === '/api/reservas') {
            api_reservation();
            return;
        }
        if ($path === '/api/checkout') {
            api_checkout();
            return;
        }
        if ($path === '/contacto') {
            save_contact_message();
            return;
        }
        if ($path === '/ingresar') {
            request_customer_login();
            return;
        }
        if ($path === '/auth/verificar') {
            verify_login_token();
            return;
        }
        if ($path === '/admin/login') {
            request_admin_password_login();
            return;
        }
        if ($path === '/admin/productos/crear') {
            $savedProduct = save_product();
            redirect_to('/admin/productos/' . (int) $savedProduct['id'] . '/editar?guardado=1');
        }
        if (preg_match('#^/admin/productos/(\d+)/actualizar$#', $path, $match)) {
            $savedProduct = save_product((int) $match[1]);
            redirect_to('/admin/productos/' . (int) $savedProduct['id'] . '/editar?guardado=1');
        }
        if (preg_match('#^/admin/productos/(\d+)/eliminar$#', $path, $match)) {
            move_product_to_trash((int) $match[1]);
            redirect_to('/admin/productos');
        }
        if (preg_match('#^/admin/productos/(\d+)/restaurar$#', $path, $match)) {
            restore_product_from_trash((int) $match[1]);
            redirect_to('/admin/productos/papelera');
        }
        if (preg_match('#^/admin/productos/(\d+)/eliminar-definitivo$#', $path, $match)) {
            delete_product_permanently((int) $match[1]);
            redirect_to('/admin/productos/papelera');
        }
        if ($path === '/admin/marcas/crear') {
            save_brand();
            redirect_to('/admin/marcas');
        }
        if (preg_match('#^/admin/marcas/(\d+)/actualizar$#', $path, $match)) {
            save_brand((int) $match[1]);
            redirect_to('/admin/marcas');
        }
        if ($path === '/admin/especializaciones/crear') {
            save_specialization();
            redirect_to('/admin/especializaciones');
        }
        if (preg_match('#^/admin/especializaciones/(\d+)/actualizar$#', $path, $match)) {
            save_specialization((int) $match[1]);
            redirect_to('/admin/especializaciones');
        }
        if (preg_match('#^/admin/marcas/(\d+)/eliminar$#', $path, $match)) {
            delete_lookup('brands', (int) $match[1]);
            redirect_to('/admin/marcas');
        }
        if (preg_match('#^/admin/especializaciones/(\d+)/eliminar$#', $path, $match)) {
            delete_lookup('specializations', (int) $match[1]);
            redirect_to('/admin/especializaciones');
        }
        if (preg_match('#^/admin/pedidos/(\d+)/estado$#', $path, $match)) {
            update_order_status((int) $match[1]);
            return;
        }
        if (preg_match('#^/admin/pedidos/(\d+)/email$#', $path, $match)) {
            send_admin_order_email((int) $match[1]);
            return;
        }
    }

    if ($path === '/') {
        home_page();
        return;
    }
    if ($path === '/productos') {
        listing_page('productos');
        return;
    }
    if ($path === '/productos-destacados') {
        filtered_product_listing_page('destacados');
        return;
    }
    if ($path === '/productos-novedades') {
        filtered_product_listing_page('novedades');
        return;
    }
    if ($path === '/especializacion') {
        specialization_listing_page();
        return;
    }
    if ($path === '/marca') {
        brand_listing_page();
        return;
    }
    if ($path === '/carrito') {
        cart_page();
        return;
    }
    if ($path === '/checkout') {
        checkout_page();
        return;
    }
    if ($path === '/contacto') {
        contact_page();
        return;
    }
    if ($path === '/quienes-somos') {
        placeholder_page('Quienes somos');
        return;
    }
    if ($path === '/terminos-y-condiciones') {
        placeholder_page('Terminos y condiciones');
        return;
    }
    if ($path === '/ingresar') {
        login_page();
        return;
    }
    if ($path === '/admin/login') {
        $redirectTo = (string) ($_SESSION['login_redirect'] ?? '/admin');
        admin_password_login_page('', $redirectTo);
        return;
    }
    if ($path === '/auth/verificar') {
        confirm_login_token_page();
        return;
    }
    if ($path === '/cuenta') {
        account_page();
        return;
    }
    if ($path === '/salir') {
        session_destroy();
        redirect_to('/');
    }
    if ($path === '/admin') {
        admin_dashboard_page();
        return;
    }
    if ($path === '/admin/productos') {
        admin_products_page();
        return;
    }
    if ($path === '/admin/productos/papelera') {
        admin_product_trash_page();
        return;
    }
    if ($path === '/admin/marcas') {
        admin_lookup_page('marcas');
        return;
    }
    if (preg_match('#^/admin/marcas/(\d+)/editar$#', $path, $match)) {
        $brand = brand_by_id((int) $match[1]);
        $brand ? brand_form($brand) : not_found();
        return;
    }
    if ($path === '/admin/especializaciones') {
        admin_lookup_page('especializaciones');
        return;
    }
    if (preg_match('#^/admin/especializaciones/(\d+)/editar$#', $path, $match)) {
        $specialization = specialization_by_id((int) $match[1]);
        $specialization ? specialization_form($specialization) : not_found();
        return;
    }
    if ($path === '/admin/pedidos') {
        admin_orders_page();
        return;
    }
    if (preg_match('#^/admin/pedidos/(\d+)$#', $path, $match)) {
        admin_order_detail_page((int) $match[1]);
        return;
    }
    if ($path === '/admin/contacto') {
        admin_contact_page();
        return;
    }
    if ($path === '/admin/usuarios') {
        admin_users_page();
        return;
    }
    if ($path === '/admin/productos/nuevo') {
        product_form();
        return;
    }
    if (preg_match('#^/admin/productos/(\d+)/editar$#', $path, $match)) {
        $product = product_by_id((int) $match[1]);
        $product ? product_form($product) : not_found();
        return;
    }
    if (preg_match('#^/producto/([^/]+)$#', $path, $match)) {
        detail_page(urldecode($match[1]));
        return;
    }

    not_found();
}

dispatch();
