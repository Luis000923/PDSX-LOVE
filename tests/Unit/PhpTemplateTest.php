<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Análisis estático, rutas del zip, instalación atómica y ejecución aislada de plantillas PHP. */
final class PhpTemplateTest extends TestCase
{
    private const SLUG = 'zz_phpunit_tpl';

    protected function tearDown(): void
    {
        PhpTemplate::remove(self::SLUG);
    }

    // ---- análisis ----

    public function testSampleTemplateIsAccepted(): void
    {
        $dir = ROOT . '/docs/plantillas-ejemplo/flores_amarillas';
        $siblings = ['index.php', 'partials/footer.php'];
        foreach ($siblings as $rel) {
            self::assertSame([], PhpTemplate::scan((string) file_get_contents("$dir/$rel"), $rel, $siblings), $rel);
        }
    }

    #[DataProvider('malicious')]
    public function testDangerousCodeIsRejected(string $code): void
    {
        self::assertNotSame([], PhpTemplate::scan('<?php ' . $code, 'index.php', ['index.php']), $code);
    }

    public static function malicious(): array
    {
        return array_map(static fn(string $c): array => [$c], [
            'system("id");', 'SyStem("id");', 'shell_exec("id");', 'exec("id");', '`id`;', 'eval("1");',
            '$f = "sys" . "tem"; $f("id");', '$$x = 1;', '("sys"."tem")("id");', 'array_map("system", ["id"]);',
            'usort($a, "strcmp");', 'call_user_func("system", "id");', 'echo $_GET["x"];', 'echo $_SERVER["HTTP_HOST"];',
            'echo $GLOBALS["a"];', 'file_get_contents("/etc/passwd");', 'unlink("x");', 'curl_init();', 'db();', 'ini_set("a","b");',
            'include $_GET["f"];', 'include "/etc/passwd";', 'include __DIR__ . "/../../src/bootstrap.php";', 'require __DIR__ . "/no-existe.php";',
            'include __DIR__ . $x;', 'new PDO("x");', 'Admin::log("x");', '\\system("id");', 'namespace A; echo 1;',
            '$o->$m();', '$o->{"a"}();', 'phpinfo();', 'mail("a","b","c");', 'preg_replace_callback("/a/", "x", "a");',
            // Bypass real confirmado en pentest: array_*_ukey/uassoc aceptan un callable por nombre literal
            // (no una llamada dinámica), así que una lista NEGRA que no las enumere una por una las deja pasar.
            // La lista BLANCA (ALLOWED_FUNCTIONS) las bloquea a todas por no estar en ella, sin enumerarlas.
            'array_diff_ukey(["id"=>1], ["x"=>1], "system");', 'array_udiff_uassoc([], [], "system");',
            'array_uintersect_assoc([], [], "system");', 'array_find([], "system");',
            // Cualquier función no revisada, aunque sea inocua a simple vista, se rechaza por defecto.
            'compact("a");', 'array_map("strtoupper", ["a"]);',
        ]);
    }

    public function testBenignCodeIsAccepted(): void
    {
        $code = '<?php $d = new DateTimeImmutable("today"); $n = strlen("a") + count([1]); '
              . 'foreach ([1,2] as $i) { echo htmlspecialchars((string) $i); } '
              . 'include __DIR__ . "/partials/x.php"; $f = fn($x) => $x * 2; echo $f(2);';
        // fn($x) => llamada a variable: se rechaza a propósito ($f(...)); el resto es válido
        $errors = PhpTemplate::scan($code, 'index.php', ['index.php', 'partials/x.php']);
        self::assertCount(1, $errors);
        self::assertStringContainsString('llamadas dinámicas', $errors[0]);
    }

    #[DataProvider('paths')]
    public function testEntryPaths(string $in, ?string $out): void
    {
        self::assertSame($out, PhpTemplate::safeEntryPath($in));
    }

    public static function paths(): array
    {
        return [['index.php', 'index.php'], ['css/app.css', 'css/app.css'], ['a/b/c.js', 'a/b/c.js'],
                ['../x.php', null], ['a/../../x.php', null], ['/etc/passwd', null], ['a\\b.php', null], ['.htaccess', null],
                ['a/.env', null], ["a\0.php", null], ['C:/x.php', null], ['a//b.php', null], ['a b.php', null], ['', null]];
    }

