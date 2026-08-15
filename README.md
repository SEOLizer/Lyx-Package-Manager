# lpm — Lyx Package Manager

`lpm` ist der Paketmanager für die Programmiersprache [Lyx](https://github.com/SEOLizer/LyX-Compiler).
Er verwaltet Abhängigkeiten, löst SemVer-Constraints auf, hält einen lokalen
Paket-Cache vor und dient `lyxc` als Auflösungs-Hook für unbekannte Imports.

`lpm` ist selbst vollständig in Lyx geschrieben und wird mit `lyxc` übersetzt.

> **Status: frühe Entwicklung (v0.1.0).** Der Install-Flow läuft end-to-end gegen
> die öffentliche Registry: leerer Cache → Index per HTTPS → Auflösung → Archiv
> per HTTPS → SHA256-Prüfung → entpackt im Cache. Funktionsfähig sind `init`,
> `cache list`, `cache clean`, `cache add`, `install`, `remove`, `list`,
> `update`, `search`, `info`, `resolve`, `pack`, `publish`, `login` und
> `logout` — der Kreis von `pack` über `publish` bis zur Installation auf einem
> fremden Rechner ist geschlossen. Offen sind Signaturen (PM-10) und die
> Compiler-Integration (PM-07, Compiler-Seite).
>
> Benötigt `lyxc` ≥ 1.0.19E (HTTPS; ohne Netzwerknutzung genügt 1.0.13J).

---

## Vision

Ein Lyx-Projekt importiert eine Unit, die lokal nicht vorliegt:

```lyx
import net.http;
```

`lyxc` erkennt den fehlenden Import und ruft `lpm` auf:

```
→ lpm resolve net/http
→ Paket wird aufgelöst, heruntergeladen, verifiziert und gecacht
→ Kompilierung läuft weiter
```

---

## Installation

Vorausgesetzt wird eine gebaute `lyxc`-Toolchain aus dem `aurum`-Repository.

```bash
git clone git@github.com:SEOLizer/lyx-lpm.git
cd lyx-lpm
make
```

Das Ergebnis ist ein Binary unter `bin/lpm`. Es ist gegen `libssl`/`libcrypto`
dynamisch gelinkt, seit `std.net.https` für den Registry-Zugriff dazugekommen
ist — siehe [Sicherheit](#sicherheit).

Die Pfade zu `lyxc` und zum `aurum`-Baum stehen oben im `Makefile` und müssen
gegebenenfalls angepasst werden:

```make
LYXC   := /pfad/zu/aurum/lyxc
AURUM  := /pfad/zu/aurum
```

Damit `lpm` ohne Pfadangabe aufrufbar ist:

```bash
make install PREFIX=~/.local      # ohne sudo, wenn ~/.local/bin im PATH liegt
sudo make install                 # systemweit nach /usr/local/bin
make uninstall                    # wieder entfernen (gleiches PREFIX angeben)
```

`DESTDIR` wird beachtet, für Paketbau oder eine Installation ins Leere:
`make install DESTDIR=/tmp/stage`.

---

## Verwendung

```bash
lpm <subcommand> [optionen]
```

| Befehl | Beschreibung | Status |
|--------|--------------|--------|
| `lpm init [--name=<name>]` | Neues Paket anlegen (erstellt `lyx.toml`) | ✅ |
| `lpm cache list` | Gecachte Pakete anzeigen | ✅ |
| `lpm cache clean` | Lokalen Cache leeren | ✅ |
| `lpm cache add <f> <n> <v>` | Lokales `.lxpkg` in den Cache entpacken | ✅ |
| `lpm install [<pkg[@ver]>]` | Auflösen, herunterladen, verifizieren, `lyx.lock` schreiben | ✅ |
| `lpm remove <pkg>` | Paket aus `lyx.toml` entfernen, Lock neu schreiben | ✅ |
| `lpm update` | Registry-Gesamtindex neu spiegeln | ✅ |
| `lpm search <query>` | Registry durchsuchen (Name und Beschreibung) | ✅ |
| `lpm info <pkg>` | Paketdetails und Versionsliste anzeigen | ✅ |
| `lpm list` | Aufgelöste Pakete aus `lyx.lock` anzeigen | ✅ |
| `lpm pack [<dir>]` | Verzeichnis zu einem reproduzierbaren `.lxpkg` packen | ✅ |
| `lpm publish [<dir>]` | Paket packen und in die Registry laden | ✅ |
| `lpm login --token=<t>` | Registry-Token in `~/.lpm/credentials` ablegen | ✅ |
| `lpm logout` | Token wieder entfernen | ✅ |
| `lpm resolve <pkg> [--entry]` | Import-Auflösung für `lyxc` (intern) | ✅ |

Globale Optionen:

| Flag | Wirkung |
|------|---------|
| `--verbose`, `-v` | Ausführliche Ausgabe |
| `--offline` | Kein Netzwerkzugriff, nur Cache |
| `--registry=<url>` | Alternative Registry verwenden (Default: `https://lpm.seolizer.de`) |
| `--version`, `-V` | Version ausgeben |
| `--help`, `-h` | Hilfe anzeigen |

### Ein Paket benutzen

```console
$ lpm search http
  net/http@0.3.0 — HTTP client for Lyx
lpm: 1 Treffer.

$ lpm install net/http
lpm: hole net/http@0.3.0 von https://lpm.seolizer.de
lpm: net/http@0.3.0 entpackt (3 Datei(en)).
lpm: 1 Paket(e) aufgeloest, lyx.lock geschrieben.

$ lpm list
Aufgeloeste Pakete (lyx.lock):
  net/http@0.3.0
```

### Ein Paket veröffentlichen

Einmalig anmelden — den Token stellt der Registry-Betreiber aus:

```console
$ lpm login --token=<token>
lpm: angemeldet an https://lpm.seolizer.de
     Token in ~/.lpm/credentials (nur fuer dich lesbar).
```

Dann im Paketverzeichnis, das ein `lyx.toml` mit `name` und `version` enthält:

```console
$ lpm publish
lpm: veroeffentliche net/http@0.3.0 an https://lpm.seolizer.de
lpm: net/http@0.3.0 ist veroeffentlicht.
     sha256 75e7cbae5eaa87a569d1edd626647d260d14f97df1888facfbb6c8ae3c92f23c
```

`publish` packt immer frisch — ein herumliegendes Archiv wird nicht verwendet.
Wer nur das Archiv braucht, nimmt `lpm pack`; zweimal gepackt ergibt denselben
SHA256. Eine veröffentlichte Version lässt sich nicht überschreiben; für eine
Korrektur wird die Versionsnummer erhöht.

Die Serverseite steht in [`deploy/README.md`](deploy/README.md).

---

## Paket-Format

### Manifest (`lyx.toml`)

```toml
[package]
name        = "net/http"
version     = "1.2.0"
author      = "Andreas Röne <andreas@example.com>"
license     = "MIT"
description = "HTTP client and server for Lyx"

[dependencies]
"std/io"     = ">=1.0.0"
"std/buffer" = "^2.1.0"
"crypto/tls" = "1.4.2"

[dev-dependencies]
"std/test" = "^1.0.0"

[build]
entry = "http.lyu"
```

Unterstützte SemVer-Ranges: `^`, `~`, `>=`, `=`.
Paketnamen sind hierarchisch (`net/http`) und passen damit direkt zur
Lyx-Import-Syntax. Erlaubte Zeichen: `[a-z0-9_/.-]`.

### Lock-Datei (`lyx.lock`)

Wird automatisch erzeugt und sollte eingecheckt, aber nicht von Hand bearbeitet
werden. Sie pinnt jede aufgelöste Version samt SHA256 des Archivs.

```toml
[[package]]
name    = "net/http"
version = "1.2.0"
sha256  = "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
source  = "https://lpm.seolizer.de/packages/net/http/1.2.0.lxpkg"
```

### Paket-Archiv (`.lxpkg`)

Ein `.lxpkg` ist ein deterministisch erzeugtes `tar.zst`-Archiv:

```
net_http_1.2.0.lxpkg
  ├── lyx.toml            ← Manifest
  ├── http.lyu            ← Quell-Unit(s)
  ├── SIGNATURE.ed25519   ← Publisher-Signatur
  └── FILES.sha256        ← Prüfsummen aller Dateien
```

### Cache-Layout

```
~/.lpm/
  cache/
    net/http/1.2.0/       ← entpacktes Paket
    buffer/2.1.3/
  registry/
    index.json            ← lokaler Registry-Spiegel (TTL 1 h)
  keys/
    trusted.pub           ← vertrauenswürdige Publisher-Keys
```

Flache (`buffer/2.1.3`) und hierarchische (`net/http/1.2.0`) Namespaces
existieren nebeneinander. Ein Verzeichnis gilt als Versions-Ebene, wenn sein
Name mit einer Ziffer beginnt — dadurch kommt der Cache ohne Zusatzmetadaten aus.

---

## Projektstruktur

```
lyx-lpm/
  main.lyx                 Einstiegspunkt
  lyx.toml                 Manifest von lpm selbst
  Makefile
  lpm/
    cli/
      args.lyx             Argument-Tokenizer, LpmCtx
      commands.lyx         Subcommand-Dispatch
    core/
      semver.lyx           SemVer-Parser, Comparator, Range-Matching
      manifest.lyx         lyx.toml-Parser, Manifest-Struct
      lockfile.lyx         lyx.lock lesen und schreiben
      dirent.lyx           Verzeichnis-Listing mit Index-Zugriff
      cache.lyx            Lokaler Paket-Cache unter ~/.lpm/
      archive.lyx          .lxpkg entpacken (zstd + tar), SHA256-Pruefung
      resolver.lyx         Versions-Katalog, Auflösung, Konflikte, Topo-Sort
      index.lyx            Parser für die Registry-Indizes (JSON)
      localcat.lyx         Katalog aus Cache und gespiegelten Indizes
      fetch.lyx            HTTPS-Transport, Spiegel, TTL, Upload
      pack.lyx             .lxpkg bauen (reproduzierbar)
      credentials.lyx      Registry-Token in ~/.lpm/credentials
  deploy/
    nginx-lpm.seolizer.de.conf   vhost der Registry
    api/lib/registry.php         Validierung, Index-Pflege
    api/v1/publish.php           POST /api/v1/publish
    README.md                    Server einrichten
  tests/
    resolver_test.lyx      Resolver-Tests (ohne Netz und Dateisystem)
    index_test.lyx         Parser-Tests
    fetch_test.lyx         Namens-, Versions- und URL-Regeln, Upload-Vorprüfungen
    pack_test.lyx          Reproduzierbarkeit, Archivinhalt, Ausschlüsse
    credentials_test.lyx   Token-Ablage, Rechte, mehrere Registries
```

### Grenzen des Resolvers

Die Auflösung ist greedy und ohne Backtracking: pro Paket wird die höchste
Version genommen, die alle bisher gesammelten Constraints erfüllt. Führt eine
solche Wahl später in eine Sackgasse, wird sie nicht zurückgenommen.

Praktisch heißt das: fordert `net/http@latest` in Version 2.0.0 ein
`std/io@^3.0.0`, das es nicht gibt, meldet `lpm` einen Konflikt — statt auf
`net/http@1.2.0` zurückzufallen, das mit dem vorhandenen `std/io` liefe.
Abhilfe bis dahin: das Constraint selbst enger fassen
(`lpm install net/http@^1.0.0`). Backtracking ist bewusst zurückgestellt;
die Spec sieht für V1 ausdrücklich Greedy vor.

### Warum der `std.*`-Baum

`aurum` hat zwei Unit-Bäume: `src.std.*` (11 Units) und `std.*` (deutlich größer).
Sie exportieren dieselben Namen und lassen sich nicht gemeinsam importieren —
schon `import src.std.io` neben `import std.zstd` scheitert mit „mehrdeutiges
Symbol" für `alloc`, `StrCmp`, `PrintIntLn` und rund ein Dutzend weitere.

`lpm` startete auf `src.std.*`, ist aber auf `std.*` portiert, weil dort alles
liegt, was der Paketmanager braucht: `std.tar`, `std.zstd`, `std.crypto.sha256`,
`std.net.https`. Der Umbau kostete das Umbiegen der Imports plus zwei Stellen
(`FileOpen`/`FileRead`/`FileClose` → `ReadFile`); `StrLen` und `ArgvGet` sind
Compiler-Intrinsics und in beiden Bäumen verfügbar.

### Warum ein eigenes `dirent.lyx`?

`aurum` liefert zwei Filesystem-Units: `src.std.fs` und `std.fs`. Sie
überschneiden sich in 19 exportierten Symbolen (`DirList`, `FileExists`,
`Mkdir`, `PathJoin`, …) und lassen sich deshalb nicht gemeinsam importieren.
`src.std.fs.DirList` liefert eine Hashmap ohne Index-Zugriff, `std.fs` hätte die
passende API — ist aber für `lpm` nicht erreichbar.

`lpm/core/dirent.lyx` ruft daher `getdents64` direkt auf und baut einen eigenen
flachen Puffer mit sortierter Offset-Tabelle. Das hält `aurum` unverändert und
macht Cache-Listings deterministisch.

---

## Roadmap

Der ausführliche Fahrplan liegt im `aurum`-Repository unter
`work/packagemanager.md`.

| Meilenstein | Work Packages | Ergebnis | Status |
|-------------|---------------|----------|--------|
| M1 Offline-Grundlage | PM-01 – PM-03 | `init`, `cache`, lokale Pakete | ✅ |
| M2 Install-Flow | PM-04 – PM-06 | `install` gegen Registry | 🟡 fast fertig |
| M3 Compiler-Integration | PM-07 | `lyxc` löst Imports automatisch auf | 🟡 in Arbeit |
| M4 Ökosystem-Launch | PM-08 – PM-10 | `publish` + öffentliche Registry | ⬜ |
| M5 Enterprise | PM-11 | Monorepo-/Workspace-Support | ⬜ |

> **Zur WP-Nummerierung:** die Commits `008a511` und `1155cf2` sprechen von
> „PM-07", meinen aber den Archiv-Download — der gehört laut Fahrplan zu
> **WP-PM-06** (Schritte 4–6 des Install-Flows). WP-PM-07 ist die
> Compiler-Integration und noch offen. Die Historie bleibt wie sie ist,
> maßgeblich ist diese Tabelle.

**Aktueller Stand:** PM-01 (SemVer, Manifest, Lock-File) und PM-02
(CLI-Grundstruktur) sind abgeschlossen. PM-03 ist abgeschlossen — der `.lxpkg`-Entpacker liegt in
`lpm/core/archive.lyx` und ist mit aktivem seccomp end-to-end verifiziert:
entpacken, SHA256-Prüfung, Abwehr von Pfad-Traversal, danach `install` gegen
das gecachte Paket. Damit ist **M1 vollständig**. Möglich wurde das durch den
Fix zu [LyX-Compiler#1185](https://github.com/SEOLizer/LyX-Compiler/issues/1185)
in `lyxc` 1.0.13J.

PM-04 (Resolver) ist implementiert und getestet: `lpm/core/resolver.lyx` löst
transitive Abhängigkeiten auf, wählt je Paket die höchste Version, die *alle*
gesammelten Constraints erfüllt, meldet Diamant-Konflikte mit beiden
unvereinbaren Constraints und sortiert topologisch. 28 Tests, `make test`.
Der Resolver hängt nicht am Netzwerk: seine Datenquelle ist ein `DepCatalog`,
den PM-05 aus dem Registry-Index füllt und die Tests direkt befüllen.

PM-05 ist fertig, in zwei Teilen. Der Index-Parser (`lpm/core/index.lyx`) liest
`/packages/<name>/index.json` in den `DepCatalog`, überspringt
`yanked`-Versionen, überliest unbekannte Schlüssel und lehnt einen Index ab,
dessen `name` nicht zum angeforderten Paket passt. Der Transport
(`lpm/core/fetch.lyx`) holt Index und Archiv per HTTPS und schreibt sie über
`.tmp` + `Rename` in den Spiegel unter `~/.lpm/registry/`. Geparst wird
weiterhin aus dem Spiegel — der Resolver sieht keinen Unterschied zwischen
„gerade geholt" und „war schon da", und `--offline` heißt schlicht, dass der
Transport nicht aufgerufen wird.

PM-06 ist verdrahtet: Manifest → Katalog → Resolver → Download → Verify →
Cache → `lyx.lock`. Der Katalog kommt aus dem lokalen Cache und den
gespiegelten Indizes; fehlt ein Paket, wird sein Index nachgeladen und erneut
aufgelöst — transitive Abhängigkeiten tauchen erst auf, wenn der Index ihres
Elternpakets gelesen ist. Was aufgelöst ist und nicht im Cache liegt, wird
heruntergeladen, gegen den `sha256` aus `lyx.lock` geprüft und erst dann
entpackt. Ein zweites `lpm install` ohne Änderung rechnet nicht neu.

Von PM-07 ist die lpm-Seite fertig: `lpm resolve <pkg>` löst auf, lädt bei
Bedarf und schreibt **genau eine Zeile** auf stdout — ohne `--entry` das
Paketverzeichnis, mit `--entry` die Unit-Datei darin. Exit 0 heißt „liegt im
Cache, Pfad gilt". Alle Meldungen gehen nach stderr, auch die von `--verbose`,
damit der Compiler stdout direkt als Pfad lesen kann. Importnamen werden
übersetzt (`net.http` → `net/http`), ein vorhandener Lock-Eintrag hat Vorrang
vor der neuesten Version.

Die Änderungen an `lyxc` selbst gehören ins Compiler-Repository und sind hier
bewusst nicht umgesetzt. Was dafür nötig wäre — Fundstellen, Stolperstellen und
der vollständige Aufrufvertrag — steht in
[`docs/compiler-integration.md`](docs/compiler-integration.md).

`lpm search` und `lpm info` lesen den Gesamtindex `/index.json`; der Spiegel
bekommt eine TTL von einer Stunde (Zeitstempel in einer `.meta` neben jeder
gespiegelten Datei), `lpm update` umgeht sie ausdrücklich. Damit ist M2
inhaltlich abgeschlossen — offen bleibt die ETag-Revalidierung, die an einem
stdlib-Bug hängt (siehe unten).

Ursprünglich war eine Go-Implementierung für V1 mit späterem Port nach Lyx
vorgesehen. Das Self-Hosting wurde vorgezogen — `lpm` ist von Beginn an in Lyx
geschrieben.

---

## Sicherheit

Bereits umgesetzt:

- SHA256 des Archivs wird gegen `lyx.lock` geprüft, **bevor** entpackt wird.
  Stimmt er nicht, wird das Archiv gelöscht und nie ausgepackt
- Der Download-Pfad wird aus Name und Version nach `docs/registry.md` gebildet,
  **nicht** aus dem `source`-Feld des Index — ein manipulierter Index kann den
  Download so nicht auf einen fremden Host umlenken
- Paketnamen und Versionen werden auf Pfad-Traversal geprüft, bevor sie in einen
  URL- oder Dateipfad gehen; ebenso jeder Pfad im tar-Archiv
- `http://` als Registry wird abgelehnt statt still auf TLS hochgestuft

Bekannter Trade-off: mit `std.net.https` kommt OpenSSL per FFI ins Binary, damit
ist `lpm` dynamisch gelinkt und verliert RELRO — Sicherheits-Score 30/35 statt
35/35 ohne Netzwerk. W^X, seccomp, landlock und Stack Canaries bleiben aktiv.
Ein Rückweg zu RELRO hieße statisches `libssl` oder ein eigener TLS-Client.

Geplant für PM-10:

- SHA256-Prüfsummen je Datei (`FILES.sha256`) und für das Gesamtarchiv
- Pinning der Archiv-Prüfsumme in `lyx.lock`
- Ed25519-Signaturen des Publishers
- Trust-on-first-use: Publisher-Key landet bei der ersten Installation in
  `~/.lpm/keys/trusted.pub`
- Reproducible Builds: deterministische `.lxpkg`-Archive
- Schutz vor Name-Squatting: Namespaces werden nur einmal vergeben

---

## Entwicklung

```bash
make          # nach bin/lpm bauen
make test     # Resolver-, Index- und Fetch-Tests bauen und ausführen
make clean    # Binary entfernen
```

`make test` läuft ohne Netzwerk — 202 Fälle in fünf Suiten. Geprüft wird alles,
was sich ohne Gegenstelle entscheiden lässt: Auflösung, Index-Parser, sowie
Namens-, Versions- und URL-Regeln, also genau die Entscheidungen, die *vor* dem
ersten Byte auf der Leitung fallen.

`pack_test` und `credentials_test` brauchen als einzige das Dateisystem. Sie
arbeiten unter `tests-tmp/` bzw. `tests-tmp-cred/` im Arbeitsverzeichnis und
räumen selbst auf; die Credentials-Suite benutzt ein eigenes `HOME`, damit sie
die echten Zugangsdaten nie anfasst.

Der Transport selbst und `publish` hängen an einer erreichbaren Registry und
werden von Hand geprüft (`lpm update`, `lpm install`, `lpm publish`).

Beim Testen von `cache clean` empfiehlt sich ein separates `HOME`, damit der
eigene Cache unangetastet bleibt:

```bash
mkdir -p /tmp/lpmtest
HOME=/tmp/lpmtest ./bin/lpm cache list
```

### Bekannte Fallstricke

- **HTTPS braucht `network.udp.connect`** — und das ist kein Tippfehler: die
  Namensauflösung vor jedem Request läuft über UDP. Der seccomp-Filter wird aus
  den `@capabilities` abgeleitet, fehlt die UDP-Capability, stirbt der Prozess
  direkt nach `socket(AF_INET, SOCK_DGRAM)` an SIGSYS — ohne Meldung, ohne
  Rückgabewert. Die vollständige Liste für `std.net.https`:
  `network.tcp.connect`, `network.udp.connect`, `memory.mmap`, `fs.read`,
  `fs.meta`. `fs.meta` braucht OpenSSL für den CA-Store unter
  `/usr/lib/ssl/certs`.
- **`std.net.https` löst `Transfer-Encoding: chunked` nicht auf**
  ([#1539](https://github.com/SEOLizer/LyX-Compiler/issues/1539)): der Rumpf
  kommt mit Chunk-Rahmen durchsetzt an, sobald der Server chunked sendet — bei
  Cloudflare größenabhängig. `lpm` löst das selbst auf (`ftDechunk`), erkannt an
  der Form statt am Header, weil `headersRaw` fehlt (siehe unten).
- **`std.net.https` liefert keine Response-Header**
  ([#1534](https://github.com/SEOLizer/LyX-Compiler/issues/1534)): `headersRaw`
  und `headersSize` werden nie gesetzt — es gibt also keine Quelle für den
  `ETag`, und `HTTPResponseFree` ruft `munmap` auf einen undefinierten Zeiger.
  In `lpm` ist die ETag-Revalidierung deshalb vorbereitet, aber abgeschaltet:
  `If-None-Match` wird gesendet und `304` ausgewertet, die TTL trägt die
  Revalidierung solange allein.
- **`std.fs.FileModTime` liefert immer 0**
  ([#1533](https://github.com/SEOLizer/LyX-Compiler/issues/1533)): gelesen wird
  Offset 80 des `stat`-Puffers, dort steht auf x86_64 `st_atime_nsec` statt
  `st_mtime` (Offset 88). `lpm` liest die mtime deshalb selbst, sonst wäre die
  Index-TTL nie abgelaufen.
- **`system.time` ist eine eigene Capability** — fehlt sie, stirbt jeder Aufruf,
  der die Uhr braucht, wortlos an SIGSYS. Gilt für alles, was `std.time`
  benutzt.
- **Historisch, alle behoben:** `std.fs` zog früher über
  `extern fn time ... link "libc.so.6"` einen Dynamiklink in jedes Programm
  ([#1191](https://github.com/SEOLizer/LyX-Compiler/issues/1191)); `sys_sendto`
  und `sys_recvfrom` bekamen im `flags`-Argument Müll
  ([#1192](https://github.com/SEOLizer/LyX-Compiler/issues/1192)); `setsockopt`
  fehlte im seccomp-Filter
  ([#1193](https://github.com/SEOLizer/LyX-Compiler/issues/1193)), ebenso
  `fcntl`, `poll` und `uname`. Seit `lyxc` 1.0.19E läuft HTTPS durch.
- **`chmod` braucht seit `lyxc` 1.0.13J die Capability `fs.perm`** — ohne sie
  SIGSYS. `lpm` benutzt es nicht, aber die Regel gilt für jeden Konsumenten.
- **`&&` schließt in `lyxc` nicht kurz.** `if p != null && deref(p)` wertet
  beide Seiten aus und segfaultet bei Nullzeigern. Null-Checks müssen
  geschachtelt werden.
- `lpmInit` schreibt `lyx.toml` bewusst direkt per `open`/`sys_write` statt über
  die Manifest-Klasse — dort besteht noch ein Allokationsproblem.

---

## Lizenz

MIT © Andreas Röne
