<?php

namespace App\Http\Controllers\Databackd\Planning;

use App\Helper\Reply;
use App\Http\Controllers\AccountBaseController;
use App\Http\Requests\Databackd\Planning\StoreBudget;
use App\Models\Databackd\FinBudget;
use App\Models\Databackd\FinEntity;
use App\Models\Databackd\FinEntry;
use App\Services\Databackd\Planning\BudgetFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * List of annual budgets and creation (empty / from actuals ± % / copy of a budget), duplicate, approve, delete.
 * The grid editor is a separate screen (databackd.planning.budget-editor.edit).
 */
class BudgetController extends AccountBaseController
{

    public function __construct()
    {
        parent::__construct();
        $this->middleware(function ($request, $next) {
            // set here: the user's locale is applied by the parent middleware, before this one
            $this->pageTitle = __('databackd-planning-budgets.title');
            abort_403(in_array('client', user_roles()));
            return $next($request);
        });
    }

    public function index(Request $request)
    {
        $query = FinBudget::with(['entity:id,name', 'creator:id,name', 'approver:id,name'])
            ->orderByDesc('year')->orderBy('name');

        $year = $request->integer('year') ?: null;
        $version = in_array($request->input('version'), FinBudget::VERSIONS) ? $request->input('version') : null;
        $entity = $request->filled('entity_id') ? $request->input('entity_id') : null;

        if ($year) {
            $query->where('year', $year);
        }

        if ($version) {
            $query->where('version', $version);
        }

        if ($entity === 'group') {
            $query->whereNull('entity_id');
        }
        elseif ($entity) {
            $query->where('entity_id', (int)$entity);
        }

        $budgets = $query->get();
        $all = FinBudget::selectRaw('year, version')->get();

        $this->budgets = $budgets;
        $this->totals = (new BudgetFactory())->totals($budgets->pluck('id')->all());
        $this->years = $all->pluck('year')->unique()->sortDesc()->values();
        $this->entities = FinEntity::orderBy('sort')->orderBy('name')->get(['id', 'name']);
        $this->totalBudgets = $all->count();
        $this->draftCount = $all->where('version', 'draft')->count();
        $this->approvedCount = $all->where('version', 'approved')->count();
        $this->yearCount = $this->years->count();
        $this->filterYear = $year;
        $this->filterVersion = $version;
        $this->filterEntity = $entity;
        $this->filtered = $year || $version || $entity;
        $this->editorExists = Route::has('databackd.planning.budget-editor.edit');

        return view('databackd.planning.budgets.index', $this->data);
    }

    public function create()
    {
        $this->pageTitle = __('databackd-planning-budgets.create_title');
        $dataYears = FinEntry::selectRaw('YEAR(entry_date) as y')->distinct()->orderByDesc('y')->pluck('y')->map(fn($y) => (int)$y)->all();
        $thisYear = now()->year;

        $this->entities = FinEntity::orderBy('sort')->orderBy('name')->get(['id', 'name']);
        $this->budgetOptions = FinBudget::with('entity:id,name')->orderByDesc('year')->orderBy('name')->get();
        $this->sourceYears = $dataYears ?: [$thisYear - 1, $thisYear];
        $this->defaultSourceYear = $this->sourceYears[0];
        $this->defaultYear = $thisYear + 1;
        $this->currencies = FinBudget::currencyOptions();
        $this->baseCurrency = FinBudget::baseCurrency();

        return view('databackd.planning.budgets.create', $this->data);
    }

    public function store(StoreBudget $request)
    {
        $data = [
            'name' => trim($request->name),
            'year' => (int)$request->year,
            'entity_id' => $request->filled('entity_id') ? (int)$request->entity_id : null,
            'scenario' => $request->scenario,
            'currency' => $request->currency,
            'notes' => $request->notes,
        ];

        $factory = new BudgetFactory();

        if ($request->start_option === 'actuals') {
            $budget = $factory->fromActuals($data, (int)$request->source_year, (float)$request->adjust_pct, $request->boolean('fill_gaps'));

            if (!$budget->lines()->exists()) {
                // Nothing to copy: say so instead of opening an empty grid without a word
                return Reply::redirect($this->editUrl($budget), __('databackd-planning-budgets.created_no_actuals', ['year' => (int)$request->source_year]));
            }
        }
        elseif ($request->start_option === 'budget') {
            $budget = $factory->fromBudget($data, FinBudget::findOrFail($request->source_budget_id));
        }
        else {
            $budget = $factory->createEmpty($data);
        }

        return Reply::redirect($this->editUrl($budget), __('databackd-planning-budgets.created'));
    }

    public function show($id)
    {
        $budget = FinBudget::findOrFail($id);

        return redirect($this->editUrl($budget));
    }

    public function duplicate($id)
    {
        $budget = FinBudget::findOrFail($id);
        $copy = (new BudgetFactory())->duplicate($budget);

        return Reply::successWithData(__('databackd-planning-budgets.duplicated'), ['id' => $copy->id, 'url' => $this->editUrl($copy)]);
    }

    public function approve($id)
    {
        $budget = FinBudget::findOrFail($id);

        if ($budget->is_approved) {
            return Reply::error(__('databackd-planning-budgets.already_approved'));
        }

        $budget->version = 'approved';
        $budget->approved_by = user()->id;
        $budget->approved_at = now();
        $budget->save();

        return Reply::success(__('databackd-planning-budgets.approved_ok'));
    }

    public function destroy($id)
    {
        $budget = FinBudget::findOrFail($id);

        if ($budget->is_approved) {
            return Reply::error(__('databackd-planning-budgets.only_draft_delete'));
        }

        $budget->lines()->delete();
        $budget->delete();

        return Reply::success(__('databackd-planning-budgets.deleted'));
    }

    /**
     * The editor when it exists, otherwise the list.
     */
    private function editUrl(FinBudget $budget): string
    {
        return Route::has('databackd.planning.budget-editor.edit')
            ? route('databackd.planning.budget-editor.edit', $budget->id)
            : route('databackd.planning.budgets.index');
    }

}
