<?php

declare(strict_types=1);

namespace BillionSend\Tests;

use BillionSend\Exception\BillionSendError;
use BillionSend\Exception\WebhookVerificationError;
use BillionSend\Webhook;

final class WebhookTest extends TestCase
{
    // The known-answer vector the API's own signing test pins.
    private const SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';
    private const ID = 'msg_p5jXN8AQM9LWM0D4loKWxJek';
    private const TIMESTAMP = '1614265330';
    private const PAYLOAD = '{"test": 2432232314}';
    private const SIGNATURE = 'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=';
    private const AT = 1614265330;

    /**
     * @return array<string, string>
     */
    private static function headers(string $signature = self::SIGNATURE, string $timestamp = self::TIMESTAMP): array
    {
        return ['Webhook-Id' => self::ID, 'webhook-timestamp' => $timestamp, 'WEBHOOK-SIGNATURE' => $signature];
    }

    private static function sign(string $secret, string $id, string $timestamp, string $payload): string
    {
        $key = base64_decode(substr($secret, 6), true);
        return 'v1,' . base64_encode(hash_hmac('sha256', "$id.$timestamp.$payload", (string) $key, true));
    }

    private function refused(callable $verify, string $want): void
    {
        try {
            $verify();
            $this->fail('no error');
        } catch (WebhookVerificationError $e) {
            $this->assertInstanceOf(BillionSendError::class, $e);
            $this->assertSame('webhook_verification_error', $e->getErrorName());
            $this->assertStringContainsString($want, $e->getMessage());
        }
    }

    public function testVerifiesThePublishedVector(): void
    {
        $this->assertSame(['test' => 2432232314], Webhook::verify(self::PAYLOAD, self::headers(), self::SECRET, now: self::AT));
        $this->assertSame(self::SIGNATURE, self::sign(self::SECRET, self::ID, self::TIMESTAMP, self::PAYLOAD));
    }

    public function testAcceptsAnySignatureOfARotationAndRefusesOthers(): void
    {
        Webhook::verify(self::PAYLOAD, self::headers('v1,AAAA ' . self::SIGNATURE), self::SECRET, now: self::AT);
        $this->refused(fn () => Webhook::verify(self::PAYLOAD, self::headers(str_replace('v1,', 'v2,', self::SIGNATURE)), self::SECRET, now: self::AT), 'No webhook signature matches');
        $this->refused(fn () => Webhook::verify(self::PAYLOAD . ' ', self::headers(), self::SECRET, now: self::AT), 'No webhook signature matches');
    }

    public function testAcceptsWhitespaceAroundTheSecretAndTheHeaders(): void
    {
        $headers = [
            'webhook-id' => ' ' . self::ID,
            'webhook-timestamp' => self::TIMESTAMP . "\n",
            'webhook-signature' => " v1,AAAA\t\n " . self::SIGNATURE . ' ',
        ];
        $this->assertSame(['test' => 2432232314], Webhook::verify(self::PAYLOAD, $headers, ' ' . self::SECRET . "\n", now: self::AT));
    }

    public function testRefusesOldNewAndMalformedTimestamps(): void
    {
        $this->refused(fn () => Webhook::verify(self::PAYLOAD, self::headers(), self::SECRET, now: self::AT + 301), 'too old');
        $this->refused(fn () => Webhook::verify(self::PAYLOAD, self::headers(), self::SECRET, now: self::AT - 301), 'too new');
        $this->refused(fn () => Webhook::verify(self::PAYLOAD, self::headers(self::SIGNATURE, '16x'), self::SECRET, now: self::AT), 'Invalid webhook-timestamp');
        Webhook::verify(self::PAYLOAD, self::headers(), self::SECRET, 700, self::AT + 600);
        Webhook::verify(self::PAYLOAD, ['webhook-id' => [self::ID], 'webhook-timestamp' => [self::TIMESTAMP], 'webhook-signature' => [self::SIGNATURE]], self::SECRET, now: self::AT);
    }

    public function testRefusesMissingHeadersBadSecretsAndNonJson(): void
    {
        $this->refused(fn () => Webhook::verify(self::PAYLOAD, [], self::SECRET), 'Missing');
        $this->refused(fn () => Webhook::verify(self::PAYLOAD, self::headers(), 'secret', now: self::AT), 'not a whsec_ secret');
        $this->refused(fn () => Webhook::verify(self::PAYLOAD, self::headers(), 'whsec_' . base64_encode('too short'), now: self::AT), 'not a whsec_ secret');
        $body = 'not json';
        $this->refused(fn () => Webhook::verify($body, self::headers(self::sign(self::SECRET, self::ID, self::TIMESTAMP, $body)), self::SECRET, now: self::AT), 'not JSON');
    }
}
