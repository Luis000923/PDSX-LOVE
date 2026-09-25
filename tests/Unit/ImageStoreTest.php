<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ImageStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/img_' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function needGd(): void
    {
        if (!extension_loaded('gd') || !function_exists('imagewebp')) {
            self::markTestSkipped('GD con WebP no disponible (se verifica dentro del contenedor).');
        }
    }

    private function write(string $bytes, string $name = 'in.bin'): string
    {
        $p = $this->dir . '/' . $name;
        file_put_contents($p, $bytes);
        return $p;
    }

    private function jpeg(int $w = 40, int $h = 20): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, 0xff8800);
        ob_start();
        imagejpeg($im);
        return (string) ob_get_clean();
    }

    // ----------------------------------------------- sin GD (siempre corren) ---

    public function testUploadWrapperRejectsBadEntries(): void
    {
        self::assertIsString(ImageStore::fromUpload(['error' => UPLOAD_ERR_OK, 'tmp_name' => ['x']], $this->dir));
        self::assertIsString(ImageStore::fromUpload(['error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => ''], $this->dir));
        self::assertIsString(ImageStore::fromUpload(['error' => UPLOAD_ERR_PARTIAL, 'tmp_name' => ''], $this->dir));
        // Un archivo local que NO viene de una subida HTTP no se acepta (is_uploaded_file).
        self::assertSame('Archivo inválido.', ImageStore::fromUpload(['error' => UPLOAD_ERR_OK, 'tmp_name' => $this->write('x')], $this->dir));
    }

    public function testEmptyAndOversizedFilesRejected(): void
    {
        self::assertIsString(ImageStore::process($this->write(''), $this->dir));
        self::assertStringContainsString('5 MB', (string) ImageStore::process($this->write(str_repeat('a', ImageStore::MAX_BYTES + 1)), $this->dir));
    }

    public function testExifOrientationParser(): void
    {
        $mk = static function (int $o, bool $le): string {
            $tiff = ($le ? 'II' . pack('v', 42) . pack('V', 8) : 'MM' . pack('n', 42) . pack('N', 8))
                . ($le ? pack('v', 1) . pack('vvV', 0x0112, 3, 1) . pack('v', $o) . "\0\0" : pack('n', 1) . pack('nnN', 0x0112, 3, 1) . pack('n', $o) . "\0\0")
                . "\0\0\0\0";
            $seg = "Exif\0\0" . $tiff;
            return "\xFF\xD8\xFF\xE1" . pack('n', strlen($seg) + 2) . $seg . "\xFF\xD9";
        };
        foreach ([1, 3, 6, 8] as $o) {
            self::assertSame($o, ImageStore::exifOrientation($mk($o, true)));
            self::assertSame($o, ImageStore::exifOrientation($mk($o, false)));
        }
        self::assertSame(1, ImageStore::exifOrientation($mk(9, true)));
        self::assertSame(1, ImageStore::exifOrientation("\xFF\xD8\xFF\xE1\xFF\xFFgarbage"));
        self::assertSame(1, ImageStore::exifOrientation(''));
    }

    public function testPublishRejectsBadSlugAndNames(): void
    {
        $f = $this->write('x', 'a1b2c3d4e5f60718.webp');
        self::assertNull(ImageStore::publish($f, '../etc'));
        self::assertNull(ImageStore::publish($this->write('x', 'evil.php'), 'abcd2345'));
        self::assertFileExists($f);
    }

    // ------------------------------------------------------------- con GD ---

    public function testValidJpegIsReencodedAsWebpWithRandomName(): void
    {
        $this->needGd();
        $r = ImageStore::process($this->write($this->jpeg(3000, 1500)), $this->dir);
        self::assertIsArray($r);
        self::assertMatchesRegularExpression(TemplateImages::FILE_RE, basename($r['path']));
        self::assertSame(1600, $r['width']);
        self::assertSame(800, $r['height']);
        self::assertSame('image/webp', (new finfo(FILEINFO_MIME_TYPE))->file($r['path']));
        self::assertSame(0644, fileperms($r['path']) & 0777);
    }

    public function testPngAndWebpAccepted(): void
    {
        $this->needGd();
        $im = imagecreatetruecolor(30, 30);
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        ob_start();
        imagewebp($im);
        $webp = (string) ob_get_clean();
        self::assertIsArray(ImageStore::process($this->write($png), $this->dir));
        self::assertIsArray(ImageStore::process($this->write($webp), $this->dir));
    }

    public function testPhpRenamedAsJpgIsRejected(): void
    {
        $this->needGd();
        self::assertIsString(ImageStore::process($this->write('<?php system($_GET["c"]); ?>', 'x.jpg'), $this->dir));
        self::assertIsString(ImageStore::process($this->write("GIF89a<?php system('id'); ?>", 'x.jpg'), $this->dir));
    }

    public function testJpegWithPhpPayloadInCommentIsNeutralized(): void
    {
        $this->needGd();
        $jpg = $this->jpeg();
        $payload = '<?php system($_GET["c"]); ?>';
        $com = "\xFF\xFE" . pack('n', strlen($payload) + 2) . $payload;
        $poly = substr($jpg, 0, 2) . $com . substr($jpg, 2);
        self::assertStringContainsString('<?php', $poly);
        $r = ImageStore::process($this->write($poly), $this->dir);
        self::assertIsArray($r);
        self::assertStringNotContainsString('<?', (string) file_get_contents($r['path']));
        self::assertStringNotContainsString('system', (string) file_get_contents($r['path']));
    }

    public function testGiantPixelCountRejectedBeforeDecoding(): void
    {
        $this->needGd();
        // PNG mínimo con cabecera IHDR de 20000x20000 (el CRC no se comprueba en getimagesize).
        $ihdr = pack('NNCCCCC', 20000, 20000, 8, 2, 0, 0, 0);
        $png = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr)) . pack('N', 0) . 'IEND' . pack('N', crc32('IEND'));
        $r = ImageStore::process($this->write($png), $this->dir);
        self::assertIsString($r);
        self::assertStringContainsString('píxeles', $r);
        // 6000x5000 = 30 MP (lados válidos, total excesivo).
        $ihdr = pack('NNCCCCC', 6000, 5000, 8, 2, 0, 0, 0);
        $png = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr)) . pack('N', 0) . 'IEND' . pack('N', crc32('IEND'));
        self::assertStringContainsString('píxeles', (string) ImageStore::process($this->write($png), $this->dir));
    }

    public function testSvgGifEmptyAndTruncatedRejected(): void
    {
        $this->needGd();
        self::assertIsString(ImageStore::process($this->write('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), $this->dir));
        $im = imagecreatetruecolor(5, 5);
        ob_start();
        imagegif($im);
        $gif = (string) ob_get_clean();
        self::assertStringContainsString('JPG, PNG o WebP', (string) ImageStore::process($this->write($gif), $this->dir));
        self::assertIsString(ImageStore::process($this->write(''), $this->dir));
        $jpg = $this->jpeg(200, 200);
        self::assertIsString(ImageStore::process($this->write(substr($jpg, 0, 30)), $this->dir));
        self::assertSame([], glob($this->dir . '/*.webp'), 'un rechazo no deja archivos de salida');
    }
}
