<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MakePlatformAdmin extends Command
{
    protected $signature = 'lumee:platform-admin {email} {--revoke : Remove platform admin access}';

    protected $description = 'Grant (or revoke) access to the Lumée ops console';

    public function handle(): int
    {
        $user = User::where('email', strtolower($this->argument('email')))->first();
        if (! $user) {
            $this->error('No user with that email. They need to register first.');

            return self::FAILURE;
        }
        $user->forceFill(['is_platform_admin' => ! $this->option('revoke')])->save();
        $this->info($user->email.($this->option('revoke') ? ' is no longer' : ' is now').' a platform admin.');

        return self::SUCCESS;
    }
}
