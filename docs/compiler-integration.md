# Compiler-Integration (WP-PM-07)

Dieses Dokument beschreibt, was `lpm` für die automatische Import-Auflösung
bereitstellt und was auf der Compiler-Seite dafür nötig wäre. **Die Änderungen
an `lyxc` sind hier nicht umgesetzt** — sie gehören ins Compiler-Repository und
liegen beim Compiler-Team. Was hier steht, ist die Schnittstelle von `lpm` aus
gesehen, samt der Fundstellen, die eine Umsetzung betreffen würde.

Stand: 2026-08-15, `lpm` 0.1.0, `lyxc` 1.0.19E.

---

## Das Ziel

```lyx
import net.http;      // liegt lokal nicht vor
```

```
lyxc main.lyx
  → Import nicht auffindbar
  → lpm resolve net.http
  → lpm lädt das Paket und meldet, wo es liegt
  → lyxc setzt dort fort
```

---

## Was `lpm` liefert

### `lpm resolve <name> [--entry]`

Löst ein Paket auf, lädt es bei Bedarf herunter, prüft den SHA256 und entpackt
es in den Cache. Der Aufrufvertrag ist bewusst eng gehalten, damit ein
Compiler die Ausgabe ohne Parser verwenden kann:

| | |
|---|---|
| **stdout** | bei Erfolg **genau eine Zeile**: ein Pfad, mit `\n` abgeschlossen. Sonst nichts |
| **stderr** | alle Meldungen — Fortschritt, Warnungen, Fehler, auch unter `--verbose` |
| **Exit 0** | Paket liegt im Cache, der Pfad auf stdout ist gültig |
| **Exit 1** | nicht auflösbar; stdout bleibt leer |

Ohne `--entry` ist die Ausgabe das **Paketverzeichnis**:

```console
$ lpm resolve net.http
/home/user/.lpm/cache/net/http/0.1.0
```

Mit `--entry` ist es die **Unit-Datei**:

```console
$ lpm resolve net.http --entry
/home/user/.lpm/cache/net/http/0.1.0/http.lyx
```

### Warum `--entry` der interessantere Modus ist

`lyxc` sucht zu `import net.http` eine *Datei* — nach heutiger Logik
`<root>/net/http.lyx` (`src/sema.lyx:1746-1757`). Der Cache liefert aber ein
*Verzeichnis*:

```
~/.lpm/cache/net/http/0.1.0/
  lyx.toml
  http.lyx          ← die Unit
  FILES.sha256
```

Ein Include-Root, unter dem `net/http.lyx` läge, gibt es nicht — und soll es
auch nicht geben, weil im Cache mehrere Versionen desselben Pakets
nebeneinander liegen. `--entry` löst das auf der `lpm`-Seite auf: gesucht wird
zuerst `[build] entry` aus der `lyx.toml` des Pakets, dann
`<letztes Namenssegment>.lyx`, dann dasselbe mit `.lyu`. Der Compiler muss das
Paketlayout damit nicht kennen.

### Weitere Eigenschaften

- **Importnamen werden übersetzt.** `net.http` → `net/http`. Enthält der
  übergebene Name schon einen Slash, bleibt er unverändert. Der Compiler kann
  den Importnamen also unverändert durchreichen.
- **Der Lock-Eintrag hat Vorrang.** Steht das Paket in `lyx.lock` des aktuellen
  Verzeichnisses, wird dessen Version genommen, nicht die neueste. Eine
  Kompilierung sieht damit dasselbe wie ein `lpm install`.
- **Cache-Hit ist billig.** Liegt die gelockte Version im Cache, antwortet
  `lpm resolve` ohne Netzzugriff und ohne die Uhr anzufassen — gemessen 19 ms.
  Ein Aufruf pro unaufgelöstem Import ist damit vertretbar.
- **`--offline` wird respektiert.** Dann wird nur der lokale Cache befragt.
- **`--registry=<url>`** wählt eine andere Registry.

---

## Was auf der Compiler-Seite nötig wäre

Die folgenden Fundstellen beziehen sich auf `aurum` @ `878189b5`.

### Der Import wird an drei Stellen unabhängig aufgelöst

| Stelle | Fundstelle | Suchpfad |
|---|---|---|
| Sema | `src/sema.lyx:1700` (`_sema_processImport`), Kaskade in `_sema_readFile` `src/sema.lyx:1420` | CWD → `-I` → `--std-path` → `/usr/include/lyx/units` |
| Codegen | `src/codegen_x86.lyx:16732` (`cg_processImportAs`) | eigene Kaskade, liest Imports ein **zweites** Mal |
| IR-Lowering | `src/ir_lower.lyx:5065-5090` | nur relativ, kein Fallback |

Ein Hook nur in Sema würde die Kompilierung später im Codegen scheitern
lassen, weil der Import dort erneut gesucht wird.

Die Fehlerstelle, an der ein Hook ansetzen könnte:
`src/sema.lyx:1804` — `"Modul nicht gefunden (weder .lyx noch .lyu)"`.

