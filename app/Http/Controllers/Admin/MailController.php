<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\TestMail;
use App\Support\MailSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

final class MailController extends Controller
{
    public function edit(): View
    {
        $saved = MailSettings::load();

        return view('admin.mail', [
            'c' => $saved ?? [
                'host' => '', 'port' => 587, 'username' => '', 'encryption' => 'tls',
                'from_address' => (string) config('mail.from.address'), 'from_name' => (string) config('mail.from.name'),
            ],
            'hasPassword' => ($saved['password'] ?? '') !== '',
            'source' => $saved ? 'painel' : 'variáveis de ambiente (MAIL_MAILER='.config('mail.default').')',
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:190'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:190'],
            'password' => ['nullable', 'string', 'max:500'],
            'encryption' => ['required', Rule::in(['tls', 'ssl', 'none'])],
            'from_address' => ['required', 'email', 'max:190'],
            'from_name' => ['required', 'string', 'max:100'],
        ]);

        MailSettings::save($data);

        return back()->with('status', 'Configuração de e-mail salva. Envie um e-mail de teste para confirmar.');
    }

    public function test(Request $request): RedirectResponse
    {
        $data = $request->validate(['to' => ['required', 'email']]);

        try {
            Mail::to($data['to'])->send(new TestMail());
        } catch (Throwable $e) {
            report($e);

            // Mostra o motivo ao admin (host, porta, autenticação...), já que o objetivo é diagnosticar.
            return back()->withInput()->withErrors(['test' => 'Falha ao enviar: '.mb_substr($e->getMessage(), 0, 500)]);
        }

        $driver = config('mail.default');

        return back()->with('status', $driver === 'log'
            ? 'Mailer "log": nada foi entregue de verdade, a mensagem só foi gravada no log. Salve uma configuração SMTP acima.'
            : "E-mail de teste enviado para {$data['to']}. Confira a caixa de entrada (e o spam).");
    }

    public function reset(): RedirectResponse
    {
        MailSettings::clear();

        return back()->with('status', 'Configuração do painel removida. Voltou a valer o que estiver nas variáveis de ambiente (reinicie o app para aplicar).');
    }
}
