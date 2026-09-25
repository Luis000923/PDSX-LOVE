<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** El destino tras registrarse/entrar solo puede salir de una lista blanca (sin redirección abierta). */
final class AuthNextTest extends TestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
    }

    public function testKnownValuesMapToInternalPaths(): void
    {
        $_GET['next'] = 'premium';
        $this->assertSame('dashboard.php?offer=1#premium', auth_next('create.php'));
        $_GET['next'] = 'code';
        $this->assertSame('dashboard.php?offer=code#premium', auth_next('create.php'));
    }

    #[DataProvider('hostile')]
    public function testAnythingElseFallsBackToDefault(mixed $value): void
    {
        $_GET['next'] = $value;
        $this->assertSame('create.php', auth_next('create.php'));
        $this->assertSame('', auth_next_qs());
    }

    public static function hostile(): array
    {
        return [['https://evil.example'], ['//evil.example'], ['../admin/index.php'], ['PREMIUM'], [''], [['premium']], ['premium ']];
    }

    public function testMissingNextUsesDefault(): void
    {
        $this->assertSame('dashboard.php', auth_next('dashboard.php'));
        $this->assertSame('', auth_next_qs());
    }

    public function testQueryStringKeepsIntent(): void
    {
        $_GET['next'] = 'code';
        $this->assertSame('?next=code', auth_next_qs());
    }
}
