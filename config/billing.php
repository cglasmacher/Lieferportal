<?php

return [
    'plans' => [
        'flex' => ['name' => 'Flex', 'monthly_net_cents' => 0, 'commission_basis_points' => 500, 'team_limit' => null, 'menu_export' => false, 'activity_log' => false, 'planned_benefits' => []],
        'starter' => ['name' => 'Starter', 'monthly_net_cents' => 4900, 'commission_basis_points' => 0, 'team_limit' => 2, 'menu_export' => false, 'activity_log' => false, 'planned_benefits' => ['Bestellwidget für die eigene Website']],
        'growth' => ['name' => 'Wachstum', 'monthly_net_cents' => 8900, 'commission_basis_points' => 0, 'team_limit' => 5, 'menu_export' => true, 'activity_log' => false, 'planned_benefits' => ['Bestellwidget für die eigene Website', 'Flyer-Generator und Kampagnenauswertung', 'Fahrer-App für eigene Lieferungen']],
        'pro' => ['name' => 'Pro', 'monthly_net_cents' => 12900, 'commission_basis_points' => 0, 'team_limit' => 15, 'menu_export' => true, 'activity_log' => true, 'planned_benefits' => ['Bestellwidget für die eigene Website', 'Flyer-Generator und Kampagnenauswertung', 'Fahrer-App für eigene Lieferungen', 'Erweiterte Auswertungen und Integrationen']],
    ],
];
