<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Api\Presenter;
use App\Http\Controllers\Controller;
use App\Models\Folder;
use App\Models\Tag;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Pastas e etiquetas. */
final class OrganizationController extends Controller
{
    public function folders(Request $request): JsonResponse
    {
        $items = Folder::query()->where('tenant_id', $this->tenant($request)->getKey())->orderBy('name')->get();

        return response()->json(['data' => $items->map(fn ($f) => Presenter::folder($f))->all()]);
    }

    public function storeFolder(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:120', Rule::unique('folders')->where('tenant_id', $tenant->getKey())]]);

        return response()->json(Presenter::folder(Folder::query()->create(['tenant_id' => $tenant->getKey(), 'name' => trim($data['name'])])), 201);
    }

    public function destroyFolder(Request $request, string $id): JsonResponse
    {
        abort_unless(Str::isUuid($id), 404);
        Folder::query()->where('tenant_id', $this->tenant($request)->getKey())->findOrFail($id)->delete();

        return response()->json(null, 204);
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

    public function destroyTag(Request $request, string $id): JsonResponse
    {
        abort_unless(Str::isUuid($id), 404);
        Tag::query()->where('tenant_id', $this->tenant($request)->getKey())->findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    private function tenant(Request $request): Tenant
    {
        return $request->attributes->get('tenant');
    }
}
