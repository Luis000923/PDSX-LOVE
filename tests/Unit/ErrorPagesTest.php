<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Vistas de error: códigos admitidos, marca y assets. render_error() termina el proceso, así que se prueban sus piezas. */
final class ErrorPagesTest extends TestCase
{
    /** @return list<array{int}> */
    public static function codes(): array
    {
        return [[403], [404], [410], [500]];
    }

    #[DataProvider('codes')]
    public function testEveryCodeHasCopy(int $code): void
    {
        $m = error_messages()[$code];
        self::assertNotSame('', $m['title']);
        self::assertNotSame('', $m['text']);
    }

    public function testErrorCodeIsWhitelisted(): void
    {
        self::assertSame(404, error_code('404'));
        self::assertSame(410, error_code(410));
        foreach ([null, '', 'abc', '200', '418', '../etc', '404; x'] as $bad) {
            self::assertNull(error_code($bad));
        }
    }

    public function testBrandAssetsAreValidSvg(): void
    {
        foreach (['logo', 'favicon', 'empty-pages', 'empty-coins', 'empty-search'] as $name) {
            $svg = simplexml_load_file(ROOT . "/public/assets/img/$name.svg");
            self::assertNotFalse($svg, $name);
            self::assertStringNotContainsString('<script', (string) file_get_contents(ROOT . "/public/assets/img/$name.svg"));
        }
        self::assertStringContainsString('#f43f5e', (string) file_get_contents(ROOT . '/public/assets/img/logo.svg'), 'punto de acento');
    }

    public function testInlineLogoAndEmptyStateEscape(): void
    {
        self::assertStringContainsString('<svg', pdsx_logo(28));
        $html = empty_state('nope', '<b>x</b>', 'a"b', '/u?x=1&y=2', 'Ir');
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringContainsString('empty-search.svg', $html, 'tipo desconocido cae en search');
        self::assertStringContainsString('&amp;', $html);
    }

    public function testAssetsHtaccessBlocksExecution(): void
    {
        $ht = (string) file_get_contents(ROOT . '/public/assets/.htaccess');
        self::assertStringContainsString('php_flag engine off', $ht);
        self::assertStringContainsString('Require all denied', $ht);
        foreach (['public/.htaccess', 'docker/apache.conf'] as $f) {
            self::assertStringContainsString('ErrorDocument 404 /error.php', (string) file_get_contents(ROOT . "/$f"));
        }
    }
}
