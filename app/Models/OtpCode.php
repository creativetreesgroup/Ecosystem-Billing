<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['phone', 'code_hash', 'expires_at', 'attempts', 'consumed_at', 'sent_via'])]
class OtpCode extends Model
{
    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'code_hash' => 'hashed',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }
}
