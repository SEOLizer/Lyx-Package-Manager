<?php
declare(strict_types=1);

/**
 * Gemeinsame Logik der lpm-Registry-API.
 *
 * Die Registry ist statisch: `lpm install` liest ausschliesslich Dateien. Nur
 * dieser Endpunkt schreibt sie. Alles hier ist deshalb darauf ausgelegt, den
 * Dateibaum in einem konsistenten Zustand zu halten — Indizes werden ueber eine
 * temporaere Datei und rename() ersetzt, nie an Ort und Stelle beschrieben.
 *
 * Protokoll: docs/registry.md im Repo lyx-lpm.
 */

const LPM_WEBROOT     = '/var/www/lpm';
const LPM_TOKENS_FILE = '/etc/lpm/tokens.json';   // bewusst ausserhalb des Webroots
const LPM_MAX_UPLOAD  = 33554432;                 // 32 MiB, wie client_max_body_size
const LPM_ZSTD_MAGIC  = "\x28\xB5\x2F\xFD";

/** Antwortet mit JSON und beendet die Anfrage. */
function lpm_reply(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    exit;
}

function lpm_fail(int $status, string $message): never
{
    lpm_reply($status, ['ok' => false, 'error' => $message]);
}

/**
 * Paketname pruefen. Dieselbe Regel wie clientseitig in FtNameIsSafe:
 * [a-z0-9_/.-], kein fuehrender/doppelter/nachlaufender Slash, kein
 * Punkt-Segment. Der Name wird unveraendert zum Pfad, deshalb ist das hier
 * die entscheidende Schranke gegen Traversal — nicht die Hoeflichkeit des
 * Clients.
 */
function lpm_name_is_safe(string $name): bool
{
    if ($name === '' || strlen($name) > 200) {
        return false;
    }
    if (!preg_match('#\A[a-z0-9_./-]+\z#', $name)) {
        return false;
    }
    if ($name[0] === '/' || substr($name, -1) === '/') {
        return false;
    }
    foreach (explode('/', $name) as $segment) {
        if ($segment === '' || preg_match('#\A\.+\z#', $segment)) {
            return false;
        }
    }
    return true;
}

/** Version pruefen: [0-9a-z.+-], kein reines Punkt-Segment. */
function lpm_version_is_safe(string $version): bool
{
    if ($version === '' || strlen($version) > 64) {
        return false;
    }
    if (!preg_match('#\A[0-9a-z.+-]+\z#', $version)) {
        return false;
    }
    return !preg_match('#\A\.+\z#', $version);
}

/**
 * Token gegen /etc/lpm/tokens.json pruefen.
 *
 * In der Datei stehen SHA256-Hexwerte, nicht die Token selbst — wer die Datei
 * liest, kann damit nichts hochladen. Verglichen wird mit hash_equals, damit
 * die Laufzeit nichts ueber den erwarteten Wert verraet.
 *
 * Format:
 *   {
 *     "<sha256 des tokens>": { "publisher": "roene", "namespaces": ["net/", "std/"] }
 *   }
 *
 * namespaces ist eine Liste erlaubter Praefixe; "*" erlaubt alles.
 */
function lpm_authenticate(string $token): array
{
    if (!is_readable(LPM_TOKENS_FILE)) {
        lpm_fail(503, 'Registry ist nicht fuer Uploads eingerichtet.');
    }
    $raw = file_get_contents(LPM_TOKENS_FILE);
    $tokens = json_decode((string) $raw, true);
    if (!is_array($tokens)) {
        lpm_fail(503, 'Token-Datei ist unlesbar.');
    }

    $given = hash('sha256', $token);
    foreach ($tokens as $hash => $info) {
        if (hash_equals((string) $hash, $given)) {
            return [
                'publisher'  => (string) ($info['publisher'] ?? 'unknown'),
                'namespaces' => (array) ($info['namespaces'] ?? []),
            ];
        }
    }
    lpm_fail(401, 'Token unbekannt.');
}

/** Darf dieser Publisher unter diesem Namen veroeffentlichen? */
function lpm_may_publish(array $account, string $name): bool
{
    foreach ($account['namespaces'] as $prefix) {
        if ($prefix === '*') {
            return true;
        }
        if (str_starts_with($name, (string) $prefix)) {
            return true;
        }
    }
    return false;
}

