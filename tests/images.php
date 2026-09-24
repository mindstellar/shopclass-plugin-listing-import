<?php
/*
 * This file is part of the Listing Import plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * An image address a partner sends is fetched only when every address it resolves to is
 * public, on the normal port, over http or https, including every redirect on the way; and
 * what arrives must be a real image under the size cap.
 */

require __DIR__ . '/lib/harness.php';
foreach (array('AddressGuard', 'Transport', 'ImageSource', 'Downloader', 'Fetcher') as $class) {
    require __DIR__ . '/../src/Images/' . $class . '.php';
}

use mindstellar\listingimport\Images\AddressGuard;
use mindstellar\listingimport\Images\Fetcher;
use mindstellar\listingimport\Images\Transport;

/** DNS answers the tests control. */
$dns = array(
    'cdn.example'       => array('93.184.216.34'),
    'inside.example'    => array('10.0.0.5'),
    'split.example'     => array('93.184.216.34', '127.0.0.1'),
    'v6.example'        => array('2606:2800:220:1:248:1893:25c8:1946'),
    'v6local.example'   => array('fd00::1'),
    'metadata.example'  => array('169.254.169.254'),
    'dual.example'      => array('2606:2800:220:1:248:1893:25c8:1946', '93.184.216.34'),
);
$guard = new AddressGuard(static fn (string $host) => $dns[$host] ?? array());

harness_section('addresses that are fetched');

foreach (array(
    'https://cdn.example/a.jpg',
    'http://cdn.example/a.jpg',
    'https://cdn.example:443/a.jpg',
    'https://v6.example/a.jpg',
    'https://93.184.216.34/a.jpg',
) as $url) {
    pin($url, true, $guard->check($url)['ok']);
}
pin('the approved IP is handed back for pinning', '93.184.216.34', $guard->check('https://cdn.example/a.jpg')['ip']);
pin('IPv4 is preferred when a host has both', '93.184.216.34', $guard->check('https://dual.example/a.jpg')['ip']);

harness_section('addresses that are not');

foreach (array(
    'file:///etc/passwd'                     => 'Only http and https addresses are fetched.',
    'gopher://cdn.example/'                  => 'Only http and https addresses are fetched.',
    'ftp://cdn.example/a.jpg'                => 'Only http and https addresses are fetched.',
    'https://cdn.example:8080/a.jpg'         => 'Only the standard port is fetched.',
    'https://user:pw@cdn.example/a.jpg'      => 'An address with a user name or password is not fetched.',
    'https://inside.example/a.jpg'           => 'The host is on a private or reserved network.',
    'https://split.example/a.jpg'            => 'The host is on a private or reserved network.',
    'https://v6local.example/a.jpg'          => 'The host is on a private or reserved network.',
    'http://metadata.example/latest'         => 'The host is on a private or reserved network.',
    'http://127.0.0.1/admin'                 => 'The host is on a private or reserved network.',
    'http://[::1]/admin'                     => 'The host is on a private or reserved network.',
    'http://[::ffff:127.0.0.1]/admin'        => 'The host is on a private or reserved network.',
    'http://100.64.0.1/a.jpg'                => 'The host is on a private or reserved network.',
    'http://0.0.0.0/a.jpg'                   => 'The host is on a private or reserved network.',
    'https://nowhere.example/a.jpg'          => 'The host name does not resolve.',
) as $url => $reason) {
    pin($url, $reason, $guard->check($url)['error'] ?? 'allowed');
}

harness_section('downloading');

/** A web server the tests script: URL => [status, location, body]. */
final class ScriptedTransport implements Transport
{
    public array $pages = array();
    public array $asked = array();

    public function get(string $url, string $ip, string $file, int $maxBytes): array
    {
        $this->asked[] = $url . ' @' . $ip;
        [$status, $location, $body] = $this->pages[$url] ?? array(404, '', '');
        if (strlen($body) > $maxBytes) {
            return array('error' => 'The file is larger than the limit.');
        }
        file_put_contents($file, $body);

        return array('status' => $status, 'location' => $location);
    }
}

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$tmp = sys_get_temp_dir() . '/li-images-' . getmypid() . '/';
@mkdir($tmp);
$web = new ScriptedTransport();
$get = new Fetcher($guard, $web, $tmp, 1000);

$web->pages['https://cdn.example/a.png'] = array(200, '', $png);
$r = $get->fetch('https://cdn.example/a.png');
pin('a real image is kept', array(true, hash('sha256', $png)), array($r['ok'], $r['hash'] ?? null));
check('under an import_ name in the temp folder', strpos($r['path'] ?? '', $tmp . 'import_') === 0 && is_file($r['path']));
pin('the connection used the approved IP', array('https://cdn.example/a.png @93.184.216.34'), $web->asked);

$web->pages['https://cdn.example/move'] = array(302, '/b.png', '');
$web->pages['https://cdn.example/b.png'] = array(200, '', $png);
pin('a redirect is followed', true, $get->fetch('https://cdn.example/move')['ok']);

$web->pages['https://cdn.example/sneaky'] = array(302, 'http://inside.example/secret', '');
$web->asked = array();
$r = $get->fetch('https://cdn.example/sneaky');
pin('a redirect to a private host is refused', 'The host is on a private or reserved network.', $r['error'] ?? null);
pin('and the private host is never asked', array('https://cdn.example/sneaky @93.184.216.34'), $web->asked);

$web->pages['https://cdn.example/loop'] = array(302, 'https://cdn.example/loop', '');
pin('a redirect loop stops', 'Too many redirects.', $get->fetch('https://cdn.example/loop')['error'] ?? null);

$web->pages['https://cdn.example/page.html'] = array(200, '', '<html>not an image</html>');
pin('something that is not an image is refused', 'Not a JPEG, PNG, GIF or WebP image.', $get->fetch('https://cdn.example/page.html')['error'] ?? null);

$web->pages['https://cdn.example/huge.png'] = array(200, '', $png . str_repeat('x', 2000));
pin('an image over the cap is refused', 'The file is larger than the limit.', $get->fetch('https://cdn.example/huge.png')['error'] ?? null);

pin('a missing image says so', 'The server answered 404.', $get->fetch('https://cdn.example/none.png')['error'] ?? null);

$left = glob($tmp . 'import_*');
pin('only the two accepted images are left in the temp folder', 2, count($left));
array_map('unlink', $left);
@rmdir($tmp);

harness_section('redirect targets');

pin('an absolute path', 'https://cdn.example/b.png', Fetcher::absolute('https://cdn.example/a/b.jpg', '/b.png'));
pin('a relative path', 'https://cdn.example/a/c.png', Fetcher::absolute('https://cdn.example/a/b.jpg', 'c.png'));
pin('scheme-relative', 'https://other.example/x.png', Fetcher::absolute('https://cdn.example/a', '//other.example/x.png'));
pin('a full address', 'http://x.example/y', Fetcher::absolute('https://cdn.example/a', 'http://x.example/y'));

exit(harness_result());
