# Response integration fixture

This fixture installs the released Componenta Config 4, MIME Type Detector 2, Validation 3.2 and HTTP Emitter 2 release series, together with the pinned DI 6 development revision:

- `componenta/di` — `e203077d12048936f078d37d1cf3badd661e1716`

The runtime dependency remains `componenta/mimetype-detector: ^2.0`. Neither the detector nor Validation is modified or audited here. The integration fixture explicitly requires `componenta/validation: ^3.2` because Validation 3.1 requires MIME Detector 1.

The fixture builds real services using DI 6's full and targeted attribute discovery and public configuration-provider contracts. It exercises response construction and native CLI emission; the separate CGI fixture verifies HTTP fields with Nyholm and Guzzle. A fully stable DI 6 installation requires its release. This fixture does not claim App HTTP/RoadRunner compatibility.
