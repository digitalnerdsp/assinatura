<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class ApiKeyController extends Controller
{
    public function index(Request $request): View
    {
        return view('api-keys.index', [
            'keys' => ApiKey::query()->where('tenant_id', $request->user()->tenant_id)->latest()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'read_only' => ['nullable', 'boolean']]);

        [, $plain] = ApiKey::issue($request->user()->tenant, $data['name'], $request->boolean('read_only'));

        // A chave só é exibida agora; depois fica apenas o hash.
        return redirect()->route('api-keys.index')->with('new_key', $plain);
    }

    public function destroy(Request $request, ApiKey $apiKey): RedirectResponse
    {
        abort_unless($apiKey->tenant_id === $request->user()->tenant_id, 404);
        $apiKey->update(['revoked_at' => now()]);

        return back()->with('status', 'Chave revogada.');
    }
}
