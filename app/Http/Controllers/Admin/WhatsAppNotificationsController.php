<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Assistant\AssistantSettings;
use App\Assistant\Channel\MetaException;
use App\Assistant\Notifications\NotificationCatalog;
use App\Assistant\Notifications\TemplateStore;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Tela do super administrador: o modelo de mensagem de cada aviso do WhatsApp, o envio para a Meta aprovar e o status. */
final class WhatsAppNotificationsController extends Controller
{
    public function edit(): View
    {
        return view('admin.notifications', [
            'catalog' => NotificationCatalog::all(),
            'templates' => app(TemplateStore::class)->all(),
            'hasWaba' => app(AssistantSettings::class)->wabaId() !== '',
            'stalledDays' => app(AssistantSettings::class)->stalledDays(),
                    ]);
    }

    /** Dias sem movimentação para o documento contar como parado. */
    public function settings(Request $request): RedirectResponse
    {
        $d = $request->validate(['stalled_days' => ['required', 'integer', 'between:1,90']], ['stalled_days.between' => 'Use de 1 a 90 dias.']);
        \App\Models\Setting::put('assistant.stalled_days', (string) $d['stalled_days']);

        return redirect()->route('admin.notifications')->with('status', 'Configuração salva.');
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        abort_unless(NotificationCatalog::exists($key), 404);
        $d = $request->validate([
            'meta_name' => ['required', 'regex:/^[a-z0-9_]{1,100}$/'],
            'language' => ['required', 'regex:/^[a-z]{2}(_[A-Z]{2})?$/'],
            'body' => ['required', 'string', 'max:'.NotificationCatalog::MAX_BODY],
        ], [
            'meta_name.regex' => 'O nome do modelo usa só letras minúsculas, números e _ (sem espaço).',
            'language.regex' => 'Use o código do idioma, como pt_BR.',
        ]);
        if (($problem = NotificationCatalog::validateBody($key, $d['body'])) !== null) {
            throw ValidationException::withMessages(['body' => $problem]);
        }
        if (\App\Models\WhatsAppTemplate::query()->where('meta_name', $d['meta_name'])->where('language', $d['language'])->where('key', '!=', $key)->exists()) {
            throw ValidationException::withMessages(['meta_name' => 'Outra situação já usa este nome de modelo neste idioma.']);
        }

        app(TemplateStore::class)->update($key, $d + ['enabled' => $request->boolean('enabled')]);

        return redirect()->route('admin.notifications')->with('status', 'Modelo salvo.');
    }

    public function submit(string $key): RedirectResponse
    {
        abort_unless(NotificationCatalog::exists($key), 404);
        $store = app(TemplateStore::class);

        try {
            $store->submit($store->find($key));
        } catch (MetaException $e) {
            return redirect()->route('admin.notifications')->with('error', $e->getMessage());
        }

        return redirect()->route('admin.notifications')->with('status', 'Modelo enviado para aprovação da Meta. Use "Atualizar status" para acompanhar.');
    }

    public function sync(): RedirectResponse
    {
        try {
            $n = app(TemplateStore::class)->sync();
        } catch (MetaException $e) {
            return redirect()->route('admin.notifications')->with('error', $e->getMessage());
        }

        return redirect()->route('admin.notifications')->with('status', $n === 0 ? 'Nenhuma mudança de status.' : "{$n} modelo(s) atualizado(s).");
    }
}
