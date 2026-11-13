<?php

declare(strict_types=1);

namespace App\Team;

use App\Envelopes\Exceptions\FlowException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Usuários da empresa: convite por e-mail (link de uso único, 7 dias), desativar, reativar e remover.
 * Só o dono age; nunca sobre si mesmo, outro dono ou usuário de outra empresa.
 */
final class TeamService
{
    public const INVITE_DAYS = 7;

    /** @return array{0:User,1:string} o usuário convidado e o token em texto puro (só para o e-mail) */
    public function invite(User $owner, string $name, string $email): array
    {
        $this->assertOwner($owner);
        $email = mb_strtolower(trim($email));

        return DB::transaction(function () use ($owner, $name, $email) {
            $this->purgeExpiredInvite($email);
            if (User::query()->whereRaw('lower(email) = ?', [$email])->lockForUpdate()->exists()) {
                throw FlowException::because('email_taken', 'Este e-mail já tem uma conta.');
            }
            $token = Str::random(48);
            $member = User::query()->forceCreate([
                'tenant_id' => $owner->tenant_id,
                'name' => trim($name),
                'email' => $email,
                'password' => Str::random(64), // inutilizável até aceitar o convite
                'role' => 'member',
                'invite_token_hash' => hash('sha256', $token),
                'invite_expires_at' => now()->addDays(self::INVITE_DAYS),
                'invited_by' => $owner->getKey(),
            ]);

            return [$member, $token];
        });
    }

    /** Novo link para um convite pendente; o anterior deixa de valer. */
    public function resend(User $owner, User $member): string
    {
        $this->guard($owner, $member);
        if (! $member->isPendingInvite()) {
            throw FlowException::because('not_pending', 'Este usuário já aceitou o convite.');
        }
        $token = Str::random(48);
        $member->forceFill(['invite_token_hash' => hash('sha256', $token), 'invite_expires_at' => now()->addDays(self::INVITE_DAYS)])->save();

        return $token;
    }

    /** Convite válido (existe, não expirou, não foi usado), ou null. */
    public function findInvite(string $token): ?User
    {
        if ($token === '' || strlen($token) > 128) {
            return null;
        }

        return User::query()->where('invite_token_hash', hash('sha256', $token))
            ->where('invite_expires_at', '>', now())->whereNull('deactivated_at')->first();
    }

    public function accept(string $token, string $password): User
    {
        return DB::transaction(function () use ($token, $password) {
            $user = $this->findInvite($token);
            $user = $user ? User::query()->whereKey($user->getKey())->lockForUpdate()->first() : null;
            if ($user === null || $user->invite_token_hash !== hash('sha256', $token)) {
                throw FlowException::because('invite_invalid', 'Este convite não vale mais. Peça um novo ao responsável da empresa.');
            }
            $user->forceFill([
                'password' => $password,
                'invite_token_hash' => null,
                'invite_expires_at' => null,
                'email_verified_at' => now(),
            ])->save();

            return $user;
        });
    }

    public function deactivate(User $owner, User $member): void
    {
        $this->guard($owner, $member);
        $member->forceFill(['deactivated_at' => now(), 'remember_token' => Str::random(60)])->save();
    }

    public function reactivate(User $owner, User $member): void
    {
        $this->guard($owner, $member);
        $member->forceFill(['deactivated_at' => null])->save();
    }

    /** Apaga o usuário; os documentos que ele criou ficam (sem autor). */
    public function remove(User $owner, User $member): void
    {
        $this->guard($owner, $member);
        $member->delete();
    }

    /** Convite vencido e nunca aceito não prende o e-mail: o usuário provisório é apagado. */
    public function purgeExpiredInvite(string $email): void
    {
        User::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])
            ->whereNotNull('invite_token_hash')->where('invite_expires_at', '<=', now())
            ->delete();
    }

    private function assertOwner(User $owner): void
    {
        if ($owner->role !== 'owner') {
            throw FlowException::because('not_owner', 'Só o responsável da empresa pode gerenciar usuários.');
        }
    }

    private function guard(User $owner, User $member): void
    {
        $this->assertOwner($owner);
        if ($member->tenant_id !== $owner->tenant_id) {
            throw FlowException::because('other_tenant', 'Usuário não encontrado.');
        }
        if ($member->is($owner)) {
            throw FlowException::because('cannot_change_self', 'Você não pode fazer isso com a sua própria conta.');
        }
        if ($member->role === 'owner') {
            throw FlowException::because('cannot_change_owner', 'Não é possível alterar outro responsável da empresa.');
        }
    }
}
