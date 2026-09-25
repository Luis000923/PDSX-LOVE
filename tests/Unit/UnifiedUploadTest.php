<?php
declare(strict_types=1);

require_once __DIR__ . '/CreatorsTestCase.php';
require_once dirname(__DIR__, 2) . '/src/HtmlScanner.php';
require_once dirname(__DIR__, 2) . '/src/UserHtml.php';

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Flujo unificado: fotos en el HTML propio privado, HTML sin marcadores intacto, rutas seguras y «publicar después». */
#[RunTestsInSeparateProcesses]
final class UnifiedUploadTest extends CreatorsTestCase
{
    private const HTML = '<!doctype html><html><body><h1>{{your_name}}</h1>{{#if img_foto_1}}<img src="{{img_foto_1}}" alt="">{{/if}}{{#unless img_foto_2}}<p>sin 2</p>{{/unless}}<p>{{img_count}}</p></body></html>';

    public function testPhotoMarkersAreReplacedWithSiteImages(): void
    {
        $imgs = TemplateImages::urls('abcdef23', [['slot' => 'foto_1', 'file' => str_repeat('a', 16) . '.webp']]);
        $out = UserHtml::withPhotos(self::HTML, $imgs, 'Mi <página>');
        self::assertStringContainsString('uploads/sites/abcdef23/' . str_repeat('a', 16) . '.webp', $out);
        self::assertStringContainsString('<p>sin 2</p>', $out);
        self::assertStringContainsString('Mi &lt;página&gt;', $out);
        self::assertStringContainsString('<p>1</p>', $out);
        self::assertStringNotContainsString('{{', $out);
        $none = UserHtml::withPhotos(self::HTML, [], 'X');
        self::assertStringNotContainsString('<img', $none, 'sin foto, el condicional se oculta');
    }

    public function testHtmlWithoutPhotoMarkersIsServedUnchanged(): void
    {
        $plain = '<html><body>{{message}} {{algo}} <p>hola</p></body></html>';
        self::assertSame($plain, UserHtml::withPhotos($plain, ['foto_1' => 'x'], 'N'));
    }

    public function testMaliciousKeysAndFilesCannotEscapeUploadsDirectory(): void
    {
        $bad = TemplateImages::urls('abcdef23', [
            ['slot' => 'foto_1', 'file' => '../../etc/passwd'],
            ['slot' => '../x', 'file' => str_repeat('b', 16) . '.webp'],
            ['slot' => 'foto_2', 'file' => str_repeat('c', 16) . '.php'],
        ]);
        self::assertSame([], $bad);
        self::assertNull(TemplateImages::urlFor('../abcdef', str_repeat('a', 16) . '.webp'));
        $out = UserHtml::withPhotos('<img src="{{img_../../x}}"><img src="{{img_foto_1}}">', ['foto_1' => (string) TemplateImages::urlFor('abcdef23', str_repeat('d', 16) . '.webp')], 'N');
        self::assertStringNotContainsString('..', str_replace('{{img_../../x}}', '', $out));
        preg_match_all('#src="([^"]*)"#', $out, $m);
        foreach ($m[1] as $src) {
            self::assertTrue($src === '{{img_../../x}}' || str_contains($src, '/uploads/sites/abcdef23/'), $src);
        }
    }

    public function testScannerAcceptsPhotoMarkersInPrivateHtml(): void
    {
        self::assertSame([], HtmlScanner::scan(self::HTML, 'index.html'));
        self::assertSame([], HtmlScanner::scanTemplate(str_replace('{{your_name}}', '{{your_name}}', self::HTML)));
    }

    public function testPublishLaterReadsStoredHtmlWithoutTouchingQuota(): void
    {
        $u = $this->user('Despues');
        $other = $this->user('Otra');
        self::$pdo->exec("INSERT IGNORE INTO templates (slug, name, kind, category, file) VALUES ('html-propio', 'HTML propio', 'user', 'romantico', 'html-propio.html')");
        $tid = $this->scalar("SELECT id FROM templates WHERE slug = 'html-propio'");
        $slug = 'pq' . bin2hex(random_bytes(3));
        self::$pdo->prepare("INSERT INTO user_sites (user_id, template_id, slug, data) VALUES (?, ?, ?, ?)")->execute([$u['id'], $tid, $slug, json_encode(['your_name' => 'Mi web'])]);
        $sid = (int) self::$pdo->lastInsertId();
        self::$pdo->prepare('INSERT INTO user_html_sites (site_id, sha256, bytes, has_assets) VALUES (?, ?, 1, 0)')->execute([$sid, str_repeat('a', 64)]);
        UserHtml::store($slug, self::HTML, []);
        try {
            $r = Creators::htmlFromSite((int) $u['id'], $sid);
            self::assertSame([], $r['errors']);
            self::assertSame('Mi web', $r['name']);
            self::assertNotSame([], Creators::htmlFromSite((int) $other['id'], $sid)['errors'], 'solo el dueño');
            $thumb = tempnam(sys_get_temp_dir(), 'th');
            file_put_contents($thumb, 'x');
            $sub = Creators::submit((int) $u['id'], ['name' => 'Publicada luego', 'description' => '', 'category' => 'romantico', 'photos' => 2, 'price' => 20, 'quota' => false], $r['html'], $thumb);
            self::assertSame([], $sub['errors']);
            self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM html_uploads'), 'no consume cupo de HTML propio');
            self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM user_sites WHERE id = $sid"), 'la página privada sigue igual');
            self::assertSame('pending', $this->row('templates', $sub['id'])['review_status']);
            Admin::deleteThumbnail((string) $this->row('templates', $sub['id'])['thumbnail']);
            @unlink(Creators::pendingPath($sub['slug']));
            // Con recursos de .zip no se puede publicar todavía.
            self::$pdo->exec("UPDATE user_html_sites SET has_assets = 1 WHERE site_id = $sid");
            self::assertNotSame([], Creators::htmlFromSite((int) $u['id'], $sid)['errors']);
        } finally {
            Sites::purgeFiles($slug);
        }
    }

    public function testEntryPointsAndOldUrlRedirect(): void
    {
        $root = dirname(__DIR__, 2) . '/public/';
        self::assertStringContainsString("upload_html.php?modo=publica", (string) file_get_contents($root . 'creator_upload.php'));
        foreach (['index.php', 'dashboard.php'] as $f) {
            self::assertStringContainsString('Subir mi plantilla', (string) file_get_contents($root . $f), $f);
        }
        self::assertStringContainsString('Publicar en la Galería', (string) file_get_contents($root . 'dashboard.php'));
        self::assertStringContainsString('modo=publica&desde=', (string) file_get_contents($root . 'dashboard.php'));
    }
}
