<?php

declare(strict_types=1);

namespace BillionSend\Tests;

use BillionSend\Client;

/**
 * What each of automations, events, logs, the brand, OAuth grants and the newer
 * methods of the other services puts on the wire.
 */
final class NewOperationsTest extends TestCase
{
    /**
     * @return iterable<string, array{callable(Client): mixed, string, string, 3?: string}>
     */
    public static function calls(): iterable
    {
        $q = ['limit' => 2, 'after' => 'x'];
        yield 'apiKeys.update' => [fn (Client $c) => $c->apiKeys->update('k1', ['name' => 'ci']), 'PATCH', '/api-keys/k1', '{"name":"ci"}'];
        yield 'automations.create' => [fn (Client $c) => $c->automations->create(['name' => 'Welcome']), 'POST', '/automations', '{"name":"Welcome"}'];
        yield 'automations.duplicate' => [fn (Client $c) => $c->automations->duplicate('a1'), 'POST', '/automations/a1/duplicate'];
        yield 'automations.get' => [fn (Client $c) => $c->automations->get('a1'), 'GET', '/automations/a1'];
        yield 'automations.list' => [fn (Client $c) => $c->automations->list($q + ['status' => 'enabled']), 'GET', '/automations?limit=2&after=x&status=enabled'];
        yield 'automations.metrics' => [fn (Client $c) => $c->automations->metrics('a1', ['start_date' => '2026-11-01', 'end_date' => '2026-11-02']), 'GET', '/automations/a1/metrics?start_date=2026-11-01&end_date=2026-11-02'];
        yield 'automations.remove' => [fn (Client $c) => $c->automations->remove('a1'), 'DELETE', '/automations/a1'];
        yield 'automations.runs.cancel' => [fn (Client $c) => $c->automations->runs->cancel('a1', 'r1'), 'POST', '/automations/a1/runs/r1/cancel'];
        yield 'automations.runs.get' => [fn (Client $c) => $c->automations->runs->get('a1', 'r1'), 'GET', '/automations/a1/runs/r1'];
        yield 'automations.runs.list' => [fn (Client $c) => $c->automations->runs->list('a1', $q), 'GET', '/automations/a1/runs?limit=2&after=x'];
        yield 'automations.simulate' => [fn (Client $c) => $c->automations->simulate('a1', ['contact_id' => 'c1']), 'POST', '/automations/a1/simulate', '{"contact_id":"c1"}'];
        yield 'automations.stop' => [fn (Client $c) => $c->automations->stop('a1'), 'POST', '/automations/a1/stop'];
        yield 'automations.stop cancelling its runs' => [fn (Client $c) => $c->automations->stop('a1', ['cancel_runs' => true]), 'POST', '/automations/a1/stop', '{"cancel_runs":true}'];
        yield 'automations.update' => [fn (Client $c) => $c->automations->update('a1', ['status' => 'enabled']), 'PATCH', '/automations/a1', '{"status":"enabled"}'];
        yield 'automations.validate' => [fn (Client $c) => $c->automations->validate(['steps' => [], 'connections' => []]), 'POST', '/automations/validate', '{"steps":[],"connections":[]}'];
        yield 'brand.get' => [fn (Client $c) => $c->brand->get(), 'GET', '/brand'];
        yield 'brand.logo.delete' => [fn (Client $c) => $c->brand->logo->delete(), 'DELETE', '/brand/logo'];
        yield 'brand.update' => [fn (Client $c) => $c->brand->update(['accent_color' => null]), 'PATCH', '/brand', '{"accent_color":null}'];
        yield 'broadcastGroups.cancel' => [fn (Client $c) => $c->broadcastGroups->cancel('g1'), 'POST', '/broadcast-groups/g1/cancel'];
        yield 'broadcastGroups.create' => [fn (Client $c) => $c->broadcastGroups->create(['name' => 'Launch']), 'POST', '/broadcast-groups', '{"name":"Launch"}'];
        yield 'broadcastGroups.get' => [fn (Client $c) => $c->broadcastGroups->get('g1'), 'GET', '/broadcast-groups/g1'];
        yield 'broadcastGroups.list' => [fn (Client $c) => $c->broadcastGroups->list($q), 'GET', '/broadcast-groups?limit=2&after=x'];
        yield 'broadcastGroups.remove' => [fn (Client $c) => $c->broadcastGroups->remove('g1'), 'DELETE', '/broadcast-groups/g1'];
        yield 'broadcastGroups.send' => [fn (Client $c) => $c->broadcastGroups->send('g1', ['scheduled_at' => 'in 1 hour']), 'POST', '/broadcast-groups/g1/send', '{"scheduled_at":"in 1 hour"}'];
        yield 'broadcastGroups.send without a body' => [fn (Client $c) => $c->broadcastGroups->send('g1'), 'POST', '/broadcast-groups/g1/send'];
        yield 'broadcastGroups.update' => [fn (Client $c) => $c->broadcastGroups->update('g1', ['name' => 'Launch 2']), 'PATCH', '/broadcast-groups/g1', '{"name":"Launch 2"}'];
        yield 'broadcasts.clickedLinks' => [fn (Client $c) => $c->broadcasts->clickedLinks('b1', $q), 'GET', '/broadcasts/b1/clicked-links?limit=2&after=x'];
        yield 'broadcasts.recipients' => [fn (Client $c) => $c->broadcasts->recipients('b1', ['type' => 'bounced', 'bounce_type' => 'Permanent']), 'GET', '/broadcasts/b1/recipients?type=bounced&bounce_type=Permanent'];
        yield 'contacts.imports.get' => [fn (Client $c) => $c->contacts->imports->get('i1'), 'GET', '/contacts/imports/i1'];
        yield 'contacts.imports.list' => [fn (Client $c) => $c->contacts->imports->list(['status' => 'queued']), 'GET', '/contacts/imports?status=queued'];
        yield 'contacts.segments.list' => [fn (Client $c) => $c->contacts->segments->list('ana@example.com', $q), 'GET', '/contacts/ana%40example.com/segments?limit=2&after=x'];
        yield 'domains.claims.create' => [fn (Client $c) => $c->domains->claims->create(['name' => 'acme.dev']), 'POST', '/domains/claim', '{"name":"acme.dev"}'];
        yield 'domains.claims.get' => [fn (Client $c) => $c->domains->claims->get('d1'), 'GET', '/domains/d1/claim'];
        yield 'domains.claims.verify' => [fn (Client $c) => $c->domains->claims->verify('d1'), 'POST', '/domains/d1/claim/verify'];
        yield 'domains.unsubscribePage.logo.delete' => [fn (Client $c) => $c->domains->unsubscribePage->logo->delete('d1'), 'DELETE', '/domains/d1/unsubscribe-page/logo'];
        yield 'emails.attachments.get' => [fn (Client $c) => $c->emails->attachments->get('e1', 'f1'), 'GET', '/emails/e1/attachments/f1'];
        yield 'emails.attachments.list' => [fn (Client $c) => $c->emails->attachments->list('e1', $q), 'GET', '/emails/e1/attachments?limit=2&after=x'];
        yield 'emails.share' => [fn (Client $c) => $c->emails->share('e1', ['expires_in' => '2 hours']), 'POST', '/emails/e1/share', '{"expires_in":"2 hours"}'];
        yield 'emails.share without a body' => [fn (Client $c) => $c->emails->share('e1'), 'POST', '/emails/e1/share'];
        yield 'emails.unshare' => [fn (Client $c) => $c->emails->unshare('e1'), 'DELETE', '/emails/e1/share'];
        yield 'events.create' => [fn (Client $c) => $c->events->create(['name' => 'user.signed_up']), 'POST', '/events', '{"name":"user.signed_up"}'];
        yield 'events.get' => [fn (Client $c) => $c->events->get('user.signed_up'), 'GET', '/events/user.signed_up'];
        yield 'events.list' => [fn (Client $c) => $c->events->list($q), 'GET', '/events?limit=2&after=x'];
        yield 'events.remove' => [fn (Client $c) => $c->events->remove('user.signed_up'), 'DELETE', '/events/user.signed_up'];
        yield 'events.send' => [fn (Client $c) => $c->events->send(['event' => 'user.signed_up', 'email' => 'ana@example.com']), 'POST', '/events/send', '{"event":"user.signed_up","email":"ana@example.com"}'];
        yield 'events.update' => [fn (Client $c) => $c->events->update('user.signed_up', ['schema' => null]), 'PATCH', '/events/user.signed_up', '{"schema":null}'];
        yield 'inboxes.agent.update' => [fn (Client $c) => $c->inboxes->agent->update('support@example.com', ['x' => 1]), 'PATCH', '/inboxes/support%40example.com/agent', '{"x":1}'];
        yield 'logs.get' => [fn (Client $c) => $c->logs->get('l1'), 'GET', '/logs/l1'];
        yield 'logs.list' => [fn (Client $c) => $c->logs->list($q), 'GET', '/logs?limit=2&after=x'];
        yield 'oauthGrants.list' => [fn (Client $c) => $c->oauthGrants->list($q), 'GET', '/oauth/grants?limit=2&after=x'];
        yield 'oauthGrants.revoke' => [fn (Client $c) => $c->oauthGrants->revoke('g1'), 'DELETE', '/oauth/grants/g1'];
        yield 'contacts.topics.list' => [fn (Client $c) => $c->contacts->topics->list('ana@example.com', $q), 'GET', '/contacts/ana%40example.com/topics?limit=2&after=x'];
        yield 'topics.list' => [fn (Client $c) => $c->topics->list($q), 'GET', '/topics?limit=2&after=x'];
        yield 'suppressions.list by kind' => [fn (Client $c) => $c->suppressions->list(['kind' => 'domain']), 'GET', '/suppressions?kind=domain'];
        yield 'webhooks.events.attempts.list' => [fn (Client $c) => $c->webhooks->events->attempts->list('w1', 'v1', $q), 'GET', '/webhooks/w1/events/v1/attempts?limit=2&after=x'];
        yield 'webhooks.events.get' => [fn (Client $c) => $c->webhooks->events->get('w1', 'v1'), 'GET', '/webhooks/w1/events/v1'];
        yield 'webhooks.events.list' => [fn (Client $c) => $c->webhooks->events->list('w1', $q), 'GET', '/webhooks/w1/events?limit=2&after=x'];
        yield 'webhooks.events.replay' => [fn (Client $c) => $c->webhooks->events->replay('w1', 'v1'), 'POST', '/webhooks/w1/events/v1/replay'];
        yield 'webhooks.rotateSigningSecret' => [fn (Client $c) => $c->webhooks->rotateSigningSecret('w1', ['overlap_hours' => 0]), 'POST', '/webhooks/w1/signing-secret/rotate', '{"overlap_hours":0}'];
    }

