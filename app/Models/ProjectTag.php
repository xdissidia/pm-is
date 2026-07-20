<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Lacodix\LaravelModelFilter\Traits\IsSearchable;
use Lacodix\LaravelModelFilter\Traits\IsSortable;
use LaravelArchivable\Archivable;

class ProjectTag extends Model
{
    use Archivable, IsSearchable, IsSortable;

    protected $fillable = ['name', 'color', 'order'];

    protected $searchable = [
        'name',
    ];

    protected $sortable = [
        'name' => 'asc',
    ];

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class);
    }
}
