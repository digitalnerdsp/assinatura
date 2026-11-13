<?php

declare(strict_types=1);

namespace App\Platform;

use App\Envelopes\Exceptions\FlowException;
use App\Models\Tenant;
use App\Models\User;
use App\Team\TeamService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Equipe da plataforma: administradores (operam clientes, saúde e selagem) e super administradores
 * (tudo, inclusive esta equipe, planos, e-mail e configurações). Só super admin concede ou retira papéis.
 * Os "raiz" (SUPER_ADMIN_EMAILS) e a própria pessoa nunca são alterados por aqui.
 */
final class PlatformUserService
{
    public const ROLES = ['admin', 'super_admin'];

    /** Define ou muda o papel de uma conta que já existe (promover). */
    public function grant(User $actor, User $target, string $role): void
    {
        $this->guard($actor, $target, $role);
        $target->forceFill(['platform_role' => $role, 'platform_granted_by' => $actor->getKey(), 'platform_granted_at' => now()])->save();
    }

    /** Muda o papel de quem já é da equipe. */
    public function change(User $actor, User $target, string $role): void
    {
        $this->guard($actor, $target, $role);
        if ($target->platform_role === null) {
            throw FlowException::because('not_team', 'Esta pessoa não faz parte da equipe da plataforma.');
        }
        $target->forceFill(['platform_role' => $role, 'platform_granted_by' => $actor->getKey(), 'platform_granted_at' => now()])->save();
    }

    /** Tira o papel; a conta e a empresa da pessoa continuam. */
    public function revoke(User $actor, User $target): void
    {
        $this->guard($actor, $target, null);
        if ($target->platform_role === null) {
            throw FlowException::because('not_team', 'Esta pessoa não faz parte da equipe da plataforma.');
        }
        $target->forceFill(['platform_role' => null, 'platform_granted_by' => null, 'platform_granted_at' => null])->save();
    }

    /**
     * Convida alguém novo: cria a conta (com empresa própria, como num cadastro) já com o papel;
     * o papel só vale depois de aceitar o convite e definir a senha.
     *
     * @return array{0:User,1:string} a conta e o token em texto puro (só para o e-mail)
     */
    public function invite(User $actor, string $name, string $email, string $role): array
    {
        $this->assertSuper($actor);
        $this->assertRole($role);
        $email = mb_strtolower(trim($email));

        return DB::transaction(function () use ($actor, $name, $email, $role) {
            app(TeamService::class)->purgeExpiredInvite($email);
            if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
                throw FlowException::because('email_taken', 'Este e-mail já tem uma conta. Use "Promover conta existente".');
            }
            $tenant = Tenant::query()->create(['name' => trim($name)]);
            $token = Str::random(48);
            $user = User::query()->forceCreate([
                'tenant_id' => $tenant->getKey(),
                'name' => trim($name),
                'email' => $email,
                'password' => Str::random(64), // inutilizável até aceitar o convite
                'role' => 'owner',
                'platform_role' => $role,
                'platform_granted_by' => $actor->getKey(),
                'platform_granted_at' => now(),
                'invite_token_hash' => hash('sha256', $token),
                'invite_expires_at' => now()->addDays(TeamService::INVITE_DAYS),
                'invited_by' => $actor->getKey(),
            ]);

            return [$user, $token];
        });
    }

    /** Novo link para um convite pendente da equipe; o anterior deixa de valer. */
    public function resend(User $actor, User $target): string
    {
        $this->guard($actor, $target, null);
        if ($target->platform_role === null) {
            throw FlowException::because('not_team', 'Esta pessoa não faz parte da equipe da plataforma.');
        }
        if (! $target->isPendingInvite()) {
            throw FlowException::because('not_pending', 'Esta pessoa já aceitou o convite.');
        }
        $token = Str::random(48);
        $target->forceFill(['invite_token_hash' => hash('sha256', $token), 'invite_expires_at' => now()->addDays(TeamService::INVITE_DAYS)])->save();

        return $token;
    }

    private function guard(User $actor, User $target, ?string $role): void
    {
        $this->assertSuper($actor);
        if ($role !== null) {
            $this->assertRole($role);
        }
        if ($target->is($actor)) {
            throw FlowException::because('cannot_change_self', 'Você não pode alterar o seu próprio papel.');
        }
        if ($target->isRootAdmin()) {
            throw FlowException::because('cannot_change_root', 'Este acesso é fixo (definido no servidor) e não pode ser alterado aqui.');
        }
    }

    private function assertSuper(User $actor): void
    {
        if (! $actor->isSuperAdmin()) {
            throw FlowException::because('not_super_admin', 'Só super administradores gerenciam a equipe da plataforma.');
        }
    }

    private function assertRole(string $role): void
    {
        if (! in_array($role, self::ROLES, true)) {
            throw FlowException::because('invalid_role', 'Papel inválido.');
        }
    }
}