    /**
     * @dataProvider calls
     * @param callable(Client): mixed $call
     */
    public function testSendsTheRequest(callable $call, string $method, string $target, ?string $body = null): void
    {
        [$client, $t] = $this->client(fn () => FakeTransport::json(200, '{"id":"x"}'));
        $this->assertSame(['id' => 'x'], $call($client));
        $this->assertCount(1, $t->requests);
        $this->assertSame($method, $t->requests[0]->method);
        $this->assertSame("https://api.test$target", $t->requests[0]->url);
        $this->assertSame($body, $t->requests[0]->body);
    }

    /**
     * @return iterable<string, array{callable(Client): \Generator, string}>
     */
    public static function walks(): iterable
    {
        yield 'automations' => [fn (Client $c) => $c->automations->listAll(), '/automations'];
        yield 'automation runs' => [fn (Client $c) => $c->automations->runs->listAll('a1'), '/automations/a1/runs'];
        yield 'broadcast groups' => [fn (Client $c) => $c->broadcastGroups->listAll(), '/broadcast-groups'];
        yield 'contact topics' => [fn (Client $c) => $c->contacts->topics->listAll('c1'), '/contacts/c1/topics'];
        yield 'topics' => [fn (Client $c) => $c->topics->listAll(), '/topics'];
        yield 'clicked links' => [fn (Client $c) => $c->broadcasts->clickedLinksAll('b1'), '/broadcasts/b1/clicked-links'];
        yield 'recipients' => [fn (Client $c) => $c->broadcasts->recipientsAll('b1'), '/broadcasts/b1/recipients'];
        yield 'contact imports' => [fn (Client $c) => $c->contacts->imports->listAll(), '/contacts/imports'];
        yield 'contact segments' => [fn (Client $c) => $c->contacts->segments->listAll('c1'), '/contacts/c1/segments'];
        yield 'attachments' => [fn (Client $c) => $c->emails->attachments->listAll('e1'), '/emails/e1/attachments'];
        yield 'events' => [fn (Client $c) => $c->events->listAll(), '/events'];
        yield 'logs' => [fn (Client $c) => $c->logs->listAll(), '/logs'];
        yield 'oauth grants' => [fn (Client $c) => $c->oauthGrants->listAll(), '/oauth/grants'];
        yield 'webhook events' => [fn (Client $c) => $c->webhooks->events->listAll('w1'), '/webhooks/w1/events'];
        yield 'webhook attempts' => [fn (Client $c) => $c->webhooks->events->attempts->listAll('w1', 'v1'), '/webhooks/w1/events/v1/attempts'];
    }

