<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TemplateTest extends TestCase
{
    private const BASE = ['your_name' => 'Ana', 'partner_name' => 'Luis', 'start_date' => '2024-02-14', 'message' => 'Hola'];

    public function testUserDataIsEscaped(): void
    {
        $html = Template::render('free-minimal.html', ['partner_name' => '<script>alert(1)</script>'] + self::BASE, ['nonce' => 'n', 'ad_slot' => '']);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testUserDataIsNotReExpandedAsTemplate(): void
    {
        $html = Template::render('free-minimal.html', ['message' => '{{{secret_raw}}} {{your_name}}'] + self::BASE, ['nonce' => 'n', 'ad_slot' => '', 'secret_raw' => 'SECRET']);
        $this->assertStringNotContainsString('SECRET', $html);
        $this->assertStringContainsString('{{your_name}}', $html);
    }

    public function testMessageNewlinesBecomeBr(): void
    {
        $html = Template::render('free-minimal.html', ['message' => "a\nb"] + self::BASE, ['nonce' => 'n']);
        $this->assertStringContainsString("a<br>\nb", $html);
    }

    public function testRawValuesAreInjected(): void
    {
        $html = Template::render('free-minimal.html', self::BASE, ['nonce' => 'abc123', 'ad_slot' => '<b id="ad"></b>']);
        $this->assertStringContainsString('nonce="abc123"', $html);
        $this->assertStringContainsString('<b id="ad"></b>', $html);
    }

    #[DataProvider('badFiles')]
    public function testInvalidTemplateNameRejected(string $file): void
    {
        $this->expectException(InvalidArgumentException::class);
        Template::render($file, self::BASE);
    }

    public static function badFiles(): array
    {
        return [['../config/wompi.php'], ['x.php'], ['a/b.html'], ['..%2Ffoo.html']];
    }

    public function testSanitizeAcceptsValidInput(): void
    {
        [$data, $errors] = Template::sanitize(self::BASE);
        $this->assertSame([], $errors);
        $this->assertSame('Ana', $data['your_name']);
    }

    public function testSanitizeRejectsEmptyFutureAndLong(): void
    {
        [, $errors] = Template::sanitize(['your_name' => '', 'partner_name' => str_repeat('a', 61), 'start_date' => '2999-01-01', 'message' => 'x']);
        $this->assertArrayHasKey('your_name', $errors);
        $this->assertArrayHasKey('partner_name', $errors);
        $this->assertArrayHasKey('start_date', $errors);
        $this->assertArrayNotHasKey('message', $errors);
    }

    public function testSanitizeRejectsInvalidDate(): void
    {
        [, $errors] = Template::sanitize(['start_date' => '2024-02-31'] + self::BASE);
        $this->assertArrayHasKey('start_date', $errors);
    }

    public function testDaysTogether(): void
    {
        $d = (new DateTimeImmutable('today'))->modify('-10 days')->format('Y-m-d');
        $this->assertSame(10, Template::daysTogether($d));
        $this->assertSame(0, Template::daysTogether('garbage'));
    }
}
