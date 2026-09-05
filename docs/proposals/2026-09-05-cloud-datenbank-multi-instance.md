# Proposal: Cloud-Datenbank Multi-Instance

| Datum | Benutzername | Kurzbeschreibung |
|---|---|---|
| 2026-09-05 | dermatthes | §§ 1–12: Proposal angelegt |

**Status:** Offen  
**Zielprojekt:** Phore ORM  
**Arbeitstitel:** Cloud-Datenbank Multi-Instance  
**Technische Basis:** PHP 8.1+, lokale SQLite-Datenbanken, Amazon S3 oder Google Cloud Storage

## § 1 Kurzfassung

Phore ORM soll optional mehrere schreibende Instanzen unterstützen, die jeweils eine lokale SQLite-Datenbank als schnell lesbare Materialisierung verwenden und einen gemeinsam erreichbaren Object Store als dauerhaften, append-only Transaktions-Log nutzen. Ein kleines, stark konsistentes Objekt `head.json` verweist auf den neuesten Commit. Jeder unveränderliche Commit verweist auf seinen Vorgänger und enthält eine vollständige logische ORM-Transaktion mit allen betroffenen Tabellen, Primärschlüsseln, Operationen, geänderten Spalten und Konfliktbedingungen.

Das Konzept ist unter klaren Grenzen machbar. Es ist jedoch keine Multi-Master-Replikation von SQLite-Dateien: Mehrere Instanzen dürfen Schreibvorgänge initiieren, aber ein bedingtes Überschreiben von `head.json` serialisiert alle globalen Commits. Der Object Store ist damit Commit-Log und globaler Compare-and-Swap-Punkt; SQLite bleibt pro Instanz eine lokale, ersetzbare Projektion. Das Modell ist für read-lastige Anwendungen mit geringer bis moderater Schreibrate interessant. Es eignet sich nicht für hohe Write-Raten, Offline-Merges, dauerhaft netzwerkunabhängige Writer oder Anwendungen, die bei einer Object-Store-Störung weiter global konsistent schreiben müssen.

Die in der Ausgangsidee genannte MySQL-Datenbank wird als offensichtlicher Versprecher behandelt: Nach erfolgreicher Veröffentlichung wird die Transaktion in die lokale SQLite-Datenbank eingespielt und deren lokaler Commit-Pointer atomar weitergesetzt.

## § 2 Zielbild und Grenzen

### § 2.1 Ziele

- Mehrere gleichberechtigte Anwendungsinstanzen können über denselben Bucket schreiben.
- Leseabfragen laufen nach einer kurzen Aktualitätsprüfung vollständig gegen lokales SQLite.
- Der Object Store enthält eine lückenlos prüfbare, unveränderliche Commit-Kette.
- Ein bedingter Schreibzugriff auf `head.json` verhindert verlorene Updates.
- Eine fachliche Transaktion mit Änderungen an mehreren Rows und Tabellen bleibt atomar.
- Neue oder lange getrennte Clients können aus einem Snapshot plus nachfolgenden Commits bootstrappen.
- Netzwerkfehler, unbekannte Request-Ausgänge und Prozessabstürze führen nicht zu doppelten fachlichen Änderungen.
- Der Storage-Adapter kapselt S3-ETags beziehungsweise GCS-Generationen hinter demselben Vertrag.

### § 2.2 Nicht-Ziele

Nicht Bestandteil des ersten Entwurfs sind konfliktfreie Offline-Änderungen, automatisches Zusammenführen divergenter Branches, schreibbare Replikate ohne Verbindung zum Object Store, Replikation beliebiger SQL-Verbindungen außerhalb von Phore ORM, das Teilen einer SQLite-Datei über ein Netzwerk-Dateisystem, Cross-Cloud-Consensus sowie eine garantierte hohe Schreibrate. Das System entscheidet bei konkurrierenden Änderungen nicht stillschweigend per Last-Write-Wins, sondern verlangt eine explizite Konfliktstrategie.

