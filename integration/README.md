# Response integration fixture

This fixture installs Config 4 and pinned snapshots of the other dependencies:

- `componenta/mimetype-detector` — `0d4b88e899fe828f0af41c57f6d47f615191d901`
- `componenta/http-emitter` — `5cb7c204845fc38100961e0d0933b48c892099d8`
- `componenta/di` — `e203077d12048936f078d37d1cf3badd661e1716`

The production dependency remains `componenta/mimetype-detector: ^2.0`; the unreleased snapshot is used only for isolated CI. No MIME detector tests, static analysis or benchmarks are run in this repository.

The integration builds the real services using the published configuration-provider contracts and DI 6's full and targeted attribute discovery. It exercises response construction and native CLI emission; the emitter's separate CGI test suite verifies actual HTTP fields. It does not claim full App HTTP/RoadRunner compatibility.
