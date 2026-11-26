# Standalone CGI interoperability

Installs the working responder and these pinned public dependency snapshots:

- `componenta/http-emitter`: `5cb7c204845fc38100961e0d0933b48c892099d8`
- `componenta/mimetype-detector`: `06f55cfe8d4d0d9f865b4d0ac89a87f12ef4d95e`

Runs real native CGI with PHP 8.4 and 8.5. Checks HTTP status, exact bytes, framing, HEAD, a prepared range response, 304 metadata, 416 and offloaded responses. Tests only the responder's consumption of the detector contract, not MIME classification. This fixture does not include DI or Validation and does not bypass the separate DI 6 dependency-conflict check.
