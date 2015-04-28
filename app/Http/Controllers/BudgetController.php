<?php namespace FireflyIII\Http\Controllers;

use Auth;
use Carbon\Carbon;
use FireflyIII\Http\Requests;
use FireflyIII\Http\Requests\BudgetFormRequest;
use FireflyIII\Models\Budget;
use FireflyIII\Models\BudgetLimit;
use FireflyIII\Models\LimitRepetition;
use FireflyIII\Repositories\Budget\BudgetRepositoryInterface;
use Input;
use Preferences;
use Redirect;
use Response;
use Session;
use URL;
use View;

/**
 * Class BudgetController
 *
 * @package FireflyIII\Http\Controllers
 */
class BudgetController extends Controller
{

    /**
     *
     */
    public function __construct()
    {
        parent::__construct();
        View::share('title', 'Budgets');
        View::share('mainTitleIcon', 'fa-tasks');
        View::share('hideBudgets', true);
    }

    /**
     * @param Budget $budget
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function amount(Budget $budget, BudgetRepositoryInterface $repository)
    {
        $amount          = intval(Input::get('amount'));
        $date            = Session::get('start', Carbon::now()->startOfMonth());
        $limitRepetition = $repository->updateLimitAmount($budget, $date, $amount);

        return Response::json(['name' => $budget->name, 'repetition' => $limitRepetition ? $limitRepetition->id : 0]);

    }

    /**
     * @return $this
     */
    public function create()
    {
        // put previous url in session if not redirect from store (not "create another").
        if (Session::get('budgets.create.fromStore') !== true) {
            Session::put('budgets.create.url', URL::previous());
        }
        Session::forget('budgets.create.fromStore');

        return view('budgets.create')->with('subTitle', 'Create a new budget');
    }

    /**
     * @param Budget $budget
     *
     * @return \Illuminate\View\View
     */
    public function delete(Budget $budget)
    {
        $subTitle = 'Delete budget' . e($budget->name) . '"';

        // put previous url in session
        Session::put('budgets.delete.url', URL::previous());

        return view('budgets.delete', compact('budget', 'subTitle'));
    }

    /**
     * @param Budget $budget
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Budget $budget, BudgetRepositoryInterface $repository)
    {

        $name = $budget->name;
        $repository->destroy($budget);


        Session::flash('success', 'The  budget "' . e($name) . '" was deleted.');

        return Redirect::to(Session::get('budgets.delete.url'));
    }

    /**
     * @param Budget $budget
     *
     * @return $this
     */
    public function edit(Budget $budget)
    {
        $subTitle = 'Edit budget "' . e($budget->name) . '"';

        // put previous url in session if not redirect from store (not "return_to_edit").
        if (Session::get('budgets.edit.fromUpdate') !== true) {
            Session::put('budgets.edit.url', URL::previous());
        }
        Session::forget('budgets.edit.fromUpdate');

        return view('budgets.edit', compact('budget', 'subTitle'));

    }

    /**
     * @return mixed
     */
    public function index(BudgetRepositoryInterface $repository)
    {
        $budgets  = $repository->getActiveBudgets();
        $inactive = $repository->getInactiveBudgets();

        /**
         * Do some cleanup:
         */
        $repository->cleanupBudgets();


        // loop the budgets:
        $budgets->each(
            function (Budget $budget) use ($repository) {
                $date               = Session::get('start', Carbon::now()->startOfMonth());
                $budget->spent      = $repository->spentInMonth($budget, $date);
                $budget->currentRep = $repository->getCurrentRepetition($budget, $date);
            }
        );

        $dateAsString  = Session::get('start', Carbon::now()->startOfMonth())->format('FY');
        $spent         = $budgets->sum('spent');
        $amount        = Preferences::get('budgetIncomeTotal' . $dateAsString, 1000)->data;
        $overspent     = $spent > $amount;
        $spentPCT      = $overspent ? ceil($amount / $spent * 100) : ceil($spent / $amount * 100);
        $budgetMax     = Preferences::get('budgetMaximum', 1000);
        $budgetMaximum = $budgetMax->data;

        return view('budgets.index', compact('budgetMaximum', 'inactive', 'budgets', 'spent', 'spentPCT', 'overspent', 'amount'));
    }

