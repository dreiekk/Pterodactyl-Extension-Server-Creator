<?php

declare(strict_types=1);

namespace ServerCreator\Models;

use Illuminate\Database\Eloquent\Model;

final class AuditEntry extends Model
{
    public $timestamps = false;

    protected $table = 'ext_server_creator_audit';

    protected $fillable = ['actor_id', 'actor_name', 'action', 'target', 'details'];

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }
}
