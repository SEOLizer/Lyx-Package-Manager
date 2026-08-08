# Registry-Protokoll

Die öffentliche Registry für `lpm` läuft unter **https://lpm.seolizer.de**.

Sie ist bewusst als *statische* Registry entworfen: alles, was `lpm install`
braucht, sind `GET`-Requests auf gewöhnliche Dateien. Erst `lpm publish`
(PM-08) benötigt serverseitige Logik.

---

## URL-Layout

| Pfad | Inhalt | Content-Type |
|------|--------|--------------|
| `/index.json` | Gesamtindex aller Pakete (Suche, `lpm search`) | `application/json` |
| `/packages/<name>/index.json` | Versionsliste eines Pakets | `application/json` |
| `/packages/<name>/<version>.lxpkg` | Paket-Archiv (tar.zst) | `application/octet-stream` |
| `/keys/<publisher>.pub` | Ed25519-Publisher-Key (TOFU) | `text/plain` |
| `/api/v1/publish` | Upload (PM-08, `POST`, Auth erforderlich) | `application/json` |

`<name>` ist der hierarchische Paketname und wird **unverändert** als Pfad
verwendet: `net/http` → `/packages/net/http/1.2.0.lxpkg`. Erlaubte Zeichen sind
`[a-z0-9_/.-]`; das schließt `..` als Segment aus und macht die Abbildung
Name → Pfad eindeutig und traversal-frei.

`<version>` ist die exakte SemVer-Version ohne `v`-Präfix.

## `/index.json`

```json
{
  "generated": "2026-08-08T12:00:00Z",
  "packages": [
    {
      "name": "net/http",
      "latest": "1.3.0",
      "description": "HTTP client and server for Lyx",
      "versions": ["1.2.0", "1.3.0"]
    }
  ]
}
```

Wird lokal unter `~/.lpm/registry/index.json` gespiegelt, TTL 1 h (siehe
`lpm/core/cache.lyx`). Mit `--offline` wird ausschließlich der Spiegel benutzt,
unabhängig vom Alter.

## `/packages/<name>/index.json`

```json
{
  "name": "net/http",
  "versions": [
    {
      "version": "1.2.0",
      "sha256": "e3b0c442...",
      "source": "https://lpm.seolizer.de/packages/net/http/1.2.0.lxpkg",
      "publisher": "roene",
      "dependencies": { "std/io": ">=1.0.0", "std/buffer": "^2.1.0" },
      "yanked": false
    }
  ]
}
```

Diese Datei ist die Auflösungsquelle für Constraints — der Resolver braucht
Versionsliste *und* Dependencies, ohne jedes Archiv herunterzuladen.

`yanked: true` bedeutet: nicht mehr für neue Auflösungen wählbar, aber weiter
herunterladbar, damit bestehende `lyx.lock` reproduzierbar bleiben.

## Fehlerfälle

Der Client unterscheidet nur nach HTTP-Status:

| Status | Bedeutung für `lpm` |
|--------|---------------------|
| 200 | ok |
| 304 | Spiegel ist aktuell (`If-None-Match`) |
| 404 | Paket bzw. Version existiert nicht |
| 4xx sonst | Client-Fehler, Abbruch mit Meldung |
| 5xx | Registry-Fehler, Abbruch (kein Retry in M2) |

## Integrität

`sha256` aus `/packages/<name>/index.json` wird beim Download geprüft und in
`lyx.lock` gepinnt. Weicht ein späterer Download vom Lock-Eintrag ab, bricht
`lpm` ab — der Lock-Eintrag hat immer Vorrang gegenüber der Registry-Angabe.
Signaturprüfung (`SIGNATURE.ed25519`, `/keys/<publisher>.pub`) folgt in PM-10.

---

## Serverseite

Webroot: `/var/www/lpm` auf `188.166.163.186` (Debian 12, nginx 1.22.1,
Let's-Encrypt-Cert, Cloudflare davor).

Anforderungen an den vhost:

- `autoindex off` (Default) — kein Directory-Listing
- `.lxpkg` → `application/octet-stream`, `expires max` (Archive sind
  unveränderlich: eine Version wird nie überschrieben)
- `index.json` → `application/json`, **kein** langes Caching; `ETag` +
  `Cache-Control: no-cache`, sonst liefert Cloudflare veraltete Indizes aus
- gzip für `application/json`; `.lxpkg` ist bereits zstd-komprimiert und darf
  nicht doppelt komprimiert werden
