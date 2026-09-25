<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/SiteExport.php';

final class SiteExportTest extends TestCase
{
    #[DataProvider('names')]
    public function testFilenameIsSafe(string $in, string $out): void
    {
        $this->assertSame($out, SiteExport::filename($in));
    }

    public static function names(): array
    {
        return [['abc123xy', 'pagina-abc123xy.html'], ['../../etc/passwd', 'pagina-etc-passwd.html'],
                ["A\"b\r\nX: y", 'pagina-a-b-x-y.html'], ['', 'pagina-amor.html'], ['ñ%', 'pagina-amor.html']];
    }

    public function testInlinesLocalAssetsOnlyInsideTemplateDir(): void
    {
        $d = sys_get_temp_dir() . '/lp_exp_' . bin2hex(random_bytes(4));
        mkdir("$d/demo", 0777, true);
        file_put_contents("$d/demo/a.css", 'body{color:red}');
        file_put_contents("$d/secret.css", 'SECRETO');
        $base = 'http://x.test';
        $html = '<link rel="stylesheet" href="' . $base . '/assets/tpl/demo/a.css">'
              . '<link rel="stylesheet" href="' . $base . '/assets/tpl/demo/../../secret.css">';
        $out = SiteExport::selfContain($html, 'demo', $d, $base);
        $this->assertStringContainsString('<style>body{color:red}</style>', $out);
        $this->assertStringNotContainsString('SECRETO', $out);
        unlink("$d/demo/a.css"); unlink("$d/secret.css"); rmdir("$d/demo"); rmdir($d);
    }
}
