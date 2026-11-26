<?php

declare(strict_types=1);

namespace BillionSend\Tests;

use BillionSend\Client;
use BillionSend\Exception\APIError;
use BillionSend\Exception\APITimeoutError;
use BillionSend\Exception\BillionSendError;
use BillionSend\FileUpload;
use BillionSend\Request;
use BillionSend\Response;

/**
 * The multipart uploads: contacts.imports.create and the two logo uploads.
 */
final class UploadTest extends TestCase
{
    private const PNG = "\x89PNG\r\n\x1a\nrest";

    /**
     * @return list<array{headers: string, content: string}>
     */
    private static function parts(Request $r): array
    {
        self::assertSame(1, preg_match('#^multipart/form-data; boundary=(\S+)$#', $r->headers['content-type'], $m));
        $chunks = explode("--{$m[1]}", (string) $r->body);
        self::assertSame('', array_shift($chunks));
        self::assertSame("--\r\n", array_pop($chunks));
        return array_map(static function (string $chunk): array {
            [$headers, $content] = explode("\r\n\r\n", substr($chunk, 2, -2), 2);
            return ['headers' => $headers, 'content' => $content];
        }, $chunks);
    }

    public function testAnImportSendsTheFileAndItsFields(): void
    {
        [$client, $t] = $this->client(fn () => FakeTransport::json(200, '{"object":"contact_import","id":"i1"}'));
        $csv = "email\nana@example.com\n";
        $got = $client->contacts->imports->create([
            'file' => new FileUpload($csv, 'people.csv'),
            'column_map' => ['email' => 'email'],
            'on_conflict' => 'skip',
            'segments' => [['id' => 's1']],
            'topics' => '[{"id":"t1","subscription":"opt_in"}]',
        ]);
        $this->assertSame('i1', $got['id']);
        $r = $t->requests[0];
        $this->assertSame(['POST', 'https://api.test/contacts/imports'], [$r->method, $r->url]);
        $this->assertSame([
            ['headers' => "Content-Disposition: form-data; name=\"file\"; filename=\"people.csv\"\r\nContent-Type: text/csv", 'content' => $csv],
            ['headers' => 'Content-Disposition: form-data; name="column_map"', 'content' => '{"email":"email"}'],
            ['headers' => 'Content-Disposition: form-data; name="on_conflict"', 'content' => 'skip'],
            ['headers' => 'Content-Disposition: form-data; name="segments"', 'content' => '[{"id":"s1"}]'],
            ['headers' => 'Content-Disposition: form-data; name="topics"', 'content' => '[{"id":"t1","subscription":"opt_in"}]'],
        ], self::parts($r));
    }

    public function testLeavesOutNullAndEmptyFields(): void
    {
        [$client, $t] = $this->client(fn () => FakeTransport::json(200, '{}'));
        $client->contacts->imports->create(['file' => 'email', 'column_map' => null, 'segments' => []]);
        $this->assertSame([
            ['headers' => "Content-Disposition: form-data; name=\"file\"; filename=\"file\"\r\nContent-Type: application/octet-stream", 'content' => 'email'],
        ], self::parts($t->requests[0]));
    }

    public function testALogoIsNamedAfterItsBytes(): void
    {
        [$client, $t] = $this->client(fn () => FakeTransport::json(200, '{"object":"logo","logo_url":"u"}'));
        $client->brand->logo->upload(['file' => self::PNG]);
        $client->domains->unsubscribePage->logo->upload('d1', ['file' => "\xff\xd8\xff\xe0"]);
        $client->brand->logo->upload(['file' => "RIFF0000WEBPrest"]);
        $this->assertSame(['PUT', 'https://api.test/brand/logo'], [$t->requests[0]->method, $t->requests[0]->url]);
        $this->assertSame(['PUT', 'https://api.test/domains/d1/unsubscribe-page/logo'], [$t->requests[1]->method, $t->requests[1]->url]);
        foreach ([['logo.png', 'image/png'], ['logo.jpg', 'image/jpeg'], ['logo.webp', 'image/webp']] as $i => [$name, $type]) {
            $this->assertSame("Content-Disposition: form-data; name=\"file\"; filename=\"$name\"\r\nContent-Type: $type", self::parts($t->requests[$i])[0]['headers']);
        }
        $this->assertSame(self::PNG, self::parts($t->requests[0])[0]['content']);
    }