/** Schreibt $data nach $path — ueber eine temporaere Datei und rename(). */
function lpm_write_atomic(string $path, string $data): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
        return false;
    }
    $tmp = $path . '.tmp' . getmypid();
    if (file_put_contents($tmp, $data) !== strlen($data)) {
        @unlink($tmp);
        return false;
    }
    @chmod($tmp, 0o644);
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/**
 * Traegt eine Version in /packages/<name>/index.json ein.
 *
 * Eine bereits vorhandene Version wird NICHT ersetzt: Archive sind
 * unveraenderlich, und ein `lyx.lock` mit gepinntem SHA256 muss auch in einem
 * Jahr noch stimmen. Das Ueberschreiben verhindert der Aufrufer bereits an der
 * Datei; hier ist es die zweite Schranke.
 */
function lpm_index_add(string $name, array $entry): bool
{
    $path = LPM_WEBROOT . '/packages/' . $name . '/index.json';

    $index = ['name' => $name, 'versions' => []];
    if (is_file($path)) {
        $existing = json_decode((string) file_get_contents($path), true);
        if (is_array($existing) && isset($existing['versions']) && is_array($existing['versions'])) {
            $index = $existing;
            $index['name'] = $name;
        }
    }

    // json_decode(assoc) macht aus einem leeren {} ein leeres Array, und
    // json_encode schreibt daraus [] zurueck. Fuer den Client ist das ein
    // Typwechsel mitten im Feld: sein Parser erwartet bei "dependencies" ein
    // Objekt und verwirft sonst den ganzen Index — ein bereits
    // veroeffentlichtes Paket waere ploetzlich unauffindbar. Deshalb beim
    // Wiedereinlesen normalisieren.
    foreach ($index['versions'] as $i => $v) {
        if (isset($v['dependencies']) && $v['dependencies'] === []) {
            $index['versions'][$i]['dependencies'] = new stdClass();
        }
    }

    foreach ($index['versions'] as $known) {
        if (($known['version'] ?? null) === $entry['version']) {
            return false;
        }
    }

    $index['versions'][] = $entry;
    usort($index['versions'], static fn(array $a, array $b): int
        => strnatcmp((string) $a['version'], (string) $b['version']));

    return lpm_write_atomic(
        $path,
        json_encode($index, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n"
    );
}

/**
 * Baut /index.json aus allen vorhandenen Paket-Indizes neu auf.
 *
 * Bewusst vollstaendig neu statt inkrementell: der Gesamtindex ist eine reine
 * Ableitung, und ein abgebrochener Upload soll ihn nicht in einen Zustand
 * bringen, den niemand mehr nachvollziehen kann. Bei der derzeitigen
 * Groessenordnung kostet das nichts.
 */
function lpm_rebuild_root_index(): bool
{
    $packagesDir = LPM_WEBROOT . '/packages';
    $packages = [];

    if (is_dir($packagesDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($packagesDir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->getFilename() !== 'index.json') {
                continue;
            }
            $data = json_decode((string) file_get_contents($file->getPathname()), true);
            if (!is_array($data) || !isset($data['name'], $data['versions'])) {
                continue;
            }

            $versions = [];
            $latest = null;
            $description = '';
            foreach ($data['versions'] as $v) {
                $version = (string) ($v['version'] ?? '');
                if ($version === '') {
                    continue;
                }
                $versions[] = $version;
                if (($v['yanked'] ?? false) === true) {
                    continue;   // zurueckgezogene Versionen sind nicht "latest"
                }
                if ($latest === null || strnatcmp($version, $latest) > 0) {
                    $latest = $version;
                }
                if (isset($v['description']) && $v['description'] !== '') {
                    $description = (string) $v['description'];
                }
            }
            if ($versions === []) {
                continue;
            }

            $packages[] = [
                'name'        => (string) $data['name'],
                'latest'      => $latest ?? end($versions),
                'description' => $description,
                'versions'    => $versions,
            ];
        }
    }

    usort($packages, static fn(array $a, array $b): int
        => strcmp((string) $a['name'], (string) $b['name']));

    $root = [
        'generated' => gmdate('Y-m-d\TH:i:s\Z'),
        'packages'  => $packages,
    ];

    return lpm_write_atomic(
        LPM_WEBROOT . '/index.json',
        json_encode($root, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n"
    );
}
