<?php

declare(strict_types=1);

use App\Enums\EnvelopeStatus;
use App\Models\Envelope;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

function wzAge(Envelope $e, int $days): void
{
    DB::table('envelopes')->where('id', $e->id)->update(['updated_at' => now()->subDays($days)]);
}

it('a lista mostra o selo Rascunho e leva ao passo em que o assistente parou', function () {
    $u = wzOwner();
    $one = wzDraft($u, 'Parou no um');
    $three = wzDraft($u, 'Parou no três');
    wzSync($three, $u, [wzPerson('Ana')]);
    app(\App\Envelopes\EnvelopeService::class)->syncFields($three->refresh(), []);

    $html = $this->actingAs($u)->get('/documentos')->assertOk()->assertSee('Rascunho')->getContent();

    expect($html)->toContain("/documentos/{$one->id}/passo/2")->and($html)->toContain("/documentos/{$three->id}/passo/4");
});

it('documento enviado e rascunho da API abrem o detalhe', function () {
    $u = wzOwner();
    $sent = wzDraft($u, 'Já enviado');
    $sent->update(['status' => EnvelopeStatus::Sent]);
    $api = wzDraft($u, 'Veio da API');
    $api->update(['wizard_step' => null]);

    $html = $this->actingAs($u)->get('/documentos')->assertOk()->getContent();

    expect($html)->toContain("/documentos/{$sent->id}\"")->and($html)->not->toContain("/documentos/{$sent->id}/passo")
        ->and($html)->toContain("/documentos/{$api->id}\"")->and($html)->not->toContain("/documentos/{$api->id}/passo");
});

it('a pasta e o painel também levam o rascunho de volta ao assistente', function () {
    $u = wzOwner();
    $d = wzDraft($u, 'Rascunho no painel');

    $this->actingAs($u)->get('/painel')->assertOk()->assertSee("/documentos/{$d->id}/passo/2", false);
    $this->actingAs($u)->get('/documentos/pastas')->assertOk()->assertSee("/documentos/{$d->id}/passo/2", false);
});

it('move para a lixeira o rascunho do assistente parado há mais de 14 dias', function () {
    $u = wzOwner();
    $old = wzDraft($u, 'Velho');
    $fresh = wzDraft($u, 'Recente');
    $edge = wzDraft($u, 'No limite');
    $sent = wzDraft($u, 'Enviado antigo');
    $sent->update(['status' => EnvelopeStatus::Sent]);
    $api = wzDraft($u, 'API antigo');
    $api->update(['wizard_step' => null]);
    wzAge($old, 15);
    wzAge($edge, 13);
    wzAge($sent, 40);
    wzAge($api, 40);

    $this->artisan('documents:trash-stale-drafts')->expectsOutputToContain('1 documento(s) movido(s) para a lixeira.')->assertExitCode(0);

    expect($old->fresh()->isTrashed())->toBeTrue()->and($fresh->fresh()->isTrashed())->toBeFalse()->and($edge->fresh()->isTrashed())->toBeFalse()
        ->and($sent->fresh()->isTrashed())->toBeFalse()->and($api->fresh()->isTrashed())->toBeFalse();
});

it('o prazo é ajustável e a lixeira esconde o rascunho da lista ativa', function () {
    $u = wzOwner();
    $d = wzDraft($u, 'Rascunho de 5 dias');
    wzAge($d, 5);

    $this->artisan('documents:trash-stale-drafts', ['--days' => 3])->expectsOutputToContain('1 documento(s)')->assertExitCode(0);

    $this->actingAs($u)->get('/documentos')->assertOk()->assertDontSee('Rascunho de 5 dias');
    $this->actingAs($u)->get('/documentos/lixeira')->assertOk()->assertSee('Rascunho de 5 dias');
});

it('agenda a limpeza diária dos rascunhos', function () {
    $scheduled = collect(app(Schedule::class)->events())->contains(fn ($e) => str_contains((string) $e->command, 'documents:trash-stale-drafts'));
    expect($scheduled)->toBeTrue();
});
