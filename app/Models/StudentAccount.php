<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class StudentAccount extends Authenticatable
{
    use BelongsToMasjid;
    use HasApiTokens { generateTokenString as private sanctumTokenString; }

    protected $fillable = ['masjid_id','contact_id','username','password','enabled','must_change_password','last_sign_in_at'];
    protected $hidden = ['password'];
    protected function casts(): array { return ['password'=>'hashed','enabled'=>'boolean','must_change_password'=>'boolean','last_sign_in_at'=>'datetime']; }
    public function setUsernameAttribute(string $value): void { $this->attributes['username'] = strtolower(trim($value)); }

    protected static function booted(): void
    {
        static::deleting(function (self $account): void { $account->tokens()->delete(); });
    }

    // The prefix is INSIDE the hashed secret: stripping it invalidates the credential.
    // It permits the global fence to refuse this realm without adding SQL to OFF readers.
    public function generateTokenString(): string { return 'student_'.$this->sanctumTokenString(); }
}
