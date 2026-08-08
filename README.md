# lpm — Lyx Package Manager

`lpm` ist der Paketmanager für die Programmiersprache [Lyx](https://github.com/SEOLizer/LyX-Compiler).
Er verwaltet Abhängigkeiten, löst SemVer-Constraints auf, hält einen lokalen
Paket-Cache vor und dient `lyxc` als Auflösungs-Hook für unbekannte Imports.

`lpm` ist selbst vollständig in Lyx geschrieben und wird mit `lyxc` übersetzt.

> **Status: frühe Entwicklung (v0.1.0).** Funktionsfähig sind `init`, `cache list`,
> `cache clean`, `cache add` (lokales `.lxpkg` entpacken) sowie `install`,
> `remove` und `list` gegen lokale Katalog-Quellen. Es fehlt der Download: der
> HTTPS-Transport zur Registry ist noch nicht angebunden — siehe
> [Roadmap](#roadmap).
>
> Benötigt `lyxc` ≥ 1.0.13J.

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

Das Ergebnis ist ein statisch gelinktes Binary unter `bin/lpm` (~120 KB, keine
Laufzeit-Abhängigkeiten).

Die Pfade zu `lyxc` und zum `aurum`-Baum stehen oben im `Makefile` und müssen
gegebenenfalls angepasst werden:

```make
LYXC   := /pfad/zu/aurum/lyxc
AURUM  := /pfad/zu/aurum
```

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
| `lpm install [<pkg[@ver]>]` | Auflösen und `lyx.lock` schreiben (ohne Download) | ⚠️ |
| `lpm remove <pkg>` | Paket aus `lyx.toml` entfernen, Lock neu schreiben | ✅ |
| `lpm update [<pkg>]` | Paket(e) aktualisieren | ⬜ |
| `lpm search <query>` | Registry durchsuchen | ⬜ |
| `lpm info <pkg>` | Paketdetails anzeigen | ⬜ |
| `lpm list` | Aufgelöste Pakete aus `lyx.lock` anzeigen | ✅ |
| `lpm publish` | Paket in die Registry hochladen | ⬜ |
| `lpm login` | Registry-Authentifizierung | ⬜ |
| `lpm resolve <pkg>` | Import-Auflösung für `lyxc` (intern) | ⬜ |

Globale Optionen:

| Flag | Wirkung |
|------|---------|
| `--verbose`, `-v` | Ausführliche Ausgabe |
| `--offline` | Kein Netzwerkzugriff, nur Cache |
| `--registry=<url>` | Alternative Registry verwenden (Default: `https://lpm.seolizer.de`) |
| `--version`, `-V` | Version ausgeben |
| `--help`, `-h` | Hilfe anzeigen |

### Beispiel

```console
$ lpm init --name=net/http
lpm: Paket 'net/http' initialisiert.
lpm: lyx.toml wurde erstellt.

$ lpm cache list
Gecachte Pakete in /home/user/.lpm/cache:
  buffer@2.1.3
  net/http@1.2.0
  net/http@1.3.0

3 Paket-Version(en).
```

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
  tests/
    resolver_test.lyx      Resolver-Tests (ohne Netz und Dateisystem)
    index_test.lyx         Parser-Tests
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
| M2 Install-Flow | PM-04 – PM-06 | `install` gegen Registry | 🟡 in Arbeit |
| M3 Compiler-Integration | PM-07 | `lyxc` löst Imports automatisch auf | ⬜ |
| M4 Ökosystem-Launch | PM-08 – PM-10 | `publish` + öffentliche Registry | ⬜ |
| M5 Enterprise | PM-11 | Monorepo-/Workspace-Support | ⬜ |

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

PM-06 (`install`, `remove`, `list`) ist verdrahtet: Manifest → Katalog →
Resolver → `lyx.lock`. Der Katalog kommt aus zwei lokalen Quellen — den
entpackten Paketen unter `~/.lpm/cache/` (Dependencies aus deren `lyx.toml`)
und den gespiegelten Registry-Indizes unter `~/.lpm/registry/packages/`.
Ein zweites `lpm install` ohne Änderung rechnet nicht neu. Was aufgelöst ist,
aber lokal fehlt, wird ausgewiesen statt heruntergeladen — der Download hängt
am fehlenden HTTPS-Transport.

Von PM-05 ist der Index-Parser fertig (`lpm/core/index.lyx`): er liest
`/packages/<name>/index.json` in den `DepCatalog`, überspringt `yanked`-Versionen,
überliest unbekannte Schlüssel und lehnt einen Index ab, dessen `name` nicht zum
angeforderten Paket passt. Der HTTPS-Transport fehlt noch — siehe unten, `getpid`
wird vom seccomp-Filter blockiert, sobald OpenSSL ins Spiel kommt.

Ursprünglich war eine Go-Implementierung für V1 mit späterem Port nach Lyx
vorgesehen. Das Self-Hosting wurde vorgezogen — `lpm` ist von Beginn an in Lyx
geschrieben.

---

## Sicherheit

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
make test     # Resolver-Tests bauen und ausführen
make clean    # Binary entfernen
```

`make test` braucht weder Netzwerk noch Schreibrechte und ist deshalb vom
seccomp-Problem unten nicht betroffen.

Beim Testen von `cache clean` empfiehlt sich ein separates `HOME`, damit der
eigene Cache unangetastet bleibt:

```bash
mkdir -p /tmp/lpmtest
HOME=/tmp/lpmtest ./bin/lpm cache list
```

### Bekannte Fallstricke

- **`std.fs` macht das Binary dynamisch gelinkt**
  ([LyX-Compiler#1191](https://github.com/SEOLizer/LyX-Compiler/issues/1191)):
  die Unit enthält ein `extern fn time(...) link "libc.so.6"`, das nur `MkTemp`
  und `TmpFile` brauchen — die Referenz allein genügt aber. `lpm` ist deshalb
  seit `lyxc` 1.0.13J nicht mehr statisch (193 KB → 240 KB), verliert RELRO und
  landet bei 40/45 statt 45/45.
- **HTTPS ist weiterhin nicht nutzbar.** Der `getpid`-Block ist mit 1.0.13J weg,
  der Verbindungsaufbau über `std.net.https` hängt aber (Timeout ohne Antwort).
  Ursache noch nicht eingegrenzt.
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
