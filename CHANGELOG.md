# Changelog

## 0.3.0 (2026-11-28)

The OpenAPI snapshot follows the published document again, 193 operations, all with a method.

- New operations: broadcast groups, one send from several sending domains (`broadcastGroups->create`, `list`, `get`, `update`, `remove`, `send` and `cancel`, with `broadcastGroups->listAll()`).
- `topics->list()` and `contacts->topics->list()` are cursor-paginated (`limit`, `after`, `before`) and have their `...All()` generators. They take `$params` before the call's options, like every other list.
- `automations->stop()` takes an optional body: `['cancel_runs' => true]` also cancels the runs still going. It sits before the call's options.
- Broadcasts take `audience_id` on create and update, and answer `audience_id` and `group_id`; domains take `tls` on create and update; an email answers `topic_id` and `tags`.
- `emails->delete()` documents the 7-day rule: an email that went out less than 7 days ago answers `409 email_recently_sent`.

## 0.2.0 (2026-11-26)

The OpenAPI snapshot is now the published document (https://billionsend.com/docs/api.openapi.json), 186 operations, all with a method.

- New operations: automations (`automations->create`, `list`, `get`, `update`, `remove`, `validate`, `duplicate`, `simulate`, `stop`, `metrics`; `automations->runs->list`, `get`, `cancel`), custom events (`events->create`, `list`, `get`, `update`, `remove`, `send`), contact imports (`contacts->imports->create`, `list`, `get`), `contacts->segments->list`, domain claims (`domains->claims->create`, `get`, `verify`), the brand (`brand->get`, `update`; `brand->logo->upload`, `delete`), `domains->unsubscribePage->logo->upload` and `delete`, `emails->share` and `unshare`, `emails->attachments->list` and `get`, `broadcasts->recipients` and `clickedLinks`, `logs->list` and `get`, `oauthGrants->list` and `revoke`, `webhooks->events->list`, `get`, `replay` and `events->attempts->list`, `webhooks->rotateSigningSecret`, `apiKeys->update` and `inboxes->agent->update`. Every cursor-paginated list has its `...All()` generator.
- Removed: `unsubscribes->delete` (a recipient's opt-out can no longer be deleted through the API) and `webhooks->rotate` (replaced by `webhooks->rotateSigningSecret`).
- Multipart uploads: a file is its content as a string, an open stream or the new `BillionSend\FileUpload`; array fields are encoded as JSON.
- `events->send` always carries an `Idempotency-Key`, like the sending operations.
- `usage->get()` answers the API's new report (`emails`, `contacts`, `segments`, `broadcasts`, `domains`, …) instead of `limits`, `today` and `period`.
- Suppressions can block a whole domain (`domain` / `domains` on create and batch add, `kind` on list); entries carry `kind`, `domain`, `removable` and `note`, and `email` is null on a domain entry.
- Domains answer `sending_limits` on `get` and their `unsubscribe_page` takes `support_url` and `powered_by`; broadcasts take `dormant_check` and `include_dormant`; `deliverability->get()` answers each sending domain's standing in `domains`.
- A 429 is retried for every request, a `POST` or `PATCH` without an `Idempotency-Key` included. 5xx, timeouts and network failures are still retried only for requests safe to repeat, and an upload without a key is not one.
- Refused before anything is sent: a path parameter of `.` or `..` (a URL folds it into another route), a header value with a non-ASCII or control character (`invalid_header`), and a control character in the API key. Whitespace around the API key is dropped.
- An empty list in a query parameter is left out instead of sent as `key=`.
- An inbox event stream resumes from an `id` sent without data, ignores a line cut off by a drop, and drops a connection silent for 60 seconds (before: 120).
- `Webhook::verify()` accepts whitespace around the secret and the header values, and a signature list separated by any whitespace.
- No more `curl_close()` deprecation warning on PHP 8.5.

## 0.1.2 (2026-11-17)

- An inbox event stream counts only the reconnects that fail in a row toward `'max_reconnects'`: each reconnect that opens the stream resets the count. A quiet inbox, whose stream the server closes every ten minutes or so with only keep-alive comments in between, no longer gives up after an hour.
- A reconnect the API refuses with a `Retry-After` (a 429, a 5xx) waits that long instead of the reconnect delay; one longer than 60 seconds throws that error at once. When the reconnects run out, the last one's error is thrown as its own class (`RateLimitError`, `APIError`, …) when the API answered; `APIConnectionError` ("gave up after N reconnects") only when it could not be reached.
- `APIError` carries the server's `Retry-After` too: `getRetryAfter()`, in seconds, null without one.

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
