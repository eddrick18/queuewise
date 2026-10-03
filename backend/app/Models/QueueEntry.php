<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QueueEntry extends Model
{
    protected $fillable = [
        'user_id',
        'service_id',
        'queue_number',
        'queue_date',
        'status',
        'joined_at',
        'called_at',
        'completed_at',
        'priority_at',
    ];

    protected function casts(): array
    {
        return [
            'queue_number' => 'integer',
            'queue_date' => 'date',
            'joined_at' => 'datetime',
            'called_at' => 'datetime',
            'completed_at' => 'datetime',
            'priority_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
