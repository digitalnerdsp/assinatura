<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Envelopes\Exceptions\FlowException;
use App\Folders\FolderService;
use App\Http\Controllers\Controller;
use App\Models\Folder;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Ações de pastas e etiquetas (a tela vive em Documentos criados). Os endereços /organizacao/* ficam por
 * compatibilidade; a regra de negócio das pastas está em FolderService.
 */
final class OrganizationController extends Controller
{
    public function __construct(private readonly FolderService $folders)
    {
    }

    public function storeFolder(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:200'], 'parent_id' => ['nullable', 'uuid']]);
        $parent = $this->parentOrFail($request, $data['parent_id'] ?? null);
        if ($parent === false) {
            return back()->withInput()->withErrors(['parent_id' => 'Pasta inválida.']);
        }

        try {
            $this->folders->create($request->user()->tenant, $data['name'], $parent);
        } catch (FlowException $e) {
            return $this->fail($e);
        }

        return back()->with('status', 'Pasta criada.');
    }

    public function updateFolder(Request $request, Folder $folder): RedirectResponse
    {
        $this->owned($request, $folder);
        $data = $request->validate(['name' => ['required', 'string', 'max:200']]);

        try {
            $this->folders->rename($folder, $data['name']);
        } catch (FlowException $e) {
            return $this->fail($e);
        }

        return back()->with('status', 'Pasta renomeada.');
    }

    public function moveFolder(Request $request, Folder $folder): RedirectResponse
    {
        $this->owned($request, $folder);
        $data = $request->validate(['parent_id' => ['nullable', 'uuid']]);
        $parent = $this->parentOrFail($request, $data['parent_id'] ?? null);
        if ($parent === false) {
            return back()->withErrors(['parent_id' => 'Pasta de destino inválida.']);
        }

        try {
            $this->folders->move($folder, $parent);
        } catch (FlowException $e) {
            return $this->fail($e);
        }

        return back()->with('status', 'Pasta movida.');
    }

    /** Números mostrados no popup de apagar pasta. */
    public function impactFolder(Request $request, Folder $folder): JsonResponse
    {
        $this->owned($request, $folder);

        return response()->json($this->folders->impact($folder));
    }

    public function destroyFolder(Request $request, Folder $folder): RedirectResponse
    {
        $this->owned($request, $folder);
        $destination = $folder->parent_id === null ? 'a lista de documentos' : '"'.Folder::query()->whereKey($folder->parent_id)->value('name').'"';

        $result = $this->folders->delete($folder);

        return back()->with('status', sprintf(
            'Pasta apagada. %d documento(s) foram para a lixeira e %d continuam em %s%s.',
            $result['trashed'], $result['kept'], $destination,
            $result['folders'] > 0 ? ' ('.$result['folders'].' subpasta(s) também apagada(s))' : '',
        ));
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

    private function owned(Request $request, Folder $folder): void
    {
        abort_unless($folder->tenant_id === $request->user()->tenant_id, 404);
    }

    /** null = raiz; false = pasta inexistente ou de outra empresa. */
    private function parentOrFail(Request $request, ?string $id): Folder|false|null
    {
        if ($id === null || $id === '') {
            return null;
        }

        return Folder::query()->where('tenant_id', $request->user()->tenant_id)->find($id) ?? false;
    }

    private function fail(FlowException $e): RedirectResponse
    {
        $field = in_array($e->reason, ['invalid_name', 'folder_name_taken'], true) ? 'name' : 'parent_id';

        return back()->withInput()->withErrors([$field => $e->getMessage()]);
    }
}
