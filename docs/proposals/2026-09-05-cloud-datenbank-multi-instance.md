# Proposal: Cloud-Datenbank Multi-Instance

| Datum | Benutzername | Kurzbeschreibung |
|---|---|---|
| 2026-09-05 | dermatthes | §§ 1–12: Proposal angelegt |
| 2026-09-05 | dermatthes | § 1, § 2.2, § 10, § 12, § 13: Heavy Write Mode mit Zeitfenstern, versiegelten Manifests und asynchroner Synchronisation ergänzt |
| 2026-09-05 | dermatthes | § 13.2–§ 13.4: Fensteröffnung durch konkurrierende Master und CAS-Konfliktauflösung präzisiert |
| 2026-09-05 | dermatthes | § 12.3, § 13.7: Heavy-Write-Abgrenzung und Wiederaufnahme nach Fensteröffnung präzisiert |

**Status:** Offen  
**Zielprojekt:** Phore ORM  
**Arbeitstitel:** Cloud-Datenbank Multi-Instance  
**Technische Basis:** PHP 8.1+, lokale SQLite-Datenbanken, Amazon S3 oder Google Cloud Storage

## § 1 Kurzfassung

Phore ORM soll optional mehrere schreibende Instanzen unterstützen, die jeweils eine lokale SQLite-Datenbank als schnell lesbare Materialisierung verwenden und einen gemeinsam erreichbaren Object Store als dauerhaften, append-only Transaktions-Log nutzen. Ein kleines, stark konsistentes Objekt `head.json` verweist auf den neuesten Commit. Jeder unveränderliche Commit verweist auf seinen Vorgänger und enthält eine vollständige logische ORM-Transaktion mit allen betroffenen Tabellen, Primärschlüsseln, Operationen, geänderten Spalten und Konfliktbedingungen.

Das Konzept ist unter klaren Grenzen machbar. Es ist jedoch keine Multi-Master-Replikation von SQLite-Dateien: Mehrere Instanzen dürfen Schreibvorgänge initiieren, aber ein bedingtes Überschreiben von `head.json` serialisiert alle globalen Commits. Der Object Store ist damit Commit-Log und globaler Compare-and-Swap-Punkt; SQLite bleibt pro Instanz eine lokale, ersetzbare Projektion. Das Modell ist für read-lastige Anwendungen mit geringer bis moderater Schreibrate interessant. Es eignet sich nicht für hohe Write-Raten, Offline-Merges, dauerhaft netzwerkunabhängige Writer oder Anwendungen, die bei einer Object-Store-Störung weiter global konsistent schreiben müssen.

Die in der Ausgangsidee genannte MySQL-Datenbank wird als offensichtlicher Versprecher behandelt: Nach erfolgreicher Veröffentlichung wird die Transaktion in die lokale SQLite-Datenbank eingespielt und deren lokaler Commit-Pointer atomar weitergesetzt.

Für Analyse-, Telemetrie- und Eventdaten ergänzt § 13 einen getrennten Heavy Write Mode. Dieser gibt die globale Reihenfolge und sofortige Konsistenz bewusst auf: Writer legen unveränderliche Batch-Objekte parallel in ein zeitlich begrenztes Fenster, während selten aktualisierte Heads und versiegelte Manifeste den vollständigen, inkrementell lesbaren Bestand abgeschlossener Fenster festhalten.

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

Nicht Bestandteil des konsistenten Modus sind konfliktfreie Offline-Änderungen, automatisches Zusammenführen divergenter Branches, schreibbare Replikate ohne Verbindung zum Object Store, Replikation beliebiger SQL-Verbindungen außerhalb von Phore ORM, das Teilen einer SQLite-Datei über ein Netzwerk-Dateisystem, Cross-Cloud-Consensus sowie eine garantierte hohe Schreibrate. Das System entscheidet bei konkurrierenden Änderungen nicht stillschweigend per Last-Write-Wins, sondern verlangt eine explizite Konfliktstrategie. Hohe Schreibraten werden ausschließlich durch den in § 13 beschriebenen, semantisch getrennten Append-Modus unterstützt; dessen verzögerte Sichtbarkeit und schwächere Konsistenz dürfen nicht unbemerkt auf normale CRUD-Tabellen übertragen werden.

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
| Hohe Schreibkonkurrenz im konsistenten Modus | Viele CAS-Konflikte, steigende Kosten und potenzielle Starvation; für ungeordnete Append-Daten den Heavy Write Mode aus § 13, sonst einen Sequencer verwenden. |
| Client lange offline | Snapshot laden und Restkette anwenden; keine Offline-Writes im MVP. |
| Schema-Version unbekannt | Upgrade verlangen und Apply stoppen. |
| Objekt versehentlich gelöscht/überschrieben | Versionierung, Retention und Bucket-Policy nutzen; Integritätsprüfung schlägt an. |
| Head öffentlich gecacht | Verboten; private, nicht zwischengespeicherte API-Zugriffe verwenden. |
| Lebenszyklusregel löscht aktive Objekte | Bucket-Konfiguration beim Start prüfen oder dokumentiert erzwingen. |

