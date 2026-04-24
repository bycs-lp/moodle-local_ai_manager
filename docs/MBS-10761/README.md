# MBS-10761 — Tool-Agent-Erweiterung für `local_ai_manager` + `block_ai_chat`

> **Feature-Branch (Ziel):** `feature/MBS-10761-tool-agent`
> **Status dieser Arbeitskopie:** Bausteine 0–10 umgesetzt, B11 (Performance/Finalisierung) offen
> **Moodle-Version:** 5.1 (Branch 501), PHP 8.2+

## Zweck dieses Verzeichnisses

Hier liegen alle MBS-10761-Artefakte, die **im Repo** verankert sein müssen:

- Diese `README.md` — Fortschritt und Übersicht.
- `DECISIONS.md` — Eigenständige technische Entscheidungen, wo die Spezifikation Spielraum lässt.
- `OPEN_QUESTIONS.md` — Verbliebene offene Fragen an Spec/Stakeholder.
- `block_ai_chat_changes.patch` — Patch-File für die parallele Umsetzung in `block_ai_chat` (nicht Teil dieses Plugins).

Die beiden Quell-Dokumente `SPEZ_AI_CHAT_AGENT.md` und `KONZEPT_AI_CHAT_AGENT.md` liegen im
Workspace-Root (`/home/peter/dev/501_docker_mbsmoodle_dev/`). Sie werden nicht in das Plugin-Repo
kopiert, da sie außerhalb des Plugins liegen; bei einem echten Feature-Branch würden sie
als Bootstrap-Commit unter `docs/MBS-10761/spec/` landen.

## Bausteine und Fortschritt

| # | Baustein | Status |
|---|----------|--------|
| 0 | Docs & Bootstrap | ✅ |
| 1 | Subplugin-Skeleton + DB-Schema + Persistent Entities + Capabilities | ✅ |
| 2 | Tool-Infrastruktur (Interfaces, Registry, WS-Adapter, Description-Resolver) | ✅ |
| 3 | Protokoll-Layer (native/emulated + Message-Adapter) | ✅ |
| 4 | Approval/HMAC, Trust-Resolver, Undo-Manager, Injection-Guard | ✅ |
| 5 | Orchestrator (run/resume loop, self-correction, lock) | ✅ |
| 6 | Core-Tools (`course_list`, `course_get_info`, `course_section_update_summary`) | ✅ |
| 7 | External Webservice `agent_run_start` + DI-Factory | ✅ (block_ai_chat-Frontend-Patch offen) |
| 8 | Override-UI (Admin) | ✅ |
| 9 | Privacy Provider, Retention Task, Events, Data Wiper | ✅ |
| 10 | Behat Generator + Custom Steps + Feature-Files | ✅ |
| 11 | Performance-Tests, Finalisierung | ⏳ |

## Datenbank-Tabellen (DB-Schema)

| Tabelle | Zweck |
|---------|-------|
| `local_ai_manager_agent_runs` | Ein Datensatz pro Agent-Lauf (Status, Prompt, Iterationen, Kontext) |
| `local_ai_manager_tool_calls` | Einzelne Tool-Aufrufe innerhalb eines Runs inkl. Approval-Status und Undo-Payload |
| `local_ai_manager_trust_prefs` | Pro-User Trust-Entscheidungen (Session/User-Scope, Ablauf) |
| `local_ai_manager_file_extract_cache` | Cache für Datei-Text-Extraktion (Ablaufdatum) |
| `local_ai_manager_tool_overrides` | Admin-Overrides (Beschreibung, Glossar, Aktiv-Flag) |

## Neue Admin-Einstellungen

- `local_ai_manager/agent_run_retention_days` (Standard: 90) — 0 = unbegrenzt.

## Scheduled Tasks

- `local_ai_manager\task\agent_run_cleanup` (täglich 03:17) — löscht abgelaufene Trust-Prefs
  und File-Extract-Cache-Einträge; bei `agent_run_retention_days > 0` zusätzlich alte
  Agent-Runs und deren Tool-Calls.

## Events

- `\local_ai_manager\event\agent_run_started`
- `\local_ai_manager\event\agent_run_finished`

## Privacy

Der Privacy-Provider exportiert und löscht Daten aus `agent_runs`, `tool_calls` und
`trust_prefs`. Löschung erfolgt datenerhaltend (Anonymisierung) für `agent_runs`/`tool_calls`,
Hard-Delete für `trust_prefs`.

## Ausführen

- **Upgrade:** `bindev/upgrade.sh`
- **Tests (PHPUnit):** `bindev/phpunit.sh --testsuite=local_ai_manager_testsuite`
- **Coding Standards:** `bindev/codechecker.sh /var/www/html/public/local/ai_manager`
- **PHPDoc-Check:** `bindev/moodlecheck.sh public/local/ai_manager`
- **Behat (MBS-10761 nur):** `bindev/behat.sh --tags=@mbs_10761`

## Verwandte Dokumente

- `SPEZ_AI_CHAT_AGENT.md` — Technische Detailspezifikation (Workspace-Root)
- `KONZEPT_AI_CHAT_AGENT.md` — Architektur-Konzept (Workspace-Root)

