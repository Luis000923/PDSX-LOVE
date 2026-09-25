<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PreviewEmbedTest extends TestCase
{
    private const DEMO = ['your_name' => 'Ana', 'partner_name' => 'Luis', 'start_date' => '2024-01-01', 'message' => 'demo'];
    private function today(): DateTimeImmutable { return new DateTimeImmutable('2026-06-01'); }

    public function testOverridesValidFields(): void
    {
        $r = Template::mergeDemo(self::DEMO, ['your_name' => ' Eva ', 'start_date' => '2025-02-03', 'message' => "a\r\nb"], $this->today());
        $this->assertSame('Eva', $r['your_name']);
        $this->assertSame('2025-02-03', $r['start_date']);
        $this->assertSame("a\nb", $r['message']);
        $this->assertSame('Luis', $r['partner_name']);
    }

    public function testInvalidKeepsDemo(): void
    {
        $r = Template::mergeDemo(self::DEMO, [
            'your_name' => ['x'], 'partner_name' => "  \x00\x0d ", 'start_date' => '2027-01-01', 'message' => '',
        ], $this->today());
        $this->assertSame(self::DEMO, $r);
        foreach (['2025-13-40', 'hoy', '2025-2-3', '2026-06-02'] as $bad) {
            $this->assertSame('2024-01-01', Template::mergeDemo(self::DEMO, ['start_date' => $bad], $this->today())['start_date']);
        }
        $this->assertSame('2026-06-01', Template::mergeDemo(self::DEMO, ['start_date' => '2026-06-01'], $this->today())['start_date']);
    }

    public function testTruncatesAndKeepsRawPayloadUnescaped(): void
    {
        $r = Template::mergeDemo(self::DEMO, ['your_name' => str_repeat('ñ', 5000), 'message' => '<script>"x"</script>' . "\r\n"], $this->today());
        $this->assertSame(60, mb_strlen($r['your_name']));
        $this->assertSame('<script>"x"</script>', $r['message']); // el escape lo hace renderRow
    }

    public function testControlCharsStripped(): void
    {
        $r = Template::mergeDemo(self::DEMO, ['your_name' => "A\r\nX-Evil: 1"], $this->today());
        $this->assertStringNotContainsString("\r", $r['your_name']);
    }
}