Der Head ist ein globaler Hotspot. Die maximale nachhaltige Write-Rate wird durch Object-Store-Latenz, drei Remote-Schritte — Head lesen, Commit schreiben, Head per CAS ersetzen — und Konfliktretries begrenzt. Das System soll daher keine feste Transaktionsrate versprechen, sondern in einem Spike die Zielregionen, Parallelität, Kosten und p95/p99-Latenzen messen. Benötigt die Anwendung hohe oder vorhersagbare Write-Raten bei geordneten, konfliktgeprüften CRUD-Transaktionen, ist ein echter Datenbankdienst oder ein dedizierter Log-Sequencer die passendere Architektur. Für ungeordnete, append-only Analyseereignisse kann stattdessen der Heavy Write Mode aus § 13 verwendet werden.

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
9. Den Heavy Write Mode als zweite, getrennt testbare Ausbaustufe nur für explizit deklarierte Append-Tabellen implementieren.

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

Vor Implementierung sind noch der Referenzprovider, das kanonische Serialisierungsformat, die erlaubten Primärschlüsseltypen, die Standard-Stale-Read-Policy, das Trigger-/Cascade-Modell, der Umgang mit Schema-Migrationen und die Retention alter Commits festzulegen. Für den Heavy Write Mode sind zusätzlich Fensterdauer, Grace Period, maximale Upload-Dauer, Batch-Größe, Manifest-Sharding, zulässige Tabellen und Watermark-Semantik zu entscheiden.

Die Empfehlung lautet, das Vorhaben als experimentellen `CloudSqliteDriver` hinter einer expliziten Feature-Grenze zu beginnen. Der konsistente Modus ist für ein enges, read-lastiges Einsatzprofil feasible und wird bei hohen parallelen CRUD-Write-Raten wirtschaftlich unattraktiv. Der getrennte Heavy Write Mode skaliert ungeordnete Append-Daten, unterstützt aber weiterhin keine Offline-Merges, unkontrollierten Direkt-SQL-Schreibzugriffe, inkompatiblen Schemas oder garantierte Schreibverfügbarkeit während einer Object-Store-Partition.


## § 13 Heavy Write Mode

### § 13.1 Zweck und Konsistenzgrenze

Der Heavy Write Mode ist für Analyse-, Telemetrie-, Audit- und Eventdaten vorgesehen, bei denen möglichst viele voneinander unabhängige Inserts aufgenommen werden sollen und eine sofortige globale Reihenfolge nicht erforderlich ist. Jeder Writer lädt unveränderliche Dateien unter einem aktiven Fenster-Präfix hoch. Es gibt keinen Head-CAS pro Datensatz oder Batch und damit keinen einzelnen Write-Hotspot im Datenpfad.

Der Modus garantiert keine sofortige Sichtbarkeit, keine globale Reihenfolge zwischen Writern und keine serialisierbaren Read-modify-write-Operationen. Er garantiert stattdessen nach erfolgreicher Versiegelung eines Fensters einen vollständigen, unveränderlichen Satz aller nach Protokoll bestätigten Batches bis zu einem Watermark. Anwendungen können somit eindeutig sagen: „Alle Ereignisse bis einschließlich Fenster W sind lokal eingespielt“, während das aktive Fenster weiterhin nur best effort sichtbar ist.

Heavy-Write-Tabellen müssen ausdrücklich als appendOnly deklariert und von konsistent replizierten CRUD-Tabellen getrennt werden. Updates und Deletes sind nicht erlaubt, solange ihre Wirkung nicht durch einen kommutativen, idempotenten und reihenfolgeunabhängigen Reducer definiert ist. Für typische Analysedaten ist deshalb ein Eventmodell mit stabiler eventId, konkretem Ereigniszeitpunkt und vollständigem Payload vorzuziehen.

### § 13.2 Objektstruktur

Empfohlene Schlüsselstruktur:

    databases/<database-id>/heavy/head.json
    databases/<database-id>/heavy/windows/<window-id>/header.json
    databases/<database-id>/heavy/windows/<window-id>/objects/<hash-prefix>/<writer-id>/<batch-id>.ndjson.zst
    databases/<database-id>/heavy/windows/<window-id>/manifests/<manifest-shard>.json
    databases/<database-id>/heavy/windows/<window-id>/seal.json

