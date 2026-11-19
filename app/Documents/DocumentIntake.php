<?php

declare(strict_types=1);

namespace App\Documents;

use App\Envelopes\Exceptions\FlowException;
use App\Signing\Exceptions\SigningException;
use App\Signing\Support\SealerClient;
use Illuminate\Http\UploadedFile;

/** Recebe o arquivo enviado (PDF ou DOCX) e devolve sempre um PDF; o DOCX é convertido no selador. */
final class DocumentIntake
{
    /** Confere só o começo do arquivo (sem converter): serve para recusar um lote inteiro antes de gravar qualquer coisa. */
    public function assertAcceptable(UploadedFile $file): void
    {
        $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 4);
        $isPdf = str_starts_with($head, '%PDF');
        $isDocx = str_starts_with($head, 'PK') && strtolower($file->getClientOriginalExtension()) === 'docx';
        if (! $isPdf && ! $isDocx) {
            throw FlowException::because('invalid_document', 'Envie um arquivo PDF ou DOCX ('.$file->getClientOriginalName().').');
        }
    }

    /**
     * @return array{pdf:string,name:string,source_sha256:?string,source_name:?string}
     *
     * @throws FlowException arquivo que não é o que diz ser, falha na conversão ou selador indisponível
     */
    public function fromUpload(UploadedFile $file): array
    {
        return $this->fromBytes((string) file_get_contents($file->getRealPath()), $file->getClientOriginalName());
    }

    /**
     * Mesma regra para arquivo que não veio de um upload de formulário (ex.: baixado do WhatsApp).
     *
     * @return array{pdf:string,name:string,source_sha256:?string,source_name:?string}
     *
     * @throws FlowException
     */
    public function fromBytes(string $bytes, string $name, int $convertTimeout = 90): array
    {
        if (str_starts_with($bytes, '%PDF')) {
            return ['pdf' => $bytes, 'name' => $name, 'source_sha256' => null, 'source_name' => null];
        }

        if (! str_starts_with($bytes, 'PK') || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'docx') {
            throw FlowException::because('invalid_document', 'Envie um arquivo PDF ou DOCX.');
        }

        try {
            $pdf = app(SealerClient::class)->convert($bytes, $convertTimeout);
        } catch (ConvertException $e) {
            throw FlowException::because('convert_failed', match ($e->reason) {
                'invalid_docx' => 'Este arquivo não parece ser um documento do Word (DOCX) válido.',
                'timeout' => 'A conversão do documento demorou demais. Salve-o como PDF e envie de novo.',
                default => 'Não conseguimos converter este documento do Word. Salve-o como PDF e envie de novo.',
            });
        } catch (SigningException) {
            throw FlowException::because('convert_unavailable', 'A conversão de documentos do Word está indisponível agora. Envie em PDF ou tente em alguns minutos.');
        }

        return [
            'pdf' => $pdf,
            'name' => preg_replace('/\.docx$/i', '', $name).'.pdf',
            'source_sha256' => hash('sha256', $bytes),
            'source_name' => $name,
        ];
    }
}
