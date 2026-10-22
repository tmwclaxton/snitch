<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\URL;

class SignedLoginUrlCommand extends Command
{
    protected $signature = 'snitch:signed-login-url
        {user=1 : User id to sign in}
        {--minutes=20 : Link lifetime in minutes}
        {--plain : Print only the URL}';

    protected $description = 'Mint a temporary signed login URL for ops screenshots';

    public function handle(): int
    {
        $user = User::query()->find((int) $this->argument('user'));
        if ($user === null) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $minutes = max(1, min(120, (int) $this->option('minutes')));
        $url = URL::temporarySignedRoute(
            'ops.signed-login',
            now()->addMinutes($minutes),
            ['user' => $user->id],
        );

        if ((bool) $this->option('plain')) {
            $this->line($url);

            return self::SUCCESS;
        }

        $this->info('Signed login URL for user #'.$user->id.' (expires in '.$minutes.'m):');
        $this->line($url);

        return self::SUCCESS;
    }
}
