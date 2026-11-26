# Upgrading to 2.x

- Requires Componenta Config 4 and MIME Type Detector 2. Update the dependencies before installing the new responder. The public detector dependency is narrowed to `MimeTypeDetectorInterface`; pass a compatible detector returning nullable `MimeType` values.
- `back()` accepts root-relative referrers by default. To allow an absolute referrer from the application's own origin, pass `trustedOrigin: 'https://shop.example'`. Untrusted or malformed referrers use the trusted fallback.
- File helpers do not advertise `Accept-Ranges` merely because a body can be rewound. Applications that implement byte ranges must explicitly set the applicable metadata.
- File paths must identify readable regular files, not stream wrappers. File names must be valid UTF-8 basenames. International names use an ASCII fallback and an extended `filename*` parameter.
- `methodNotAllowed()` validates method tokens and preserves case; invalid entries now throw. Invalid ETags and malformed JSONP callback expressions throw.
- When no content is permitted, the response does not consume or take ownership of caller-owned streams. Previously created responses passed to `respond()` still pass through unchanged.

The builder is not a transport, authorization layer, range handler, or upload safety validator. For 401 responses, the caller must provide the appropriate authentication challenge before emission; for 206 responses, it must provide the correct range metadata.
- Seekable response bodies are now rewound during response construction. Non-seekable bodies retain their cursor without an inferred content length.

- Unreadable body streams are rejected. Status and response fields are validated before a caller resource is wrapped.

- New responses discard stale representation, framing and file-offload defaults from a response factory; unrelated application-wide headers are retained.

- New cache policies replace previous expiration overrides (`X-Accel-Expires`, `Expires`, `Pragma`). New responses do not inherit a factory's `Location` or CGI `Status`.
