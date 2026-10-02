<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class NavigationNode extends Model
{
    use HasUuids;

    protected $fillable = [
        'floor_id',
        'type',
        'x',
        'y',
    ];

    protected $casts = [
        'x' => 'float',
        'y' => 'float',
    ];

    public function floor()
    {
        return $this->belongsTo(Floor::class);
    }

    public function outgoingEdges()
    {
        return $this->hasMany(NavigationEdge::class, 'from_node_id');
    }

    public function incomingEdges()
    {
        return $this->hasMany(NavigationEdge::class, 'to_node_id');
    }
}

