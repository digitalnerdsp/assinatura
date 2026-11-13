<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Signer;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Documentos em que o usuário logado é signatário (pelo e-mail dele).
 *
 * Só da PRÓPRIA empresa: o cadastro ainda não verifica o e-mail, então listar envelopes de outras
 * empresas deixaria qualquer pessoa ler (e assinar) documentos alheios cadastrando o e-mail da vítima.
 */
final class MySignaturesController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $signers = Signer::query()
            ->where('role', 'signer')
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($user->email)])
            ->whereHas('envelope', fn ($q) => $q
                ->where('tenant_id', $user->tenant_id)
                ->visible()
                ->where('status', '!=', 'draft'))
            ->with('envelope')
            ->latest()
            ->paginate(20);

        // Para cada linha: pode assinar agora? (envelope aberto, pendente e na vez dele)
        $signers->getCollection()->each(function (Signer $s): void {
            $envelope = $s->envelope;
            $s->setAttribute('can_sign', $envelope->status->isOpenForSigning()
                && ! in_array($s->status->value, ['signed', 'refused'], true)
                && $envelope->activeSigners()->contains('id', $s->id));
            $s->setAttribute('waiting_turn', $envelope->status->isOpenForSigning()
                && ! in_array($s->status->value, ['signed', 'refused'], true)
                && ! $s->can_sign);
        });

        return view('documents.mine', ['signers' => $signers]);
    }
}
