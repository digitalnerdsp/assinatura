<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Api\Presenter;
use App\Contacts\ContactService;
use App\Envelopes\Exceptions\FlowException;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Contatos da empresa pela API. */
final class ContactController extends Controller
{
    public function __construct(private readonly ContactService $contacts)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'between:1,100']]);
        $tenant = $this->tenant($request);
        $like = isset($f['q']) ? '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($f['q'])).'%' : null;

        $page = Contact::query()->where('tenant_id', $tenant->getKey())
            ->when($like, fn ($q) => $q->where(fn ($w) => $w->whereRaw('lower(name) LIKE ?', [$like])->orWhereRaw('lower(email) LIKE ?', [$like])->orWhereRaw('lower(company) LIKE ?', [$like])))
            ->orderByRaw('lower(name)')->paginate((int) ($f['per_page'] ?? 20));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Contact $c) => Presenter::contact($c))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(true));

        try {
            $contact = $this->contacts->save($this->tenant($request), $data);
        } catch (FlowException $e) {
            return response()->json(['error' => $e->reason, 'message' => $e->getMessage()], 422);
        }

        return response()->json(Presenter::contact($contact), 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(Presenter::contact($this->find($request, $id)));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $contact = $this->find($request, $id);
        $data = $request->validate($this->rules(false));
        $merged = $data + $contact->only(['name', 'email', 'phone', 'document', 'company', 'notes']);

        try {
            $contact = $this->contacts->save($this->tenant($request), $merged, $contact);
        } catch (FlowException $e) {
            return response()->json(['error' => $e->reason, 'message' => $e->getMessage()], 422);
        }

        return response()->json(Presenter::contact($contact->fresh()));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->contacts->delete($this->find($request, $id));

        return response()->json(null, 204);
    }

    /** @return array<string,list<string>> */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'max:150'],
            'email' => [$req, 'email', 'max:190'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'document' => ['sometimes', 'nullable', 'string', 'max:18'],
            'company' => ['sometimes', 'nullable', 'string', 'max:150'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    private function find(Request $request, string $id): Contact
    {
        abort_unless(Str::isUuid($id), 404);

        return Contact::query()->where('tenant_id', $this->tenant($request)->getKey())->findOrFail($id);
    }

    private function tenant(Request $request): Tenant
    {
        return $request->attributes->get('tenant');
    }
}
