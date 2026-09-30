# Planung: Schreib-App (Projekte & Freigaben)

Dieses Dokument hält die Architektur-Entscheidungen fest, **bevor** der erste
Schreib-App-Code entsteht. Es ist der verbindliche Rahmen für Phase 4 der
Roadmap. Bei Änderungen an diesen Entscheidungen: dieses Dokument zuerst
aktualisieren, dann bauen.

## 1. Grundmodell: Sichtbarkeit hängt am Projekt, nicht am Nutzer

Jeder Account besitzt seine Projekte. Ein Projekt (z. B. ein Roman) bündelt
alle zugehörigen Texte: Kapitel, Charaktere, Beziehungen, Hintergründe.

```
User 1 ──besitzt──> Projekt A ──bündelt──> Kapitel, Charaktere, ...
User 1 ──besitzt──> Projekt B
User 2 ──besitzt──> Projekt C

Freigabe: User 1 gibt Projekt A an User 2 frei
```

Kernregel:

> **Sichtbarkeit ist eine Eigenschaft des Projekts.**
> Ein Nutzer sieht ein Objekt genau dann, wenn er Besitzer des Projekts ist
> ODER auf der Freigabeliste des Projekts steht.

Es gibt keine Einzel-Freigaben für einzelne Kapitel oder Charaktere.
Freigabe erfolgt immer nur auf Projektebene. (Kaskade: wer das Projekt
darf, darf alle seine Texte.)

## 2. Datenmodell (von Anfang an, auch wo die UI später kommt)

### Schreib-App-Tabellen (Modul-Migration, nicht Core)

```sql
projects (
    id TEXT PRIMARY KEY,
    object_id TEXT REFERENCES content_objects(id),  -- Roman als Content-Objekt
    owner_id TEXT NOT NULL REFERENCES users(id),
    title TEXT NOT NULL,
    created_at ...
)

project_members (
    project_id TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role TEXT NOT NULL DEFAULT 'member' CHECK (role IN ('owner', 'member')),
    PRIMARY KEY (project_id, user_id)
)
```

**Warum `project_members` sofort anlegen, auch wenn Version 1 nur der
Besitzer sieht:** Jede Lese- und Schreibabfrage geht von Tag 1 über diese
Tabelle (bei Version 1 einfach mit leerer Member-Liste). „Freigeben" ist
dann später ein Tabelleneintrag, kein Umbau. Keine Stelle im Code prüft
Sichtbarkeit, ohne die Tabelle mit abzufragen.

### Projekt-Zugehörigkeit an jedem Texttyp

Jede Detailtabelle der Schreib-App (chapters, characters, relationships,
backgrounds) bekommt zwingend:

```sql
project_id TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE
```

### Slug-Eindeutigkeit: pro Projekt, nicht global

Kapitel „kapitel-1" wird in jedem Roman vorkommen. Deshalb:

```sql
UNIQUE (project_id, slug)
```

statt der heutigen globalen Eindeutigkeit pro Typ. Das muss **jetzt**
entschieden werden, weil Links (`[[typ:slug]]`) dauerhaft im Text stehen.

**Link-Syntax (Entscheidung):** innerhalb eines Projekts reicht der Kurzlink
`[[kapitel:kapitel-1]]` und wird im Kontext des aktuellen Projekts aufgelöst.
Projektübergreifende Links (später, selten): `[[kapitel:projekt/kapitel-1]]`.

Dafür muss `ModuleInterface::resolveLink()`/`resolveLinks()` den Projekt-Kontext
erhalten können (Erweiterung im Core, bevor die Schreib-App gebaut wird —
sonst baut das Modul am Core vorbei).

## 3. Eine zentrale Sichtbarkeits-Prüfung

Es gibt **genau eine Funktion** im Schreib-App-Modul, die beantwortet:

> „Darf User X Projekt Y sehen?" / „...und bearbeiten?"

```php
canAccess(User $user, string $projectId): bool      // sehen
canEdit(User $user, string $projectId): bool       // mitarbeiten
isOwner(User $user, string $projectId): bool        // löschen/verwalten
```