### `includePath` ist heute ein einzelner Pfad

`src/sema.lyx:417` und `src/codegen_x86.lyx:790` halten je einen `pchar`, keine
Liste. „Den Include-Pfad ergänzen", wie der Fahrplan es formuliert, hat also
kein bestehendes Konstrukt. Zwei Wege:

1. **Ein eigenes Feld** für den von `lpm` gelieferten Pfad, neben
   `includePath`. Kleiner Eingriff, kein Umbau an bestehendem Code.
2. **`includePath` zu einer echten Liste** ausbauen. Größerer Umbau, macht
   nebenbei mehrfaches `-I` für Menschen nutzbar.

Mit `--entry` entfällt die Frage teilweise: der Compiler bekommt einen fertigen
Dateipfad und muss ihn nur öffnen, statt einen Suchpfad zu erweitern.

### Subprozess-Bausteine sind vorhanden

`sys_fork`, `sys_execve`, `sys_pipe`, `sys_pipe2`, `sys_dup2`, `sys_dup3` und
`sys_wait4` sind als Builtins registriert (`src/sema.lyx:6229-6254`) und werden
emittiert (`src/codegen_x86.lyx:9923ff`, `10020ff`). Neue Intrinsics braucht es
nicht.

Im Compiler selbst wird bisher nur in `src/tooling/self_test.lyx:21-32` ein
Kindprozess gestartet — **ohne** dessen stdout zu lesen. Ein fertiges Muster
dafür steht in `std/process.lyx:161` (`SpawnWithPipes`), das `lyxc` heute nicht
importiert.

### Vorsicht bei `IRLower`

`src/ir_lower.lyx:5069-5072` hält fest, dass neue Methoden an `IRLower` die
vtable verschieben und der Seed-Compiler daran schon einmal gescheitert ist.

### Bootstrap

`lyxc` ist selbstkompilierend: Quelle `src/lyxc.lyx`, Seed
`src/lyxc_bootstrap`, Fixpunktprüfung `make singularity` (S3 == S4). Laut
`Makefile:5-14` muss der Seed neu verankert werden, sobald sich die **Ausgabe
des Codegens** ändert. Reine Frontend-/Sema-Änderungen kommen mit
`make build` + `make bootstrap` aus, solange die Singularitätsprüfung hält.

### Flags

Der Fahrplan sieht vor:

```
lyxc --pkg-resolve=auto     # Standard: resolve, wenn lyx.toml vorhanden
lyxc --pkg-resolve=always   # immer
lyxc --pkg-resolve=never    # nie (CI, Offline)
```

Parsing gehört nach `parseLongFlag` (`src/lyxc.lyx:751`); das Muster für
`--x=wert` gibt es dort bereits bei `--include-path=` (`src/lyxc.lyx:828-831`)
und `--std-path=` (`:833-836`). Hilfetext bei `src/lyxc.lyx:1399ff`.

---

## Empfohlener Ablauf im Compiler

```
Import nicht auffindbar
  └─ --pkg-resolve=never?         → bisheriger Fehler, Ende
  └─ auto und kein lyx.toml da?   → bisheriger Fehler, Ende
  └─ lpm resolve <importname> --entry
       ├─ Exit 0 → stdout ist der Pfad zur Unit-Datei; diese öffnen und
       │           fortsetzen. Den Pfad merken, damit Codegen denselben
       │           Import nicht erneut auflösen muss.
       └─ Exit 1 → bisheriger Fehler, ergänzt um die stderr-Ausgabe von lpm
```

Sinnvolle Absicherungen, die `lpm` nicht abnehmen kann:

- **Kein rekursiver Aufruf pro Import-Versuch.** Ein Import, den `lpm` schon
  einmal nicht auflösen konnte, sollte nicht erneut angefragt werden.
- **Ein Zeitlimit** für den Kindprozess: `lpm` geht ins Netz.
- **stdout strikt lesen.** Genau eine Zeile, führende und folgende Leerzeichen
  abschneiden, bei mehr als einer Zeile abbrechen statt zu raten. Das ist die
  Gegenprobe zum Vertrag oben.

---

## Wie sich das testen lässt, ohne den Compiler zu ändern

Der Vertrag ist vollständig von der Kommandozeile prüfbar:

```console
$ cd /tmp/leer && printf '[package]\nname="x"\nversion="0.1.0"\n' > lyx.toml

$ lpm resolve net.http --entry; echo "exit=$?"
/home/user/.lpm/cache/net/http/0.1.0/http.lyx
exit=0

$ lpm resolve net.http --entry 2>/dev/null | wc -l
1

$ lpm resolve gibts.nicht --entry; echo "exit=$?"
lpm: error: Paket nicht in der Registry — gibts/nicht (*)
lpm: error: gibts/nicht liess sich nicht aufloesen.
exit=1
```

Der dritte Fall zeigt beides: Exit 1 und leeres stdout.
