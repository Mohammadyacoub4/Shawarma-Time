<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Bootstrap the initial Shawarma Time administrator account.
 *
 * SECURITY NOTES:
 * - Passwords are entered via secret() which suppresses terminal echo.
 * - Passwords are never stored in plaintext or written to any log.
 * - The --password option exists solely for non-interactive CI; avoid using
 *   it interactively so the password does not appear in shell history.
 * - The command refuses to create a duplicate admin when one already exists,
 *   unless --force is passed (useful for adding a second admin).
 * - No default admin credentials are hardcoded anywhere in the codebase.
 *
 * USAGE (interactive — recommended for production):
 *   php artisan shawarma:create-admin
 *
 * USAGE (non-interactive CI — password passed via env or piped stdin):
 *   php artisan shawarma:create-admin --name="Jane Doe" --email=jane@example.com --force
 *   # Then enter the password interactively, or pipe: echo "secret" | php artisan ...
 *
 * After running this command, sign in at /login using the email and password you provided.
 * The created account will have the Admin role and full access to both branches.
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'shawarma:create-admin
                            {--name=          : Admin full name}
                            {--email=         : Admin email address}
                            {--password=      : Admin password — AVOID on interactive terminals (use the secret prompt instead)}
                            {--force          : Allow creating an additional admin even when one already exists}';

    protected $description = 'Securely bootstrap the initial Shawarma Time administrator account';

    public function handle(): int
    {
        // ── Guard against accidental duplicate admin creation ─────────────────
        $existingAdminCount = User::where('role', UserRole::Admin)->count();

        if ($existingAdminCount > 0 && ! $this->option('force')) {
            $this->warn("⚠  An administrator account already exists ({$existingAdminCount} total).");
            $this->line('   Pass --force to create an additional admin account.');
            $this->line('   All further employee accounts should be created through the Admin dashboard.');

            return self::FAILURE;
        }

        if ($existingAdminCount > 0 && $this->option('force')) {
            $this->warn("Creating an additional admin account (--force is set). {$existingAdminCount} admin(s) already exist.");

            if (! $this->confirm('Continue?', false)) {
                $this->info('Aborted.');
                return self::SUCCESS;
            }
        }

        // ── Collect inputs ────────────────────────────────────────────────────
        $name     = $this->option('name')  ?: $this->ask('Full name');
        $email    = $this->option('email') ?: $this->ask('Email address');
        $password = $this->option('password') ?: $this->secret('Password (min 12 characters, not echoed)');

        if (! $password) {
            $this->error('Password cannot be empty.');
            return self::FAILURE;
        }

        // Confirm password when not supplied via option (i.e., interactive mode)
        if (! $this->option('password')) {
            $confirm = $this->secret('Confirm password');
            if ($password !== $confirm) {
                $this->error('Passwords do not match. No account was created.');
                return self::FAILURE;
            }
        }

        // ── Validate ──────────────────────────────────────────────────────────
        $validator = Validator::make(
            compact('name', 'email', 'password'),
            [
                'name'     => ['required', 'string', 'max:255'],
                'email'    => ['required', 'email:rfc', 'unique:users,email'],
                'password' => ['required', 'string', 'min:12'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
            return self::FAILURE;
        }

        // ── Create the admin ──────────────────────────────────────────────────
        $admin = User::create([
            'name'                => $name,
            'email'               => $email,
            'password'            => Hash::make($password),
            'role'                => UserRole::Admin,
            'is_active'           => true,
            'must_change_password' => false,
        ]);

        // Clear $password from memory as soon as it is hashed
        unset($password, $confirm);

        $this->newLine();
        $this->info('✅  Admin account created successfully.');
        $this->line("   Name  : {$admin->name}");
        $this->line("   Email : {$admin->email}");
        $this->line("   ID    : {$admin->id}");
        $this->newLine();
        $this->line('Sign in at: <your-app-url>/login');
        $this->line('Use the email and password you just entered.');
        $this->newLine();
        $this->warn('Reminder: do NOT create further employee accounts via this command.');
        $this->warn('Use the Admin dashboard (Employees → Create) for all subsequent accounts.');

        return self::SUCCESS;
    }
}
