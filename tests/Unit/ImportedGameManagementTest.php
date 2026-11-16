<?php

declare(strict_types=1);

namespace ServerCreator\Tests\Unit;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use ServerCreator\Http\Controllers\AdminController;
use ServerCreator\Models\AuditEntry;
use ServerCreator\Models\Game;
use ServerCreator\Panel\Panel;
use ServerCreator\Services\Audit;
use ServerCreator\Services\Quotas;

$autoload = getenv('SERVER_CREATOR_LARAVEL_AUTOLOAD');
if ($autoload && is_file($autoload)) {
    require_once $autoload;
}

if (class_exists(\Illuminate\Database\Eloquent\Model::class)) {
    class ImportedTestUser extends \Illuminate\Database\Eloquent\Model
    {
        protected $table = 'users';
    }

    class ImportedTestEgg extends \Illuminate\Database\Eloquent\Model
    {
        protected $table = 'eggs';

        public function variables(): \Illuminate\Database\Eloquent\Relations\HasMany
        {
            return $this->hasMany(ImportedTestVariable::class, 'egg_id');
        }
    }

    class ImportedTestVariable extends \Illuminate\Database\Eloquent\Model
    {
        protected $table = 'egg_variables';
    }

    class ImportedTestRequest extends \Illuminate\Http\Request
    {
        public function validate(array $rules, ...$params): array
        {
            if (! class_exists(\Illuminate\Validation\Factory::class)) {
                return array_intersect_key($this->all(), $rules);
            }
            $translator = new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader, 'en');
            $validator = (new \Illuminate\Validation\Factory($translator))->make($this->all(), $rules);
            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            return $validator->validated();
        }
    }

    class ImportedTestValidationException extends RuntimeException
    {
        public function __construct(private readonly array $messages)
        {
            parent::__construct('Validation failed.');
        }

        public static function withMessages(array $messages): self
        {
            return new self($messages);
        }

        public function errors(): array
        {
            return $this->messages;
        }
    }
}

final class ImportedGameManagementTest extends TestCase
{
    protected function setUp(): void
    {
        if (! class_exists(Manager::class)) {
            self::markTestSkipped('Laravel database components are required.');
        }
        if (! class_exists(\Pterodactyl\Models\User::class)) {
            class_alias(ImportedTestUser::class, \Pterodactyl\Models\User::class);
        }
        if (! class_exists(\Pterodactyl\Models\Egg::class)) {
            class_alias(ImportedTestEgg::class, \Pterodactyl\Models\Egg::class);
        }
        if (! class_exists(ValidationException::class)) {
            class_alias(ImportedTestValidationException::class, ValidationException::class);
        }
        $db = new Manager;
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
        $db->setAsGlobal();
        $db->bootEloquent();
        Game::unguard();
        $schema = $db->schema();
        Schema::swap($schema);
        $schema->create('users', static function ($table): void {
            $table->increments('id');
            $table->string('username');
        });
        $schema->create('eggs', static function ($table): void {
            $table->increments('id');
            $table->string('name');
        });
        $schema->create('servers', static function ($table): void {
            $table->increments('id');
        });
        $schema->create('egg_variables', static function ($table): void {
            $table->increments('id');
            $table->integer('egg_id');
            $table->string('env_variable');
            $table->boolean('user_viewable');
            $table->boolean('user_editable');
        });
        (require __DIR__.'/../../database/migrations/2026_10_09_200000_create_ext_server_creator_tables.php')->up();
        (require __DIR__.'/../../database/migrations/2026_11_17_000000_add_catalog_import_permissions.php')->up();
        (require __DIR__.'/../../database/migrations/2026_11_18_000000_create_ext_server_creator_audit.php')->up();
        $schema->table('ext_server_creator_games', static function ($table): void {
            $table->integer('ports')->default(1);
            $table->text('port_variables')->nullable();
            $table->text('default_ports')->nullable();
            $table->string('port_range')->nullable();
        });
        $db->table('users')->insert(['id' => 1, 'username' => 'alice']);
        $db->table('eggs')->insert(['id' => 5, 'name' => 'Barotrauma']);
        $db->table('servers')->insert(['id' => 20]);
        $db->table('egg_variables')->insert(['egg_id' => 5, 'env_variable' => 'QUERY_PORT', 'user_viewable' => true, 'user_editable' => true]);
    }

    protected function tearDown(): void
    {
        if (class_exists(Manager::class)) {
            Game::reguard();
        }
        parent::tearDown();
    }

    public function test_private_updates_validate_and_audit_without_changing_owner_or_egg(): void
    {
        $game = $this->game();
        $this->game(null);
        $listed = $this->controller()->importedGames()->getData(true)['games'];
        self::assertCount(1, $listed);
        self::assertSame('alice', $listed[0]['owner_name']);
        $this->controller()->updateImportedGame($this->request($this->input()), $game->id);
        $game->refresh();
        self::assertSame('Edited', $game->name);
        self::assertSame(1, $game->owner_id);
        self::assertSame(5, $game->egg_id);
        self::assertSame(2147483647, $game->memory_max);
        $entry = AuditEntry::query()->firstOrFail();
        self::assertSame('imported_game.updated', $entry->action);
        self::assertSame('Original', $entry->details['before']['name']);
        self::assertSame('Edited', $entry->details['after']['name']);
    }

