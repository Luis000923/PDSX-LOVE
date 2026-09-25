<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/HtmlScanner.php';
require_once dirname(__DIR__, 2) . '/src/UserHtml.php';

final class UserHtmlTest extends TestCase
{
    private const HTML = '<!doctype html><html><head><meta charset="utf-8"><title>Hola</title></head><body><h1>Hola</h1></body></html>';
    /** @var list<string> */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
        $this->tmp = [];
    }

    private function file(string $bytes, string $suffix): string
    {
        $p = tempnam(sys_get_temp_dir(), 'uh') ?: throw new RuntimeException('tmp');
        rename($p, $p .= $suffix);
        file_put_contents($p, $bytes);
        $this->tmp[] = $p;
        return $p;
    }

    /** @param array<string,string> $entries */
    private function zip(array $entries, ?callable $tweak = null): string
    {
        $p = $this->file('', '.zip');
        unlink($p);
        $z = new ZipArchive();
        $z->open($p, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $data) {
            $z->addFromString($name, $data);
        }
        if ($tweak !== null) {
            $tweak($z);
        }
        $z->close();
        return $p;
    }

    /** @param array<string,string> $entries @return list<string> */
    private function zipErrors(array $entries, ?callable $tweak = null): array
    {
        return UserHtml::inspect($this->zip($entries, $tweak), 'a.zip')['errors'];
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true) ?: '';
    }

    public function testHtmlValido(): void
    {
        $r = UserHtml::inspect($this->file(self::HTML, '.html'), 'Mi Pagina.HTML');
        self::assertSame([], $r['errors']);
        self::assertSame(hash('sha256', self::HTML), $r['sha256']);
        self::assertSame(strlen(self::HTML), $r['bytes']);
    }

    public function testHtmlRechazos(): void
    {
        self::assertNotEmpty(UserHtml::inspect($this->file(self::HTML, '.php'), 'x.php')['errors']);
        self::assertNotEmpty(UserHtml::inspect($this->file(self::HTML, '.html'), 'x.html.php')['errors']);
        self::assertNotEmpty(UserHtml::inspect($this->file('', '.html'), 'x.html')['errors']);
        self::assertNotEmpty(UserHtml::inspect($this->file("\x89PNG\r\n\x1a\n" . str_repeat("\0", 50), '.html'), 'x.html')['errors']);
        self::assertNotEmpty(UserHtml::inspect($this->file(str_repeat('a', 600000), '.html'), 'x.html')['errors']);
        self::assertNotEmpty(UserHtml::inspect($this->file('<html><body><iframe src=x></iframe></body></html>', '.html'), 'x.html')['errors']);
        // .zip real con extensión .html, y HTML con extensión .zip
        self::assertNotEmpty(UserHtml::inspect($this->zip(['index.html' => self::HTML]), 'x.html')['errors']);
        self::assertNotEmpty(UserHtml::inspect($this->file(self::HTML, '.zip'), 'x.zip')['errors']);
    }

    public function testZipValidoConRecursosYCarpetaContenedora(): void
    {
        $entries = ['index.html' => '<html><head><link rel="stylesheet" href="css/s.css"></head><body><img src="img/a.png"><script src="js/a.js"></script></body></html>',
                    'css/s.css' => 'body{background:#fff}', 'js/a.js' => 'document.title="x";', 'notas.txt' => 'hola', 'Img/A.PNG' => $this->png()];
        $r = UserHtml::inspect($this->zip($entries), 'a.zip');
        self::assertSame([], $r['errors']);
        self::assertSame(['css/s.css', 'js/a.js', 'notas.txt', 'img/a.png'], array_keys($r['assets']));

        $wrapped = [];
        foreach ($entries as $k => $v) {
            $wrapped['mi-pagina/' . $k] = $v;
        }
        $r2 = UserHtml::inspect($this->zip($wrapped), 'a.zip');
        self::assertSame([], $r2['errors']);
        self::assertArrayHasKey('css/s.css', $r2['assets']);
    }

    public function testZipSlipYRutasAbsolutas(): void
    {
        self::assertNotEmpty($this->zipErrors(['index.html' => self::HTML, '../evil.txt' => 'x']));
        self::assertNotEmpty($this->zipErrors(['index.html' => self::HTML, 'a/../../evil.txt' => 'x']));
        self::assertNotEmpty($this->zipErrors(['index.html' => self::HTML, '/etc/x.txt' => 'x']));
        self::assertNotEmpty($this->zipErrors(['index.html' => self::HTML, 'C:/x.txt' => 'x']));
        self::assertNotEmpty($this->zipErrors(['index.html' => self::HTML, 'a\\b.txt' => 'x']));
    }

    public function testEntradasOcultasYNul(): void
    {
        self::assertNotEmpty($this->zipErrors(['index.html' => self::HTML, '.htaccess' => 'x']));
        self::assertNotEmpty($this->zipErrors(['index.html' => self::HTML, 'a/.git/config' => 'x']));
        self::assertNotEmpty($this->zipErrors(['index.html' => self::HTML, "a\0.txt" => 'x']));
        // basura de macOS: se ignora sin error y no se guarda
        $r = UserHtml::inspect($this->zip(['index.html' => self::HTML, '__MACOSX/._index.html' => 'x']), 'a.zip');
        self::assertSame([], $r['errors']);
        self::assertSame([], $r['assets']);
    }

    public function testEnlaceSimbolico(): void
    {
        $errors = $this->zipErrors(['index.html' => self::HTML, 'link.txt' => '/etc/passwd'], static function (ZipArchive $z): void {
            $z->setExternalAttributesName('link.txt', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        });
        self::assertNotEmpty($errors);
    }

    public function testExtensionesPeligrosas(): void
    {
        self::assertNotEmpty($this->zipErrors(['index.html' => self::HTML, 'shell.php' => '<?php system($_GET[0]);']));
        self::assertNotEmpty($this->zipErrors(['index.html' => self::HTML, 'shell.php.png' => $this->png()]));
        self::assertNotEmpty($this->zipErrors(['index.html' => self::HTML, 'x.PHP' => 'x']));
        self::assertNotEmpty($this->zipErrors(['index.html' => self::HTML, 'x.phtml' => 'x']));
        self::assertNotEmpty($this->zipErrors(['index.html' => self::HTML, 'otra.html' => self::HTML]));
        self::assertNotEmpty($this->zipErrors(['index.html' => self::HTML, 'x.exe' => 'MZ']));
        self::assertNotEmpty($this->zipErrors(['index.html' => self::HTML, 'x.svgz' => 'x']));
        self::assertNotEmpty($this->zipErrors(['index.html' => self::HTML, 'espacio raro.txt' => 'x']));
    }

    public function testSinIndex(): void
    {
        self::assertNotEmpty($this->zipErrors(['a.txt' => 'x']));
        self::assertNotEmpty($this->zipErrors(['carpeta/index.html' => self::HTML, 'otra/x.txt' => 'x']));
        self::assertNotEmpty($this->zipErrors(['sub/dir/index.html' => self::HTML]));
    }

    public function testContenidoDeRecursosSeEscanea(): void
    {
        self::assertNotEmpty($this->zipErrors(['index.html' => self::HTML, 'a.js' => 'fetch("/profile.php")']));
        self::assertNotEmpty($this->zipErrors(['index.html' => self::HTML, 'a.css' => 'a{width:expression(1)}']));
        self::assertNotEmpty($this->zipErrors(['index.html' => self::HTML, 'a.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>1</script></svg>']));
        self::assertNotEmpty($this->zipErrors(['index.html' => '<body><script>eval(1)</script></body>']));
        self::assertNotEmpty($this->zipErrors(['index.html' => self::HTML, 'a.woff2' => 'no es fuente']));
        self::assertNotEmpty($this->zipErrors(['index.html' => self::HTML, 'a.png' => '<?php echo 1;']));
        self::assertNotEmpty($this->zipErrors(['index.html' => self::HTML, 'a.gif' => $this->png()]));
    }

    public function testLimitesDeEntradasYBomba(): void
    {
        $many = ['index.html' => self::HTML];
        for ($i = 0; $i < 60; $i++) {
            $many["f$i.txt"] = 'x';
        }
        self::assertNotEmpty($this->zipErrors($many));

        // Bomba: muchas entradas de texto que juntas superan los 8 MB descomprimidos (cada una comprime a casi nada).
        $bomb = ['index.html' => self::HTML];
        for ($i = 0; $i < 20; $i++) {
            $bomb["b$i.txt"] = str_repeat('A', 500000);
        }
        $p = $this->zip($bomb);
        self::assertLessThan(200000, filesize($p));
        self::assertNotEmpty(UserHtml::inspect($p, 'a.zip')['errors']);

        // Un solo archivo enorme y muy comprimible
        self::assertNotEmpty($this->zipErrors(['index.html' => self::HTML, 'big.txt' => str_repeat('A', 9 * 1048576)]));
    }

    public function testGuardadoYBase(): void
    {
        $slug = 'ztest' . random_int(100000, 999999);
        try {
            UserHtml::store($slug, self::HTML, ['css/s.css' => 'a{}']);
            self::assertFileExists(UserHtml::docPath($slug));
            self::assertFileExists(UserHtml::assetsDir($slug) . '/css/s.css');
            $doc = UserHtml::document($slug, true);
            self::assertNotNull($doc);
            self::assertStringContainsString('<base href="' . url('uploads/sites/' . $slug . '/a/') . '">', $doc);
            self::assertStringNotContainsString('<base', (string) UserHtml::document($slug, false));
            self::assertNull(UserHtml::document('../etc', false));
        } finally {
            @unlink(UserHtml::assetsDir($slug) . '/css/s.css');
            @rmdir(UserHtml::assetsDir($slug) . '/css');
            @rmdir(UserHtml::assetsDir($slug));
            @rmdir(ROOT . '/public/uploads/sites/' . $slug);
            @unlink(UserHtml::docPath($slug));
            @rmdir(dirname(UserHtml::docPath($slug)));
        }
    }

    public function testStoreRechazaRutasPeligrosas(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UserHtml::store('abcdefgh', self::HTML, ['../x.txt' => 'x']);
    }

    public function testInjectBaseSinHead(): void
    {
        self::assertStringStartsWith('<!doctype html><base href="https://x.test/a/">', UserHtml::injectBase('<!doctype html><p>x</p>', 'https://x.test/a/'));
        self::assertStringStartsWith('<base href=', UserHtml::injectBase('<p>x</p>', 'https://x.test/a/'));
    }

    public function testImagenValidaSiHayGd(): void
    {
        if (!function_exists('imagecreatefrompng')) {
            self::markTestSkipped('GD no disponible en este PHP: los caminos de imagen se prueban en el contenedor.');
        }
        self::assertSame([], $this->zipErrors(['index.html' => self::HTML, 'a.png' => $this->png()]));
    }
}
