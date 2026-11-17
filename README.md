# BillionSend PHP SDK

The official PHP client for the [BillionSend](https://billionsend.com) email API: send email, run inboxes for people and agents, stream inbox events, verify webhooks, and manage domains, contacts and broadcasts.

- Every API operation (139), generated from the BillionSend OpenAPI document.
- Errors as classes, all `BillionSendError`s with the status, the API's error name and the request id.
- Safe sends: every send carries an `Idempotency-Key`, so retries after a timeout never send twice.
- Generators for every list, and live inbox events with automatic resume.
- PHP 8.1 or later, no dependencies beyond `ext-curl` and `ext-json`.

Documentation: [billionsend.com/docs](https://billionsend.com/docs) · Source: [github.com/billionsend/billionsend-php](https://github.com/billionsend/billionsend-php)

## Install

```bash
composer require billionsend/billionsend-php
```

## Quick start

Create an API key on the dashboard (it starts with `bs_`) and pass it to the client, or set `BILLIONSEND_API_KEY`:

```php
use BillionSend\Client;

$client = new Client(); // reads BILLIONSEND_API_KEY
// or: new Client('bs_...')

$sent = $client->emails->send([
    'from' => 'Acme <hello@acme.dev>',
    'to' => ['ana@example.com'],
    'subject' => 'Welcome to Acme',
    'html' => '<p>Glad you are here.</p>',
]);
echo $sent['id'];
```

`BILLIONSEND_BASE_URL` (or `'base_url'` in the options) points the client at another API host. A key restricted to one inbox is what an agent should hold; see [API keys](https://billionsend.com/docs/api-keys).

## How the methods are named

Every operation is a method, named after its `operationId`: the dotted path is the chain of services. `emails.send` is `$client->emails->send()`, `emails.receiving.attachments.list` is `$client->emails->receiving->attachments->list()`, `inboxes.threads.labels.set` is `$client->inboxes->threads->labels->set()`. The one operation that also has sub-operations, `emails.metrics`, is `$client->emails->metrics->get()` beside `->links()`, `->countries()` and `->clients()`.

A method takes the path parameters in order, then the request body, then the query and header parameters (`$params`), then the call's options. Bodies and answers are associative arrays: the JSON the [API reference](https://billionsend.com/docs) describes, field for field. List parameters can be PHP lists; they go comma-separated in one key.

```php
$page = $client->emails->list(['limit' => 50]);
foreach ($page['data'] as $email) {
    echo $email['id'], ' ', $email['subject'], "\n";
}
```

## Receive mail in an agent loop

Open an inbox's event stream and act on each new message. The stream reconnects on its own with `Last-Event-ID`, replaying what happened while it was away; keep `$stream->lastEventId()` and pass it back as `'last_event_id'` to resume after a restart too. A `StreamGapEvent` means the stream resumed from a position older than the server keeps (24 hours): read the lists again.

```php
use BillionSend\InboxStreamEvent;
use BillionSend\StreamGapEvent;

$stream = $client->inboxes->events->streamInbox('support@acme.dev', ['types' => 'inbox.email.received']);
foreach ($stream as $event) {
    if ($event instanceof StreamGapEvent) {
        continue; // events may be missing: read the threads list again
    }
    $data = $event->payload(); // thread_id, email_id, ...
    $client->inboxes->threads->emails->reply($event->inboxId(), $data['thread_id'], $data['email_id'], [
        'text' => 'Thanks, we are on it.',
    ]);
}
```

One inbox's stream ends after that inbox's `inbox.deleted` or `inbox.expired`; `$client->inboxes->events->stream()` follows every inbox the key can read. The options `'reconnect' => false`, `'max_reconnects'` (default 5) and `'reconnect_delay'` (seconds, default 1; the server's `retry` overrides it) tune the reconnects; after the last one the loop throws an `APIConnectionError`, and an error a retry cannot fix (a revoked key) is thrown as its own class.

## Lists

Every cursor-paginated list has an `...All()` generator that fetches pages as you go: forward from `'after'`, or backward when `'before'` is set.

```php
foreach ($client->emails->listAll(['limit' => 100]) as $email) {
    echo $email['id'], "\n";
}
```

## Errors

A failed call throws one of `ValidationError` (400, 422), `AuthenticationError` (401, or no key configured), `PermissionDeniedError` (403), `NotFoundError` (404), `ConflictError` (409), `RateLimitError` (429, with `getRetryAfter()` in seconds), `APIError` (5xx), `APIConnectionError` (no answer) or `APITimeoutError` (the attempt timed out), all in `BillionSend\Exception` and all `BillionSendError`s:

```php
use BillionSend\Exception\BillionSendError;
use BillionSend\Exception\NotFoundError;

try {
    $email = $client->emails->get($id);
} catch (NotFoundError) {
    // no such email
} catch (BillionSendError $e) {
    error_log(sprintf('%s %s: %s (request %s)', $e->getStatusCode(), $e->getErrorName(), $e->getMessage(), $e->getRequestId()));
}
```

`getErrorName()` is the API's error name (such as `validation_error`); `getRequestId()` is the answer's `x-request-id`, the request on the dashboard's Logs page.

## Retries and idempotency

Requests safe to repeat are retried on 429, 5xx, timeouts and network failures (`'max_retries'`, default 2): `GET`, `PUT` and `DELETE`, and the eight sending operations, which always carry an `Idempotency-Key`. A sending operation's retries reuse one key, so a retry after a timeout never sends twice; a `409 concurrent_idempotent_requests` (the first attempt still in progress) is retried too. The wait is the server's `Retry-After` when it sends one (longer than 60 seconds: the error is thrown instead), otherwise an exponential backoff with jitter from 0.5 s, at most 8 seconds. Each attempt is bounded by `'timeout'` (seconds, default 60).

Pass your own key to make a send safe across calls and processes too:

```php
$client->emails->send($email, ['idempotency_key' => "welcome/$userId"]);
```

The last argument of every method takes these options: `'idempotency_key'`, `'headers'`, `'timeout'`, `'max_retries'`, and `'response_headers' => &$headers` to get the answer's headers back. The client's own options are `'base_url'`, `'timeout'`, `'max_retries'`, `'headers'` and `'transport'` (any `BillionSend\Transport`, to send through your own HTTP stack).

## Verify webhooks

Pass the raw body, the request headers and the endpoint's `whsec_` secret. The signature (HMAC-SHA256, any of the signatures a secret rotation sends) and the timestamp (within 5 minutes) are checked before the event is parsed:

```php
use BillionSend\Exception\WebhookVerificationError;
use BillionSend\Webhook;

try {
    $event = Webhook::verify(file_get_contents('php://input'), getallheaders(), getenv('BILLIONSEND_WEBHOOK_SECRET'));
} catch (WebhookVerificationError) {
    http_response_code(400);
    exit;
}
if ($event['type'] === 'inbox.email.received') {
    // ...
}
```

## Development

The service classes are generated from `openapi/api.openapi.json`, a snapshot of the published API document:

```bash
composer install
php bin/sync-openapi.php     # refresh the snapshot (or --from <path>)
php bin/generate.php         # regenerate src/Service, src/ClientServices.php and the coverage calls
vendor/bin/phpunit
```

The coverage test calls every operation of the snapshot through the SDK against a transport that knows only the snapshot's operations: a new operation without a method, or a method that reaches anything else, fails it.

## Releasing

Packagist reads versions from this repository's tags. It learns of a new tag from the repository's Packagist webhook (Settings → Webhooks: payload URL `https://packagist.org/api/github?username=<Packagist user>`, content type `application/json`, secret: that user's Packagist API token, push events), or from the update API when no webhook is set:

```bash
curl -X POST "https://packagist.org/api/update-package?username=<Packagist user>&apiToken=<API token>" \
  -H "Content-Type: application/json" \
  -d '{"repository":{"url":"https://github.com/billionsend/billionsend-php"}}'
```

1. Set `VERSION` in `src/Version.php`, add the version to `CHANGELOG.md`, and commit.
2. Tag the commit `v<VERSION>` (for example `v0.1.0`) and push the tag.
3. Run the `release` workflow (Actions → release → Run workflow) on that tag. It refuses a tag that does not name `src/Version.php`'s version, runs the checks and creates the GitHub release. With the webhook, Packagist has the version within a minute of the tag's push (otherwise run the update call above); `composer require billionsend/billionsend-php:^<VERSION>` installs it.

## License

MIT