    // ---- instalación y ejecución ----

    private function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl') . '.zip';
        $z = new ZipArchive();
        $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $name => $bytes) {
            $z->addFromString($name, $bytes);
        }
        $z->close();
        return $path;
    }

    public function testInstallRenderAndReplace(): void
    {
        $zip = $this->zip([
            self::SLUG . '/index.php'        => '<?php echo "<p>", $t["your_name"], "|", $t["message"], "|", $t["days_together"], "|", $nonce, "|", $assets, "css/a.css", "</p>"; include __DIR__ . "/p/x.php";',
            self::SLUG . '/p/x.php'          => '<i><?= $t["partner_name"] ?></i>',
            self::SLUG . '/css/a.css'        => 'body{}',
        ]);
        self::assertSame([], PhpTemplate::install(self::SLUG, $zip));
        self::assertFileExists(ROOT . '/public/assets/tpl/' . self::SLUG . '/css/a.css');
        self::assertFileDoesNotExist(ROOT . '/public/assets/tpl/' . self::SLUG . '/index.php', 'el código PHP no se publica');

        $html = PhpTemplate::render(self::SLUG, [
            'your_name' => '<script>alert(1)</script>', 'partner_name' => 'Luis & co', 'start_date' => date('Y-m-d'), 'message' => "a\nb",
        ], ['nonce' => 'N0NCE']);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('a<br>' . "\n" . 'b', $html);
        self::assertStringContainsString('<i>Luis &amp; co</i>', $html);
        self::assertStringContainsString('|N0NCE|', $html);
        self::assertStringContainsString('assets/tpl/' . self::SLUG . '/css/a.css', $html);

        // Reemplazo: la nueva versión sustituye a la anterior por completo.
        $zip2 = $this->zip(['index.php' => '<?php echo "v2";']);
        self::assertSame([], PhpTemplate::install(self::SLUG, $zip2));
        self::assertSame('v2', PhpTemplate::render(self::SLUG, []));
        self::assertFileDoesNotExist(ROOT . '/public/assets/tpl/' . self::SLUG . '/css/a.css');
    }

    public function testTemplateCannotSeeApplicationScope(): void
    {
        $zip = $this->zip(['index.php' => '<?php echo isset($user) ? "leak" : "ok", isset($pdo) ? "leak" : "ok", isset($data) ? "leak" : "ok";']);
        self::assertSame([], PhpTemplate::install(self::SLUG, $zip));
        $GLOBALS['user'] = ['x' => 1];
        self::assertSame('okokok', PhpTemplate::render(self::SLUG, ['your_name' => 'a']));
        unset($GLOBALS['user']);
    }

    public function testInstallRejectsBadBundles(): void
    {
        $cases = [
            'sin index'        => ['a.php' => '<?php echo 1;'],
            'traversal'        => ['index.php' => '<?php echo 1;', '../evil.php' => '<?php echo 1;'],
            'extensión'        => ['index.php' => '<?php echo 1;', 'run.sh' => 'id'],
            'php en assets'    => ['index.php' => '<?php echo 1;', 'css/x.phtml' => '<?php echo 1;'],
            'código peligroso' => ['index.php' => '<?php system($_GET["c"]);'],
            'oculto'           => ['index.php' => '<?php echo 1;', '.htaccess' => 'x'],
        ];
        foreach ($cases as $label => $files) {
            $errors = PhpTemplate::install(self::SLUG, $this->zip($files));
            self::assertNotSame([], $errors, $label);
            self::assertDirectoryDoesNotExist(PhpTemplate::baseDir() . '/' . self::SLUG, $label);
        }
        self::assertNotSame([], PhpTemplate::install('../x', $this->zip(['index.php' => '<?php echo 1;'])));
        self::assertNotSame([], PhpTemplate::install(self::SLUG, __FILE__), 'no es un zip');
    }

    #[DataProvider('badSlugs')]
    public function testRenderRejectsTraversalSlugs(string $slug): void
    {
        $this->expectException(Throwable::class);
        PhpTemplate::render($slug, []);
    }

    public static function badSlugs(): array
    {
        return [['../src'], ['..'], ['a/b'], ['/etc'], ['UPPER'], ['ab'], ['zz_missing_tpl']];
    }
}
