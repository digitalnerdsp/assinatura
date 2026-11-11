<?php

declare(strict_types=1);

use App\Mail\SignatureRequestedMail;
use App\Models\Envelope;
use App\Models\Signer;

// Mail::fake() não renderiza o template; este teste garante que o Blade compila de verdade.
it('renderiza o convite de assinatura (normal, lembrete e com prazo)', function (bool $reminder, bool $withExpiry) {
    $envelope = new Envelope(['title' => 'Contrato', 'message' => 'Segue o contrato.']);
    if ($withExpiry) {
        $envelope->expires_at = now()->addDays(5);
    }
    $signer = new Signer(['name' => 'Ana', 'email' => 'ana@example.com']);
    $signer->setRelation('envelope', $envelope);

    $html = (new SignatureRequestedMail($signer, 'https://exemplo.test/assinar/abc', $reminder))->render();

    expect($html)->toContain('Contrato')->toContain('https://exemplo.test/assinar/abc');
    if ($withExpiry) {
        expect($html)->toContain('disponível até');
    }
})->with([[false, false], [true, false], [true, true]]);
