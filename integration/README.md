# Response integration fixture

This fixture installs released Componenta Config 4 and MIME Type Detector 2, together with these pinned development snapshots:

- `componenta/http-emitter` — `82f4b773677979ec1a79f97bf9e8ae1dd0d4a097`
- `componenta/di` — `e203077d12048936f078d37d1cf3badd661e1716`
- `componenta/validation` — `59abbe8d1388fe9ccc016c12dc12a2de389685f7` (compat/mimetype-detector-v2, prospective 3.2)

The production dependency remains `componenta/mimetype-detector: ^2.0`. No MIME detector tests, static analysis or benchmarks are run in this repository.

The fixture builds the real services using the configuration-provider contracts and DI 6's full and targeted attribute discovery. It exercises response construction and native CLI emission; the standalone CGI fixture verifies actual HTTP fields. It does not claim full App HTTP/RoadRunner compatibility.

Validation 3.1.0 still requires MIME Detector 1.x. Only the integration project allows the pinned compatible Validation development version; the production packages have no development dependency override. A fully stable DI/Validation installation requires publication of compatible versions.
