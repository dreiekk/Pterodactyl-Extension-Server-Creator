<?php

declare(strict_types=1);

namespace ServerCreator\Models;

use Illuminate\Database\Eloquent\Model;

final class CatalogPermission extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'ext_server_creator_catalog_permissions';

    protected $primaryKey = 'user_id';

    protected $fillable = ['user_id'];
}
