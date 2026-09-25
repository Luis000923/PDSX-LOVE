<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Base de las pruebas de creadores: usuarios, plantillas 'utpl' y limpieza de archivos. Cada clase corre en proceso aparte. */
abstract class CreatorsTestCase extends TestCase
{
    protected static PDO $pdo;
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        try {
            self::$pdo = db();
        } catch (PDOException $e) {
            self::markTestSkipped('MySQL no disponible: ' . $e->getMessage());
        }
        foreach (['template_earnings', 'site_creations', 'template_unlocks', 'html_uploads', 'user_sites'] as $t) {
            self::$pdo->exec("DELETE FROM $t");
        }
        self::$pdo->exec("DELETE FROM templates WHERE kind = 'utpl'");
        foreach (['coin_transactions', 'award_grants', 'user_badges', 'awards_closed_months', 'payments', 'users'] as $t) {
            self::$pdo->exec("DELETE FROM $t");
        }
        self::$pdo->exec("DELETE FROM settings WHERE `key` IN ('creators_config', 'awards_config')");
        Creators::flushCache();
        Awards::flushCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
        self::$pdo->exec("DELETE FROM settings WHERE `key` IN ('creators_config', 'awards_config')");
        Creators::flushCache();
    }

    protected function tierId(string $slug): int
    {
        return (int) self::$pdo->query("SELECT id FROM membership_tiers WHERE slug = '$slug'")->fetchColumn();
    }

    /** @return array<string,mixed> */
    protected function user(string $alias, int $coins = 0, ?string $tier = null, string $exp = '2099-01-01 00:00:00'): array
    {
        $tid = $tier === null ? null : $this->tierId($tier);
        self::$pdo->prepare('INSERT INTO users (email, password_hash, display_name, coins, membership_tier_id, is_premium, membership_expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$alias . uniqid() . '@t.test', 'x', $alias, $coins, $tid, $tid === null ? 0 : 1, $tid === null ? null : $exp]);
        return $this->row('users', (int) self::$pdo->lastInsertId());
    }

    /** @return array<string,mixed> */
    protected function row(string $table, int $id): array
    {
        $st = self::$pdo->prepare("SELECT * FROM $table WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch();
    }

    /** Plantilla utpl con archivo en storage. @return array<string,mixed> */
    protected function utpl(int $owner, string $status = 'approved', int $price = 20, bool $quota = false, string $html = '<h1>{{your_name}}</h1><p>{{message}}</p>'): array
    {
        $slug = 'u-' . bin2hex(random_bytes(5));
        self::$pdo->prepare("INSERT INTO templates (slug, name, kind, category, file, price_coins, is_premium, membership_unlocks, is_active, owner_user_id, review_status, credit_alias, reviewed_at)
                             VALUES (?, ?, 'utpl', 'romantico', ?, ?, ?, ?, ?, ?, ?, (SELECT display_name FROM users WHERE id = ?), UTC_TIMESTAMP())")
            ->execute([$slug, 'T ' . $slug, $slug . '.html', $price, $quota ? 1 : 0, $quota ? 1 : 0, $status === 'approved' ? 1 : 0, $owner, $status, $owner]);
        $id = (int) self::$pdo->lastInsertId();
        $path = Creators::htmlPath($slug);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $html);
        $this->files[] = $path;
        $this->files[] = Creators::pendingPath($slug);
        return $this->row('templates', $id);
    }

    /** @return array<string,string> */
    protected function data(): array
    {
        return ['your_name' => 'Ana', 'partner_name' => 'Luis', 'start_date' => '2024-01-01', 'message' => 'Hola'];
    }

    protected function coins(int $uid): int
    {
        return (int) self::$pdo->query("SELECT coins FROM users WHERE id = $uid")->fetchColumn();
    }

    protected function ledger(int $uid): int
    {
        return (int) self::$pdo->query("SELECT COALESCE(SUM(delta), 0) FROM coin_transactions WHERE user_id = $uid")->fetchColumn();
    }

    protected function scalar(string $sql): int
    {
        return (int) self::$pdo->query($sql)->fetchColumn();
    }

    /** Compra (crea una página) con la fila de usuario fresca. @return array{slug?:string,error?:string} */
    protected function buy(int $uid, array $tpl): array
    {
        return Sites::create($this->row('users', $uid), $tpl, $this->data());
    }
}