heavy/head.json ist nur der per CAS ersetzte Zeiger auf den aktuellen, unveränderlichen Fenster-Header. Jeder windows/<window-id>/header.json enthält Fenster-ID, Beginn, Schreibfrist und previousWindowId und bildet damit die rückwärts durchlaufbare Head-Kette. Ein beim CAS unterlegener, noch nicht referenzierter Fenster-Header ist verwaist und hat keine fachliche Wirkung. Ein seal.json ist unveränderlich und enthält windowId, previousSealedWindowId, Beginn und Ende des Zeitfensters, die Liste beziehungsweise IDs aller Manifest-Shards, Objektanzahl, Gesamtbytes, Schema-Version und einen Hash oder Merkle-Root über die Manifestdaten.

Die eigentlichen Objektnamen werden nicht sequenziell vergeben. Ein zufälliger oder gehashter Präfix verteilt Schreiblast über Storage-Partitionen. batchId und die enthaltenen eventId-Werte sind global eindeutig und bei Retries stabil. Pro Ereignis eine einzelne Kleinstdatei anzulegen ist zwar möglich, erzeugt bei Millionen Ereignissen aber hohe Request-, Listing- und Speicherkosten. Empfohlen werden Writer-seitige Micro-Batches, beispielsweise komprimiertes NDJSON oder für analytische Verarbeitung Parquet, mit konfigurierbaren Grenzen für Ereigniszahl, Bytes und maximale Pufferzeit.

### § 13.3 Schreiben in das aktive Fenster

Jeder Master kann zugleich normaler Writer und Fensteröffner sein. Er liest oder cached heavy/head.json und den referenzierten Fenster-Header. Solange dessen Schreibfrist nicht abgelaufen ist, lädt er seinen Batch ohne globalen Lock unter einem eindeutigen Schlüssel in dieses Fenster.

Ist die Schreibfrist abgelaufen, legt der Master zunächst einen neuen unveränderlichen Fenster-Header an, dessen previousWindowId auf das bisherige Fenster zeigt, und versucht heavy/head.json mit dem zuvor gelesenen Generation-/ETag-Token per CAS auf diesen Header umzusetzen. Genau ein konkurrierender Master gewinnt. Jeder Verlierer erkennt am CAS-Konflikt, dass bereits ein anderer Master ein neues Fenster geöffnet hat, lädt den aktuellen Head und schreibt seinen Batch in das dort referenzierte Gewinnerfenster. Der nicht referenzierte Header des Verlierers bleibt wirkungslos und kann später als verwaistes Objekt entfernt werden.

Der normale Ablauf lautet:

1. Aktuellen Head und Fenster-Header laden.
2. Bei abgelaufener Schreibfrist ein Nachfolgefenster per CAS öffnen; bei Konflikt den Gewinner-Head laden.
3. Batch kanonisch serialisieren, eine stabile batchId vergeben und mit Create-only-Vorbedingung unter dem aktiven Fenster-Präfix hochladen.
4. Nach erfolgreichem Upload den Head nochmals lesen.
5. Ist weiterhin dasselbe Fenster aktiv, ist der Batch für dieses Fenster bestätigt.
6. Hat während des bereits laufenden Uploads ein anderer Master das Fenster gewechselt, denselben logischen Batch zusätzlich in das neue aktive Fenster schreiben. Stabile eventId- und batchId-Werte machen die Überlappung beim Apply idempotent.
7. Erst danach der Anwendung Erfolg melden.

Der CAS-Konflikt löst konkurrierende Fensteröffnungen vollständig auf. Die Nachprüfung nach dem Batch-Upload behandelt ausschließlich den anderen Race: Ein Writer kann das alte Fenster noch vor dessen Ablauf gelesen haben, während sein Upload erst nach der erfolgreichen Head-Umsetzung endet. Ein unbekannter Upload-Ausgang wird unter demselben Objektschlüssel per Create-only-Retry oder Existenz- und Hashprüfung geklärt.

Der Head wird typischerweise nur beim Fensterwechsel, beispielsweise alle zehn Minuten, umgesetzt. Dadurch greifen Limits für wiederholte Änderungen desselben Objektnamens nicht in den normalen Batch-Pfad ein. Die erreichbare Schreibrate wird primär durch Bucket-Rate, Objektverteilung, Netzwerkbandbreite und Batch-Größe begrenzt.

### § 13.4 Fensterwechsel und Versiegelung

