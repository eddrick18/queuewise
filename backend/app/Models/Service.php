<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    protected $fillable = [
        'name',
        'description',
        'average_service_minutes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'average_service_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function queueEntries(): HasMany
    {
        return $this->hasMany(QueueEntry::class);
    }
}