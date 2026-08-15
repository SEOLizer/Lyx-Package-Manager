<?php
declare(strict_types=1);

/**
 * POST /api/v1/publish — ein .lxpkg in die Registry aufnehmen.
 *
 * Der Request ist absichtlich einfach gehalten, damit ein Client ihn ohne
 * multipart-Kodierung bauen kann: der Rumpf ist das rohe Archiv, alles andere
 * steht in Kopfzeilen.
 *
 *   Authorization: Bearer <token>
 *   X-Lpm-Name:    net/http
 *   X-Lpm-Version: 1.2.0
 *   X-Lpm-Deps:    {"std/io":">=1.0.0"}      (optional, JSON-Objekt)
 *   X-Lpm-Desc:    HTTP client for Lyx       (optional, eine Zeile)
 *   Content-Type:  application/octet-stream
 *
 * Antwort: JSON. Bei Erfolg 201 mit sha256 und der Quell-URL.
 *
 * Eine veroeffentlichte Version wird nie ueberschrieben (409). Wer sich
 * vertan hat, veroeffentlicht eine neue Version — ein `lyx.lock` mit gepinntem
 * SHA256 muss auch spaeter noch stimmen.
 */

require_once __DIR__ . '/../lib/registry.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    lpm_fail(405, 'Nur POST.');
}

// ---------------------------------------------------------------------------
// Authentifizierung
// ---------------------------------------------------------------------------

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('#\ABearer\s+(\S+)\z#', $auth, $m)) {
    header('WWW-Authenticate: Bearer');
    lpm_fail(401, 'Authorization: Bearer <token> fehlt.');
}
$account = lpm_authenticate($m[1]);

// ---------------------------------------------------------------------------
// Kopfzeilen pruefen
// ---------------------------------------------------------------------------

$name    = trim((string) ($_SERVER['HTTP_X_LPM_NAME'] ?? ''));
$version = trim((string) ($_SERVER['HTTP_X_LPM_VERSION'] ?? ''));

if (!lpm_name_is_safe($name)) {
    lpm_fail(400, 'X-Lpm-Name fehlt oder ist unzulaessig (erlaubt: [a-z0-9_/.-], keine Punkt-Segmente).');
}
if (!lpm_version_is_safe($version)) {
    lpm_fail(400, 'X-Lpm-Version fehlt oder ist unzulaessig (erlaubt: [0-9a-z.+-]).');
}
if (!lpm_may_publish($account, $name)) {
    lpm_fail(403, 'Dieser Token darf unter "' . $name . '" nicht veroeffentlichen.');
}

$deps = [];
$depsRaw = (string) ($_SERVER['HTTP_X_LPM_DEPS'] ?? '');
if ($depsRaw !== '') {
    $decoded = json_decode($depsRaw, true);
    if (!is_array($decoded)) {
        lpm_fail(400, 'X-Lpm-Deps ist kein JSON-Objekt.');
    }
    foreach ($decoded as $depName => $constraint) {
        if (!is_string($depName) || !is_string($constraint)) {
            lpm_fail(400, 'X-Lpm-Deps: Name und Constraint muessen Zeichenketten sein.');
        }
        if (!lpm_name_is_safe($depName)) {
            lpm_fail(400, 'X-Lpm-Deps: unzulaessiger Paketname "' . $depName . '".');
        }
        $deps[$depName] = $constraint;
    }
}

// Beschreibung: eine Zeile, keine Steuerzeichen — sie landet im Gesamtindex und
// wird von `lpm search` ausgegeben.
$description = trim((string) ($_SERVER['HTTP_X_LPM_DESC'] ?? ''));
$description = preg_replace('#[\x00-\x1F\x7F]#', '', $description) ?? '';
if (strlen($description) > 200) {
    $description = substr($description, 0, 200);
}

// ---------------------------------------------------------------------------
// Rumpf einlesen
// ---------------------------------------------------------------------------

$body = file_get_contents('php://input');
if ($body === false || $body === '') {
    lpm_fail(400, 'Leerer Rumpf — erwartet wird das .lxpkg.');
}
if (strlen($body) > LPM_MAX_UPLOAD) {
    lpm_fail(413, 'Archiv groesser als 32 MiB.');
}
// Ein .lxpkg ist ein zstd-Frame. Die Magic-Bytes zu pruefen kostet nichts und
// faengt den haeufigsten Bedienfehler ab: das unkomprimierte tar hochladen.
if (!str_starts_with($body, LPM_ZSTD_MAGIC)) {
    lpm_fail(400, 'Kein zstd-Archiv (Magic-Bytes fehlen) — .lxpkg erwartet.');
}

$sha256 = hash('sha256', $body);

// Ein mitgeschickter Hash wird geprueft, nicht uebernommen: so faellt eine auf
// dem Transportweg veraenderte Datei sofort auf, statt erst beim Client.
$claimed = strtolower(trim((string) ($_SERVER['HTTP_X_LPM_SHA256'] ?? '')));
if ($claimed !== '' && !hash_equals($sha256, $claimed)) {
    lpm_fail(400, 'SHA256 stimmt nicht mit dem Rumpf ueberein.');
}

// ---------------------------------------------------------------------------
// Ablegen
// ---------------------------------------------------------------------------

$archivePath = LPM_WEBROOT . '/packages/' . $name . '/' . $version . '.lxpkg';

// Doppelte Sicherung gegen Traversal: der endgueltige Pfad muss unterhalb des
// Paketbaums liegen. lpm_name_is_safe hat das bereits geprueft; diese Zeile
// haelt auch dann, wenn dort je etwas gelockert wird.
$expectedPrefix = LPM_WEBROOT . '/packages/';
if (!str_starts_with($archivePath, $expectedPrefix) || str_contains($archivePath, '/../')) {
    lpm_fail(400, 'Pfad unzulaessig.');
}

if (is_file($archivePath)) {
    lpm_fail(409, $name . '@' . $version . ' ist bereits veroeffentlicht. Versionen sind unveraenderlich.');
}

if (!lpm_write_atomic($archivePath, $body)) {
    lpm_fail(500, 'Archiv liess sich nicht ablegen.');
}

$entry = [
    'version'      => $version,
    'sha256'       => $sha256,
    'source'       => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'lpm.seolizer.de')
                      . '/packages/' . $name . '/' . $version . '.lxpkg',
    'publisher'    => $account['publisher'],
    'dependencies' => (object) $deps,
    'yanked'       => false,
];
if ($description !== '') {
    $entry['description'] = $description;
}

if (!lpm_index_add($name, $entry)) {
    // Der Paket-Index liess sich nicht schreiben oder kannte die Version schon.
    // Das gerade abgelegte Archiv waere dann verwaist — also zuruecknehmen.
    @unlink($archivePath);
    lpm_fail(500, 'Paket-Index liess sich nicht aktualisieren.');
}

if (!lpm_rebuild_root_index()) {
    // Das Paket ist auffindbar (Paket-Index steht), nur die Suche hinkt
    // hinterher. Kein Grund, den Upload zu verwerfen — aber sagen muss man es.
    lpm_reply(201, [
        'ok'      => true,
        'name'    => $name,
        'version' => $version,
        'sha256'  => $sha256,
        'source'  => $entry['source'],
        'warning' => 'Gesamtindex konnte nicht neu gebaut werden; lpm search zeigt das Paket erst spaeter.',
    ]);
}

lpm_reply(201, [
    'ok'      => true,
    'name'    => $name,
    'version' => $version,
    'sha256'  => $sha256,
    'source'  => $entry['source'],
]);
