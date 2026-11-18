<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Contacts\ContactCsv;
use App\Contracts\ContractWatch;
use App\Folders\FolderService;
use App\Http\Controllers\Controller;
use App\Models\Envelope;
use App\Models\Folder;
use App\Models\Tag;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** B.I. de vigência: contratos concluídos com vencimento monitorado, por faixa de prazo. */
final class ContractController extends Controller
{
    public const FAIXAS = ['avencer', 'vencidos', 'ate30', '31a60', '61a90', 'vigentes', 'todos'];

    public function __construct(private readonly ContractWatch $watch, private readonly FolderService $folders)
    {
    }

    public function index(Request $request): View
    {
        $f = $this->filters($request);
        $tenant = $request->user()->tenant;
        $today = $this->watch->today()->toDateString();
        $base = $this->filtered($tenant, $f);

        return view('contracts.index', [
            'filters' => $f,
            'counts' => $this->counts($base, $today),
            'rows' => $this->band(clone $base, $f['faixa'], $today)->with(['tags', 'folder'])->orderBy('contract_expires_on')->orderByRaw('lower(title)')->paginate(25)->withQueryString(),
            'chart' => $this->chart($base, $this->watch->today()),
            'watch' => $this->watch,
            'today' => $this->watch->today(),
            'folderPaths' => $this->folders->paths($tenant),
            'tags' => Tag::query()->where('tenant_id', $tenant->getKey())->orderBy('name')->get(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $f = $this->filters($request);
        $tenant = $request->user()->tenant;
        $today = $this->watch->today();
        $paths = $this->folders->paths($tenant);
        $query = $this->band($this->filtered($tenant, $f), $f['faixa'], $today->toDateString())->with(['tags'])->orderBy('contract_expires_on')->orderByRaw('lower(title)');
        $names = ['overdue' => 'Vencido', 'd30' => 'Até 30 dias', 'd60' => '31 a 60 dias', 'd90' => '61 a 90 dias', 'valid' => 'Vigente'];

        return response()->streamDownload(function () use ($query, $paths, $today, $names): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fwrite($out, "Título;Pasta;Etiquetas;Vencimento;Dias restantes;Faixa;Avisar (dias antes);E-mails do aviso\n");
            $query->chunk(500, function ($chunk) use ($out, $paths, $today, $names): void {
                foreach ($chunk as $e) {
                    /** @var Envelope $e */
                    $left = $this->watch->daysLeft($e, $today);
                    fputcsv($out, array_map([ContactCsv::class, 'safeCell'], [
                        $e->title, $paths[$e->folder_id] ?? '', $e->tags->pluck('name')->implode(', '), $e->contract_expires_on->format('d/m/Y'),
                        (string) $left, $names[$this->watch->band((int) $left)], (string) $e->contract_alert_days, implode(', ', (array) $e->contract_alert_emails),
                    ]), ';', '"', '');
                }
            });
            fclose($out);
        }, 'contratos-vigencia.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{faixa:string,folder?:string,tag?:string,q?:string} */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'faixa' => ['nullable', Rule::in(self::FAIXAS)],
            'folder' => ['nullable', 'uuid'],
            'tag' => ['nullable', 'uuid'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return ['faixa' => $data['faixa'] ?? 'avencer'] + array_filter(['folder' => $data['folder'] ?? null, 'tag' => $data['tag'] ?? null, 'q' => $data['q'] ?? null], fn ($v) => $v !== null && $v !== '');
    }

    /** Monitorados da empresa + pasta (e subpastas), etiqueta e busca. @return Builder<Envelope> */
    private function filtered(Tenant $tenant, array $f): Builder
    {
        $query = $this->watch->monitored($tenant);

        if (isset($f['folder'])) {
            $folder = Folder::query()->where('tenant_id', $tenant->getKey())->find($f['folder']);
            $query->whereIn('folder_id', $folder ? $this->folders->descendantIds($folder) : []);
        }
        if (isset($f['tag'])) {
            $query->whereHas('tags', fn ($q) => $q->where('tags.id', $f['tag'])->where('tags.tenant_id', $tenant->getKey()));
        }
        if (isset($f['q'])) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($f['q'])).'%';
            $query->where(fn ($q) => $q->whereRaw('lower(title) like ?', [$like])
                ->orWhereHas('signers', fn ($s) => $s->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(email) like ?', [$like])));
        }

        return $query;
    }

    /** Aplica a faixa escolhida. A coluna é data pura: as comparações usam o "hoje" de Brasília. */
    private function band(Builder $query, string $faixa, string $today): Builder
    {
        return match ($faixa) {
            'vencidos' => $query->whereRaw('contract_expires_on < cast(? as date)', [$today]),
            'ate30' => $query->whereRaw('contract_expires_on >= cast(? as date) and contract_expires_on <= cast(? as date) + 30', [$today, $today]),
            '31a60' => $query->whereRaw('contract_expires_on > cast(? as date) + 30 and contract_expires_on <= cast(? as date) + 60', [$today, $today]),
            '61a90' => $query->whereRaw('contract_expires_on > cast(? as date) + 60 and contract_expires_on <= cast(? as date) + 90', [$today, $today]),
            'vigentes' => $query->whereRaw('contract_expires_on > cast(? as date) + 90', [$today]),
            'avencer' => $query->whereRaw('contract_expires_on <= cast(? as date) + GREATEST(30, COALESCE(contract_alert_days, 30))', [$today]),
            default => $query,
        };
    }

    /** @return array{overdue:int,d30:int,d60:int,d90:int,valid:int,soon:int} */
    private function counts(Builder $base, string $today): array
    {
        $row = (clone $base)->toBase()->selectRaw(
            'count(*) filter (where contract_expires_on < cast(? as date)) as overdue,
             count(*) filter (where contract_expires_on >= cast(? as date) and contract_expires_on <= cast(? as date) + 30) as d30,
             count(*) filter (where contract_expires_on > cast(? as date) + 30 and contract_expires_on <= cast(? as date) + 60) as d60,
             count(*) filter (where contract_expires_on > cast(? as date) + 60 and contract_expires_on <= cast(? as date) + 90) as d90,
             count(*) filter (where contract_expires_on > cast(? as date) + 90) as valid,
             count(*) filter (where contract_expires_on <= cast(? as date) + GREATEST(30, COALESCE(contract_alert_days, 30))) as soon',
            array_fill(0, 9, $today),
        )->first();

        return ['overdue' => (int) $row->overdue, 'd30' => (int) $row->d30, 'd60' => (int) $row->d60, 'd90' => (int) $row->d90, 'valid' => (int) $row->valid, 'soon' => (int) $row->soon];
    }

    /** Vencimentos por mês nos próximos 12 meses (a partir do mês de hoje). @return array{labels:list<string>,values:list<int>} */
    private function chart(Builder $base, CarbonImmutable $today): array
    {
        $start = $today->startOfMonth();
        $labels = [];
        $values = [];
        $counts = (clone $base)->toBase()
            ->whereRaw('contract_expires_on >= cast(? as date) and contract_expires_on < cast(? as date)', [$today->toDateString(), $start->addMonths(12)->toDateString()])
            ->selectRaw("to_char(contract_expires_on, 'YYYY-MM') as m, count(*) as c")->groupBy('m')->pluck('c', 'm');

        for ($i = 0; $i < 12; $i++) {
            $month = $start->addMonths($i);
            $labels[] = $month->locale('pt_BR')->isoFormat('MMM/YY');
            $values[] = (int) ($counts[$month->format('Y-m')] ?? 0);
        }

        return ['labels' => $labels, 'values' => $values];
    }
}
