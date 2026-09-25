<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TemplateImagesTest extends TestCase
{
    public function testEmptyAndNullMeanNoPhotos(): void
    {
        self::assertSame([], TemplateImages::parse(null));
        self::assertSame([], TemplateImages::parse(''));
        self::assertSame([], TemplateImages::parse('{}'));
        self::assertNull(TemplateImages::validate(null));
    }

    public function testSlotsAndRepeatAreExpanded(): void
    {
        $f = TemplateImages::parse('{"max":6,"slots":[{"key":"principal","label":"Foto principal","required":true},{"key":"linea_1","label":"L1"}],"repeat":{"prefix":"foto","label":"Foto {n}","min":1,"max":3}}');
        self::assertSame(['principal', 'linea_1', 'foto_1', 'foto_2', 'foto_3'], array_column($f, 'key'));
        self::assertSame([true, false, true, false, false], array_column($f, 'required'));
        self::assertSame('Foto 2', $f[3]['label']);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidSpecs(): iterable
    {
        yield 'no json' => ['{no'];
        yield 'lista' => ['[1,2]'];
        yield 'clave con ..' => ['{"slots":[{"key":"../x","label":"a"}]}'];
        yield 'clave mayuscula' => ['{"slots":[{"key":"Foto","label":"a"}]}'];
        yield 'clave con espacio' => ['{"slots":[{"key":"a b","label":"a"}]}'];
        yield 'clave larga' => ['{"slots":[{"key":"' . str_repeat('a', 31) . '","label":"a"}]}'];
        yield 'clave con barra' => ['{"slots":[{"key":"a/b","label":"a"}]}'];
        yield 'clave numerica' => ['{"slots":[{"key":"1a","label":"a"}]}'];
        yield 'clave no string' => ['{"slots":[{"key":5,"label":"a"}]}'];
        yield 'repetida' => ['{"slots":[{"key":"a","label":"a"},{"key":"a","label":"b"}]}'];
        yield 'choque repeat' => ['{"slots":[{"key":"foto_1","label":"a"}],"repeat":{"prefix":"foto","max":2}}'];
        yield 'mas de 12' => ['{"repeat":{"prefix":"foto","max":13}}'];
        yield 'slots+repeat > 12' => ['{"slots":[{"key":"a","label":"a"}],"repeat":{"prefix":"foto","max":12}}'];
        yield 'max menor' => ['{"max":2,"repeat":{"prefix":"foto","max":3}}'];
        yield 'repeat min>max' => ['{"repeat":{"prefix":"foto","min":4,"max":3}}'];
        yield 'prefijo raro' => ['{"repeat":{"prefix":"../f","max":3}}'];
        yield 'clave extra' => ['{"slots":[],"evil":1}'];
        yield 'slot clave extra' => ['{"slots":[{"key":"a","label":"a","path":"/etc"}]}'];
        yield 'required no bool' => ['{"slots":[{"key":"a","label":"a","required":"yes"}]}'];
        yield 'label vacia' => ['{"slots":[{"key":"a","label":""}]}'];
        yield 'label enorme' => ['{"slots":[{"key":"a","label":"' . str_repeat('x', 81) . '"}]}'];
        yield 'label no string' => ['{"slots":[{"key":"a","label":["x"]}]}'];
        yield 'slots no lista' => ['{"slots":{"a":1}}'];
        yield 'profundo' => [str_repeat('[', 50) . str_repeat(']', 50)];
    }

    /** @dataProvider invalidSpecs */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSpecs')]
    public function testInvalidSpecsYieldNoPhotosAndAnError(string $json): void
    {
        $prev = ini_set('error_log', '/dev/null');
        self::assertSame([], TemplateImages::parse($json, $err));
        self::assertNotNull($err);
        self::assertNotNull(TemplateImages::validate($json));
        ini_set('error_log', (string) $prev);
    }

    public function testControlCharsAreStrippedFromLabels(): void
    {
        $f = TemplateImages::parse('{"slots":[{"key":"a","label":"Foto\u0000 \u001b[31mA"}]}');
        self::assertSame('Foto [31mA', $f[0]['label']);
    }

    public function testFromManifest(): void
    {
        $f = TemplateImages::fromManifest('{"name":"x","images":{"slots":[{"key":"principal","label":"P","required":true}]}}');
        self::assertSame('principal', $f[0]['key']);
        self::assertSame([], TemplateImages::fromManifest('{"name":"x"}'));
        $prev = ini_set('error_log', '/dev/null');
        self::assertSame([], TemplateImages::fromManifest('{"images":{"slots":[{"key":"../x","label":"a"}]}}', $err));
        self::assertNotNull($err);
        self::assertSame([], TemplateImages::fromManifest('no json', $err2));
        self::assertNotNull($err2);
        ini_set('error_log', (string) $prev);
    }

    public function testUrlsAreServerBuiltAndMaliciousRowsIgnored(): void
    {
        $slug = 'abcd2345';
        $ok = 'a1b2c3d4e5f60718.webp';
        self::assertSame(url('uploads/sites/abcd2345/' . $ok), TemplateImages::urlFor($slug, $ok));
        foreach (['../abcd.webp', 'a1b2c3d4e5f60718.php', 'A1B2C3D4E5F60718.webp', 'x.webp', "a1b2c3d4e5f60718.webp\n", 'a1b2c3d4e5f60718.webp/../../x'] as $bad) {
            self::assertNull(TemplateImages::urlFor($slug, $bad), $bad);
        }
        foreach (['../x', 'ABCD', 'ab', 'abcd2345/../..', "abcd2345\n"] as $badSlug) {
            self::assertNull(TemplateImages::urlFor($badSlug, $ok), $badSlug);
        }
        $urls = TemplateImages::urls($slug, [
            ['slot' => 'principal', 'file' => $ok],
            ['slot' => '../etc', 'file' => $ok],
            ['slot' => 'x"><script>', 'file' => $ok],
            ['slot' => 'malo', 'file' => '../../../etc/passwd'],
            ['slot' => 'malo2', 'file' => 'x.php'],
        ]);
        self::assertSame(['principal'], array_keys($urls));
    }

    public function testHtmlInjectionAndConditionals(): void
    {
        $file = 'free-minimal.html';
        $dir = ROOT . '/templates';
        $name = 'zz-imgtest-' . bin2hex(random_bytes(3)) . '.html';
        file_put_contents($dir . '/' . $name, '<p>{{#if img_foto_1}}<img src="{{img_foto_1}}">{{/if}}{{#unless img_foto_1}}SVG{{/unless}}|{{img_foto_2}}|{{img_count}}|{{message}}</p>');
        try {
            $data = ['message' => '{{img_foto_1}}', 'img_foto_2' => 'javascript:evil', 'start_date' => '2020-01-01'];
            $with = Template::render($name, $data, ['images' => ['foto_1' => '/uploads/sites/abcd2345/a1b2c3d4e5f60718.webp', '../x' => 'no']]);
            self::assertStringContainsString('<img src="/uploads/sites/abcd2345/a1b2c3d4e5f60718.webp">', $with);
            self::assertStringNotContainsString('SVG', $with);
            self::assertStringContainsString('|1|', $with);
            self::assertStringContainsString('|{{img_foto_1}}</p>', $with);   // el dato del usuario no se interpreta
            self::assertStringNotContainsString('javascript:', $with);        // img_* de $data se descarta
            $without = Template::render($name, ['start_date' => '2020-01-01'], []);
            self::assertStringContainsString('<p>SVG||0|', $without);
        } finally {
            @unlink($dir . '/' . $name);
        }
        self::assertNotSame('', $file);
    }

    public function testPhpTemplateGetsImagesAsUrlMap(): void
    {
        $slug = 'zz_img_' . bin2hex(random_bytes(3));
        $dir = PhpTemplate::baseDir() . '/' . $slug;
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/index.php', '<?php echo $t[\'image_count\'], "|", $t[\'images\'][\'foto_1\'] ?? "none", "|", $t[\'images\'][\'x\'] ?? "none";');
        try {
            $out = PhpTemplate::render($slug, ['start_date' => '2020-01-01'], ['images' => ['foto_1' => '/u/a.webp?x="y', '../x' => 'z']]);
            self::assertSame('1|/u/a.webp?x=&quot;y|none', $out);
            self::assertSame('0|none|none', PhpTemplate::render($slug, ['start_date' => '2020-01-01']));
        } finally {
            @unlink($dir . '/index.php');
            @rmdir($dir);
        }
    }

    public function testPurgeFilesOnlyTouchesValidSlugsInsideUploads(): void
    {
        $base = ROOT . '/public/uploads/sites';
        @mkdir($base, 0755, true);
        $slug = 'tpurge' . bin2hex(random_bytes(2));
        mkdir($base . '/' . $slug . '/sub', 0755, true);
        file_put_contents($base . '/' . $slug . '/a.webp', 'x');
        // Enlace simbólico hacia un directorio externo: se elimina el enlace, nunca el destino.
        $outside = sys_get_temp_dir() . '/purge_out_' . bin2hex(random_bytes(3));
        mkdir($outside);
        file_put_contents($outside . '/keep.txt', 'k');
        symlink($outside, $base . '/' . $slug . '/sub/link');
        Sites::purgeFiles($slug);
        self::assertDirectoryDoesNotExist($base . '/' . $slug);
        self::assertFileExists($outside . '/keep.txt');

        // Slug enlace: no se sigue.
        $link = 'tlink' . bin2hex(random_bytes(2));
        symlink($outside, $base . '/' . $link);
        Sites::purgeFiles($link);
        self::assertFileExists($outside . '/keep.txt');
        unlink($base . '/' . $link);

        // Slugs inválidos: sin efecto.
        Sites::purgeFiles('../../etc');
        Sites::purgeFiles('');
        Sites::purgeFiles('A/B');
        self::assertFileExists($outside . '/keep.txt');
        unlink($outside . '/keep.txt');
        rmdir($outside);
    }

    // ------------------------------------------------ contrato Sites::create ---

    /** @return array{0:PDO,1:array<string,mixed>,2:array<string,mixed>} */
    private function dbFixture(): array
    {
        try {
            $pdo = db();
        } catch (PDOException $e) {
            self::markTestSkipped('MySQL no disponible: ' . $e->getMessage());
        }
        $pdo->exec('DELETE FROM coin_transactions');
        $pdo->exec('DELETE FROM user_sites');
        $pdo->exec("DELETE FROM templates WHERE slug = 'ti_free'");
        $pdo->exec('DELETE FROM users');
        $pdo->prepare('INSERT INTO users (email, password_hash, coins) VALUES (?, ?, 100)')->execute(['ti' . uniqid() . '@t.test', 'x']);
        $u = $pdo->query('SELECT * FROM users ORDER BY id DESC LIMIT 1')->fetch();
        $pdo->exec("INSERT INTO templates (slug, name, kind, category, file, price_coins) VALUES ('ti_free', 'ti', 'html', 'romantico', 'free-minimal.html', 10)");
        $t = $pdo->query("SELECT * FROM templates WHERE slug = 'ti_free'")->fetch();
        return [$pdo, $u, $t];
    }

    public function testCreateCallbackErrorRollsBackWithoutCharging(): void
    {
        [$pdo, $u, $t] = $this->dbFixture();
        $seen = null;
        $res = Sites::create($u, $t, ['x' => 'y'], static function (PDO $p, int $id, string $slug, array $fresh) use (&$seen): string {
            $seen = [$id, $slug, (int) $fresh['id']];
            return 'falló la foto';
        });
        self::assertSame(['error' => 'falló la foto'], $res);
        self::assertGreaterThan(0, $seen[0]);
        self::assertMatchesRegularExpression(TemplateImages::SLUG_RE, $seen[1]);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM user_sites')->fetchColumn());
        self::assertSame(100, (int) $pdo->query('SELECT coins FROM users')->fetchColumn());
    }

    public function testCreateCallbackExceptionRollsBackAndRethrows(): void
    {
        [$pdo, $u, $t] = $this->dbFixture();
        try {
            Sites::create($u, $t, ['x' => 'y'], static function (): ?string {
                throw new RuntimeException('boom');
            });
            self::fail('debía relanzar');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM user_sites')->fetchColumn());
        self::assertFalse($pdo->inTransaction());
    }

    public function testCreateCallbackSuccessChargesAndWithoutCallbackBehavesAsBefore(): void
    {
        [$pdo, $u, $t] = $this->dbFixture();
        $res = Sites::create($u, $t, ['x' => 'y'], static function (PDO $p, int $id, string $slug): ?string {
            $p->prepare('INSERT INTO site_images (site_id, slot, file, mime, width, height, bytes) VALUES (?, ?, ?, ?, 1, 1, 1)')
                ->execute([$id, 'principal', 'a1b2c3d4e5f60718.webp', 'image/webp']);
            return null;
        });
        self::assertArrayHasKey('slug', $res);
        self::assertSame(90, (int) $pdo->query('SELECT coins FROM users')->fetchColumn());
        $siteId = (int) $pdo->query('SELECT id FROM user_sites')->fetchColumn();
        self::assertSame(['principal'], array_keys(TemplateImages::forSite($siteId, $res['slug'])));
        // Datos manipulados en site_images: se ignoran.
        $pdo->exec("INSERT INTO site_images (site_id, slot, file, mime, width, height, bytes) VALUES ($siteId, '../../x', 'a1b2c3d4e5f60718.webp', 'image/webp', 1, 1, 1), ($siteId, 'ok', '../../etc/passwd', 'image/webp', 1, 1, 1)");
        self::assertSame(['principal'], array_keys(TemplateImages::forSite($siteId, $res['slug'])));
        // Borrar la página elimina las filas (FK CASCADE).
        $pdo->exec('DELETE FROM user_sites');
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM site_images')->fetchColumn());

        $u = $pdo->query('SELECT * FROM users')->fetch();
        self::assertArrayHasKey('slug', Sites::create($u, $t, ['x' => 'y']));
        self::assertSame(80, (int) $pdo->query('SELECT coins FROM users')->fetchColumn());
    }
}
