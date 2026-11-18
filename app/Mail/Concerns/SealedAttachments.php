<?php

declare(strict_types=1);

namespace App\Mail\Concerns;

use App\Models\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Facades\Storage;

/** PDFs selados de um envio como anexos, respeitando o teto de tamanho do e-mail. */
trait SealedAttachments
{
    /** @return array{0:list<Attachment>,1:bool} anexos que cabem e se algum arquivo ficou de fora */
    protected function sealedAttachments(Envelope $envelope): array
    {
        $cap = (int) round((float) config('signing.mail_attachment_max_mb') * 1048576);
        $out = [];
        $used = 0;
        $partial = false;

        foreach ($envelope->files() as $file) {
            if ($file->finalPath === null || ! Storage::disk('local')->exists($file->finalPath)) {
                continue;
            }
            $size = (int) ($file->finalSize ?? Storage::disk('local')->size($file->finalPath));
            if ($used + $size > $cap) {
                $partial = true;

                continue;
            }
            $used += $size;
            $out[] = Attachment::fromStorageDisk('local', $file->finalPath)->as('assinado-'.$file->name)->withMime('application/pdf');
        }

        return [$out, $partial];
    }
}
