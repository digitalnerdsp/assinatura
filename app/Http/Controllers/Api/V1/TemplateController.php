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
use App\Templates\TemplateFiller;
use App\Templates\TemplateService;
use App\Templates\ValueDoesNotFit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/** Modelos em PDF com lacunas: consulta, remoção e geração de documentos. Criar e editar modelos é só pela tela (exige o PDF e as posições). */
final class TemplateController extends Controller
{
    public function __construct(
        private readonly EnvelopeService $envelopes,
        private readonly SignerNotifier $notifier,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $templates = DocumentTemplate::query()->where('tenant_id', $this->tenant($request)->getKey())->with('fields')->latest()->get();

        return response()->json(['data' => $templates->map(fn ($t) => Presenter::template($t))->all()]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(Presenter::template($this->find($request, $id)));
    }

    /** Criar e editar modelos saiu da API: depende do PDF e das posições das lacunas, marcadas na tela Modelos. */
    public function unavailable(): JsonResponse
    {
        return response()->json([
            'error' => 'templates_ui_only',
            'message' => 'Modelos são criados e editados na tela Modelos do sistema. Pela API é possível consultar, remover e gerar documentos a partir deles.',
        ], 410);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        app(TemplateService::class)->delete($this->find($request, $id));

        return response()->json(null, 204);
    }

    /** Gera um documento a partir do modelo. Corpo: title, variables{chave da lacuna => texto}, signers[] e as mesmas opções de POST /envelopes. */
    public function generate(Request $request, string $id): JsonResponse
    {
        $template = $this->find($request, $id);
        $filler = app(TemplateFiller::class);
        $request->merge(['variables' => TemplateFiller::normalize($request->input('variables'))]);

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
        ] + $filler->rules($template, 'variables');
        $data = $request->validate($rules);

        try {
            $pdf = $filler->render($template, $data['variables'] ?? []);
        } catch (ValueDoesNotFit $e) {
            return response()->json(['error' => 'does_not_fit', 'field' => $e->fieldKey, 'message' => $e->getMessage()], 422);
        } catch (FlowException $e) {
            return response()->json(['error' => $e->reason, 'message' => $e->getMessage()], 422);
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
                    'template_id' => $template->getKey(),
                    'external_id' => $data['external_id'] ?? null,
                    'metadata' => $data['metadata'] ?? null,
                ], fn ($v) => $v !== null) + ($reminder === null ? [] : ['reminder_every_days' => $reminder === 0 ? null : $reminder]),
            );

            if ($request->boolean('send')) {
                $this->envelopes->send($envelope, RequestContext::fromRequest($request));
                $this->notifier->inviteActive($envelope->fresh());
            }
        } catch (FlowException $e) {
            if (\App\Plans\Decision::isPlanReason($e->reason)) {
                return response()->json(['error' => 'plan_limit_reached', 'reason' => $e->reason, 'message' => $e->getMessage()], 403);
            }

            return response()->json(['error' => $e->reason, 'message' => $e->getMessage()], 422);
        }

        return response()->json(Presenter::envelope($envelope->fresh()), 201);
    }

    private function find(Request $request, string $id): DocumentTemplate
    {
        abort_unless(Str::isUuid($id), 404);

        return DocumentTemplate::query()->where('tenant_id', $this->tenant($request)->getKey())->with('fields')->findOrFail($id);
    }

    private function tenant(Request $request): Tenant
    {
        return $request->attributes->get('tenant');
    }
}
