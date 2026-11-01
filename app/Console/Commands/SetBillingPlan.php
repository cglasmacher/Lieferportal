<?php

namespace App\Console\Commands;

use App\Domain\Billing\MonthlyBilling;
use App\Domain\Billing\Plans;
use App\Models\Restaurant;
use App\Models\RestaurantActivity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SetBillingPlan extends Command
{
    protected $signature = 'billing:set-plan {slug} {plan} {--billing-from= : Erster vereinbarter voller Abrechnungsmonat JJJJ-MM}';

    protected $description = 'Assign a confirmed tariff; configuration only, does not charge or invoice.';

    public function handle(Plans $plans): int
    {
        try {
            $plan = $plans->get($this->argument('plan'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            return DB::transaction(function () use ($plan) {
                $r = Restaurant::where('slug', $this->argument('slug'))->lockForUpdate()->first();
                if (! $r) {
                    $this->error('Restaurant nicht gefunden.');

                    return self::FAILURE;
                }
                $seats = DB::table('restaurant_user')->where('restaurant_id', $r->id)->count();
                if ($plan['team_limit'] !== null && $seats > $plan['team_limit']) {
                    $this->error('Zu viele Teamzugänge für diesen Tarif. Bitte zuerst die Zuordnungen reduzieren.');

                    return self::FAILURE;
                }
                if ($this->option('billing-from') !== null) {
                    app(MonthlyBilling::class)->agree($r, $plan['key'], $this->option('billing-from'));
                }
                if ($r->billing_plan !== $plan['key']) {
                    RestaurantActivity::create(['restaurant_id' => $r->id, 'action' => 'billing.changed', 'metadata' => ['from' => $r->billing_plan, 'to' => $plan['key'], 'monthly_net_cents' => $plan['monthly_net_cents'], 'commission_basis_points' => $plan['commission_basis_points']]]);
                }
                $r->update(['billing_plan' => $plan['key'], 'requested_billing_plan' => null, 'billing_requested_at' => null, 'billing_requested_by' => null]);
                $this->info('Tarif zugeordnet. '.($this->option('billing-from') ? 'Abrechnungsbedingungen ab '.$this->option('billing-from').' gespeichert. ' : 'Kein Abrechnungsstart festgelegt. ').'Kein Zahlungseinzug.');

                return self::SUCCESS;
            });
        } catch (\DomainException|\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