    /**
     * @return \Illuminate\View\View
     */
    public function noBudget(BudgetRepositoryInterface $repository)
    {
        $start    = Session::get('start', Carbon::now()->startOfMonth());
        $end      = Session::get('end', Carbon::now()->startOfMonth());
        $list     = $repository->getWithoutBudget($start, $end);
        $subTitle = 'Transactions without a budget between ' . $start->format('jS F Y') . ' and ' . $end->format('jS F Y');

        return view('budgets.noBudget', compact('list', 'subTitle'));
    }

    /**
     * @return mixed
     */
    public function postUpdateIncome()
    {

        $date = Session::get('start', Carbon::now()->startOfMonth())->format('FY');
        Preferences::set('budgetIncomeTotal' . $date, intval(Input::get('amount')));

        return Redirect::route('budgets.index');
    }

    /**
     *
     * @param Budget          $budget
     * @param LimitRepetition $repetition
     *
     * @return \Illuminate\View\View
     */
    public function show(Budget $budget, LimitRepetition $repetition = null, BudgetRepositoryInterface $repository)
    {
        if (!is_null($repetition->id) && $repetition->budgetLimit->budget->id != $budget->id) {
            return view('error')->with('message', 'Invalid selection.');
        }

        $journals = $repository->getJournals($budget, $repetition);
        $limits   = !is_null($repetition->id) ? [$repetition->budgetLimit] : $repository->getBudgetLimits($budget);
        $subTitle = !is_null($repetition->id) ? e($budget->name) . ' in ' . $repetition->startdate->format('F Y') : e($budget->name);

        return view('budgets.show', compact('limits', 'budget', 'repetition', 'journals', 'subTitle'));
    }

    /**
     * @param BudgetFormRequest         $request
     * @param BudgetRepositoryInterface $repository
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(BudgetFormRequest $request, BudgetRepositoryInterface $repository)
    {
        $budgetData = [
            'name' => $request->input('name'),
            'user' => Auth::user()->id,
        ];
        $budget     = $repository->store($budgetData);

        Session::flash('success', 'New budget "' . $budget->name . '" stored!');

        if (intval(Input::get('create_another')) === 1) {
            // set value so create routine will not overwrite URL:
            Session::put('budgets.create.fromStore', true);

            return Redirect::route('budgets.create')->withInput();
        }

        // redirect to previous URL.
        return Redirect::to(Session::get('budgets.create.url'));

    }

    /**
     * @param Budget                    $budget
     * @param BudgetFormRequest         $request
     * @param BudgetRepositoryInterface $repository
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Budget $budget, BudgetFormRequest $request, BudgetRepositoryInterface $repository)
    {
        $budgetData = [
            'name'   => $request->input('name'),
            'active' => intval($request->input('active')) == 1
        ];

        $repository->update($budget, $budgetData);

        Session::flash('success', 'Budget "' . $budget->name . '" updated.');

        if (intval(Input::get('return_to_edit')) === 1) {
            // set value so edit routine will not overwrite URL:
            Session::put('budgets.edit.fromUpdate', true);

            return Redirect::route('budgets.edit', $budget->id)->withInput(['return_to_edit' => 1]);
        }

        // redirect to previous URL.
        return Redirect::to(Session::get('budgets.edit.url'));

    }

    /**
     * @return $this
     */
    public function updateIncome()
    {
        $date         = Session::get('start', Carbon::now()->startOfMonth())->format('FY');
        $budgetAmount = Preferences::get('budgetIncomeTotal' . $date, 1000);

        return view('budgets.income')->with('amount', $budgetAmount);
    }

}