Alle Listen (Projektübersicht, Kapitelliste, Charakterliste) und alle
Einzelansichten rufen NUR diese Funktionen auf. Nirgendwo steht ein
rohes SQL mit selbst gebauter Sichtbarkeits-Bedingung.
Grund: verteilte Prüfungen garantieren eine vergessene Stelle — und jede
vergessene Stelle ist eine Sicherheitslücke (fremde Romane lesbar).

## 4. Rollen: global vs. projekt-lokal

Zwei getrennte Ebenen, nicht vermischen:

| Ebene | Bedeutung | Beispiele |
|---|---|---|
| Globale Rolle (admin/editor/user) | Rechte am System | Seiten verwalten, Nutzer anlegen (Admin) |
| Projekt-Rolle (owner/member) | Rechte an einem konkreten Projekt | Lesen, Schreiben, Projekt löschen |

**Version 1 bewusst nur zwei Projekt-Rollen:**
- `owner`: alles — verwalten, freigeben, löschen
- `member`: alle Texte im Projekt lesen und anlegen/bearbeiten

Feinabstufungen („nur Lesen", „nur eigene Kapitel") kommen später; die
Tabelle kann sie schon heute aufnehmen, ohne Umbau.

## 5. Lösch-Regeln (vor dem ersten Löschen-Button festgelegt)

- **Projekt löschen:** nur `owner`. Kaskadiert alles (Kapitel, Charaktere,
  Revisionen, Freigaben). Mitarbeiter verlieren mit — Besitzer entscheidet.
- **Objekt innerhalb eines Projekts löschen (Kapitel/Charakter/...):**
  jeder `member` darf eigene Objekte löschen (`content_objects.owner_id`,
  existiert im Core); Objekte anderer nur mit `owner`.
- **Projekt-Freigabe zurückziehen:** nur `owner`.

## 6. Übersichts-Screen

Die Startseite der Schreib-App ist EINE Abfrage (UNION):

```
meine Projekte (owner_id = ich)
∪ freigegebene Projekte (project_members.user_id = ich)
```

Sortierung, Filterung, Cover etc. sind UI-Details auf dieser einen Abfrage.

## 7. Abgrenzung zum Core

- „Projekt", „Freigabe", „Sichtbarkeit" sind **Schreib-App-Konzepte** und
  leben im Modul (eigene Migrationen, eigene Prüfungen). Der Core bleibt
  schlank: content_objects, Revisionen, Rollen-Guard, Registry.
- Einziger vorab nötiger Core-Eingriff: Link-Auflösung mit Projekt-Kontext
  (siehe 2.).
- Soll das pages-Modul später ebenfalls Freigaben bekommen, wird das
  Konzept **dann** in den Core gehoben — nicht vorher.

## 8. Bau-Reihenfolge

1. **Admin-Bereich „Nutzer verwalten"** (anlegen, Rolle setzen, deaktivieren):
   Voraussetzung für „spezifischen User freigeben" und für Testnutzer.
2. **Schreib-App-Fundament:** `projects` + `project_members` + die drei
   Prüf-Funktionen + Projekt-Übersicht („meine + freigegebene").
   Kern-Link-Auflösung um Projekt-Kontext erweitern.
3. **Texttypen** (Kapitel → Charaktere → Beziehungen → Hintergründe):
   alle laufen durch dieselben Prüf-Funktionen.
4. **Freigabe-UI** („für alle / für bestimmte"): nur noch ein Formular
   auf der längst existierenden Logik.

## 9. Nicht entschiedene Fragen (bewusst aufgeschoben)

- Projekt-Cover, Beschreibungstexte, Tags (UI-Detail, jederzeit nachrüstbar)
- „Für alle freigeben" als dritter Freigabe-Modus neben Einzelnutzern
- Änderungshistorie pro Projekt (Revisionen existieren pro Objekt; eine
  projektübergreifende Chronik ist ein eigenes Feature)
- Export (epub/PDF) — separates Modul-Thema
