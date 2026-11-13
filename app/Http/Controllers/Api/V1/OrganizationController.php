<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Api\Presenter;
use App\Envelopes\Exceptions\FlowException;
use App\Folders\FolderService;
use App\Http\Controllers\Controller;
use App\Models\Folder;
use App\Models\Tag;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Pastas e etiquetas. */
final class OrganizationController extends Controller
{
    public function folders(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        $paths = app(FolderService::class)->paths($tenant);
        $items = Folder::query()->where('tenant_id', $tenant->getKey())->get()->sortBy(fn (Folder $f) => $paths[$f->getKey()] ?? $f->name, SORT_NATURAL | SORT_FLAG_CASE)->values();

        return response()->json(['data' => $items->map(fn ($f) => Presenter::folder($f, $paths))->all()]);
    }

    public function storeFolder(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:200'], 'parent_id' => ['nullable', 'uuid']]);
        $parent = $this->folderOrNotFound($tenant, $data['parent_id'] ?? null);

        try {
            $folder = app(FolderService::class)->create($tenant, $data['name'], $parent);
        } catch (FlowException $e) {
            return $this->folderError($e);
        }

        return response()->json(Presenter::folder($folder, app(FolderService::class)->paths($tenant)), 201);
    }

    /** Renomeia e/ou move (parent_id nulo = raiz). */
    public function updateFolder(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenant($request);
        $folder = $this->folderOrNotFound($tenant, $id, required: true);
        $data = $request->validate(['name' => ['sometimes', 'required', 'string', 'max:200'], 'parent_id' => ['sometimes', 'nullable', 'uuid']]);
        $svc = app(FolderService::class);

        try {
            if (array_key_exists('name', $data)) {
                $svc->rename($folder, $data['name']);
            }
            if (array_key_exists('parent_id', $data)) {
                $svc->move($folder->fresh(), $this->folderOrNotFound($tenant, $data['parent_id']));
            }
        } catch (FlowException $e) {
            return $this->folderError($e);
        }

        return response()->json(Presenter::folder($folder->fresh(), $svc->paths($tenant)));
    }

    /** Apaga a pasta e as subpastas: o que pode vai para a lixeira, o resto sobe para a pasta pai. */
    public function destroyFolder(Request $request, string $id): JsonResponse
    {
        $folder = $this->folderOrNotFound($this->tenant($request), $id, required: true);

        return response()->json(app(FolderService::class)->delete($folder));
    }
    public function tags(Request $request): JsonResponse
    {
        $items = Tag::query()->where('tenant_id', $this->tenant($request)->getKey())->orderBy('name')->get();

        return response()->json(['data' => $items->map(fn ($t) => Presenter::tag($t))->all()]);
    }

    public function storeTag(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('tags')->where('tenant_id', $tenant->getKey())],
            'color' => ['nullable', Rule::in(Tag::COLORS)],
        ]);

        $tag = Tag::query()->create(['tenant_id' => $tenant->getKey(), 'name' => trim($data['name']), 'color' => $data['color'] ?? 'primary']);

        return response()->json(Presenter::tag($tag), 201);
    }

    public function updateTag(Request $request, string $id): JsonResponse
    {
        abort_unless(Str::isUuid($id), 404);
        $tenant = $this->tenant($request);
        $tag = Tag::query()->where('tenant_id', $tenant->getKey())->findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:60', Rule::unique('tags')->where('tenant_id', $tenant->getKey())->ignore($tag->getKey())],
            'color' => ['sometimes', 'required', Rule::in(Tag::COLORS)],
        ]);

        $tag->update(array_filter(['name' => isset($data['name']) ? trim($data['name']) : null, 'color' => $data['color'] ?? null]));

        return response()->json(Presenter::tag($tag->fresh()));
    }

    public function destroyTag(Request $request, string $id): JsonResponse
    {
        abort_unless(Str::isUuid($id), 404);
        Tag::query()->where('tenant_id', $this->tenant($request)->getKey())->findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    /** null/vazio = raiz (ou nenhum); id inexistente ou de outra empresa = 404. */
    private function folderOrNotFound(Tenant $tenant, ?string $id, bool $required = false): ?Folder
    {
        if ($id === null || $id === '') {
            abort_if($required, 404);

            return null;
        }
        abort_unless(Str::isUuid($id), 404);

        return Folder::query()->where('tenant_id', $tenant->getKey())->findOrFail($id);
    }

    /** Nome inválido/repetido vira erro de validação (como sempre); o resto usa o código do serviço. */
    private function folderError(FlowException $e): JsonResponse
    {
        if (in_array($e->reason, ['invalid_name', 'folder_name_taken'], true)) {
            throw ValidationException::withMessages(['name' => [$e->getMessage()]]);
        }

        return response()->json(['error' => $e->reason, 'message' => $e->getMessage()], 422);
    }

    private function tenant(Request $request): Tenant
    {
        return $request->attributes->get('tenant');
    }
}
