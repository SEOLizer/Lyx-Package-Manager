# lpm — Handbuch

Vom leeren Verzeichnis bis zum veröffentlichten Paket. Alle Beispiele sind
gegen `lpm 0.1.0` und `https://lpm.seolizer.de` ausgeführt.

Die Serverseite (Registry einrichten, vhost, Token-Datei) steht in
[`../deploy/README.md`](../deploy/README.md).

---

## Inhalt

1. [Installieren](#1-installieren)
2. [Ein Projekt anlegen und Pakete benutzen](#2-ein-projekt-anlegen-und-pakete-benutzen)
3. [Ein eigenes Paket bauen](#3-ein-eigenes-paket-bauen)
4. [Einen Token bekommen](#4-einen-token-bekommen)
5. [Anmelden](#5-anmelden)
6. [Veröffentlichen](#6-veröffentlichen)
7. [Alltag](#7-alltag)
8. [Wenn etwas schiefgeht](#8-wenn-etwas-schiefgeht)
9. [Was noch nicht geht](#9-was-noch-nicht-geht)

---

## 1. Installieren

Vorausgesetzt wird eine gebaute `lyxc`-Toolchain (≥ 1.0.19E) aus dem
`aurum`-Repository.

```bash
git clone git@github.com:SEOLizer/lyx-lpm.git
cd lyx-lpm
make
make install PREFIX=~/.local      # ohne sudo, wenn ~/.local/bin im PATH liegt
```

Prüfen:

```console
$ lpm --version
lpm 0.1.0
```

Systemweit ginge `sudo make install` (nach `/usr/local/bin`), entfernen mit
`make uninstall` unter Angabe desselben `PREFIX`.

> **Nach jedem `make` neu installieren.** Sonst läuft im Terminal weiter die
> alte Fassung, während `bin/lpm` im Repo schon die neue ist.

---

## 2. Ein Projekt anlegen und Pakete benutzen

```console
$ mkdir hallo && cd hallo
$ lpm init --name=demo/hello
lpm: Paket 'demo/hello' initialisiert.
lpm: lyx.toml wurde erstellt.
```

Das erzeugt ein Manifest:

```toml
[package]
name        = "demo/hello"
version     = "0.1.0"
author      = ""
license     = "MIT"
description = ""

[build]
entry       = "main.lyx"
```

Ein Paket suchen und einbinden:

```console
$ lpm search http
  net/http@0.3.0 — HTTP client for Lyx
lpm: 1 Treffer.

$ lpm install net/http
lpm: hole net/http@0.3.0 von https://lpm.seolizer.de
lpm: net/http@0.3.0 entpackt (3 Datei(en)).
lpm: 1 Paket(e) aufgeloest, lyx.lock geschrieben.
```

`install` trägt die Abhängigkeit in `lyx.toml` ein, löst sie auf, lädt das
Archiv, prüft dessen SHA256 und entpackt es nach `~/.lpm/cache/`. Das Ergebnis
steht in `lyx.lock`.

**Version festlegen** — ohne Angabe steht `"latest"` im Manifest, also „immer
die neueste":

```console
$ lpm install net/http@^0.3.0     # empfohlen: SemVer-Range
$ lpm install net/http@0.3.0      # exakt diese Version
```

Unterstützt werden `^`, `~`, `>=` und exakte Versionen. Für Reproduzierbarkeit
sorgt in jedem Fall `lyx.lock` — die Datei gehört ins Repository, sie pinnt
jede Version samt Prüfsumme.

Weitere Befehle:

```console
$ lpm list                 # was ist aufgelöst?
$ lpm info net/http        # Beschreibung, latest, alle Versionen
$ lpm remove net/http      # aus lyx.toml entfernen, Lock neu schreiben
```

---

## 3. Ein eigenes Paket bauen

Ein Paket ist ein Verzeichnis mit einem `lyx.toml` und mindestens einer Unit:

```
mein-paket/
  lyx.toml
  http.lyx          ← die Unit
  docs/readme.md    ← darf mit, muss nicht
```

Das Manifest braucht mindestens `name` und `version`:

```toml
[package]
name        = "net/http"
version     = "1.2.0"
description = "HTTP client for Lyx"       # erscheint in lpm search
license     = "MIT"
author      = "Anna Beispiel <anna@example.org>"

[dependencies]
"std/io" = ">=1.0.0"

[build]
entry = "http.lyx"        # welche Datei die Unit ist
```

**Paketnamen** sind hierarchisch und passen damit zur Import-Syntax:
`net/http` wird zu `import net.http`. Erlaubt sind `[a-z0-9_/.-]` — keine
Großbuchstaben, keine Leerzeichen, keine `..`-Segmente.

**`entry`** sagt `lpm resolve`, welche Datei die Unit ist. Fehlt der Eintrag,
wird `<letztes Namenssegment>.lyx` gesucht, danach `.lyu`.

Bauen:

```console
$ lpm pack
lpm: net/http@1.2.0 gepackt:
  ./net_http-1.2.0.lxpkg
  sha256 6e8701a7f14e40ca549e760c2f65127f65ca2d502000ae8101bc468d4754645b
  611 Bytes
```

Das Archiv ist ein `tar.zst` und **reproduzierbar**: zweimal gepackt ergibt
denselben SHA256. Dateien werden sortiert aufgenommen, Zeitstempel und Rechte
sind fest — sonst wäre die Prüfsumme in `lyx.lock` nur eine Momentaufnahme.

**Was nicht mit eingepackt wird:** versteckte Dateien (auch `.git`),
`lyx.lock` (gehört dem Anwender), `bin/`, und bereits vorhandene `.lxpkg`.
Alles andere kommt mit, bis Verzeichnistiefe 4.

Zusätzlich landet ein **`FILES.sha256`** im Archiv, mit einer Zeile je Datei.

Wer nur lokal testen will, braucht die Registry nicht:

```console
$ lpm cache add ./net_http-1.2.0.lxpkg net/http 1.2.0
lpm: net/http@1.2.0 entpackt nach /home/du/.lpm/cache/net/http/1.2.0
```

---

## 4. Einen Token bekommen

Zum Lesen der Registry braucht es nichts. Wer **veröffentlichen** will, braucht
einen Token vom Betreiber der Registry.

### Als Publisher

Frag den Betreiber von `lpm.seolizer.de` nach einem Token und nenne dabei den
Namensraum, unter dem du veröffentlichen willst (z. B. `net/`). Du bekommst
eine Zeichenkette aus 64 Hexziffern. **Sie wird genau einmal angezeigt** — der
Server speichert nur ihren Hash und kann sie nicht erneut ausgeben.

### Als Betreiber

Auf dem Registry-Server, als root:

```console
# lpm-token add anna net/ crypto/
Token für 'anna' (net/, crypto/):

  3f9a…  (64 Hexziffern)

Dieser Token wird NICHT gespeichert und lässt sich nicht erneut anzeigen.
```

Ohne Namensraum-Angabe (`lpm-token add anna`) darf der Token überall
veröffentlichen. Mit Angabe gilt die Grenze wirklich:

```console
$ curl … -H "X-Lpm-Name: net/boese" …
{"ok": false, "error": "Dieser Token darf unter \"net/boese\" nicht veroeffentlichen."}
```

Weitere Befehle:

```console
# lpm-token list                 # wer hat Zugang?
# lpm-token remove anna          # Zugang entziehen
```

Ein entzogener Zugang lässt bereits veröffentlichte Pakete unberührt — sie
gehören zur Registry, nicht zum Token.

Das Skript liegt im Repo unter `deploy/lpm-token` und gehört nach
`/usr/local/sbin/lpm-token`. Es schreibt nur SHA256-Hashes nach
`/etc/lpm/tokens.json`; wer die Datei liest, kann damit nichts hochladen.

---

## 5. Anmelden

Einmalig je Registry:

```console
$ lpm login --token=3f9a…
lpm: angemeldet an https://lpm.seolizer.de
     Token in ~/.lpm/credentials (nur fuer dich lesbar).
```

Die Datei bekommt Modus `0600` und enthält eine Zeile je Registry:

```
https://lpm.seolizer.de 3f9a…
```

Für eine andere Registry:

```console
$ lpm login --token=… --registry=https://registry.intern.example
```

Beide Anmeldungen bestehen nebeneinander; welche gilt, entscheidet
`--registry` beim jeweiligen Aufruf.

Abmelden:

```console
$ lpm logout                                        # Standard-Registry
$ lpm logout --registry=https://registry.intern.example
```

> Der Token wird von `lpm` nirgends ausgegeben — auch nicht gekürzt und auch
> nicht unter `--verbose`. Ein Protokoll, das du weitergibst, enthält ihn also
> nicht.

---

## 6. Veröffentlichen

Im Paketverzeichnis:

```console
$ lpm publish
lpm: veroeffentliche net/http@1.2.0 an https://lpm.seolizer.de
lpm: net/http@1.2.0 ist veroeffentlicht.
     sha256 6e8701a7f14e40ca549e760c2f65127f65ca2d502000ae8101bc468d4754645b
```

`publish` **packt immer frisch** — ein herumliegendes `.lxpkg` wird nicht
hochgeladen. Sonst veröffentlicht man irgendwann den Stand von vorgestern.

Mitgeschickt werden Name, Version, Prüfsumme, die Dependencies aus dem
Manifest und die Beschreibung. Der Server prüft die Prüfsumme gegen den
empfangenen Rumpf, legt das Archiv ab und aktualisiert beide Indizes.

**Eine veröffentlichte Version lässt sich nicht überschreiben:**

```console
$ lpm publish
lpm: error: diese Version ist bereits veroeffentlicht
     HTTP 409
```

Für eine Korrektur wird die Versionsnummer erhöht. Der Grund: ein `lyx.lock`
mit gepinntem SHA256 muss auch in einem Jahr noch stimmen.

Danach ist das Paket sofort für alle da:

```console
$ lpm search http
  net/http@1.2.0 — HTTP client for Lyx
```

---

## 7. Alltag

| Aufgabe | Befehl |
|---|---|
| Paketliste auffrischen | `lpm update` |
| Suchen (Name und Beschreibung) | `lpm search <text>` |
| Details und Versionen | `lpm info <paket>` |
| Gecachte Pakete anzeigen | `lpm cache list` |
| Cache leeren | `lpm cache clean` |
| Ohne Netz arbeiten | jeder Befehl mit `--offline` |
| Andere Registry | jeder Befehl mit `--registry=<url>` |
| Mehr Ausgabe | `--verbose` |

**Der Index wird eine Stunde lang gespiegelt.** Innerhalb dieser Zeit fragt
`search` und `info` nicht erneut nach; `lpm update` umgeht die Frist.

**`--offline`** benutzt ausschließlich den lokalen Cache und den vorhandenen
Spiegel. Fehlt dort etwas, sagt `lpm` das dazu:

```console
$ lpm install --offline
lpm: error: Paket nicht in der Registry — net/http (^1.0.0)
     --offline ist gesetzt — gesucht wurde nur im lokalen Spiegel.
```

### Abhängigkeiten von Abhängigkeiten

Das läuft von selbst. Fordert dein Projekt `demo/top`, und das braucht
`demo/mid`, das wiederum `demo/base`:

```console
$ lpm install
lpm: hole demo/base@1.0.0 …
lpm: hole demo/mid@1.1.0 …
lpm: hole demo/top@2.0.0 …
lpm: 3 Paket(e) aufgeloest, lyx.lock geschrieben.
```

Fordern zwei Pakete dasselbe Paket in unvereinbaren Versionen, bricht `lpm`
ab und nennt beide Bedingungen — und schreibt **kein** `lyx.lock`:

```console
lpm: error: unvereinbare Versions-Constraints — demo/base (^1.0.0 gegen ^2.0.0)
```

---

## 8. Wenn etwas schiefgeht

| Meldung | Bedeutung |
|---|---|
| `nicht angemeldet — erst 'lpm login --token=<token>' ausfuehren` | Kein Token für diese Registry hinterlegt |
| `nicht angemeldet oder nicht berechtigt (lpm login)` | Token abgelehnt (401) oder Namensraum verboten (403) |
| `diese Version ist bereits veroeffentlicht` | 409 — Versionsnummer erhöhen |
| `Paket nicht in der Registry` | Name unbekannt; mit `--offline` nur der lokale Spiegel durchsucht |
| `unvereinbare Versions-Constraints` | Zwei Pakete fordern Unvereinbares, beide Bedingungen werden genannt |
| `SHA256 stimmt nicht` | Heruntergeladenes Archiv passt nicht zu `lyx.lock`. Wird gelöscht, nie entpackt |
| `kein lyx.toml in diesem Verzeichnis` | `lpm pack`/`publish` im falschen Verzeichnis |
| `lyx.toml ist unvollstaendig` | `name` oder `version` fehlt |
| `keine Datei zum Einpacken gefunden` | Alles im Verzeichnis fällt unter die Ausschlüsse |
| `Registry-Index ist beschaedigt` | Spiegel unbrauchbar — `lpm update` holt ihn neu |
| `Antwort der Registry zu gross` | Index über 4 MiB bzw. Archiv über 64 MiB |

Bei Netzproblemen hilft `--verbose`: es zeigt, welche URL geholt wird.

Der lokale Zustand liegt vollständig unter `~/.lpm/`:

```
~/.lpm/
  cache/         entpackte Pakete
  registry/      gespiegelte Indizes (+ .meta mit dem Zeitstempel)
  tmp/           Archive während des Downloads
  credentials    Token, Modus 0600
```

Im Zweifel: `lpm cache clean` und `~/.lpm/registry/` löschen — beides wird beim
nächsten Aufruf neu aufgebaut. Die Anmeldung bleibt dabei erhalten;
`credentials` fasst nur `lpm login` und `lpm logout` an.

---

## 9. Was noch nicht geht

Ehrlichkeit an dieser Stelle spart Suchzeit:

- **Keine Signaturen.** Die Prüfsumme sichert den Transportweg, nicht die
  Herkunft: wer die Registry kontrolliert, kontrolliert die Pakete. Ed25519
  mit Trust-on-first-use ist geplant (WP-PM-10).
- **Kein Zurückziehen über den Client.** `yanked` muss der Betreiber von Hand
  setzen, siehe `deploy/README.md`.
- **`lpm update` aktualisiert keine Pakete**, sondern nur den Index. Eine neue
  Version bekommst du, indem du das Constraint in `lyx.toml` änderst und
  `lpm install` aufrufst — oder `lyx.lock` löschst.
- **Der Compiler ruft `lpm` noch nicht selbst auf.** `lpm resolve` liegt
  bereit, die Compiler-Seite fehlt (siehe `compiler-integration.md`).
- **Keine `[dev-dependencies]`-Auswertung.** Das Feld wird geparst, aber bei
  der Auflösung nicht berücksichtigt.
- **Der Resolver geht greedy vor**, ohne Backtracking: er nimmt je Paket die
  höchste passende Version und nimmt eine Wahl nicht zurück, wenn sie später
  in eine Sackgasse führt. Dann kommt ein Konflikt, obwohl eine ältere
  Version des Elternpakets gepasst hätte. Abhilfe: das Constraint selbst
  enger fassen.
