<?php

namespace App\Console\Commands;

use App\Models\User;
use App\UserRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CreateUserCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:create
                            {--name= : The name of the user}
                            {--email= : The email address of the user}
                            {--password= : The password for the user}
                            {--role=trader : The role (guest, trader, admin)}
                            {--phone= : Optional phone number}
                            {--verify-email : Mark the email as verified}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new user with the specified credentials';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = $this->option('name');
        $email = $this->option('email');
        $password = $this->option('password');
        $role = $this->option('role');
        $phone = $this->option('phone');
        $verifyEmail = $this->option('verify-email');

        if (empty($name) || empty($email) || empty($password)) {
            $name = $this->ask('Name');
            $email = $this->ask('Email');
            $password = $this->secret('Password');

            if ($this->confirm('Do you want to set a role?', false)) {
                $role = $this->choice('Role', ['guest', 'trader', 'admin'], 1);
            }

            if ($this->confirm('Do you want to add a phone number?', false)) {
                $phone = $this->ask('Phone');
            }

            $verifyEmail = $this->confirm('Mark email as verified?', false);
        }

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => $role,
            'phone' => $phone,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'password' => ['required', Password::default()],
            'role' => ['required', 'string', Rule::in(['guest', 'trader', 'admin'])],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => UserRole::from($role),
            'phone' => $phone,
        ]);

        if ($verifyEmail) {
            $user->update(['email_verified_at' => now()]);
        }

        $this->info('User created successfully!');
        $this->table(
            ['Field', 'Value'],
            [
                ['ID', $user->id],
                ['Name', $user->name],
                ['Email', $user->email],
                ['Role', $user->role->value],
                ['Phone', $user->phone ?? '-'],
                ['Email Verified', $verifyEmail ? 'Yes' : 'No'],
            ]
        );

        return self::SUCCESS;
    }
}
