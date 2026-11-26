# Standalone CGI interoperability

Installs the working responder and these pinned public dependency snapshots:

- `componenta/http-emitter`: `3faf7f95a0936d1031593e895f1a5f9edef0a1d8`
- `componenta/mimetype-detector`: `7131260a4f28724ff3e111a019d0260a2edacc57`

Runs real native CGI with PHP 8.4 and 8.5. Checks HTTP status, exact bytes, framing, HEAD, a prepared range response, 304 metadata, 416 and offloaded responses. Tests only the responder's consumption of the detector contract, not MIME classification. This fixture does not include DI or Validation and does not bypass the separate DI 6 dependency-conflict check.

The matrix covers Nyholm and Guzzle PSR-17 implementations for each PHP version. A denied response also verifies that previously staged file-offload instructions cannot select a file.