Der Wechsel von W auf W+1 wird von dem ersten Master durchgeführt, der nach Ablauf der Schreibfrist einen Write beginnt:

1. Der Master schreibt einen unveränderlichen Header für W+1 mit previousWindowId=W.
2. Er versucht heavy/head.json per CAS von W auf W+1 umzusetzen.
3. Bei Erfolg ist W geschlossen und W+1 das einzige kanonische aktive Fenster.
4. Bei einem CAS-Konflikt verwirft der Master seinen Kandidaten logisch, lädt den bereits von einem anderen Master gesetzten Head und schreibt nach dessen Fenster.
5. Nach einer konfigurierbaren Grace Period listet ein beliebiger Master oder Compactor ausschließlich das Präfix von W vollständig und stark konsistent.
6. Er erzeugt ein oder mehrere unveränderliche Manifest-Shards und veröffentlicht seal.json mit Prüfsummen, Zählern und previousSealedWindowId.
7. Der aktuelle Head beziehungsweise eine separate, selten aktualisierte Seal-Referenz wird per CAS auf lastSealedWindowId=W fortgeschrieben.

Es gibt keinen fest zugewiesenen Rotator und keine konkurrierenden kanonischen Nachfolgefenster: Der Head-CAS entscheidet die Fensteröffnung. Fällt der Gewinner nach dem CAS aus, bleibt das neue Fenster dennoch aus seinem Header rekonstruierbar; jeder andere Master kann normal hineinschreiben und die Versiegelung des Vorgängers übernehmen.

Die Grace Period schützt vor Uploads, die bereits unter W begonnen wurden, als W noch aktuell war. Ein Writer, dessen Upload die Head-Umsetzung überlappt, erkennt das bei seiner Abschlussprüfung und schreibt denselben Batch idempotent nach W+1. Erst danach gilt sein Write als bestätigt. So bleibt der einfache CAS-Fensterwechsel erhalten, ohne dass ein während der Manifest-Erstellung verspätet fertiggestellter Upload unbemerkt verloren geht.

### § 13.5 Index und Manifest-Sharding

Das Manifest ist der Index eines abgeschlossenen Fensters. Ein Replikat muss deshalb nicht alle historischen Bucket-Objekte auflisten oder Millionen Dateien anhand lokaler IDs vergleichen. Es liest den letzten versiegelten Head, folgt über previousSealedWindowId rückwärts bis zum eigenen lokalen Watermark und lädt nur die Manifest-Shards und Datenobjekte der fehlenden Fenster.

Große Fenster verwenden mehrere Manifest-Shards mit begrenzter Eintrags- und Bytegröße. Jeder Eintrag enthält mindestens Objektschlüssel, Batch-ID, Dateigröße, Inhalts-Hash, Encoder-Version, Schema-Version, Ereignisanzahl sowie optional minimale und maximale Ereigniszeit. seal.json legt Reihenfolge und Hash aller Shards fest, obwohl die Reihenfolge der enthaltenen Ereignisse fachlich keine Bedeutung hat.

Das Manifest ist kein Suchindex für einzelne fachliche Datensätze. Analytische Filter laufen nach der Materialisierung in SQLite oder einem späteren Analyseformat. Optionale Statistiken wie Zeitbereich, Partition-Key oder Bloom-Filter dürfen nur die Auswahl zu ladender Batches optimieren und niemals die Vollständigkeitsprüfung ersetzen.

### § 13.6 Synchronisation und Read-Semantik

Eine lokale Instanz speichert last_sealed_window_id und alle angewendeten batchId- beziehungsweise eventId-Werte oder eine äquivalente Idempotenzstruktur. Bei der Synchronisation lädt sie die Kette fehlender Seals, kehrt diese um und verarbeitet die Fenster chronologisch. Innerhalb eines Fensters dürfen Manifest-Shards und Batches parallel geladen und in beliebiger Reihenfolge angewendet werden.

Jeder Batch wird geprüft und in einer lokalen SQLite-Transaktion eingespielt. Append-Tabellen verwenden eventId als Unique Key und idempotente Inserts, beispielsweise INSERT ... ON CONFLICT DO NOTHING. Erst wenn sämtliche Manifest-Shards und Objekte eines Fensters erfolgreich verarbeitet sind, wird der lokale Watermark atomar auf dieses Fenster gesetzt.

Die API unterscheidet mindestens:

- sealed: vollständig und reproduzierbar bis lastSealedWindowId;
- maxAge(Duration): wartet beziehungsweise synchronisiert bis zu einem ausreichend neuen Seal;
- includeActive: listet zusätzlich das aktive Fenster best effort, ohne Vollständigkeitsgarantie.