## § 3 Konsistenzmodell und Invarianten

Der Erfolg des Modells hängt von folgenden Invarianten ab:

1. `head.json` wird ausschließlich mit einer atomaren Compare-and-Swap-Vorbedingung ersetzt.
2. Commit- und Snapshot-Objekte sind unveränderlich und werden vor der Veröffentlichung des darauf zeigenden Heads vollständig hochgeladen.
3. Ein Commit enthält genau eine atomare logische Transaktion und genau einen `parentCommitId`.
4. Die Commit-ID ist inhaltsadressiert oder anderweitig global eindeutig; jeder Commit enthält zusätzlich einen kryptografischen Hash seines kanonisch serialisierten Inhalts.
5. Die lokale SQLite-Tabelle `_phore_replication_state` und die fachlichen Row-Änderungen werden in derselben lokalen SQLite-Transaktion aktualisiert.
6. Commits werden lokal immer in Kettenreihenfolge vom ältesten zum neuesten angewendet.
7. Eine Instanz bestätigt einen Schreibvorgang erst, nachdem der Head-CAS erfolgreich war. Ein lokaler Apply-Fehler nach der Veröffentlichung macht den globalen Commit nicht rückgängig; die Instanz muss reparieren oder aus einem Snapshot neu aufbauen.
8. Ein unklarer Netzwerkausgang wird durch erneutes Lesen und Suchen der `mutationId` aufgelöst, nicht durch blindes Wiederholen mit einer neuen ID.
9. Schema-Version, Encoder-Version und erforderliche Fähigkeiten stehen in jedem Commit. Unbekannte Versionen führen zu einem kontrollierten Abbruch.
10. Direkte Änderungen an der lokalen SQLite-Datei außerhalb des Replikationspfads sind im replizierten Modus nicht zulässig.

Sind die Provider-Voraussetzungen erfüllt, liefert die erfolgreiche Head-Aktualisierung eine globale lineare Commit-Reihenfolge. Verliert eine Instanz die Verbindung zum Object Store, darf sie weiter aus ihrem dokumentiert veralteten lokalen Stand lesen, aber nicht global konsistent schreiben. Das System entscheidet sich damit im Partitionierungsfall bewusst für Konsistenz statt Schreibverfügbarkeit.

## § 4 Objekt- und Commit-Format

Empfohlene Schlüsselstruktur:

```text
databases/<database-id>/head.json
databases/<database-id>/commits/<commit-id>.json
databases/<database-id>/snapshots/<snapshot-id>.sqlite.zst
```

`head.json` enthält mindestens `databaseId`, `commitId`, `sequence`, `schemaVersion`, optional `latestSnapshotId` und einen Format-Versionswert. Die Storage-Revision des Head-Objekts — GCS-Generation oder S3-ETag — wird nicht inhaltlich interpretiert, sondern nur als opaker CAS-Token gespeichert.

Ein Commit enthält mindestens:

```json
{
  "formatVersion": 1,
  "databaseId": "orders",
  "commitId": "sha256:...",
  "parentCommitId": "sha256:...",
  "sequence": 1842,
  "mutationId": "01J...",
  "schemaVersion": 7,
  "createdAt": "2026-09-05T12:00:00Z",
  "changes": [
    {
      "table": "users",
      "primaryKey": {"id": "01J..."},
      "operation": "update",
      "expected": {"rowVersion": 12},
      "set": {"email": "new@example.org"},
      "unset": []
    }
  ],
  "checksum": "sha256:..."
}
```

Alle Row-Änderungen einer fachlichen Transaktion stehen gemeinsam in `changes`. Inserts enthalten eine vollständige Post-Image-Row; Updates enthalten mindestens die geänderten Spalten und einen erwarteten Vorzustand; Deletes enthalten Primärschlüssel, Tombstone-Information und einen erwarteten Vorzustand. Ein bloßes „Spalte X wurde geändert“ ohne Konfliktbedingung reicht nicht, weil zwei Instanzen sonst dieselbe Row widersprüchlich überschreiben könnten.

