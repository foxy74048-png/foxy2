<?php

namespace App\Ai\HostProfiles;

use Illuminate\Database\Eloquent\Model;

/**
 * Versão do conhecimento de um anfitrião (manual, telas, glossário). Acessado sempre pelo HostProfile.
 */
class KnowledgePack extends Model
{
    protected $fillable = ['version', 'content', 'content_hash'];
}
