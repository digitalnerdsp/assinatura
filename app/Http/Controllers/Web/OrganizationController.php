<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Folder;
use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Pastas e etiquetas do cliente para organizar os envelopes. */
final class OrganizationController extends Controller
{
    public function index(Request $request): View
    {
        $tenantId = $request->user()->tenant_id;

        return view('organization.index', [
            'folders' => Folder::query()->where('tenant_id', $tenantId)->withCount('envelopes')->orderBy('name')->get(),
            'tags' => Tag::query()->where('tenant_id', $tenantId)->withCount('envelopes')->orderBy('name')->get(),
            'colors' => Tag::COLORS,
        ]);
    }

    public function storeFolder(Request $request): RedirectResponse
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('folders')->where('tenant_id', $tenantId)],
        ], ['name.unique' => 'Já existe uma pasta com este nome.']);

        Folder::query()->create(['tenant_id' => $tenantId, 'name' => trim($data['name'])]);

        return back()->with('status', 'Pasta criada.');
    }

    public function destroyFolder(Request $request, Folder $folder): RedirectResponse
    {
        abort_unless($folder->tenant_id === $request->user()->tenant_id, 404);
        $folder->delete(); // os envelopes ficam sem pasta (nullOnDelete)

        return back()->with('status', 'Pasta removida. Os envelopes dela continuam disponíveis, sem pasta.');
    }

    public function storeTag(Request $request): RedirectResponse
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('tags')->where('tenant_id', $tenantId)],
            'color' => ['required', Rule::in(Tag::COLORS)],
        ], ['name.unique' => 'Já existe uma etiqueta com este nome.']);

        Tag::query()->create(['tenant_id' => $tenantId, 'name' => trim($data['name']), 'color' => $data['color']]);

        return back()->with('status', 'Etiqueta criada.');
    }

    public function destroyTag(Request $request, Tag $tag): RedirectResponse
    {
        abort_unless($tag->tenant_id === $request->user()->tenant_id, 404);
        $tag->delete();

        return back()->with('status', 'Etiqueta removida.');
    }
}