    /**
     * @dataProvider walks
     * @param callable(Client): \Generator $walk
     */
    public function testWalksTheNewLists(callable $walk, string $path): void
    {
        [$client, $t] = $this->client(fn (int $n) => FakeTransport::json(200, $n === 1
            ? '{"object":"list","has_more":true,"data":[{"id":"a"}]}'
            : '{"object":"list","has_more":false,"data":[{"id":"b"}]}'));
        $this->assertSame(['a', 'b'], array_column(iterator_to_array($walk($client)), 'id'));
        $this->assertSame("https://api.test$path", $t->requests[0]->url);
        $this->assertSame("https://api.test$path?after=a", $t->requests[1]->url);
    }

    public function testAnEventCarriesOneKeyAcrossItsRetries(): void
    {
        [$client, $t] = $this->client(fn (int $n) => $n === 1 ? FakeTransport::json(503, '{}') : FakeTransport::json(202, '{}'));
        $client->events->send(['event' => 'x', 'email' => 'ana@example.com']);
        $this->assertCount(2, $t->requests);
        $this->assertMatchesRegularExpression(self::UUID_V4, $t->requests[0]->headers['idempotency-key']);
        $this->assertSame($t->requests[0]->headers['idempotency-key'], $t->requests[1]->headers['idempotency-key']);
    }
}
