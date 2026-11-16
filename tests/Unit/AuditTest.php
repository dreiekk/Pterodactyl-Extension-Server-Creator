<?php

declare(strict_types=1);

namespace ServerCreator\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ServerCreator\Models\AuditEntry;
use ServerCreator\Services\Audit;

final class AuditTest extends TestCase
{
    protected function setUp(): void
    {
        $autoload = getenv('SERVER_CREATOR_LARAVEL_AUTOLOAD');
        if ($autoload && is_file($autoload)) {
            require_once $autoload;
        }
        if (! class_exists(\Illuminate\Database\Capsule\Manager::class)) {
            self::markTestSkipped('Laravel database components are required for audit tests.');
        }
        if (! class_exists(\Pterodactyl\Models\User::class)) {
            class_alias(AuditTestUser::class, \Pterodactyl\Models\User::class);
        }
        $database = new \Illuminate\Database\Capsule\Manager;
        $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $database->setAsGlobal();
        $database->bootEloquent();
        \Illuminate\Support\Facades\Schema::swap($database->schema());
        $migration = require __DIR__.'/../../database/migrations/2026_11_18_000000_create_ext_server_creator_audit.php';
        $migration->up();
    }

    public function test_records_actor_snapshot_and_safe_details_without_user_foreign_key(): void
    {
        $actor = new \Pterodactyl\Models\User;
        $actor->id = 7;
        $actor->username = 'alice';
        (new Audit)->record($actor, 'server.created', 'Survival', ['server_id' => 42, 'memory' => 2048]);
        $entry = AuditEntry::query()->firstOrFail();
        self::assertSame(7, $entry->actor_id);
        self::assertSame('alice', $entry->actor_name);
        self::assertSame('server.created', $entry->action);
        self::assertSame('Survival', $entry->target);
        self::assertSame(['server_id' => 42, 'memory' => 2048], $entry->details);
        self::assertNotNull($entry->created_at);
    }

    public function test_migration_rolls_back(): void
    {
        $migration = require __DIR__.'/../../database/migrations/2026_11_18_000000_create_ext_server_creator_audit.php';
        $migration->down();
        self::assertFalse(\Illuminate\Database\Capsule\Manager::schema()->hasTable('ext_server_creator_audit'));
    }

    public function test_pages_without_duplicate_or_missing_entries_when_new_events_arrive(): void
    {
        $actor = new \Pterodactyl\Models\User;
        $actor->id = 7;
        $actor->username = 'alice';
        $audit = new Audit;
        for ($i = 1; $i <= 55; $i++) {
            $audit->record($actor, 'server.created', "Server {$i}");
        }
        $first = $audit->page();
        self::assertCount(50, $first['entries']);
        self::assertSame(55, $first['entries'][0]['id']);
        self::assertSame(6, $first['next_before']);
        $audit->record($actor, 'server.created', 'New server');
        $second = $audit->page($first['next_before']);
        self::assertSame([5, 4, 3, 2, 1], array_column($second['entries'], 'id'));
        self::assertNull($second['next_before']);
    }
}

if (class_exists(\Illuminate\Database\Eloquent\Model::class)) {
    class AuditTestUser extends \Illuminate\Database\Eloquent\Model
    {
        protected $table = 'users';
    }
} elseif (($autoload = getenv('SERVER_CREATOR_LARAVEL_AUTOLOAD')) && is_file($autoload)) {
    require_once $autoload;
    class AuditTestUser extends \Illuminate\Database\Eloquent\Model
    {
        protected $table = 'users';
    }
}
