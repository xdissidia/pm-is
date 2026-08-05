<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Attachment extends Model
{
    protected $fillable = [
        'task_id',
        'storm_attachment_id',
        'user_id',
        'name',
        'path',
        'thumb',
        'type',
        'size',
    ];

    protected $casts = [
        'storm_attachment_id' => 'integer',
    ];

    /**
     * Where the file actually sits on disk. `path` is the URL the front end
     * uses ("/storage/tasks/1/x.pdf"); uploads are written to the local disk
     * under "public/". Null when the file is gone or stored elsewhere.
     */
    public function absolutePath(): ?string
    {
        if (! Str::startsWith($this->path, '/storage/')) {
            return null;
        }

        $absolute = storage_path('app/public/'.Str::after($this->path, '/storage/'));

        return is_file($absolute) ? $absolute : null;
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
