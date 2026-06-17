<?php
declare(strict_types=1);

putenv('INEXO_SKIP_DISPATCH=1');
require dirname(__DIR__) . '/index.php';

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL);
        fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
        fwrite(STDERR, 'Actual: ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

assert_same(
    ['lat' => 18.4861, 'lng' => -69.9312],
    valid_lat_lng('18.4861', '-69.9312'),
    'Valid coordinates should be normalized.'
);
assert_same(null, valid_lat_lng('91', '-69.9312'), 'Latitude outside the valid range should be rejected.');
assert_same(null, valid_lat_lng('18.4861', '-181'), 'Longitude outside the valid range should be rejected.');
assert_same(165, google_route_duration_seconds('165.4s'), 'Google duration values should be converted to seconds.');
assert_same(0, google_route_duration_seconds('invalid'), 'Invalid duration values should be rejected.');
assert_same('1 h 3 min', format_route_duration(3765), 'Durations should be formatted for admin views.');
assert_same(
    'https://www.google.com/maps/dir/?api=1&origin=18.1%2C-69.1&destination=18.2%2C-69.2&travelmode=driving',
    google_maps_route_url([
        'origin_lat' => '18.1',
        'origin_lng' => '-69.1',
        'lat' => '18.2',
        'lng' => '-69.2',
    ]),
    'The driver link should use the saved origin and destination coordinates.'
);

echo "route_helpers_test: ok\n";
