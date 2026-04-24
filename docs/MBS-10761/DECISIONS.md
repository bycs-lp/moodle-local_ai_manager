# MBS-10761 — Technische Entscheidungen

Dokumentiert eigenständige Entscheidungen, wo die Spezifikation Spielraum gelassen hat.
Format: `[YYYY-MM-DD] <Kontext>: <Entscheidung> — <Begründung>`.

- [2026-04-22] Entity-Namespace: `\local_ai_manager\local\agent\entity\*` (wie in SPEZ §6.3 vorgesehen),
  Interfaces/DTOs/Services liegen flach unter `\local_ai_manager\agent\*`.
  — Trennt Domain-Entitäten (DB-gebunden) von Infrastruktur-Klassen.

- [2026-04-22] `approval_token`: Base64-Encoding wie in SPEZ §9.1, aber mit URL-safe
  `base64url` (str_replace `+/=` → `-_`-Strip), damit der Token in URL-Parametern transportierbar ist.
  — SPEZ zeigt `base64_encode`; in der Praxis landet der Token in JSON-Payloads und teils in URLs.

- [2026-04-22] HMAC-Secret wird via `upgrade.php`-Hook ersterstellt; Fallback-Initialisierung auch
  im `approval_token::boot_secret()` idempotent, damit Tests ohne Upgrade laufen.
  — Verhindert race conditions während Install.

- [2026-04-22] `tool_definition` nutzt `\core\context` (moderner Namespaced-Kontext, Moodle 4.2+) —
  kein `\context`. Einheitlich mit MBS-Coding-Standards (siehe `.github/copilot-instructions.md`).

- [2026-04-22] Persistent-Entity-Basis: `\core\persistent`. Nicht `xmldb_object`, nicht raw DML.
  — MBS-Standard aus Copilot-Instructions §4.2.

- [2026-04-22] Capability `local/ai_manager:configuretrust` (aus SPEZ §10.1) und
  `local/ai_manager:managetools` (aus KONZEPT §3.2.5) beide im Access-File.
  — Beide wurden in verschiedenen Dokumenten genannt; umgesetzt als zwei separate Caps
  (managetools: Tool-Overrides; configuretrust: Global-Trust).

- [2026-04-22] `injection_guard::wrap_untrusted` verwendet XML-ähnliche Tags
  `<untrusted_data source="…">…</untrusted_data>`; jeglicher Inhalt innerhalb wird
  HTML-/XML-escaped (`htmlspecialchars`) damit das Modell keine eingeschleuste
  Tag-Schließung als Instruktion wahrnehmen kann.
  — Defense in depth; spec spricht nur allgemein von "wrapping".

- [2026-04-22] Tabellenname `local_ai_manager_file_extract_cache` (35 Zeichen) statt
  `local_ai_manager_file_extraction_cache` (38 Zeichen). Beide wären formal erlaubt
  (Moodle-Limit 53 inkl. Präfix), aber der kürzere Name gewinnt Spielraum für
  MySQL-Constraint-/Index-Namen (`…_contenthash-mechanism_unq` kann bei langen
  Basisnamen gegen 64-Zeichen-Limits laufen).

- [2026-04-22] Emulated-Mode-JSON-Extraktion: Balanced-brace-Matcher mit explizitem
  String-Literal-State (nicht mit primitivem Brace-Counting) — einzelne `}` in Strings
  dürfen den Parser nicht verwirren.
  — SPEZ verweist auf den existierenden `extract_single_json_object()` aus
  `aipurpose_agent`; wir implementieren eine robustere Variante.
