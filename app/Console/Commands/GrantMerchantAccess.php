<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class GrantMerchantAccess extends Command
{
    protected $signature = 'merchant:grant {slug} {email} {--role=owner} {--name=}';

    protected $description = 'Grant restaurant access; new users receive a password through a hidden prompt.';

    public function handle(): int
    {
        $role = $this->option('role');
        $email = mb_strtolower(trim($this->argument('email')));
        if (! in_array($role, ['owner', 'manager', 'staff'], true) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Gültige E-Mail und Rolle owner, manager oder staff erforderlich.');

            return self::FAILURE;
        }
        $restaurant = Restaurant::where('slug', $this->argument('slug'))->first();
        if (! $restaurant) {
            $this->error('Restaurant nicht gefunden.');

            return self::FAILURE;
        }
        $user = User::where('email', $email)->first();
        $password = null;
        if (! $user) {
            $name = trim((string) $this->option('name'));
            if ($name === '' || mb_strlen($name) > 120) {
                $this->error('--name ist für neue Benutzer erforderlich (maximal 120 Zeichen).');

                return self::FAILURE;
            }
            if (! $this->input->isInteractive()) {
                $this->error('Neue Benutzer benötigen eine interaktive Passworteingabe.');

                return self::FAILURE;
            }
            $password = $this->secret('Neues Passwort (mindestens 12 Zeichen)');
            $confirmation = $this->secret('Passwort wiederholen');
            if (Validator::make(['password' => $password, 'password_confirmation' => $confirmation], ['password' => 'required|string|min:12|max:1024|confirmed'])->fails()) {
                $this->error('Passwort ist zu kurz oder stimmt nicht überein.');

                return self::FAILURE;
            }
        }
        DB::transaction(function () use ($user, $email, $password, $restaurant, $role) {
            $account = $user ?? User::create(['name' => trim((string) $this->option('name')), 'email' => $email, 'password' => $password]);
            $account->restaurants()->syncWithoutDetaching([$restaurant->id => ['role' => $role]]);
        });
        $this->info('Restaurantzugang eingerichtet. Anmeldung unter /portal/login.');

        return self::SUCCESS;
    }
}
