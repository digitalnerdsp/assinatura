<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\SigningOrder;
use App\Envelopes\EnvelopeService;
use App\Envelopes\Exceptions\FlowException;
use App\Evidence\EvidenceLogger;
use App\Evidence\RequestContext;
use App\Http\Controllers\Controller;
use App\Models\Envelope;
use App\Notifications\SignerNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class EnvelopeController extends Controller
{
    public function __construct(
        private readonly EnvelopeService $envelopes,
        private readonly SignerNotifier $notifier,
        private readonly EvidenceLogger $evidence,
    ) {
    }

    public function dashboard(Request $request): View
    {
        $envelopes = Envelope::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->withCount('signers')
            ->latest()
            ->paginate(20);

        return view('envelopes.index', compact('envelopes'));
    }

    public function create(): View
    {
        return view('envelopes.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'message' => ['nullable', 'string', 'max:2000'],
            'document' => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'signing_order' => ['required', Rule::enum(SigningOrder::class)],
            'required_level' => ['required', Rule::in(['simple', 'advanced'])],
            'signers' => ['required', 'array', 'min:1', 'max:20'],
            'signers.*.name' => ['required', 'string', 'max:150'],
            'signers.*.email' => ['required', 'email', 'max:190'],
            'signers.*.phone' => ['nullable', 'string', 'max:30'],
            'signers.*.cpf' => ['nullable', 'string', 'max:14'],
        ]);

        try {
            $envelope = $this->envelopes->create(
                $request->user()->tenant,
                $request->user(),
                (string) file_get_contents($request->file('document')->getRealPath()),
                $request->file('document')->getClientOriginalName(),
                $data['title'],
                array_values($data['signers']),
                SigningOrder::from($data['signing_order']),
                $data['required_level'],
                $data['message'] ?? null,
            );
        } catch (FlowException $e) {
            return back()->withInput()->withErrors(['document' => $e->getMessage()]);
        }

        return redirect()->route('envelopes.show', $envelope)->with('status', 'Envelope criado. Revise e envie para assinatura.');
    }

    public function show(Request $request, Envelope $envelope): View
    {
        $this->owned($request, $envelope);
        $envelope->load(['signers.signature', 'document']);

        return view('envelopes.show', [
            'envelope' => $envelope,
            'events' => $envelope->evidenceEvents()->get(),
            'intact' => $this->evidence->isIntact($envelope),
        ]);
    }

    public function send(Request $request, Envelope $envelope): RedirectResponse
    {
        $this->owned($request, $envelope);

        try {
            $tokens = $this->envelopes->send($envelope, RequestContext::fromRequest($request));
        } catch (FlowException $e) {
            return back()->withErrors(['envelope' => $e->getMessage()]);
        }

        foreach ($envelope->signers as $signer) {
            $this->notifier->invite($signer, $tokens[$signer->getKey()]);
        }

        return back()->with('status', 'Convites enviados.');
    }

    public function cancel(Request $request, Envelope $envelope): RedirectResponse
    {
        $this->owned($request, $envelope);

        try {
            $this->envelopes->cancel($envelope, RequestContext::fromRequest($request));
        } catch (FlowException $e) {
            return back()->withErrors(['envelope' => $e->getMessage()]);
        }

        return back()->with('status', 'Envelope cancelado.');
    }

    public function original(Request $request, Envelope $envelope): StreamedResponse
    {
        $this->owned($request, $envelope);

        return Storage::disk('local')->download($envelope->document->storage_path, $envelope->document->name);
    }

    public function final(Request $request, Envelope $envelope): StreamedResponse
    {
        $this->owned($request, $envelope);
        abort_if($envelope->final_path === null, 404);

        return Storage::disk('local')->download($envelope->final_path, 'assinado-'.$envelope->document->name);
    }

    private function owned(Request $request, Envelope $envelope): void
    {
        abort_unless($envelope->tenant_id === $request->user()->tenant_id, 404);
    }
}
