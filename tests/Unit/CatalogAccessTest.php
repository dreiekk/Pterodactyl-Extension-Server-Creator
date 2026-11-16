<?php

declare(strict_types=1);

namespace ServerCreator\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ServerCreator\Models\CatalogEgg;
use ServerCreator\Models\CatalogPermission;
use ServerCreator\Models\Game;

final class CatalogAccessTest extends TestCase
{
    protected function setUp(): void
    {
        $autoload = getenv('SERVER_CREATOR_LARAVEL_AUTOLOAD');
        if ($autoload && is_file($autoload)) {
            require_once $autoload;
        }
        if (! class_exists(\Illuminate\Database\Capsule\Manager::class)) {
            self::markTestSkipped('Laravel database components are required for catalog access tests.');
        }

        $database = new \Illuminate\Database\Capsule\Manager;
        $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        $database->setAsGlobal();
        $database->bootEloquent();
        $schema = $database->schema();
        $schema->create('users', static function ($table): void {
            $table->increments('id');
        });
        $schema->create('eggs', static function ($table): void {
            $table->increments('id');
        });
        $schema->create('ext_server_creator_games', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('owner_id')->nullable();
            $table->string('name');
            $table->boolean('enabled')->default(true);
            $table->integer('ports')->default(1);
            $table->timestamps();
        });
        $schema->create('ext_server_creator_catalog_permissions', static function ($table): void {
            $table->unsignedInteger('user_id')->primary();
        });
        $schema->create('ext_server_creator_catalog_eggs', static function ($table): void {
            $table->string('catalog_id')->primary();
            $table->unsignedInteger('egg_id');
        });
    }

    public function test_private_games_are_only_visible_to_their_authorized_owner(): void
    {
        Game::query()->create(['name' => 'Public']);
        Game::query()->create(['name' => 'Alice', 'owner_id' => 1]);
        Game::query()->create(['name' => 'Bob', 'owner_id' => 2]);
        Game::query()->create(['name' => 'Disabled', 'owner_id' => 1, 'enabled' => false]);

        $available = static fn (int $id, bool $allowed): array => Game::query()->availableTo($id, $allowed)->where('enabled', true)->orderBy('id')->pluck('name')->all();
        self::assertSame(['Public', 'Alice'], $available(1, true));
        self::assertSame(['Public', 'Bob'], $available(2, true));
        self::assertSame(['Public'], $available(1, false));
        self::assertSame(['Public'], $available(3, true));
    }

    public function test_permissions_and_catalog_mapping_use_their_real_primary_keys(): void
    {
        CatalogPermission::query()->firstOrCreate(['user_id' => 9]);
        self::assertTrue(CatalogPermission::query()->whereKey(9)->exists());
        CatalogPermission::query()->whereKey(9)->delete();
        self::assertFalse(CatalogPermission::query()->whereKey(9)->exists());

        CatalogEgg::query()->create(['catalog_id' => 'games-barotrauma', 'egg_id' => 42]);
        self::assertSame(42, CatalogEgg::query()->findOrFail('games-barotrauma')->egg_id);
    }

    public function test_catalog_migration_can_be_applied_and_rolled_back(): void
    {
        $schema = \Illuminate\Database\Capsule\Manager::schema();
        $schema->drop('ext_server_creator_catalog_permissions');
        $schema->drop('ext_server_creator_catalog_eggs');
        $schema->drop('ext_server_creator_games');
        $schema->create('ext_server_creator_games', static function ($table): void {
            $table->increments('id');
        });
        \Illuminate\Support\Facades\Schema::swap($schema);
        $migration = require __DIR__.'/../../database/migrations/2026_11_17_000000_add_catalog_import_permissions.php';
        $migration->up();
        self::assertTrue($schema->hasColumn('ext_server_creator_games', 'owner_id'));
        self::assertTrue($schema->hasTable('ext_server_creator_catalog_eggs'));
        self::assertTrue($schema->hasTable('ext_server_creator_catalog_permissions'));
        $migration->down();
        self::assertFalse($schema->hasColumn('ext_server_creator_games', 'owner_id'));
        self::assertFalse($schema->hasTable('ext_server_creator_catalog_eggs'));
        self::assertFalse($schema->hasTable('ext_server_creator_catalog_permissions'));
    }
}
