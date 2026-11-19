<?php

declare(strict_types=1);

namespace App\Themes;

use App\Models\Setting;
use App\Models\User;

/**
 * Menu do sistema como dados (contrato v1). Cada item: label, icon (chave semântica que o tema traduz
 * para o ícone dele), url, active, children (mesmo formato, 1 nível) e group (rótulo de seção, opcional).
 */
final class MenuBuilder
{
    /** @return list<array{label:string,icon:string,url:string,active:bool,external:bool,group:?string,children:list<array{label:string,url:string,active:bool}>}> */
    public function for(User $user): array
    {
        $is = fn (string ...$patterns): bool => request()->routeIs(...$patterns);
        $item = fn (string $label, string $icon, string $url, bool $active, array $children = [], ?string $group = null, bool $external = false): array => [
            'label' => $label, 'icon' => $icon, 'url' => $url, 'active' => $active, 'external' => $external, 'group' => $group, 'children' => $children,
        ];
        $child = fn (string $label, string $route, string ...$patterns): array => ['label' => $label, 'url' => route($route), 'active' => $is(...($patterns ?: [$route]))];

        $docs = [
            $child('Documentos criados', 'envelopes.index', 'envelopes.index', 'envelopes.show', 'envelopes.create'),
            $child('Pastas e etiquetas', 'documents.folders'),
            $child('Minhas assinaturas', 'documents.mine'),
            $child('Lixeira', 'documents.trash'),
        ];
        $cfg = [
            $child('Meu perfil', 'profile.edit', 'profile.*'),
            $child('Assinatura da empresa', 'company.signing.edit', 'company.*'),
            $child('Usuários', 'team.index', 'team.*'),
        ];
        if ($user->role === 'owner') {
            $cfg[] = $child('Plano e uso', 'plan-usage.index', 'plan-usage.*');
            $cfg[] = $child('Marca própria', 'brand.edit', 'brand.*');
            $cfg[] = $child('API', 'api-keys.index', 'api-keys.*');
            $cfg[] = $child('Webhooks', 'webhooks.index', 'webhooks.*');
        }

        $menu = [
            $item('Painel', 'home', route('dashboard'), $is('dashboard')),
            $item('Documentos', 'folder', route('envelopes.index'), $is('envelopes.*', 'documents.*'), $docs),
            $item('Modelos', 'templates', route('templates.index'), $is('templates.*')),
            $item('Contatos', 'contacts', route('contacts.index'), $is('contacts.*')),
            $item('Calendário', 'calendar', route('calendar.index'), $is('calendar.*')),
            $item('Contratos', 'templates', route('contracts.index'), $is('contracts.*')),
            $item('Relatórios', 'reports', route('reports.index'), $is('reports.*')),
            ...($user->role === 'owner' && \App\Plans\Entitlements::for($user->tenant)->feature('assistant') ? [$item('Assistente', 'assistant', route('assistant.index'), $is('assistant.*'))] : []),
            $item('Configurações', 'settings', '#', $is('profile.*', 'company.*', 'api-keys.*', 'webhooks.*', 'team.*', 'brand.*', 'plan-usage.*'), $cfg),
            $item('Verificar documento', 'verify', route('verify.index'), false, [], null, true),
        ];
        if ($help = Setting::helpUrl()) {
            $menu[] = $item('Central de ajuda', 'help', $help, false, [], null, true);
        }

        if ($user->isPlatformAdmin()) {
            $menu[] = $item('Visão geral', 'platform', route('admin.dashboard'), $is('admin.dashboard'), [], 'Plataforma');
            $menu[] = $item('Clientes', 'tenants', route('admin.tenants.index'), $is('admin.tenants.*'));
            $menu[] = $item('Assinaturas', 'subscriptions', route('admin.subscriptions.index'), $is('admin.subscriptions.*'));
            $menu[] = $item('Saúde do sistema', 'health', route('admin.health'), $is('admin.health*'));
            $menu[] = $item('Selagem do PDF', 'sealing', route('admin.sealing'), $is('admin.sealing*'));
            if ($user->isSuperAdmin()) {
                $menu[] = $item('Planos', 'plans', route('admin.plans.index'), $is('admin.plans.*'));
                $menu[] = $item('Usuários da plataforma', 'platform-users', route('admin.users.index'), $is('admin.users.*'));
                $menu[] = $item('Temas', 'themes', route('admin.themes.index'), $is('admin.themes.*'));
                $menu[] = $item('E-mail (SMTP)', 'mail', route('admin.mail'), $is('admin.mail*'));
                $menu[] = $item('Assistente WhatsApp', 'assistant', route('admin.assistant'), $is('admin.assistant*'));
                $menu[] = $item('Configurações', 'platform-settings', route('admin.settings'), $is('admin.settings*'));
            }
        }

        return $menu;
    }
}
