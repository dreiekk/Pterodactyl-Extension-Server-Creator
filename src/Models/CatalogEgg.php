<?php

declare(strict_types=1);

namespace ServerCreator\Models;

use Illuminate\Database\Eloquent\Model;

final class CatalogEgg extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'ext_server_creator_catalog_eggs';

    protected $primaryKey = 'catalog_id';

    protected $keyType = 'string';

    protected $fillable = ['catalog_id', 'egg_id'];
}
