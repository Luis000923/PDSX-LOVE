<?php
declare(strict_types=1);

require_once __DIR__ . '/CreatorsTestCase.php';
require_once dirname(__DIR__, 2) . '/src/HtmlScanner.php';

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Envío, revisión, visibilidad, separación de cuotas, configuración, escaneo de plantillas y render aislado. */
#[RunTestsInSeparateProcesses]
final class CreatorsTest extends CreatorsTestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $slug) {
            @unlink(Creators::htmlPath($slug));
            @unlink(Creators::pendingPath($slug));
        }
        foreach (self::$pdo->query("SELECT thumbnail FROM templates WHERE kind = 'utpl' AND thumbnail IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $t) {
            Admin::deleteThumbnail((string) $t);
        }
        parent::tearDown();
    }

    /** @return array{id:int,slug:string,errors:list<string>} */
    private function submit(int $uid, array $over = [], ?string $alias = null): array
    {
        $thumb = tempnam(sys_get_temp_dir(), 'th');
        file_put_contents($thumb, 'x');
        $r = Creators::submit($uid, $over + ['name' => 'Mi plantilla', 'description' => 'Bonita', 'category' => 'romantico', 'photos' => 2, 'price' => 30, 'quota' => false],
            '<h1>{{your_name}}</h1>{{#if img_foto1}}<img src="{{img_foto1}}" alt="">{{/if}}', $thumb, $alias);
        @unlink($thumb);
        if ($r['slug'] !== '') {
            $this->cleanup[] = $r['slug'];
        }
        return $r;
    }

    public function testSubmitCreatesPendingInactiveTemplateWithSpecAndNoQuotaUsage(): void
    {
        $u = $this->user('Autora');
        $r = $this->submit((int) $u['id'], ['quota' => true, 'photos' => 3]);
        self::assertSame([], $r['errors']);
        $t = $this->row('templates', $r['id']);
        self::assertSame('pending', $t['review_status']);
        self::assertSame(0, (int) $t['is_active']);
        self::assertSame('utpl', $t['kind']);
        self::assertSame(1, (int) $t['is_premium']);
        self::assertSame(1, (int) $t['membership_unlocks']);
        self::assertCount(3, TemplateImages::parse($t['image_spec']));
        self::assertFileExists(Creators::pendingPath($t['slug']));
        self::assertFileDoesNotExist(Creators::htmlPath($t['slug']));
        // Cuotas SEPARADAS: el ledger de HTML propio no cambia y su cupo sigue intacto.
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM html_uploads'));
        self::assertSame(Access::FREE_MONTHLY_HTML_UPLOADS, Access::htmlUploadUsage($this->row('users', (int) $u['id']))['remaining']);
    }

    public function testUserHtmlUploadQuotaDoesNotBlockPublicSubmission(): void
    {
        $u = $this->user('Agotada');
        self::$pdo->prepare('INSERT INTO html_uploads (user_id, sha256, bytes) VALUES (?, ?, 1)')->execute([$u['id'], str_repeat('a', 64)]);
        self::assertSame(0, Access::htmlUploadUsage($this->row('users', (int) $u['id']))['remaining']);
        self::assertSame([], $this->submit((int) $u['id'])['errors'], 'el cupo de HTML propio agotado no impide publicar');
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM html_uploads'));
    }

    public function testAntiSpamLimitsAreConfigurable(): void
    {
        $cfg = Creators::DEFAULTS;
        $cfg['max_pending'] = 2;
        $cfg['max_templates'] = 3;
        self::assertSame([], Creators::saveConfig($cfg));
        $u = $this->user('Spam');
        self::assertSame([], $this->submit((int) $u['id'])['errors']);
        self::assertSame([], $this->submit((int) $u['id'])['errors']);
        self::assertNotSame([], $this->submit((int) $u['id'])['errors'], 'tercer pendiente rechazado');
        self::assertSame(2, $this->scalar("SELECT COUNT(*) FROM templates WHERE kind = 'utpl'"), 'un envío rechazado no deja filas');
        self::$pdo->exec("UPDATE templates SET review_status = 'approved' WHERE kind = 'utpl'");
        self::assertSame([], $this->submit((int) $u['id'])['errors']);
        self::assertNotSame([], $this->submit((int) $u['id'])['errors'], 'máximo de plantillas públicas');
    }

    public function testRejectedSubmissionLeavesNoRowsOrFiles(): void
    {
        $u = $this->user('Mala');
        $before = glob(Creators::dir() . '/*') ?: [];
        foreach ([['price' => 1], ['price' => 9999], ['category' => 'nada'], ['photos' => 13], ['name' => '']] as $over) {
            self::assertNotSame([], $this->submit((int) $u['id'], $over)['errors']);
        }
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM templates WHERE kind = 'utpl'"));
        self::assertSame($before, glob(Creators::dir() . '/*') ?: []);
    }

    public function testAliasIsRequiredIfMissingAndDoesNotActivateRankings(): void
    {
        self::$pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)')->execute(['sinalias@t.test', 'x']);
        $uid = (int) self::$pdo->lastInsertId();
        self::assertNotSame([], $this->submit($uid)['errors']);
        self::assertSame([], $this->submit($uid, [], 'Nuevo Alias')['errors']);
        $row = $this->row('users', $uid);
        self::assertSame('Nuevo Alias', $row['display_name']);
        self::assertSame(0, (int) $row['show_in_rankings']);
    }

    public function testApproveMakesVisibleAndPromotesFileRejectRequiresNote(): void
    {
        $u = $this->user('Aprueba');
        $admin = $this->user('Admin');
        $r = $this->submit((int) $u['id']);
        self::assertNotSame([], Creators::reject((int) $admin['id'], $r['id'], '  '), 'nota obligatoria');
        self::assertSame([], Creators::approve((int) $admin['id'], $r['id'], ['price' => 50, 'category' => 'aniversario', 'quota' => false]));
        $t = $this->row('templates', $r['id']);
        self::assertSame('approved', $t['review_status']);
        self::assertSame(1, (int) $t['is_active']);
        self::assertSame(50, (int) $t['price_coins']);
        self::assertSame(0, (int) $t['is_premium']);
        self::assertSame('Aprueba', $t['credit_alias']);
        self::assertFileExists(Creators::htmlPath($t['slug']));
        self::assertFileDoesNotExist(Creators::pendingPath($t['slug']));
        self::assertNotSame([], Creators::approve((int) $admin['id'], $r['id']), 'ya no está pendiente');
        self::assertNotSame([], Creators::approve((int) $admin['id'], $this->submit((int) $u['id'])['id'], ['price' => 500]), 'precio fuera de rango');
    }

    public function testRejectWithdrawResubmitFlow(): void
    {
        $u = $this->user('Ciclo');
        $admin = $this->user('Adm');
        $r = $this->submit((int) $u['id']);
        self::assertSame([], Creators::reject((int) $admin['id'], $r['id'], 'Falta contraste'));
        $t = $this->row('templates', $r['id']);
        self::assertSame('rejected', $t['review_status']);
        self::assertSame('Falta contraste', $t['review_note']);
        self::assertSame([], Creators::resubmit((int) $u['id'], $r['id'], '<p>{{message}}</p>'));
        self::assertSame('pending', $this->row('templates', $r['id'])['review_status']);
        self::assertSame([], Creators::approve((int) $admin['id'], $r['id']));
        self::assertTrue(Creators::withdraw($r['id'], (int) $u['id']));
        self::assertSame('withdrawn', $this->row('templates', $r['id'])['review_status']);
        self::assertSame(0, (int) $this->row('templates', $r['id'])['is_active']);
        self::assertFalse(Creators::withdraw($r['id'], (int) $this->user('Otro')['id']), 'solo el dueño');
        self::assertSame([], Creators::resubmit((int) $u['id'], $r['id'], '<p>{{message}} v2</p>'));
        self::assertStringContainsString('<p>{{message}}</p>', (string) Creators::html($t['slug']), 'el vigente no cambia hasta aprobar');
        self::assertStringContainsString('v2', (string) Creators::reviewHtml($t['slug']));
    }

    public function testNonApprovedTemplatesNeverAppearInPublicQueries(): void
    {
        $u = $this->user('Vis');
        $ids = [];
        foreach (['pending', 'rejected', 'withdrawn', 'approved'] as $s) {
            $ids[$s] = (int) $this->utpl((int) $u['id'], $s)['id'];
        }
        $st = self::$pdo->query('SELECT t.id FROM templates t WHERE ' . Creators::PUBLIC_WHERE . " AND t.kind = 'utpl'");
        self::assertSame([$ids['approved']], array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
        // Aunque un admin active is_active a mano, sin aprobar sigue oculta.
        self::$pdo->exec("UPDATE templates SET is_active = 1 WHERE kind = 'utpl'");
        $st = self::$pdo->query('SELECT t.id FROM templates t WHERE ' . Creators::PUBLIC_WHERE . " AND t.kind = 'utpl'");
        self::assertSame([$ids['approved']], array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
        // Ni colaboradores ni tienda las cuentan.
        self::assertSame([], Creators::collaborators());
    }

    public function testEveryPublicTemplateQueryFiltersReviewStatus(): void
    {
        // Barrera estática: las rutas públicas y de compra usan PUBLIC_WHERE o excluyen 'utpl' explícitamente.
        $root = dirname(__DIR__, 2);
        foreach (['public/index.php', 'public/create.php', 'public/preview.php'] as $f) {
            self::assertStringContainsString('Creators::PUBLIC_WHERE', (string) file_get_contents("$root/$f"), $f);
        }
        self::assertStringContainsString("kind NOT IN (\\'user\\', \\'utpl\\')", (string) file_get_contents("$root/public/checkout_wompi.php"));
        foreach (['templates', 'template_edit', 'template_preview'] as $f) {
            self::assertStringContainsString("'utpl'", (string) file_get_contents("$root/public/admin/$f.php"), $f);
        }
    }

    public function testConfigValidationIsStrict(): void
    {
        self::assertNotSame([], Creators::saveConfig(['share_pct' => 95] + Creators::DEFAULTS));
        self::assertNotSame([], Creators::saveConfig(['share_pct' => -1] + Creators::DEFAULTS));
        self::assertNotSame([], Creators::saveConfig(['min_price' => 300] + Creators::DEFAULTS), 'mínimo mayor que máximo');
        self::assertNotSame([], Creators::saveConfig(['milestones' => [['count' => 3, 'coins' => 1, 'badge' => ''], ['count' => 3, 'coins' => 1, 'badge' => '']]] + Creators::DEFAULTS));
        self::assertNotSame([], Creators::saveConfig(['milestones' => [['count' => 1, 'coins' => 1, 'badge' => 'hacker']]] + Creators::DEFAULTS));
        self::assertNotSame([], Creators::saveConfig(['share_pct' => 'abc'] + Creators::DEFAULTS));
        self::assertSame(30, Creators::config()['share_pct'], 'nada inválido se guardó');
        self::assertSame([], Creators::saveConfig(['share_pct' => '45', 'share_on_quota_unlock' => false] + Creators::DEFAULTS));
        self::assertSame(45, Creators::config()['share_pct']);
        self::$pdo->prepare("UPDATE settings SET `value` = '{roto' WHERE `key` = 'creators_config'")->execute();
        Creators::flushCache();
        self::assertSame(30, Creators::config()['share_pct'], 'JSON dañado: valores por defecto');
    }

    public function testTemplateScannerAcceptsMarkersAndRejectsAbuse(): void
    {
        $ok = '<!doctype html><html><body><h1>{{your_name}} y {{partner_name}}</h1><p>{{days_together}} días</p>{{#if img_foto1}}<img src="{{img_foto1}}" alt="">{{/if}}</body></html>';
        self::assertSame([], HtmlScanner::scanTemplate($ok));
        $bad = [
            'sin marcadores'     => '<p>hola</p>',
            'crudo'              => '<p>{{{nonce}}} {{message}}</p>',
            'desconocido'        => '<p>{{message}} {{secreto}}</p>',
            'en script'          => '<p>{{message}}</p><script>var a = "{{your_name}}";</script>',
            'en style'           => '<p>{{message}}</p><style>a{content:"{{your_name}}"}</style>',
            'en onclick'         => '<p onclick="x(\'{{your_name}}\')">{{message}}</p>',
            'en href'            => '<a href="{{message}}">x</a>',
            'en style attr'      => '<p style="background:url({{img_foto1}})">{{message}}</p>',
            'iframe'             => '<p>{{message}}</p><iframe src="https://e.com"></iframe>',
            'fetch'              => '<p>{{message}}</p><script>fetch("/x")</script>',
        ];
        foreach ($bad as $why => $html) {
            self::assertNotSame([], HtmlScanner::scanTemplate($html), $why);
        }
        self::assertNotSame([], HtmlScanner::scanTemplate(str_repeat('a', 524289) . '{{message}}'));
    }

    public function testInspectUploadRejectsZipAndAcceptsSingleHtml(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'h');
        file_put_contents($f, '<!doctype html><html><body><p>{{message}}</p></body></html>');
        self::assertSame([], Creators::inspectUpload($f, 'x.html')['errors']);
        self::assertNotSame([], Creators::inspectUpload($f, 'x.zip')['errors']);
        file_put_contents($f, '<!doctype html><html><body><p>sin marcadores</p></body></html>');
        self::assertNotSame([], Creators::inspectUpload($f, 'x.html')['errors']);
        @unlink($f);
    }

    public function testRenderEscapesUserDataAndNeverRunsInjectedMarkers(): void
    {
        $u = $this->user('Render');
        $t = $this->utpl((int) $u['id'], 'approved', 20, false, '<h1>{{your_name}}</h1><p>{{message}}</p>');
        $html = Creators::render($t['slug'], ['your_name' => '<script>alert(1)</script>', 'message' => "a\n{{partner_name}}", 'partner_name' => 'SECRETO', 'start_date' => '2024-01-01']);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('<br>', $html);
        self::assertStringNotContainsString('SECRETO', $html, 'los datos no se interpretan como plantilla (un solo pase)');
    }

    public function testCreatorsPathsAreValidated(): void
    {
        self::assertNull(Creators::html('../../etc/passwd'));
        self::assertNull(Creators::html('u-zz'));
        self::assertNull(Creators::reviewHtml('..'));
    }

    public function testSandboxHeadersNeverAllowSameOriginAndFrameUsesThem(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/UserHtml.php');
        self::assertStringContainsString("Content-Security-Policy: sandbox allow-scripts; default-src 'none'", $src);
        preg_match('/header\\(\"Content-Security-Policy: sandbox[^\\n]*/', $src, $m);
        self::assertNotEmpty($m);
        self::assertStringNotContainsString('allow-same-origin', $m[0]);
        self::assertStringContainsString("connect-src 'none'", $src);
        $root = dirname(__DIR__, 2);
        foreach (['public/frame.php', 'public/preview.php', 'public/admin/creator_preview.php'] as $f) {
            self::assertStringContainsString('UserHtml::sendSandboxHeaders()', (string) file_get_contents("$root/$f"), $f);
            self::assertStringNotContainsString('allow-scripts allow-same-origin', (string) file_get_contents("$root/$f"), $f);
        }
        $view = (string) file_get_contents("$root/public/view.php");
        self::assertStringContainsString("['user', 'utpl']", $view, 'view.php enmarca las utpl en iframe sandbox');
        self::assertStringContainsString('sandbox="allow-scripts"', $view);
    }
}
