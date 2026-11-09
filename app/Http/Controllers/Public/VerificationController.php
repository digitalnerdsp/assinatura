<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Evidence\EvidenceLogger;
use App\Http\Controllers\Controller;
use App\Models\Envelope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Verificação pública: qualquer pessoa com o código confere status, signatários e hash do documento. */
final class VerificationController extends Controller
{
    public function __construct(private readonly EvidenceLogger $evidence)
    {
    }

    public function index(): View
    {
        return view('verify.index');
    }

    public function lookup(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);

        return redirect()->route('verify.show', strtoupper(trim($data['code'])));
    }

    public function show(string $code): View
    {
        return view('verify.show', $this->data($code) + ['match' => null]);
    }

    public function check(Request $request, string $code): View
    {
        $request->validate(['file' => ['required', 'file', 'max:51200']]);

        $data = $this->data($code);
        /** @var Envelope $envelope */
        $envelope = $data['envelope'];
        $hash = hash_file('sha256', $request->file('file')->getRealPath());

        $match = match (true) {
            $envelope->final_sha256 !== null && hash_equals($envelope->final_sha256, $hash) => 'final',
            hash_equals($envelope->document->sha256, $hash) => 'original',
            default => 'none',
        };

        return view('verify.show', $data + ['match' => $match, 'uploadedHash' => $hash]);
    }

    /** @return array{envelope:Envelope,intact:bool} */
    private function data(string $code): array
    {
        $envelope = Envelope::query()
            ->with(['document', 'tenant', 'signers.signature'])
            ->where('verification_code', strtoupper($code))
            ->firstOrFail();

        return ['envelope' => $envelope, 'intact' => $this->evidence->isIntact($envelope)];
    }
}
