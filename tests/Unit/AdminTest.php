<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Cubre el filtro de plantillas subidas, que es la superficie de ataque del panel. */
final class AdminTest extends TestCase
{
    private const VALID = <<<'HTML'
    <!doctype html><html lang="es"><head><meta charset="utf-8"><title>{{your_name}}</title>
    <script nonce="{{{nonce}}}" src="https://cdn.tailwindcss.com"></script></head>
    <body><h1>{{your_name}} &amp; {{partner_name}}</h1><p>{{days_together}} días</p>
    <p>{{message}}</p>{{{ad_slot}}}
    <script nonce="{{{nonce}}}">console.log('ok');</script></body></html>
    HTML;

    public function testValidTemplateAccepted(): void
    {
        $this->assertSame([], Admin::validateTemplateHtml(self::VALID));
    }

    public function testPhpCodeRejected(): void
    {
        $html = str_replace('{{message}}', '<?php system($_GET["c"]); ?>', self::VALID);
        $this->assertNotEmpty(Admin::validateTemplateHtml($html));
    }

    public function testScriptWithoutNonceRejected(): void
    {
        $html = str_replace('{{{ad_slot}}}', '<script>alert(1)</script>', self::VALID);
        $this->assertNotEmpty(Admin::validateTemplateHtml($html));
    }

    public function testInlineEventHandlerRejected(): void
    {
        $html = str_replace('{{message}}', '<img src="x" onerror="alert(1)">', self::VALID);
        $this->assertNotEmpty(Admin::validateTemplateHtml($html));
    }

    public function testExternalHostRejected(): void
    {
        $html = str_replace('https://cdn.tailwindcss.com', 'https://evil.example/x.js', self::VALID);
        $this->assertNotEmpty(Admin::validateTemplateHtml($html));
    }

    public function testUnknownPlaceholderRejected(): void
    {
        $html = str_replace('{{message}}', '{{password_hash}}', self::VALID);
        $this->assertNotEmpty(Admin::validateTemplateHtml($html));
    }

    public function testUnknownRawPlaceholderRejected(): void
    {
        $html = str_replace('{{{ad_slot}}}', '{{{message}}}', self::VALID);
        $this->assertNotEmpty(Admin::validateTemplateHtml($html));
    }

    public function testJavascriptUriRejected(): void
    {
        $html = str_replace('{{message}}', '<a href="javascript:alert(1)">x</a>', self::VALID);
        $this->assertNotEmpty(Admin::validateTemplateHtml($html));
    }

    public function testAdHtmlRejectsScriptsAndExternalImages(): void
    {
        $this->assertNotEmpty(Admin::validateAdHtml('<script src="https://ads.example/a.js"></script>'));
        $this->assertNotEmpty(Admin::validateAdHtml('<img src="https://ads.example/a.png">'));
        $this->assertSame([], Admin::validateAdHtml('<a href="/love/promo"><img src="/love/assets/thumbs/b.png" alt="Promo"></a>'));
    }

    /** @return array<string, array{0:string, 1:string}> */
    public static function slugCases(): array
    {
        return [
            'acentos'  => ['Corazón Rojo', 'corazon-rojo'],
            'símbolos' => ['  ¡Amor & Paz!  ', 'amor-paz'],
            'vacío'    => ['///', ''],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('slugCases')]
    public function testSlugify(string $input, string $expected): void
    {
        $this->assertSame($expected, Admin::slugify($input));
    }
}