Für neue Schlüssel werden UUIDv7, ULID oder ein anderer global eindeutiger, clientseitig erzeugbarer Primärschlüssel empfohlen. Unkoordiniertes SQLite-`AUTOINCREMENT` auf mehreren Instanzen kann identische IDs erzeugen und ist deshalb im replizierten Modus nicht als globaler Schlüssel zulässig.

## § 5 Schreibprotokoll

Ein Insert, Update oder Delete läuft wie folgt:

1. Die Instanz liest `head.json` einschließlich CAS-Token und synchronisiert alle fehlenden Commits in die lokale SQLite-Datenbank.
2. Sie erzeugt eine stabile `mutationId` und baut aus der ORM-Operation eine kanonische logische Transaktion mit expliziten erwarteten Vorzuständen.
3. Sie startet lokal `BEGIN IMMEDIATE`, wendet die Transaktion testweise an, lässt Constraints und ORM-Validierung laufen und führt anschließend `ROLLBACK` aus. Dieser Preflight erkennt lokale Fehler, reserviert aber keinen globalen Zustand.
4. Sie schreibt das unveränderliche Commit-Objekt unter einer neuen, kollisionssicheren ID. Das erstmalige Anlegen muss ebenfalls bedingt erfolgen, etwa S3 `If-None-Match: *` oder GCS `ifGenerationMatch=0`.
5. Sie ersetzt `head.json` nur, wenn dessen CAS-Token noch dem in Schritt 1 gelesenen Token entspricht.
6. Bei Erfolg wendet sie exakt diesen Commit in einer lokalen SQLite-Transaktion an und aktualisiert darin zugleich `_phore_replication_state.last_commit_id`.
7. Bei einem CAS-Konflikt lädt sie den neuen Head, spielt fremde Commits ein und führt die fachliche Operation erneut gegen den neuen Zustand aus. Ein alter Row-Diff darf nicht ungeprüft auf eine neue Basis kopiert werden.
8. Nach einer konfigurierbaren Zahl von Versuchen — standardmäßig vier — bricht sie mit einer `ConcurrentWriteException` ab. Retries verwenden begrenzten exponentiellen Backoff mit Jitter.

Der lokale Preflight ist nützlich, garantiert aber nicht, dass der spätere globale Commit gültig bleibt: Zwischen Preflight und Head-CAS kann ein anderer Commit veröffentlicht werden. Deshalb muss nach jedem CAS-Konflikt die komplette fachliche Mutation neu ausgewertet werden. Constraints, Trigger, Unique-Keys und abhängige Rows können sich auf der neuen Basis anders verhalten.

Schlägt die Antwort des Head-CAS durch Timeout oder Verbindungsabbruch fehl, ist der Ausgang unbekannt. Die Instanz liest den Head erneut und prüft die Kette auf ihre `mutationId`. Nur wenn die Mutation sicher nicht veröffentlicht wurde, darf sie denselben logischen Versuch wiederholen.

## § 6 Lesen und Synchronisation

Vor einer konsistenten Leseoperation liest die Instanz die Metadaten von `head.json`. Stimmt `commitId` mit dem lokalen Pointer überein, wird sofort aus SQLite gelesen. Andernfalls lädt die Instanz die Rückwärtskette bis zu ihrem lokalen `last_commit_id`, prüft für jedes Objekt ID, Parent, Sequence, Datenbank-ID, Schema-Version und Checksumme, kehrt die gefundene Liste um und wendet sie in chronologischer Reihenfolge an.

Jeder Commit wird in einer eigenen lokalen SQLite-Transaktion oder eine zusammenhängende Gruppe in einer kontrollierten Batch-Transaktion angewendet. Fachliche Daten und lokaler Pointer müssen immer gemeinsam committen. Fehlt ein Objekt, stimmt ein Hash nicht oder liegt der lokale Pointer nicht mehr in der erreichbaren Kette, wird nicht mit einem teilweise aktualisierten Zustand weitergearbeitet; die Instanz wechselt in einen Reparaturpfad und baut die Datenbank aus einem verifizierten Snapshot neu auf.

