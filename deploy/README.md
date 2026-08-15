# Registry-Server einrichten

Die Registry ist statisch: `lpm install` liest ausschließlich Dateien über
`GET`. Serverseitige Logik gibt es an genau einer Stelle — dem Publish-Endpunkt
unter `/api/`. Das ist Absicht, siehe [`../docs/registry.md`](../docs/registry.md).

Diese Anleitung beschreibt den Stand auf `lpm.seolizer.de` (Debian 12,
nginx 1.22.1, php8.2-fpm, Cloudflare davor).

---

## 1. Verzeichnisse

```bash
sudo install -d -o www-data -g www-data /var/www/lpm
sudo install -d -o www-data -g www-data /var/www/lpm/packages
sudo install -d -o www-data -g www-data /var/www/lpm/keys
sudo install -d -o www-data -g www-data /var/www/lpm/api/v1
sudo install -d -o www-data -g www-data /var/www/lpm/api/lib
```

Der Webroot muss `www-data` gehören: der Publish-Endpunkt legt dort Archive und
Indizes an.

## 2. API ausrollen

```bash
scp deploy/api/lib/registry.php  root@<server>:/var/www/lpm/api/lib/
scp deploy/api/v1/publish.php    root@<server>:/var/www/lpm/api/v1/
scp deploy/lpm-token             root@<server>:/usr/local/sbin/
ssh root@<server> 'chown -R www-data:www-data /var/www/lpm/api \
                   && chmod 644 /var/www/lpm/api/*/*.php \
                   && chmod 755 /usr/local/sbin/lpm-token'
```

## 3. vhost

`deploy/nginx-lpm.seolizer.de.conf` nach
`/etc/nginx/sites-available/lpm.seolizer.de`, verlinken, prüfen, neu laden:

```bash
sudo ln -sf /etc/nginx/sites-available/lpm.seolizer.de /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

Die drei Regeln, auf die es ankommt:

- **PHP läuft nur unter `/api/`.** Außerhalb gibt `location ~ \.php$` ein 404
  zurück. Ohne diese Regel würde eine `.php`-Datei in einem hochgeladenen Paket
  zu Remote Code Execution.
- **`index.json` wird nicht gecacht** (`Cache-Control: no-cache`), sonst liefert
  Cloudflare nach einem `publish` weiter den alten Index aus.
- **`.lxpkg` ist unveränderlich** (`immutable`, ein Jahr) und wird nicht
  gzip-komprimiert — zstd ist schon drin.

## 4. Token anlegen

Dafür gibt es `lpm-token`. Es legt `/etc/lpm/tokens.json` bei Bedarf an, setzt
die Rechte und schreibt nur SHA256-Hashes hinein — **nie den Token selbst**.
Wer die Datei liest, kann damit nichts hochladen.

```console
# lpm-token add anna net/ crypto/
Token für 'anna' (net/, crypto/):

  3f9a…  (64 Hexziffern)

Dieser Token wird NICHT gespeichert und lässt sich nicht erneut anzeigen.
```

Die Namensräume sind erlaubte Präfixe. Ein Token mit `net/` kann `net/http`
veröffentlichen, `crypto/tls` aber nicht — so lässt sich Namensraum-Übernahme
verhindern, ohne für jeden Publisher einen eigenen Server zu brauchen. Ohne
Angabe (`lpm-token add anna`) gilt `*`, also alles.

```console
# lpm-token list                 # wer hat Zugang?
PUBLISHER            NAMENSRÄUME
roene                *
anna                 net/, crypto/

# lpm-token remove anna          # Zugang entziehen
```

Ein entzogener Zugang lässt bereits veröffentlichte Pakete unberührt — sie
gehören zur Registry, nicht zum Token. Ein verlorener Token lässt sich nicht
wiederherstellen: entziehen und neu ausstellen.

Von Hand ginge es auch; das Format ist
`{"<sha256 des tokens>": {"publisher": "...", "namespaces": ["..."]}}`,
Eigentümer `root:www-data`, Modus `640`.

## 5. Prüfen

```bash
curl -s https://lpm.seolizer.de/index.json | head
curl -s -o /dev/null -w '%{http_code}\n' -X POST https://lpm.seolizer.de/api/v1/publish   # 401 erwartet
```

`401` ist hier das gute Ergebnis: der Endpunkt lebt und verlangt einen Token.

---

## Der Publish-Endpunkt

`POST /api/v1/publish`

| Kopfzeile | Pflicht | Inhalt |
|---|---|---|
| `Authorization: Bearer <token>` | ja | |
| `X-Lpm-Name` | ja | `net/http` |
| `X-Lpm-Version` | ja | `1.2.0` |
| `X-Lpm-Sha256` | nein | wird gegen den Rumpf geprüft, nicht übernommen |
| `X-Lpm-Deps` | nein | JSON-Objekt, z. B. `{"std/io":">=1.0.0"}` |
| `X-Lpm-Desc` | nein | eine Zeile, landet im Gesamtindex |

Der Rumpf ist das rohe `.lxpkg` (kein multipart). Antwort ist JSON.

| Status | Bedeutung |
|---|---|
| 201 | aufgenommen, Antwort enthält `sha256` und `source` |
| 400 | Name, Version, Deps oder Archiv unbrauchbar |
| 401 | Token fehlt oder ist unbekannt |
| 403 | Token darf unter diesem Namen nicht veröffentlichen |
| 409 | Version existiert bereits — Archive sind unveränderlich |
| 413 | größer als 32 MiB |

Nach jedem erfolgreichen Upload wird `/packages/<name>/index.json` ergänzt und
`/index.json` vollständig neu gebaut. Beides über `rename()`, damit ein
gleichzeitiger `GET` nie eine halb geschriebene Datei sieht.

### Was der Endpunkt bewusst nicht tut

- **Er packt das Archiv nicht aus.** Ein Server, der fremde Archive entpackt,
  ist ein Server, der fremde Pfade anlegt. Geprüft werden Größe und die
  zstd-Magic-Bytes; der Inhalt interessiert erst den Client.
- **Er überschreibt nie eine Version.** Wer sich vertan hat, veröffentlicht
  eine neue Version. Ein `lyx.lock` mit gepinntem SHA256 muss auch in einem
  Jahr noch stimmen.
- **Er nimmt keine Signaturen entgegen.** Ed25519/TOFU ist WP-PM-10; bis dahin
  ist der Token die einzige Zugangskontrolle.

---

## Von Hand veröffentlichen

Ohne Client geht es auch:

```bash
curl -X POST https://lpm.seolizer.de/api/v1/publish \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-Lpm-Name: net/http" \
  -H "X-Lpm-Version: 0.2.0" \
  -H 'X-Lpm-Deps: {"std/io":">=1.0.0"}' \
  -H "X-Lpm-Desc: HTTP client for Lyx" \
  --data-binary @net_http-0.2.0.lxpkg
```

Mit Client ist es `lpm login --token=<token>` einmalig, danach `lpm publish`.

## Ein Paket zurückziehen

Dafür gibt es noch keinen Endpunkt. Von Hand:

```bash
# yanked auf true setzen ...
sudo -u www-data nano /var/www/lpm/packages/net/http/index.json

# ... und den Gesamtindex neu bauen lassen
sudo -u www-data php -r 'require "/var/www/lpm/api/lib/registry.php"; lpm_rebuild_root_index();'
```

Das Archiv bleibt liegen — `yanked` heißt „nicht mehr für neue Auflösungen
wählbar", nicht „gelöscht". Bestehende `lyx.lock` müssen reproduzierbar bleiben.
