<?php

namespace App\Platform\Files;

use App\Platform\Context\ExecutionContext;
use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Arquivo de uma organização (docs/01, seção 7.3). Os produtos guardam só o file_id.
 * Disco: "public" em servidor único; "s3" quando o bucket existir (F4) — só configuração.
 */
class File extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = ['organization_id', 'owner_type', 'disk', 'path', 'mime', 'size'];

    /**
     * Guarda o envio na pasta da organização com nome novo (nunca o nome que veio do navegador).
     */
    public static function store(UploadedFile $upload, string $ownerType, string $disk, string $folder): self
    {
        $file = new self(['owner_type' => $ownerType, 'disk' => $disk, 'mime' => $upload->getMimeType(), 'size' => $upload->getSize()]);
        $file->id = strtolower((string) Str::ulid());
        $file->organization_id = ExecutionContext::current()->organization->getKey();

        $file->path = $upload->storeAs(
            "org/{$file->organization_id}/{$folder}",
            $file->id.'.'.($upload->guessExtension() ?: 'bin'),
            ['disk' => $disk, 'visibility' => 'public'],
        );
        $file->save();

        return $file;
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    /** Apaga o registro e o arquivo. */
    public function purge(): void
    {
        Storage::disk($this->disk)->delete($this->path);
        $this->delete();
    }
}
