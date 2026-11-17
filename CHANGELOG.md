# Changelog

## 0.1.1 (2026-11-17)

- The `...All()` generators throw a `BillionSendError` named `application_error` on an answer that is not a list page (no `data` array) or when the item to continue from has no `id`, instead of ending quietly: a truncated walk no longer passes for the whole list.

## 0.1.0 (2026-11-17)

- First version of the BillionSend SDK for PHP: every operation of the BillionSend API (139), grouped into services and named after each operation's `operationId` (`emails.receiving.attachments.list` is `$client->emails->receiving->attachments->list()`). `emails.metrics`, which also has sub-operations, is `$client->emails->metrics->get()`. Request bodies and answers are associative arrays, the JSON the API document describes.
- Errors as classes: `ValidationError`, `AuthenticationError`, `PermissionDeniedError`, `NotFoundError`, `ConflictError`, `RateLimitError` (with `getRetryAfter()`), `APIError`, `APIConnectionError` and `APITimeoutError`, all `BillionSendError`s with `getStatusCode()`, `getErrorName()`, `getMessage()` and `getRequestId()` (the answer's `x-request-id`).
- The sending operations always carry an `Idempotency-Key`: `'idempotency_key'`'s, or a new UUID per call that its retries reuse.
- Retries for 429, 5xx, timeouts, network failures and `409 concurrent_idempotent_requests`, on requests safe to repeat; `Retry-After` honoured up to 60 seconds, exponential backoff with jitter otherwise (0.5 s doubling, at most 8 s); per-attempt timeouts.
- List query parameters go comma-separated in one key, as the document declares them.
- `listAll()` / `searchAll()` generators over cursor-paginated lists, forward or backward.
- Inbox event streams (server-sent events) yielding `InboxStreamEvent` and `StreamGapEvent`, resuming with `Last-Event-ID` after a dropped connection; a gap handed out once per position; one inbox's stream ends after its `inbox.deleted` or `inbox.expired`.
- `Webhook::verify()` checks a webhook delivery's signature and timestamp and returns the parsed event.
- `BILLIONSEND_API_KEY` and `BILLIONSEND_BASE_URL`; a pluggable `Transport` (cURL by default); PHP 8.1 or later, no dependencies beyond `ext-curl` and `ext-json`.
