<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WompiTest extends TestCase
{
    private function event(string $secret = 'evt_test'): array
    {
        $ev = [
            'event' => 'transaction.updated',
            'data' => ['transaction' => ['id' => 't1', 'status' => 'APPROVED', 'amount_in_cents' => 1990000]],
            'timestamp' => 1700000000,
            'signature' => ['properties' => ['transaction.id', 'transaction.status', 'transaction.amount_in_cents']],
        ];
        $ev['signature']['checksum'] = hash('sha256', 't1APPROVED19900001700000000' . $secret);
        return $ev;
    }

    public function testValidChecksumAccepted(): void
    {
        $this->assertTrue(wompi_verify_event($this->event()));
    }

    public function testWrongSecretRejected(): void
    {
        $this->assertFalse(wompi_verify_event($this->event('otro')));
    }

    public function testTamperedAmountRejected(): void
    {
        $ev = $this->event();
        $ev['data']['transaction']['amount_in_cents'] = 1;
        $this->assertFalse(wompi_verify_event($ev));
    }

    public function testMissingSignatureRejected(): void
    {
        $ev = $this->event();
        unset($ev['signature']);
        $this->assertFalse(wompi_verify_event($ev));
    }

    public function testUnknownPropertyPathRejected(): void
    {
        $ev = $this->event();
        $ev['signature']['properties'][] = 'transaction.nope';
        $this->assertFalse(wompi_verify_event($ev));
    }

    public function testIntegritySignature(): void
    {
        $this->assertSame(hash('sha256', 'REF1990000COPint_test'), wompi_integrity_signature('REF', 1990000, 'COP'));
    }
}