Eine Abfrage ist auf den Head linearisiert, den sie zu Beginn gelesen und vollständig materialisiert hat. Während einer langen SQLite-Lesetransaktion dürfen neuere globale Commits erscheinen; sie werden erst bei der nächsten Synchronisation sichtbar. Optional können Anwendungen eine explizite Stale-Read-Policy mit maximalem Alter verwenden und dadurch den Head-Request überspringen. Der Standard für „konsistent lesen“ bleibt jedoch die Prüfung des Heads.

Der Head-Request auf jedem Read erzeugt mindestens einen Netzwerk-Roundtrip und macht auch reine Reads teilweise von Object-Store-Latenz und -Verfügbarkeit abhängig. Die API soll daher Konsistenzprofile wie `fresh`, `maxAge(Duration)` und `local` ausdrücklich modellieren, statt Aktualität implizit zu verändern.

## § 7 Transaktionen, Konflikte und SQL-Semantik

Die serialisierbare Query-AST von Phore ORM kann um eine ebenso serialisierbare Mutation-AST ergänzt werden. Repliziert werden Werte und beabsichtigte Zustandsübergänge, nicht freie SQL-Strings. Nichtdeterministische SQL-Ausdrücke wie `CURRENT_TIMESTAMP`, zufällige IDs oder lokale Collations werden vor der Veröffentlichung in konkrete kanonische Werte aufgelöst.

Empfohlene Konfliktregeln:

- Insert: Der Primärschlüssel darf nicht existieren.
- Update: Die Row muss existieren und `rowVersion` oder die explizit gelesenen Vergleichswerte müssen übereinstimmen.
- Delete: Die Row muss existieren und die erwartete Version erfüllen.
- Mehrzeilige Transaktion: Jede Vorbedingung muss innerhalb derselben lokalen SQLite-Transaktion gelten; sonst wird nichts angewendet.
- Unabhängige Rows: Ein CAS-Retry darf die fachliche Mutation automatisch neu aufbauen.
- Dieselbe Row oder Unique-Key: Standardmäßig Konflikt statt stillem Last-Write-Wins.
- Benutzerdefinierte Merge-Strategien: erst als spätere, explizite Erweiterung.

Row-/Spalten-Deltas decken nur Änderungen ab, die vollständig durch die ORM kontrolliert werden. Trigger, Cascades, Foreign Keys, Generated Columns und Schemaänderungen können zusätzliche Effekte erzeugen. Für das MVP müssen alle Replikate dasselbe Schema und kompatible SQLite-Versionen verwenden, `PRAGMA foreign_keys=ON` erzwingen und deterministische Trigger voraussetzen. Alternativ kann der Commit neben der Mutation die erwarteten Post-Images aller tatsächlich veränderten Rows enthalten und deren Ergebnis nach dem Apply verifizieren.

Schema-Migrationen sind spezielle, exklusiv serialisierte Commits. Eine Instanz mit unbekannter Migration darf keine nachfolgenden Daten-Commits anwenden. Rolling Upgrades benötigen Capability- und Mindest-Reader-Versionen im Head; ansonsten kann eine alte Instanz neue Daten falsch interpretieren.

## § 8 Snapshots, Bootstrap und Aufräumen

Ein Snapshot wird nur aus einem vollständig bis Commit `C` synchronisierten, konsistenten SQLite-Zustand erstellt. Dazu ist die SQLite Backup API oder `VACUUM INTO` zu verwenden; das Kopieren einer geöffneten Datenbankdatei ist insbesondere im WAL-Modus unzulässig. Der Snapshot enthält oder begleitet Metadaten mit `databaseId`, `baseCommitId=C`, `sequence`, `schemaVersion`, Dateigröße und SHA-256-Hash.

