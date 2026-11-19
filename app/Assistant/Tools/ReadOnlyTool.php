<?php

declare(strict_types=1);

namespace App\Assistant\Tools;

use App\Enums\EnvelopeStatus;
use App\Models\Envelope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Base das consultas: tudo é filtrado pela empresa do usuário vinculado e ignora a lixeira; títulos e nomes saem
 * como texto inerte (uma linha, sem HTML, sem chaves duplas, entre « »); documento ambíguo nunca é escolhido sozinho.
 */
abstract class ReadOnlyTool implements Tool
{
    protected const TZ = 'America/Sao_Paulo';

    public function needsConfirmation(): bool
    {
        return false;
    }

    /** Documentos da empresa do usuário, fora da lixeira. @return Builder<Envelope> */
    protected function documents(User $user): Builder
    {
        return Envelope::query()->where('tenant_id', $user->tenant_id)->whereNull('trashed_at');
    }

    /** Texto vindo de dados (título, nome): sem quebras de linha, sem HTML, sem chaves duplas, com tamanho limitado. */
    protected function clean(string $text, int $max = 80): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '';
        $text = str_replace(['<', '>', '{{', '}}'], ['‹', '›', '{ {', '} }'], $text);
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return Str::limit($text, $max, '…');
    }

    protected function quote(string $text, int $max = 80): string
    {
        return '«'.$this->clean(str_replace(['«', '»'], '"', $text), $max).'»';
    }

    protected function statusLabel(EnvelopeStatus $status): string
    {
        return match ($status) {
            EnvelopeStatus::Draft => 'Rascunho', EnvelopeStatus::Sent => 'Enviado', EnvelopeStatus::InProgress => 'Em andamento', EnvelopeStatus::Sealing => 'Finalizando',
            EnvelopeStatus::Completed => 'Concluído', EnvelopeStatus::Refused => 'Recusado', EnvelopeStatus::Expired => 'Expirado', EnvelopeStatus::Cancelled => 'Cancelado',
        };
    }

    protected function date(?\DateTimeInterface $at): string
    {
        return $at === null ? '—' : \Carbon\CarbonImmutable::instance($at)->timezone(self::TZ)->format('d/m/Y');
    }

    /**
     * Acha um documento pelo identificador ou por parte do título.
     *
     * @return array{doc:?Envelope,options:Collection<int,Envelope>}  doc preenchido = um só; options = vários candidatos (até 5); ambos vazios = não achou
     */
    protected function resolve(User $user, string $term): array
    {
        $term = trim($term);
        if ($term === '') {
            return ['doc' => null, 'options' => collect()];
        }

        if (Str::isUuid($term)) {
            return ['doc' => $this->documents($user)->whereKey($term)->first(), 'options' => collect()];
        }

        $found = $this->documents($user)->whereRaw('title ILIKE ?', ['%'.$this->escapeLike(mb_substr($term, 0, 100)).'%'])
            ->orderByDesc('created_at')->orderByDesc('id')->limit(6)->get();

        return $found->count() === 1 ? ['doc' => $found->first(), 'options' => collect()] : ['doc' => null, 'options' => $found->take(5)->values()];
    }

    protected function notFound(): ToolResult
    {
        return new ToolResult('Eu não encontrei esse documento entre os da sua empresa. Confira o nome ou peça a lista dos documentos.');
    }

    /** Mais de um candidato: mostra as opções e pede para escolher (nunca escolhe sozinho). @param Collection<int,Envelope> $options */
    protected function ask(Collection $options): ToolResult
    {
        $lines = $options->values()->map(fn (Envelope $e, int $i) => ($i + 1).'. '.$this->quote($e->title).' — '.$this->statusLabel($e->status).', '.$this->date($e->created_at))->implode("\n");

        return new ToolResult(
            "Encontrei vários documentos com esse nome. Diga qual deles você quer:\n".$lines,
            list: ['body' => 'Qual documento?', 'button' => 'Escolher', 'rows' => $this->rows($options)],
        );
    }

    /** @param Collection<int,Envelope> $docs @return list<array{id:string,title:string,description:string}> */
    protected function rows(Collection $docs): array
    {
        return $docs->take(10)->map(fn (Envelope $e) => [
            'id' => 'doc_'.$e->getKey(), 'title' => Str::limit($this->clean($e->title, 24), 24, ''),
            'description' => Str::limit($this->statusLabel($e->status).', '.$this->date($e->created_at), 72, ''),
        ])->values()->all();
    }

    private function escapeLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }
}
