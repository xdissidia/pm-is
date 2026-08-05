<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A PMIS user as STORM knows them. Filled in by StormUserDirectory whenever
 * PMIS files a ticket, which is what lets tickets carry STORM's own user ids.
 */
class UserStorm extends Model
{
    protected $fillable = [
        'user_id',
        'storm_user_id',
        'name',
        'email',
        'pmis_user_id',
    ];

    protected $casts = [
        'storm_user_id' => 'integer',
        'pmis_user_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