    public function test_secondary_variables_must_be_editable_and_fit_the_port_count(): void
    {
        $game = $this->game();
        foreach ([
            ['port_variables' => ['SECRET']],
            ['ports' => 1, 'default_ports' => [27015], 'port_variables' => ['QUERY_PORT']],
        ] as $changes) {
            try {
                $this->controller()->updateImportedGame($this->request(array_replace($this->input(), $changes)), $game->id);
                self::fail('Invalid secondary ports were accepted.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('port_variables', $exception->errors());
            }
        }
    }

    public function test_default_port_count_must_match_required_ports(): void
    {
        $game = $this->game();
        try {
            $this->controller()->updateImportedGame($this->request(array_replace($this->input(), ['default_ports' => [27015]])), $game->id);
            self::fail('Mismatched default ports were accepted.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('default_ports', $exception->errors());
        }
    }

    public function test_deletion_preserves_egg_mapping_and_server_and_unlinks_profile(): void
    {
        $game = $this->game();
        Manager::table('ext_server_creator_catalog_eggs')->insert(['catalog_id' => 'barotrauma', 'egg_id' => 5]);
        Manager::table('ext_server_creator_servers')->insert(['server_id' => 20, 'user_id' => 1, 'game_id' => $game->id]);
        $this->controller()->destroyImportedGame($this->request([]), $game->id);
        self::assertTrue(Manager::table('eggs')->where('id', 5)->exists());
        self::assertTrue(Manager::table('servers')->where('id', 20)->exists());
        self::assertTrue(Manager::table('ext_server_creator_catalog_eggs')->where('catalog_id', 'barotrauma')->exists());
        self::assertNull(Manager::table('ext_server_creator_servers')->where('server_id', 20)->value('game_id'));
        self::assertSame('imported_game.deleted', AuditEntry::query()->firstOrFail()->action);
    }

    public function test_private_actions_reject_public_profiles(): void
    {
        $game = $this->game(null);
        foreach (['updateImportedGame', 'enableImportedGame', 'destroyImportedGame'] as $method) {
            try {
                $this->controller()->$method($this->request($this->input()), $game->id);
                self::fail('Public profile accepted.');
            } catch (ModelNotFoundException) {
                self::assertTrue($game->fresh()->enabled);
            }
        }
    }

    public function test_disabling_and_reenabling_are_audited(): void
    {
        $game = $this->game();
        $this->controller()->enableImportedGame($this->request(['enabled' => false]), $game->id);
        self::assertFalse($game->fresh()->enabled);
        try {
            $game->fresh()->ensureCatalogSelectable();
            self::fail('Disabled profiles must not be selectable through the catalog.');
        } catch (\ServerCreator\Exceptions\CreatorException $exception) {
            self::assertSame(403, $exception->render()->getStatusCode());
        }
        $this->controller()->enableImportedGame($this->request(['enabled' => true]), $game->id);
        self::assertTrue($game->fresh()->enabled);
        $game->fresh()->ensureCatalogSelectable();
        $entries = AuditEntry::query()->orderBy('id')->get();
        self::assertSame(['enabled' => true], $entries[0]->details['before']);
        self::assertSame(['enabled' => false], $entries[0]->details['after']);
        self::assertSame(['enabled' => true], $entries[1]->details['after']);
    }

    private function controller(): AdminController
    {
        return new AdminController((new ReflectionClass(Panel::class))->newInstanceWithoutConstructor(), new Quotas, new Audit);
    }

    private function request(array $data): ImportedTestRequest
    {
        $request = new ImportedTestRequest($data);
        $user = new \Pterodactyl\Models\User;
        $user->id = 1;
        $user->username = 'alice';
        $request->setUserResolver(static fn () => $user);

        return $request;
    }

    private function game(?int $owner = 1): Game
    {
        return Game::query()->create([
            'owner_id' => $owner, 'egg_id' => 5, 'name' => 'Original', 'memory_min' => 64, 'memory_max' => 2147483647,
            'disk_min' => 128, 'disk_max' => 2147483647, 'cpu_min' => 10, 'cpu_max' => 2147483647,
            'ports' => 2, 'default_ports' => [27015, 27016], 'port_variables' => ['QUERY_PORT'],
        ]);
    }

    private function input(): array
    {
        return [
            'name' => 'Edited', 'description' => null, 'enabled' => true, 'memory_min' => 64, 'memory_max' => 2147483647,
            'disk_min' => 128, 'disk_max' => 2147483647, 'cpu_min' => 10, 'cpu_max' => 2147483647,
            'ports' => 2, 'default_ports' => [27015, 27016], 'port_variables' => ['QUERY_PORT'], 'port_range' => null,
            'egg_id' => 999, 'owner_id' => 999,
        ];
    }
}
