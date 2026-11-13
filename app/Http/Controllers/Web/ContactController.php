<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Contacts\ContactCsv;
use App\Contacts\ContactService;
use App\Envelopes\Exceptions\FlowException;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Agenda de contatos da empresa. */
final class ContactController extends Controller
{
    public function __construct(private readonly ContactService $contacts)
    {
    }

    public function index(Request $request): View
    {
        $tenantId = $request->user()->tenant_id;
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'tag' => ['nullable', 'uuid']]);

        $items = Contact::query()->where('tenant_id', $tenantId)->with('tags')
            ->when($filters['q'] ?? null, function ($query, string $q) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($q)).'%';
                $query->where(fn ($w) => $w->whereRaw('lower(name) LIKE ?', [$like])->orWhereRaw('lower(email) LIKE ?', [$like])
                    ->orWhereRaw('lower(company) LIKE ?', [$like])->orWhere('phone', 'like', $like));
            })
            ->when($filters['tag'] ?? null, fn ($query, string $tag) => $query->whereHas('tags', fn ($t) => $t->where('tags.id', $tag)))
            ->orderByRaw('lower(name)')->paginate(25)->withQueryString();

        return view('contacts.index', [
            'contacts' => $items,
            'filters' => $filters,
            'tags' => Tag::query()->where('tenant_id', $tenantId)->orderBy('name')->get(),
        ]);
    }

    /** Sugestões para o formulário de criar documento (no máximo 8, só da empresa). */
    public function suggest(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        return response()->json($this->contacts->search($request->user()->tenant, mb_substr($q, 0, 100))
            ->map(fn (Contact $c) => ['name' => $c->name, 'email' => $c->email, 'phone' => $c->phone])->values());
    }

    public function import(Request $request, ContactCsv $csv): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:2048', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel']], [
            'file.mimetypes' => 'Envie um arquivo CSV (separado por ; ou ,).',
        ]);

        try {
            $result = $csv->import($request->user()->tenant, $request->file('file')->getRealPath());
        } catch (FlowException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return back()
            ->with('status', "Importação concluída: {$result['created']} criado(s), {$result['updated']} atualizado(s), {$result['skipped']} ignorado(s).")
            ->with('import_errors', array_slice($result['errors'], 0, 20));
    }

    public function export(Request $request, ContactCsv $csv): StreamedResponse
    {
        $content = $csv->export($request->user()->tenant);

        return response()->streamDownload(fn () => print($content), 'contatos.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->persist($request, null);
    }

    public function update(Request $request, Contact $contact): RedirectResponse
    {
        abort_unless($contact->tenant_id === $request->user()->tenant_id, 404);

        return $this->persist($request, $contact);
    }

    public function destroy(Request $request, Contact $contact): RedirectResponse
    {
        abort_unless($contact->tenant_id === $request->user()->tenant_id, 404);
        $this->contacts->delete($contact);

        return back()->with('status', 'Contato apagado.');
    }

    private function persist(Request $request, ?Contact $contact): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30'],
            'document' => ['nullable', 'string', 'max:18'],
            'company' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['uuid'],
        ]);

        $tenant = $request->user()->tenant;
        $wanted = array_values(array_unique($data['tags'] ?? []));
        if (Tag::query()->where('tenant_id', $tenant->getKey())->whereIn('id', $wanted)->count() !== count($wanted)) {
            return back()->withInput()->withErrors(['tags' => 'Etiqueta inválida.']);
        }
        $data['tags'] = $wanted;

        try {
            $this->contacts->save($tenant, $data, $contact);
        } catch (FlowException $e) {
            $field = ['contact_email_taken' => 'email', 'invalid_phone' => 'phone', 'invalid_document' => 'document'][$e->reason] ?? 'name';

            return back()->withInput()->withErrors([$field => $e->getMessage()]);
        }

        return back()->with('status', 'Contato salvo.');
    }
}
