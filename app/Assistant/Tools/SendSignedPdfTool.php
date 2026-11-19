<?php

declare(strict_types=1);

namespace App\Assistant\Tools;

use App\Assistant\Channel\MetaException;
use App\Assistant\Channel\MetaWhatsAppClient;
use App\Enums\EnvelopeStatus;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Envia ao WhatsApp do próprio usuário o PDF assinado de um documento concluído (um por arquivo). O destinatário não é
 * um parâmetro: vem do usuário vinculado. O PDF nunca passa pela IA. Arquivo grande demais vai por link do painel.
 */
final class SendSignedPdfTool extends ReadOnlyTool
{
    public function __construct(private readonly MetaWhatsAppClient $client)
    {
    }

    public function name(): string
    {
        return 'send_signed_pdf';
    }

    public function description(): string
    {
        return 'Envia ao WhatsApp do usuário o PDF assinado de um documento concluído. "document" é o identificador ou parte do título.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['document' => ['type' => 'string', 'maxLength' => 100]], 'required' => ['document']];
    }

    public function run(User $user, array $args): ToolResult
    {
        $found = $this->resolve($user, (string) ($args['document'] ?? ''));
        if ($found['doc'] === null) {
            return $found['options']->isNotEmpty() ? $this->ask($found['options']) : $this->notFound();
        }

        $e = $found['doc'];
        $title = $this->quote($e->title);
        if ($e->status !== EnvelopeStatus::Completed) {
            return new ToolResult("O documento {$title} ainda não foi concluído. Só envio documentos assinados por todos.");
        }

        $files = $e->files()->filter(fn ($f) => $f->finalPath !== null && Storage::disk('local')->exists($f->finalPath))->values();
        if ($files->isEmpty()) {
            return new ToolResult("O PDF assinado de {$title} não está disponível agora. Veja no painel: ".route('envelopes.show', $e));
        }
        $phone = (string) $user->whatsapp_phone;
        if ($phone === '') {
            return new ToolResult('O seu número de WhatsApp não está vinculado.');
        }

        $limit = (int) round((float) config('assistant.max_send_mb') * 1048576);
        if ($files->contains(fn ($f) => (int) ($f->finalSize ?? Storage::disk('local')->size($f->finalPath)) > $limit)) {
            return new ToolResult("O PDF assinado de {$title} é grande demais para o WhatsApp. Baixe pelo painel: ".route('envelopes.show', $e));
        }

        $sent = 0;
        try {
            foreach ($files as $i => $f) {
                $mediaId = $this->client->uploadMedia((string) Storage::disk('local')->get($f->finalPath), 'application/pdf');
                $this->client->sendDocument($phone, $mediaId, 'assinado-'.$this->fileName($f->name), $i === 0 ? 'Documento assinado: '.$this->clean($e->title, 100) : null);
                $sent++;
            }
        } catch (MetaException $ex) {
            report($ex);

            return new ToolResult($sent === 0
                ? 'Não consegui enviar o arquivo agora. Baixe pelo painel: '.route('envelopes.show', $e)
                : "Não consegui enviar tudo agora: {$sent} de {$files->count()} arquivos já foram enviados. Baixe o restante pelo painel: ".route('envelopes.show', $e));
        }

        return new ToolResult($files->count() === 1
            ? "Enviei o PDF assinado de {$title} para este WhatsApp."
            : "Enviei os {$files->count()} arquivos assinados de {$title} para este WhatsApp.");
    }

    private function fileName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F\\\\\/]+/u', '_', $name) ?? 'documento.pdf';

        return mb_substr(trim($name) !== '' ? $name : 'documento.pdf', 0, 120);
    }
}