Nach dem Upload wird ein spezieller Snapshot-Announcement-Commit über denselben Head-CAS veröffentlicht. Dieser Commit verweist auf das unveränderliche Snapshot-Objekt und dessen `baseCommitId`; er ändert keine fachlichen Rows. Ein neuer Client läuft vom aktuellen Head rückwärts bis zum jüngsten kompatiblen Snapshot-Hinweis, lädt und verifiziert dessen SQLite-Datei und spielt anschließend alle nach `baseCommitId` liegenden Daten-Commits vorwärts ein. Die rückwärts gelesenen Commits müssen dafür zunächst gesammelt und umgekehrt werden.

Eine zufällige Snapshot-Auslösung ab einer Kettenlänge von beispielsweise 30 oder 40 Commits ist zulässig, aber nur eine Optimierung. Mehrere Clients dürfen parallel denselben Basisstand snapshotten; nur ein Announcement wird über CAS Teil der kanonischen Kette, übrige Uploads sind verwaist. Für Produktion sind ein deterministischer Threshold, ein best-effort Lease oder ein dedizierter Compactor leichter beobachtbar.

Garbage Collection ist schwieriger als Snapshot-Erstellung. Alte Commits dürfen nur gelöscht werden, wenn kein zulässiger Client sie noch benötigt und mindestens ein verifizierter Snapshot samt vollständiger Folgekette erhalten bleibt. Ohne Client-Leases oder verbindlichen Retention-Horizont löscht das MVP keine kanonischen Commits. Verwaiste, niemals vom Head erreichbare Commit- und Snapshot-Objekte können nach einer großzügigen Frist markiert und entfernt werden.

## § 9 SQLite-Anforderungen

SQLite eignet sich gut als lokale Materialisierung: Transaktionen sind lokal atomar, Reads sind schnell, und jede Instanz kann ihre Datei unabhängig verwalten. Die Datei selbst darf jedoch niemals gleichzeitig von Rechnern im Object Store geöffnet oder über ein Netzwerk-Dateisystem geteilt werden. Die offizielle SQLite-Dokumentation hält insbesondere fest, dass WAL alle Prozesse auf demselben Host voraussetzt und nur einen Writer gleichzeitig zulässt.

Innerhalb einer Instanz können mehrere PHP-Prozesse dieselbe lokale SQLite-Datei verwenden, sofern sie auf demselben Host laufen, normale SQLite-Locks respektieren und Synchronisation/Apply über einen lokalen Writer-Lock koordinieren. WAL kann lokale Leser und den Writer besser entkoppeln, ist aber kein Replikationsmechanismus. Snapshots müssen über eine konsistente SQLite-Schnittstelle erzeugt und nach dem Download mit `PRAGMA integrity_check` sowie dem externen Hash geprüft werden.

Die lokale Datenbank gilt als Cache mit dauerhaftem Cursor, nicht als alleinige Wahrheit. Ein Crash zwischen erfolgreichem globalem CAS und lokalem Apply ist deshalb heilbar: Beim Neustart erkennt die Instanz den abweichenden Head und spielt den bereits veröffentlichten Commit ein. Ein deterministischer Apply-Fehler nach Veröffentlichung ist dagegen ein schwerer Fehler und muss die Instanz fail-closed setzen, weil andere Replikate den Commit ebenfalls erreichen werden.

## § 10 Fehlerfälle und betriebliche Grenzen