Bei zehn Minuten Fensterdauer liegt die garantierte Sichtbarkeit typischerweise um Fensterdauer plus Grace Period, Sealing- und Sync-Zeit hinter dem aktuellen Zeitpunkt. Kürzere Fenster reduzieren den Lag, erhöhen aber Head-, Listing- und Manifestkosten. Ein S3-Event beziehungsweise eine Cloud-Storage-Pub/Sub-Benachrichtigung auf seal.json kann Replikate sofort wecken; die Benachrichtigung bleibt ein Hinweis, während Head, Seal und Manifest die Wahrheit bilden.

### § 13.7 Fehlerfälle

| Fehlerfall | Verhalten |
|---|---|
| Mehrere Writer laden parallel | Unabhängige Schlüssel vermeiden Konflikte; Uploads skalieren mit dem Bucket. |
| Derselbe Batch wird wiederholt | Stabiler Schlüssel und Hash beziehungsweise idempotente eventId verhindern doppelte Wirkung. |
| Writer lädt nach altem Head hoch | Post-Upload-Head-Prüfung kopiert den Batch in das neue Fenster; spätere Deduplizierung ist verpflichtend. |
| Writer stürzt vor Abschlussprüfung ab | Write gilt nicht als bestätigt und muss mit derselben ID wiederholt werden. |
| Fensteröffner stürzt nach erfolgreichem Head-CAS ab | Der neue Fenster-Header bleibt kanonisch; ein anderer Master schreibt dort weiter und übernimmt später die Versiegelung des Vorgängers. |
| Objekt fehlt trotz Manifesteintrag | Fenster ist nicht vollständig lesbar; Watermark wird nicht weitergesetzt. |
| Objekt erscheint nach Versiegelung im alten Präfix | Es besitzt keine bestätigte Zugehörigkeit zu diesem Seal; ein korrekt bestätigter Writer hat eine Kopie in einem späteren Fenster. |
| Manifest wird doppelt erzeugt | Nur das über Head und Seal-Hash referenzierte Manifest ist kanonisch. |
| Benachrichtigung fehlt oder kommt doppelt | Periodischer Head-Abgleich und idempotenter Sync stellen Fortschritt sicher. |
| Ungeordnetes Ereignis verändert bestehenden Zustand | Operation wird abgelehnt oder benötigt einen ausdrücklich definierten kommutativen Reducer. |

### § 13.8 Skalierung und Grenzen

Durch einzigartige Objektschlüssel entfällt die Ein-Write-pro-Sekunde-Grenze von Google Cloud Storage für denselben Objektnamen im eigentlichen Datenpfad. Google Cloud Storage nennt für neue Buckets anfänglich ungefähr 1.000 Objekt-Writes pro Sekunde und skaliert bei verteilter Last weiter; Amazon S3 nennt mindestens 3.500 schreibende Requests pro Sekunde und Prefix. Beide Werte sind Bucket- beziehungsweise Prefix-Eigenschaften und müssen mit realer Batch-Größe, Region und Clientparallelität getestet werden.

Der verbleibende Engpass ist die Versiegelung: Sehr viele Kleinstobjekte verlängern Listing, Manifest-Erstellung, Download und SQLite-Apply. Micro-Batching ist deshalb Bestandteil des Protokolls, nicht nur eine optionale Optimierung. Für Millionen Ereignisse pro Fenster können hierarchische Manifeste, mehrere Partitionen pro fachlichem Datenstrom und ein separater Compactor erforderlich werden.

Der Heavy Write Mode ist kein Ersatz für Kafka, Pub/Sub, Kinesis oder eine analytische Datenbank, wenn niedrige Event-Latenz, dauerhaft geordnete Partitionen, Consumer-Gruppen, Backpressure oder komplexe Stream-Verarbeitung benötigt werden. Er ist ein bewusst einfaches Object-Store-Protokoll für hohe Append-Raten, verzögerte Vollständigkeit und kostengünstige lokale Materialisierung.

Maßgebliche Primärquellen:

- [Amazon S3: Performance Design Patterns](https://docs.aws.amazon.com/AmazonS3/latest/userguide/optimizing-performance.html)
- [Google Cloud Storage: Request Rate and Access Distribution](https://cloud.google.com/storage/docs/request-rate)
- [Google Cloud Storage: Quotas and Limits](https://cloud.google.com/storage/quotas)
- [Amazon S3: Event Notifications](https://docs.aws.amazon.com/AmazonS3/latest/userguide/EventNotifications.html)
- [Google Cloud Storage: Pub/Sub Notifications](https://cloud.google.com/storage/docs/pubsub-notifications)
