# Standalone CGI interoperability

Installs the working responder and these pinned public dependency snapshots:

- `componenta/http-emitter`: `73a3d13d7d9f2033cf170e9d73dd1e6b2d72ddff`
- MIME Type Detector: published stable `^2.0` release series.

Runs real native CGI with PHP 8.4 and 8.5. Checks HTTP status, exact bytes, framing, HEAD, a prepared range response, 304 metadata, 416 and offloaded responses. Tests only the responder's consumption of the detector contract, not MIME classification. This fixture does not include DI or Validation; the separate DI integration uses their pinned compatible development revisions.

The matrix covers Nyholm and Guzzle PSR-17 implementations for each PHP version. A denied response also verifies that previously staged file-offload instructions cannot select a file.

The private-cache scenario verifies that old response and native nginx expiration overrides cannot survive the explicit no-store policy. It checks the CGI output, not the behavior of a running nginx cache.
