# lpm — Lyx Package Manager

`lpm` ist der Paketmanager für die Programmiersprache [Lyx](https://github.com/SEOLizer/LyX-Compiler).
Er verwaltet Abhängigkeiten, löst SemVer-Constraints auf, hält einen lokalen
Paket-Cache vor und dient `lyxc` als Auflösungs-Hook für unbekannte Imports.

`lpm` ist selbst vollständig in Lyx geschrieben und wird mit `lyxc` übersetzt.

> **Status: frühe Entwicklung (v0.1.0).** Funktionsfähig sind `init`, `cache list`
> und `cache clean`. Alle netzwerkabhängigen Befehle sind noch Stubs — siehe
> [Roadmap](#roadmap).

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
| `lpm install [<pkg>]` | Paket bzw. alle Dependencies installieren | ⬜ |
| `lpm remove <pkg>` | Paket aus `lyx.toml` entfernen | ⬜ |
| `lpm update [<pkg>]` | Paket(e) aktualisieren | ⬜ |
| `lpm search <query>` | Registry durchsuchen | ⬜ |
| `lpm info <pkg>` | Paketdetails anzeigen | ⬜ |
| `lpm list` | Installierte Pakete anzeigen | ⬜ |
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
```

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
| M1 Offline-Grundlage | PM-01 – PM-03 | `init`, `cache`, lokale Pakete | 🟡 in Arbeit |
| M2 Install-Flow | PM-04 – PM-06 | `install` gegen Registry | ⬜ |
| M3 Compiler-Integration | PM-07 | `lyxc` löst Imports automatisch auf | ⬜ |
| M4 Ökosystem-Launch | PM-08 – PM-10 | `publish` + öffentliche Registry | ⬜ |
| M5 Enterprise | PM-11 | Monorepo-/Workspace-Support | ⬜ |

**Aktueller Stand:** PM-01 (SemVer, Manifest, Lock-File) und PM-02
(CLI-Grundstruktur) sind abgeschlossen. PM-03 ist bis auf den
`.lxpkg`-Entpacker (tar.zst) fertig.

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
make clean    # Binary entfernen
```

Beim Testen von `cache clean` empfiehlt sich ein separates `HOME`, damit der
eigene Cache unangetastet bleibt:

```bash
mkdir -p /tmp/lpmtest
HOME=/tmp/lpmtest ./bin/lpm cache list
```

### Bekannte Fallstricke

- **`&&` schließt in `lyxc` nicht kurz.** `if p != null && deref(p)` wertet
  beide Seiten aus und segfaultet bei Nullzeigern. Null-Checks müssen
  geschachtelt werden.
- `lpmInit` schreibt `lyx.toml` bewusst direkt per `open`/`sys_write` statt über
  die Manifest-Klasse — dort besteht noch ein Allokationsproblem.

---

## Lizenz

MIT © Andreas Röne
