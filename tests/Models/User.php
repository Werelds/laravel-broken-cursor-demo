<?php

namespace Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

/**
 * @property int                $id
 *
 * @property Thing[]|Collection $things
 */
class User extends Model
{
    public function things(): BelongsToMany
    {
        return $this->belongsToMany(Thing::class, 'user_things', 'userId', 'thingId');
    }
}
