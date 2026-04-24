# MBS-10761 — Offene Fragen

- **Entity-Tracker-Event-Handler:** SPEZ verweist auf Core-Events zur Invalidierung
  (`\core\event\course_deleted` etc.). Die vollständige Event-Liste über *alle*
  getrackten Entity-Typen (quiz, forum, assignment, …) ist in Baustein 9 zu
  ergänzen. Baustein 2 liefert nur die Infrastruktur.

- **Override-DB-Layer (Baustein 8):** `tool_description_resolver` hält in Baustein 2
  nur die Fallback-Kette Code → Site → Tenant **ohne** DB-Zugriff auf die noch
  nicht existierende `local_ai_manager_tool_overrides`-Tabelle. Die DB-Anbindung
  kommt mit Baustein 8. Interface so gestaltet, dass die Erweiterung additiv ist.

- **Connector-Tool-Calling-Methoden:** SPEZ §11 verlangt Erweiterungen in
  `\local_ai_manager\base_connector` (`supports_native_tool_calling`,
  `build_tool_calling_payload`, `parse_tool_calling_response`). Diese werden
  erst mit dem Orchestrator (Baustein 5) scharf geschaltet; Baustein 3 liefert
  nur die Protokoll-Klassen als reine Transformations-Helfer.

- **Run-Lock:** SPEZ §9.3 setzt Redis-Lock-Factory voraus (MBS-Setup).
  Die `lock_config::get_lock_factory('local_ai_manager_agent_run')`-Nutzung
  wird erst im Orchestrator (Baustein 5) aktiv; in Baustein 4 ist die
  Lock-Strategie in der Approval-External-Function bereits adressiert.
