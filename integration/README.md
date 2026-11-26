# Response integration fixture

This fixture installs Config 4 and pinned snapshots of the other dependencies:

- `componenta/mimetype-detector` — `7131260a4f28724ff3e111a019d0260a2edacc57`
- `componenta/http-emitter` — `3faf7f95a0936d1031593e895f1a5f9edef0a1d8`
- `componenta/di` — `e203077d12048936f078d37d1cf3badd661e1716`

The production dependency remains `componenta/mimetype-detector: ^2.0`; the unreleased snapshot is used only for isolated CI. No MIME detector tests, static analysis or benchmarks are run in this repository.

The integration builds the real services using the published configuration-provider contracts and DI 6's full and targeted attribute discovery. It exercises response construction and native CLI emission; the emitter's separate CGI test suite verifies actual HTTP fields. It does not claim full App HTTP/RoadRunner compatibility.

## Known external integration blocker

DI 6 currently requires `componenta/validation ^3.1`, whose released version 3.1.0 and current main require `componenta/mimetype-detector ^1.0`. The responder requires `^2.0`. The separate DI workflow is intentionally not marked successful or bypassed; update Validation before rerunning the real DI integration. The standalone CGI fixture covers the responder/emitter combination without DI.
