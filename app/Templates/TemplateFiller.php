<?php

declare(strict_types=1);

namespace App\Templates;

use App\Envelopes\Exceptions\FlowException;
use App\Models\DocumentTemplate;
use App\Signing\Exceptions\SigningException;
use App\Signing\Support\SealerClient;

/** Preenche as lacunas de um modelo: valida os valores e pede ao serviço de selagem para escrevê-los no PDF. */
final class TemplateFiller
{
    public const MAX_VALUE = 500;

    public function __construct(private readonly SealerClient $sealer)
    {
    }

    /**
     * Limpa os valores recebidos: UTF-8 inválido vira "?" e quebras de linha do navegador (\r\n) viram \n,
     * para que o limite de 500 caracteres conte como o navegador (que conta a quebra como 1).
     *
     * @param  mixed  $values
     * @return array<string,mixed>
     */
    public static function normalize(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }
        $out = [];
        foreach ($values as $k => $v) {
            $out[$k] = is_string($v) ? str_replace(["\r\n", "\r"], "\n", mb_convert_encoding($v, 'UTF-8', 'UTF-8')) : $v;
        }

        return $out;
    }

    /** Regras de validação (Laravel) de cada lacuna, sob o prefixo dado: obrigatória ou opcional, texto de até 500 caracteres. @return array<string,list<string>> */
    public function rules(DocumentTemplate $template, string $prefix = 'values', bool $enforceRequired = true): array
    {
        $rules = [];
        foreach ($template->fields as $f) {
            $rules["{$prefix}.{$f->key}"] = [$enforceRequired && $f->required ? 'required' : 'nullable', 'string', 'max:'.self::MAX_VALUE];
        }

        return $rules;
    }

    /** Chaves das lacunas obrigatórias que ficaram sem valor. @param array<string,mixed> $values @return list<string> */
    public function missing(DocumentTemplate $template, array $values): array
    {
        return $template->fields->filter(fn ($f) => $f->required && trim((string) ($values[$f->key] ?? '')) === '')->pluck('key')->values()->all();
    }

    /**
     * Devolve o PDF com os valores escritos. Lacunas em branco não desenham nada.
     *
     * @param  array<string,mixed>  $values  chave da lacuna => texto
     *
     * @throws ValueDoesNotFit quando um texto não cabe
     * @throws FlowException PDF ilegível ou serviço indisponível
     */
    public function render(DocumentTemplate $template, array $values): string
    {
        $payload = [];
        foreach ($template->fields as $f) {
            $text = str_replace("\r\n", "\n", (string) ($values[$f->key] ?? ''));
            if (trim($text) === '') {
                continue;
            }
            $payload[] = ['key' => $f->key, 'page' => $f->page, 'x' => $f->x, 'y' => $f->y, 'width' => $f->width, 'height' => $f->height, 'text' => $text, 'multiline' => $f->multiline];
        }

        try {
            return $this->sealer->fill($template->pdfContents(), $payload)['pdf'];
        } catch (FillException $e) {
            if ($e->reason === 'does_not_fit') {
                $label = (string) ($template->fields->firstWhere('key', $e->fieldKey)?->label ?? $e->fieldKey);
                throw new ValueDoesNotFit((string) $e->fieldKey, $label);
            }
            throw FlowException::because('invalid_document', 'Não foi possível preencher o PDF deste modelo.');
        } catch (SigningException) {
            throw FlowException::because('sealer_unavailable', 'O serviço de PDF está indisponível no momento. Tente de novo em instantes.');
        }
    }
}