| Fehlerfall | Erforderliches Verhalten |
|---|---|
| Zwei Writer lesen denselben Head | Nur ein Head-CAS gewinnt; der Verlierer synchronisiert, revalidiert und versucht erneut. |
| Commit-Upload erfolgreich, Head-CAS verloren | Commit bleibt verwaist und wird später bereinigt; keine fachliche Wirkung. |
| Head-CAS erfolgreich, Antwort verloren | Über `mutationId` und erneutes Lesen auflösen; nicht blind duplizieren. |
| Head zeigt auf fehlenden Commit | Datenkorruption beziehungsweise verletzte Veröffentlichungsreihenfolge; fail closed. |
| Lokaler Apply scheitert nach globalem Commit | Lokale DB verwerfen/reparieren; globalen Head nicht zurückbiegen. |
| Object Store nicht erreichbar | Frische Reads und Writes schlagen fehl; explizit erlaubte lokale Stale Reads bleiben möglich. |
| Hohe Schreibkonkurrenz | Viele CAS-Konflikte, steigende Kosten und potenzielle Starvation; Architektur ist ungeeignet oder benötigt einen Sequencer. |
| Client lange offline | Snapshot laden und Restkette anwenden; keine Offline-Writes im MVP. |
| Schema-Version unbekannt | Upgrade verlangen und Apply stoppen. |
| Objekt versehentlich gelöscht/überschrieben | Versionierung, Retention und Bucket-Policy nutzen; Integritätsprüfung schlägt an. |
| Head öffentlich gecacht | Verboten; private, nicht zwischengespeicherte API-Zugriffe verwenden. |
| Lebenszyklusregel löscht aktive Objekte | Bucket-Konfiguration beim Start prüfen oder dokumentiert erzwingen. |

Der Head ist ein globaler Hotspot. Die maximale nachhaltige Write-Rate wird durch Object-Store-Latenz, drei Remote-Schritte — Head lesen, Commit schreiben, Head per CAS ersetzen — und Konfliktretries begrenzt. Das System soll daher keine feste Transaktionsrate versprechen, sondern in einem Spike die Zielregionen, Parallelität, Kosten und p95/p99-Latenzen messen. Benötigt die Anwendung hohe oder vorhersagbare Write-Raten, ist ein echter Datenbankdienst oder ein dedizierter Log-Sequencer die passendere Architektur.

## § 11 Provider-Abstraktion und Sicherheit

Der `ObjectCommitStore`-Vertrag darf nur Provider akzeptieren, die starke Read-after-write-Konsistenz für Objektinhalt und Metadaten sowie atomare bedingte Ersetzungen bieten. Amazon S3 unterstützt starke Read-after-write-Konsistenz für PUT, DELETE und HEAD-Metadaten sowie bedingte Writes mit `If-Match` und `If-None-Match`. Google Cloud Storage bietet starke globale Object-Read-after-write-Konsistenz und `ifGenerationMatch`; bei einer falschen Generation schlägt die Operation mit `412 Precondition Failed` fehl.

Provider-Mapping:

| Operation | Amazon S3 | Google Cloud Storage |
|---|---|---|
| Unveränderliches Objekt anlegen | `If-None-Match: *` | `ifGenerationMatch=0` |
| Head lesen | Inhalt plus ETag | Inhalt plus Generation |
| Head per CAS ersetzen | `If-Match: <etag>` | `ifGenerationMatch=<generation>` |
| CAS-Konflikt | `412`, gegebenenfalls `409` | `412` |
| Revision behandeln | ETag als opaker Token | Generation als opaker Token |

Ein Adapter ohne diese Garantien wird abgelehnt; ein Prozess-Lock in einer einzelnen Instanz ist kein Ersatz. Cross-Region- oder Cross-Cloud-Replikation eines Buckets darf nicht als zusätzlicher Consensus-Punkt missverstanden werden.

Der Bucket bleibt privat, verwendet TLS, serverseitige Verschlüsselung, Versionierung und möglichst Retention gegen versehentliche Löschung. Writer benötigen nur Zugriff auf ihren Datenbank-Präfix und dürfen keine fremden Datenbanken auflisten. Sensible Werte in Row-Deltas können zusätzlich anwendungsseitig verschlüsselt werden; dabei bleiben IDs, Parent-Zeiger und für Synchronisation erforderliche Metadaten authentifiziert. Jeder Commit wird gegen Größen-, Tabellen-, Spalten- und Schema-Limits validiert, bevor er SQLite erreicht.

Maßgebliche Primärquellen:

