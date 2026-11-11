<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Api\Presenter;
use App\Enums\SigningOrder;
use App\Envelopes\EnvelopeService;
use App\Envelopes\Exceptions\FlowException;
use App\Evidence\RequestContext;
use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use App\Models\Tenant;
use App\Notifications\SignerNotifier;
use App\Templates\TemplateRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/** Modelos com {{variáveis}}: cadastro e geração de envelopes. */
final class TemplateController extends Controller
{
    public function __construct(
        private readonly EnvelopeService $envelopes,
        private readonly TemplateRenderer $renderer,
        private readonly SignerNotifier $notifier,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $templates = DocumentTemplate::query()->where('tenant_id', $this->tenant($request)->getKey())->latest()->get();

        return response()->json(['data' => $templates->map(fn ($t) => Presenter::template($t))->all()]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(Presenter::template($this->find($request, $id), withBody: true));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $template = DocumentTemplate::query()->create($data + ['tenant_id' => $this->tenant($request)->getKey()]);

        return response()->json(Presenter::template($template, withBody: true), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $template = $this->find($request, $id);
        $template->update($this->validated($request));

        return response()->json(Presenter::template($template->fresh(), withBody: true));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->find($request, $id)->delete();

        return response()->json(null, 204);
    }

    /** Gera um envelope a partir do modelo. Corpo: title, variables{}, signers[] e as mesmas opções de POST /envelopes. */
    public function generate(Request $request, string $id): JsonResponse
    {
        $template = $this->find($request, $id);

        $rules = [
            'title' => ['required', 'string', 'max:190'],
            'message' => ['nullable', 'string', 'max:2000'],
            'variables' => ['nullable', 'array'],
            'external_id' => ['nullable', 'string', 'max:120'],
            'metadata' => ['nullable', 'array', 'max:30'],
            'signing_order' => ['nullable', Rule::enum(SigningOrder::class)],
            'require_otp' => ['nullable', 'boolean'],
            'require_selfie' => ['nullable', 'boolean'],
            'require_document' => ['nullable', 'boolean'],
            'require_geolocation' => ['nullable', 'boolean'],
            'send' => ['nullable', 'boolean'],
            'validity_days' => ['nullable', 'integer', 'between:1,365'],
            'reminder_every_days' => ['nullable', 'integer', 'between:0,30'],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['in:email,whatsapp,sms'],
            'folder_id' => ['nullable', 'uuid'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['uuid'],
            'signers' => ['required', 'array', 'min:1', 'max:20'],
            'signers.*.name' => ['required', 'string', 'max:150'],
            'signers.*.email' => ['required', 'email', 'max:190'],
            'signers.*.phone' => ['nullable', 'string', 'max:30'],
            'signers.*.cpf' => ['nullable', 'string', 'max:14'],
            'signers.*.position' => ['nullable', 'integer', 'between:1,50'],
        ];
        foreach ($template->variables() as $var) {
            $rules["variables.$var"] = ['required', 'string', 'max:500'];
        }
        $data = $request->validate($rules);

        try {
            $pdf = $this->renderer->render($template, $data['variables'] ?? []);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['error' => 'render_failed', 'message' => 'Não foi possível gerar o PDF deste modelo.'], 500);
        }

        $reminder = $data['reminder_every_days'] ?? null;

        try {
            $envelope = $this->envelopes->create(
                $this->tenant($request),
                null,
                $pdf,
                Str::slug($template->name).'.pdf',
                $data['title'],
                array_values($data['signers']),
                SigningOrder::from($data['signing_order'] ?? 'parallel'),
                'simple',
                $data['message'] ?? null,
                [
                    'otp' => $request->boolean('require_otp'),
                    'selfie' => $request->boolean('require_selfie'),
                    'document' => $request->boolean('require_document'),
                    'geolocation' => $request->boolean('require_geolocation'),
                ],
                array_filter([
                    'validity_days' => $data['validity_days'] ?? null,
                    'channels' => $data['channels'] ?? null,
                    'folder_id' => $data['folder_id'] ?? null,
                    'tags' => $data['tags'] ?? null,
                    'external_id' => $data['external_id'] ?? null,
                    'metadata' => $data['metadata'] ?? null,
                ], fn ($v) => $v !== null) + ($reminder === null ? [] : ['reminder_every_days' => $reminder === 0 ? null : $reminder]),
            );

            if ($request->boolean('send')) {
                $this->envelopes->send($envelope, RequestContext::fromRequest($request));
                $this->notifier->inviteActive($envelope->fresh());
            }
        } catch (FlowException $e) {
            return response()->json(['error' => $e->reason, 'message' => $e->getMessage()], 422);
        }

        return response()->json(Presenter::envelope($envelope->fresh()), 201);
    }

    /** @return array{name:string,body:string} */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:200000'],
        ]);

        return ['name' => $data['name'], 'body' => TemplateRenderer::sanitize($data['body'])];
    }

    private function find(Request $request, string $id): DocumentTemplate
    {
        abort_unless(Str::isUuid($id), 404);

        return DocumentTemplate::query()->where('tenant_id', $this->tenant($request)->getKey())->findOrFail($id);
    }

    private function tenant(Request $request): Tenant
    {
        return $request->attributes->get('tenant');
    }
}