    public function testAnOpenFileAndAPathKeepTheirNames(): void
    {
        [$client, $t] = $this->client(fn () => FakeTransport::json(200, '{}'));
        $dir = sys_get_temp_dir() . '/billionsend-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $path = "$dir/brand.png";
        file_put_contents($path, self::PNG);
        try {
            $handle = fopen($path, 'rb');
            $client->brand->logo->upload(['file' => $handle]);
            fclose($handle);
            $client->brand->logo->upload(['file' => FileUpload::fromPath($path)]);
            $client->brand->logo->upload(['file' => FileUpload::fromPath($path, "a\"b\r\n.png", 'image/x-test')]);
        } finally {
            unlink($path);
            rmdir($dir);
        }
        foreach ([0, 1] as $i) {
            $this->assertSame(['headers' => "Content-Disposition: form-data; name=\"file\"; filename=\"brand.png\"\r\nContent-Type: image/png", 'content' => self::PNG], self::parts($t->requests[$i])[0]);
        }
        $this->assertSame("Content-Disposition: form-data; name=\"file\"; filename=\"a%22b%0D%0A.png\"\r\nContent-Type: image/x-test", self::parts($t->requests[2])[0]['headers']);
    }

    public function testRefusesWhatCannotBeSent(): void
    {
        [$client, $t] = $this->client(fn () => FakeTransport::json(200, '{}'));
        foreach ([
            fn () => $client->brand->logo->upload(['file' => 42]),
            fn () => $client->contacts->imports->create(['file' => 'x', 'on_conflict' => new \stdClass()]),
            fn () => FileUpload::fromPath('/nonexistent/logo.png'),
        ] as $call) {
            try {
                $call();
                $this->fail('no error');
            } catch (\InvalidArgumentException) {
            }
        }
        try {
            $client->brand->logo->upload(['file' => new FileUpload('x', 'a', "text/plain\r\nx: y")]);
            $this->fail('no error');
        } catch (BillionSendError $e) {
            $this->assertSame('invalid_header', $e->getErrorName());
        }
        $this->assertSame([], $t->requests);
    }

    /**
     * @return iterable<string, array{callable(Client): mixed}>
     */
    public static function uploads(): iterable
    {
        yield 'brand logo (PUT)' => [fn (Client $c) => $c->brand->logo->upload(['file' => self::PNG])];
        yield 'domain logo (PUT)' => [fn (Client $c) => $c->domains->unsubscribePage->logo->upload('d1', ['file' => self::PNG])];
        yield 'import (POST)' => [fn (Client $c) => $c->contacts->imports->create(['file' => 'email'])];
    }

    /**
     * @dataProvider uploads
     * @param callable(Client): mixed $upload
     */
    public function testAnUploadIsRepeatedOnlyAfterA429(callable $upload): void
    {
        foreach ([
            fn (): Response => FakeTransport::json(503, '{"name":"unavailable","message":"later"}'),
            fn (): Response => throw new APITimeoutError('Request timed out'),
            fn (): Response => throw new \BillionSend\Exception\APIConnectionError('Network error: reset'),
        ] as $answer) {
            [$client, $t] = $this->client($answer);
            try {
                $upload($client);
                $this->fail('no error');
            } catch (APIError | \BillionSend\Exception\APIConnectionError) {
            }
            $this->assertCount(1, $t->requests);
        }
        [$client, $t] = $this->client(fn (int $n) => $n === 1
            ? FakeTransport::json(429, '{"name":"rate_limit_exceeded","message":"slow"}', ['retry-after' => '2'])
            : FakeTransport::json(200, '{}'));
        $upload($client);
        $this->assertCount(2, $t->requests);
        $this->assertSame([2.0], $this->waits);
        $this->assertSame($t->requests[0]->body, $t->requests[1]->body);
    }
}
