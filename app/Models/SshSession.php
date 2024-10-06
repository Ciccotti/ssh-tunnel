<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SshSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'connection_request_id',
        'started_at',
        'ended_at',
        'status'
    ];

    public function connectionRequest()
    {
        return $this->belongsTo(ConnectionRequest::class);
    }
}