- [Amazon S3: Conditional Writes](https://docs.aws.amazon.com/AmazonS3/latest/userguide/conditional-writes.html)
- [Amazon S3: Strong Consistency](https://docs.aws.amazon.com/AmazonS3/latest/userguide/Welcome.html#ConsistencyModel)
- [Google Cloud Storage: Request Preconditions](https://cloud.google.com/storage/docs/request-preconditions)
- [Google Cloud Storage: Consistency](https://cloud.google.com/storage/docs/consistency)
- [SQLite: Write-Ahead Logging](https://www.sqlite.org/wal.html)
- [SQLite: Atomic Commit](https://www.sqlite.org/atomiccommit.html)

## § 12 MVP und Entscheidungsbedarf

### § 12.1 Empfohlener MVP

1. Provider-neutralen `ObjectCommitStore` und zunächst je einen schmalen S3- und GCS-Contract-Test implementieren; anschließend einen Provider als Referenzadapter auswählen.
2. Replizierten Modus auf ORM-eigene Inserts, Updates und Deletes mit UUID-/ULID-Primärschlüsseln begrenzen.
3. Kanonisches Commit-Format, Hash-Verifikation, `mutationId`, Row-Versionen und lokale `_phore_replication_state`-Tabelle implementieren.
4. Sync, CAS-Write, vier begrenzte Retries mit Jitter und Reparatur aus einem Snapshot umsetzen.
5. Snapshots per SQLite Backup API erstellen; zunächst keine kanonischen Commits automatisch löschen.
6. Schema-Migrationen im ersten MVP entweder vollständig sperren oder nur bei exklusivem Maintenance-Modus erlauben.
7. Konsistenzprofile für Reads explizit in der API sichtbar machen.
8. Observability für Head-Sequence, lokale Lag-Länge, CAS-Konflikte, Retry-Zahl, Snapshot-Alter, Apply-Fehler und verwaiste Objekte bereitstellen.

### § 12.2 Abnahmekriterien

Der MVP gilt als technisch tragfähig, wenn automatisierte Tests folgende Eigenschaften nachweisen:

- Hunderte konkurrierende, zufällig verzögerte Writer ergeben dieselbe kanonische Reihenfolge und denselben finalen Datenhash wie ein serielles Referenzmodell.
- Prozessabstürze und verlorene Antworten werden vor und nach jedem Remote-Schritt injiziert, ohne doppelte fachliche Mutation oder verlorenen bestätigten Commit.
- Unique-, Foreign-Key- und Row-Version-Konflikte brechen deterministisch ab oder werden nach Rebase korrekt neu ausgewertet.
- Eine Transaktion über mehrere Rows und Tabellen ist auf jedem Replikat vollständig oder gar nicht sichtbar.
- Neue Clients bootstrappen aus Snapshot plus Restkette zum exakt gleichen Datenhash.
- Manipulierte, fehlende oder falsch verkettete Objekte werden erkannt und führen nicht zu stiller Datenabweichung.
- S3- und GCS-Integrationstests verifizieren reale CAS-Konflikte, starke Reads und unbekannte Request-Ausgänge.
- Lasttests dokumentieren p50/p95/p99 für Reads, konfliktfreie Writes und konkurrierende Writes sowie Request-Kosten.

### § 12.3 Offene Entscheidungen

Vor Implementierung sind noch der Referenzprovider, das kanonische Serialisierungsformat, die erlaubten Primärschlüsseltypen, die Standard-Stale-Read-Policy, das Trigger-/Cascade-Modell, der Umgang mit Schema-Migrationen und die Retention alter Commits festzulegen.

Die Empfehlung lautet, das Vorhaben als experimentellen `CloudSqliteDriver` hinter einer expliziten Feature-Grenze zu beginnen. Das Konzept ist für ein enges, read-lastiges Einsatzprofil feasible. Es scheitert oder wird wirtschaftlich unattraktiv, sobald hohe parallele Write-Raten, Offline-Merges, unkontrollierte Direkt-SQL-Schreibzugriffe, inkompatible Schemas oder Schreibverfügbarkeit während einer Object-Store-Partition gefordert werden.
