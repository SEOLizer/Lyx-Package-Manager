# lpm — Handbuch

Vom leeren Verzeichnis bis zum veröffentlichten Paket. Alle Beispiele sind
gegen `lpm 0.1.4` und `https://lpm.seolizer.de` ausgeführt.

Die Serverseite (Registry einrichten, vhost, Token-Datei) steht in
[`../deploy/README.md`](../deploy/README.md).

---

## Inhalt

1. [Installieren](#1-installieren)
2. [Ein Projekt anlegen und Pakete benutzen](#2-ein-projekt-anlegen-und-pakete-benutzen)
3. [Ein eigenes Paket bauen](#3-ein-eigenes-paket-bauen)
   — auch: [mehrere Units aufteilen](#mehrere-units--ein-paket-oder-mehrere)
4. [Einen Token bekommen](#4-einen-token-bekommen)
   — auch: [eigene Registry statt Token](#eine-eigene-registry-statt-eines-tokens)
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
lpm 0.1.4
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
wird `<letztes Namenssegment>.lyx` gesucht, danach `.lyu`. Ein Paket mit
mehreren einzeln importierbaren Units trägt zusätzlich `[build.units]` ein —
siehe „Mehrere Units" weiter unten.

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

### Warnung bei undeklarierten Imports

`pack` und `publish` durchsuchen die `.lyx`-Dateien nach `import`-Zeilen und
melden, was weder in `[dependencies]` steht noch zum Compiler gehört:

```console
$ lpm pack
lpm: warning: 2 Import(e) stehen nicht in [dependencies]:
     crypto/tls
     demo/base
     Wer sie aus der Registry bezieht, traegt sie ein:
     lpm install <paket>
lpm: demo/luecke@0.1.0 gepackt:
```

Das ist eine **Warnung, kein Fehler** — das Paket wird gebaut. `lpm` kann nicht
wissen, woher eine Unit kommen soll; gemeint ist der vergessene Eintrag.

Nicht gemeldet werden Units, die mit dem Compiler kommen (`std.*`, `data.*`,
`src.*`), das eigene Paket samt allem darunter (`demo.luecke.intern` in
`demo/luecke`), und Units, die als Datei im selben Paket liegen — der Fall
„mehrere Units in einem Paket" von oben.

Wer nur lokal testen will, braucht die Registry nicht:

```console
$ lpm cache add ./net_http-1.2.0.lxpkg net/http 1.2.0
lpm: net/http@1.2.0 entpackt nach /home/du/.lpm/cache/net/http/1.2.0
```

### Mehrere Units — ein Paket oder mehrere?

**Ein Paket ist immer ein Verzeichnis.** Der Manifestname `lyx.toml` liegt
fest; zwei Manifeste nebeneinander im selben Ordner gibt es nicht.

Das ist mehr als eine Formalie, denn `lpm pack` nimmt **alles** aus dem
Verzeichnis. Liegen vier Units flach beieinander:

```
demo/
  lyx.toml        name = "demo/http", entry = "http.lyx"
  http.lyx
  json.lyx
  tls.lyx
  buffer.lyx
```

dann enthält das gebaute Archiv alle vier — `entry` wählt nicht aus, sondern
benennt nur die Haupt-Unit:

```console
$ lpm pack && zstd -dc demo_http-1.0.0.lxpkg | tar -t
buffer.lyx
http.lyx
json.lyx
lyx.toml
tls.lyx
FILES.sha256
```

(Die Reihenfolge ist alphabetisch — daher steht `lyx.toml` mitten drin und
`FILES.sha256` als Erzeugtes am Schluss.)

Die Frage ist deshalb nicht „wie teile ich die Dateien auf", sondern:
**sollen die Units einzeln importierbar sein?**

**Ja — dann je ein Verzeichnis.**

```
projekte/
  http/     lyx.toml (demo/http)     http.lyx
  json/     lyx.toml (demo/json)     json.lyx
  tls/      lyx.toml (demo/tls)      tls.lyx
  buffer/   lyx.toml (demo/buffer)   buffer.lyx
```

Abhängigkeiten untereinander stehen in den jeweiligen `[dependencies]`, ganz
normal. Veröffentlicht wird je Verzeichnis:

```bash
for d in http json tls buffer; do (cd "$d" && lpm publish); done
```

**Nein — dann ein Paket mit mehreren Dateien.** Sinnvoll, wenn `json.lyx` und
`buffer.lyx` Innereien von `demo/http` sind und niemand sie einzeln einbinden
soll.

**Beides — ein Paket, trotzdem einzeln importierbar.** Dafür gibt es
`[build.units]`:

```toml
[package]
name    = "vega"
version = "0.1.0"

[build]
entry = "vega.lyx"

[build.units]
types = "types.lyx"
core  = "core.lyx"
```

Der Schlüssel ist das Namenssegment **hinter** dem Paketnamen, der Wert die
Datei im Paketverzeichnis. `import vega.types` löst damit auf:

```console
$ lpm resolve vega.types --entry
/home/du/.lpm/cache/vega/0.1.0/types.lyx
```

Was dabei zu wissen ist:

- **Ein eigenständiges Paket hat Vorrang.** `lpm resolve vega.types` sucht
  zuerst ein Paket `vega/types`. Erst wenn es keins gibt, wird `vega` befragt
  und dort in `[build.units]` nachgeschlagen. Ein später veröffentlichtes
  `vega/types` übernimmt also, ohne dass jemand seine Importe ändert.
- **Der Schlüssel wird verglichen, wie er dasteht** — dieselbe Schreibweise
  wie im Import. Paketnamen sind ohnehin klein zu schreiben (siehe oben),
  also auch die Schlüssel.
- **`pack` prüft die Tabelle.** Zeigt ein Eintrag auf eine Datei, die es nicht
  gibt, bricht `lpm pack` ab — das ist ein Fehler, keine Warnung, denn beim
  Anwender wäre das Paket längst veröffentlicht.
- **Ohne `--entry`** kommt das Paketverzeichnis heraus, aber nur, wenn die
  Unit wirklich in der Tabelle steht. `lpm resolve vega.beliebig` schlägt
  fehl, statt kommentarlos das Verzeichnis von `vega` auszugeben.
- **Die Tabelle steht nur in der `lyx.toml` des Pakets**, nicht im
  Registry-Index. Sie wird beim Auflösen aus dem entpackten Paket gelesen —
  an der Registry ändert sich nichts.

### Importnamen vom Paketnamen trennen: `[build.provides]`

Ein Paket muss nicht so heißen wie der Import, den es bedient. `[build.provides]`
hält fest, welche Importnamen aus welchem Verzeichnis kommen:

```toml
[package]
name = "lyx-std-dev"

[build]
entry = "std/io.lyx"

[build.provides]
std  = "std"      # import std.fs            -> <paket>/std/fs.lyx
data = "data"     # import std.cloud.gcp.jwt -> <paket>/std/cloud/gcp/jwt.lyx
```

Der Schlüssel ist das **erste Segment** des Importnamens, der Wert das
Verzeichnis im Paket. Die restlichen Segmente werden zum Pfad darunter, also
auch mehrstufige Namen — `[build.units]` braucht dafür keinen Eintrag mehr.
Weil der Wert ein Verzeichnis ist, darf ein Paket seine Dateien anders anordnen
als sein Importname lautet (`vega = "src/vega"`).

**Gesucht wird im lokalen Cache.** `lpm resolve` liest
`~/.lpm/cache/<paket>/<version>/lyx.toml` und prüft dort `[build.provides]`.
Kein Serverumbau, und `--offline` ändert am Ergebnis nichts. Die Grenze gehört
dazu: **ein Paket, das noch nicht im Cache liegt, wird so nicht gefunden** —
`lpm install <paket>` muss einmal gelaufen sein. Der Registry-Index trägt die
bedienten Namen (noch) nicht.

**Vorrang**, in dieser Reihenfolge:

1. Ein Paket, dessen **Name** dem Importnamen entspricht. Alles Bestehende
   bleibt damit gültig.
2. `[build.units]` des zutreffenden Pakets.
3. `[build.provides]` eines gecachten Pakets.

Erst wenn im Cache nichts davon passt, wird die Registry gefragt. Bedienen
**zwei** gecachte Pakete denselben Importnamen, ist das ein Fehler mit beiden
Namen in der Meldung und nicht die Wahl des ersten Funds:

```console
$ lpm resolve std.fs --entry
lpm: error: Importname "std" wird von mehreren gecachten Paketen bedient:
     andere-std@0.0.1 ([build.provides] std = "std")
     lyx-std-dev@0.1.0 ([build.provides] std = "std")
     Eines der beiden Pakete aus dem Cache entfernen: lpm cache remove <name>
```

Ein Schlüssel darf selbst mehrstufig sein — dann wird er in der `lyx.toml`
gequotet, weil TOML den Punkt sonst als Tabellentrenner liest:

```toml
[build.units]
forms         = "vega/forms.lyx"
"backend.x11" = "vega/backend/x11.lyx"
```

`lpm resolve vega.backend.x11 --entry` probiert von der feinsten zur grobsten
Zerlegung: erst das Paket `vega/backend/x11`, dann `vega/backend` mit der Unit
`x11`, dann `vega` mit der Unit `backend.x11`. Ein eigenständiges Paket hat
also weiter Vorrang (Issue #2). Ein Schlüssel mit Slash wird beim Packen
abgelehnt: beim Verbraucher steht ein Importname mit Punkten.

### Grenzen eines Pakets

Gemessen an der Lyx-Standardbibliothek (`std`: 500 Units, 503 Dateien) gelten:

| Grenze | Wert | Ort |
|---|---|---|
| Dateien je Paket | 4096 | `PK_MAX_FILES`, `LPA_MAX_FILES` |
| Größe je Datei | 16 MiB | `PK_FILE_MAX` |
| entpackte Gesamtgröße | 64 MiB | `PK_TOTAL_MAX`, `LPA_TAR_MAX` |
| Verzeichnisebenen | 8 | `PK_DEPTH_MAX` |
| Einträge in `[build.units]` | 4096 | `MF_MAX_UNITS` |
| Einträge in `[build.provides]` | 64 | `MF_MAX_PROV` |
| Größe einer `lyx.toml` | 4 MiB | `MF_FILE_MAX` |

Jede Grenze ist laut: `lpm pack` bricht mit `PK_ERR_LIMIT` ab, ein zu tiefes
Verzeichnis ebenfalls, und ein `[build.units]` über der Grenze mit der Meldung,
wie viele Einträge nicht aufgenommen wurden. Ein Paket, dessen Manifest
Einträge verschweigt, entsteht nicht mehr (Issues #3, #4). Die Entpackseite
zieht dieselben Zahlen, damit ein fremdes Archiv nicht mehr mitbringen kann,
als dieses lpm packen würde.

Nicht eingetragene Mit-Units bleiben, was sie waren: nur innerhalb ihres
Pakets brauchbar. Wer `json.lyx` als Innerei von `demo/http` behalten will,
lässt es einfach aus `[build.units]` heraus.

Einen Workspace-Modus, der mehrere Pakete eines Repositories gemeinsam
verwaltet und in einem Rutsch veröffentlicht, gibt es noch nicht (WP-PM-11).
Bis dahin ist die Schleife oben der Weg.

---

## 4. Einen Token bekommen

Zum Lesen der Registry braucht es nichts. Wer **veröffentlichen** will, braucht
einen Token vom Betreiber der Registry.

### Es gibt keine Selbstregistrierung

`lpm.seolizer.de` liefert nur Paketdaten aus. Die Wurzel ist geschlossen:

```console
$ curl -o /dev/null -w '%{http_code}\n' https://lpm.seolizer.de/
403

$ curl -o /dev/null -w '%{http_code}\n' https://lpm.seolizer.de/index.json
200
```

Kein Anmeldeformular, keine Weboberfläche, kein Benutzerkonto. Token werden von
Hand vergeben — Absicht, denn der Namensraum eines Paketmanagers lässt sich
kaum zurückholen, wenn er einmal belegt ist.

### Als Publisher

Anfragen laufen über ein Issue im Repository:

  → https://github.com/SEOLizer/Lyx-Package-Manager/issues

Ein Titel, der mit `Token:` beginnt, macht die Anfrage auf den ersten Blick
erkennbar — etwa `Token: Namensraum net/`.

Vier Angaben gehören hinein, damit ohne Rückfragen entschieden werden kann:

| Angabe | Wozu |
|---|---|
| **Namensraum** (z. B. `net/`) | Der Token wird darauf eingeschränkt. Ohne Angabe gälte `*` — das wird nicht vergeben |
| **Publisher-Name** | Kurzname für die Token-Verwaltung; erscheint im Paketindex als `publisher` und ist damit öffentlich |
| **Zweck bzw. geplantes Paket** | Ein, zwei Sätze. Namensraum-Konflikte fallen so früh auf, nicht erst beim ersten `publish` |
| **Kontaktweg für die Übergabe** | Wohin der Token geschickt werden kann — siehe unten |

Beispiel:

```
Titel: Token: Namensraum net/

Namensraum:   net/
Publisher:    anna
Zweck:        HTTP-Client und ein kleiner URL-Parser; net/http und net/url
Kontakt:      anna@example.org
```

> **Der Token gehört nicht ins Issue.** Ein GitHub-Issue ist öffentlich und
> bleibt es auch nach dem Schließen. Im Issue wird nur die Anfrage verhandelt;
> der Token geht über den dort genannten Kontaktweg zurück. Wer versehentlich
> einen Token öffentlich gemacht hat, meldet das im selben Issue — er wird dann
> entzogen und neu ausgestellt.

Zurück kommt eine Zeichenkette aus 64 Hexziffern. **Sie wird genau einmal
angezeigt** — der Server speichert nur ihren Hash und kann sie nicht erneut
ausgeben. Geht sie verloren, wird der alte Zugang entzogen und ein neuer
ausgestellt; bereits veröffentlichte Pakete bleiben davon unberührt.

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

### Eine eigene Registry statt eines Tokens

Für Pakete, die gar nicht öffentlich sein sollen, ist der Umweg über einen
Token unnötig. Eine eigene Registry spricht `--registry=<url>` an — und rein
lokal geht es ganz ohne:

```console
$ lpm pack
$ lpm cache add ./demo_beispiel-1.2.0.lxpkg firma/intern 1.0.0
lpm: firma/intern@1.0.0 entpackt nach /home/du/.lpm/cache/firma/intern/1.0.0

$ lpm resolve firma.intern --entry --offline
/home/du/.lpm/cache/firma/intern/1.0.0/beispiel.lyx
```

Das Paket ist damit auflösbar, **obwohl es in keiner Registry steht** — auch
mit `--offline`. Für firmeninterne Units, Zwischenstände und Versuche ist das
der kürzeste Weg; ein Token wird erst gebraucht, wenn andere das Paket über das
Netz beziehen sollen.

Zu beachten: Name und Version gibt hier der Aufrufer an, sie müssen nicht zum
`lyx.toml` im Archiv passen. Welche Datei als Unit gilt, entscheidet weiterhin
`[build] entry`.

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
| Pakete aktualisieren | `lpm update` bzw. `lpm update <pkg>` |
| Suchen (Name und Beschreibung) | `lpm search <text>` |
| Details und Versionen | `lpm info <paket>` |
| Gecachte Pakete anzeigen | `lpm cache list` |
| Einzelnes Paket entfernen | `lpm cache remove <pkg> [<version>]` |
| Cache leeren | `lpm cache clean` |
| Ohne Netz arbeiten | jeder Befehl mit `--offline` |
| Andere Registry | jeder Befehl mit `--registry=<url>` |
| Mehr Ausgabe | `--verbose` |

### Aktualisieren

`lpm update` holt den Index frisch und löst danach neu auf — **innerhalb der
Constraints aus `lyx.toml`**. Geändert wird nur `lyx.lock`:

```console
$ lpm update
lpm: hole demo/base@1.5.0 von https://lpm.seolizer.de
  ~ demo/base 1.0.0 -> 1.5.0
lpm: 1 Aenderung(en).
```

Die Zeichen vor dem Namen: `~` geändert, `+` neu hinzugekommen, `-` weggefallen
(etwa weil eine neue Version des Elternpakets die Abhängigkeit nicht mehr hat).

Steht in `lyx.toml` `"demo/base" = "^1.0.0"`, dann kommt `update` bis `1.5.0`,
aber nicht auf `2.0.0` — dafür muss das Constraint selbst weiter gefasst
werden (`lpm install demo/base@^2.0.0`). Das Manifest rührt `update` nie an.

**Ein einzelnes Paket** aktualisieren, ohne den Rest zu bewegen:

```console
$ lpm update demo/base
```

Alle übrigen Einträge aus `lyx.lock` werden dabei auf ihrer Version
festgehalten.

### Der Cache wird geprüft

Fehlt ein in `lyx.lock` gepinntes Paket im Cache, meldet `lpm install` das und
holt es nach — auch dann, wenn `lyx.lock` zu `lyx.toml` passt:

```console
$ lpm install
lpm: vega@0.1.6 ist gelockt, liegt aber nicht im Cache.
lpm: hole vega@0.1.6 von https://lpm.seolizer.de
lpm: 1 Paket(e) aufgeloest, lyx.lock geschrieben.
```

Aufgelöst wird dabei auf die gelockten Versionen, nicht neu gewählt: jeder
Lock-Eintrag gilt als exakte Version. Mit `--offline` bleibt es bei der Meldung
`fehlt im Cache` und Rückgabewert 1.

Ein Paket aus `~/.lpm/cache` wird vor dem Benutzen gegen sein `FILES.sha256`
geprüft. Weicht eine Datei ab, wird das Paket verworfen und neu geholt:

```console
$ lpm install
lpm: warning: demo/base@1.0.0: Datei im Cache wurde veraendert (base.lyx)
     wird verworfen und neu geholt.
lpm: hole demo/base@1.0.0 von https://lpm.seolizer.de
```

Der sha256 in `lyx.lock` deckt nur den Weg vom Server bis in den Cache ab —
was danach dort passiert, sähe sonst niemand. Mit `--offline` bleibt es bei der
Meldung, weil die heile Fassung nicht erreichbar ist.

Pakete ohne `FILES.sha256` (von vor `lpm pack`) gelten als nicht prüfbar und
werden durchgelassen; unter `--verbose` steht ein Hinweis. Ein Signaturersatz
ist das nicht: die Liste liegt im selben Archiv wie die Dateien.

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
| `Datei im Cache wurde veraendert` | Ein Paket unter `~/.lpm/cache` weicht von seinem `FILES.sha256` ab. Es wird verworfen und neu geholt; mit `--offline` bleibt es bei der Meldung |
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
- **Der Compiler ruft `lpm` noch nicht selbst auf.** `lpm resolve` liegt
  bereit, die Compiler-Seite fehlt (siehe `compiler-integration.md`).
- **Keine `[dev-dependencies]`-Auswertung.** Das Feld wird geparst, aber bei
  der Auflösung nicht berücksichtigt.
- **Kein Workspace.** Mehrere Pakete in einem Repository müssen einzeln
  gebaut und veröffentlicht werden, je Verzeichnis ein Aufruf (WP-PM-11).
- **Der Resolver geht greedy vor**, ohne Backtracking: er nimmt je Paket die
  höchste passende Version und nimmt eine Wahl nicht zurück, wenn sie später
  in eine Sackgasse führt. Dann kommt ein Konflikt, obwohl eine ältere
  Version des Elternpakets gepasst hätte. Abhilfe: das Constraint selbst
  enger fassen.
