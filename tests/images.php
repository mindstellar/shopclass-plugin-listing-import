<?php
/*
 * This file is part of the Listing Import plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * An image is fetched only through the address guard, redirects included, from the IP the
 * guard approved; and what arrives must be a real image under the size cap. The guard
 * itself is core's AddressGuard, tested in core; a stand-in with fixed answers is used here.
 */

require __DIR__ . '/lib/harness.php';
foreach (array('Transport', 'ImageSource', 'Downloader', 'Fetcher') as $class) {
    require __DIR__ . '/../src/Images/' . $class . '.php';
}

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
    'multi.example'     => array('93.184.216.1', '93.184.216.2', '93.184.216.3', '93.184.216.4'),
);
/** Core's AddressGuard answers, from the DNS table above: private ranges are refused. */
$guard = new class ($dns) {
    private array $dns;

    public function __construct(array $dns)
    {
        $this->dns = $dns;
    }

    public function check(string $url): array
    {
        $ips = $this->dns[(string)parse_url($url, PHP_URL_HOST)] ?? array();
        if ($ips === array()) {
            return array('ok' => false, 'error' => 'The host name does not resolve.');
        }
        foreach ($ips as $ip) {
            if (preg_match('/^(10\.|127\.|169\.254\.|fd)/', $ip)) {
                return array('ok' => false, 'error' => 'The host is on a private or reserved network.');
            }
        }

        return array('ok' => true, 'ip' => $ips[0], 'ips' => $ips);
    }
};

harness_section('downloading');

/** A web server the tests script: URL => [status, location, body]. */
final class ScriptedTransport implements Transport
{
    public array $pages = array();
    public array $asked = array();
    public array $down  = array();

    public function get(string $url, string $ip, string $file, int $maxBytes): array
    {
        $this->asked[] = $url . ' @' . $ip;
        if (in_array($ip, $this->down, true)) {
            return array('error' => 'The server could not be reached.', 'unreachable' => true);
        }
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

$web->pages['https://multi.example/a.png'] = array(200, '', $png);
$web->down  = array('93.184.216.1');
$web->asked = array();
pin('a dead address is skipped for the host\'s next one', true, $get->fetch('https://multi.example/a.png')['ok']);
pin('which was checked like the first', array('https://multi.example/a.png @93.184.216.1', 'https://multi.example/a.png @93.184.216.2'), $web->asked);
$web->down  = array('93.184.216.1', '93.184.216.2', '93.184.216.3');
$web->asked = array();
pin('after three dead addresses it gives up', 'The server could not be reached.', $get->fetch('https://multi.example/a.png')['error'] ?? null);
pin('without trying a fourth', 3, count($web->asked));
$web->down = array();

$left = glob($tmp . 'import_*');
pin('only the three accepted images are left in the temp folder', 3, count($left));
array_map('unlink', $left);
@rmdir($tmp);

harness_section('redirect targets');

pin('an absolute path', 'https://cdn.example/b.png', Fetcher::absolute('https://cdn.example/a/b.jpg', '/b.png'));
pin('a relative path', 'https://cdn.example/a/c.png', Fetcher::absolute('https://cdn.example/a/b.jpg', 'c.png'));
pin('scheme-relative', 'https://other.example/x.png', Fetcher::absolute('https://cdn.example/a', '//other.example/x.png'));
pin('a full address', 'http://x.example/y', Fetcher::absolute('https://cdn.example/a', 'http://x.example/y'));

exit(harness_result());
