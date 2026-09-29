<?php

namespace App\Providers;

use App\Models\VotingSubject;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });

        RateLimiter::for('public-voting-lookup', function (Request $request): Limit {
            return Limit::perMinute(60)->by($this->votingThrottleKey([
                $this->votingSubjectSlug($request), $request->ip(),
            ]));
        });

        RateLimiter::for('public-voting-submission', function (Request $request): Limit {
            $slug = $this->votingSubjectSlug($request);
            $code = $request->input('registration_code');
            $registration = is_string($code) && mb_strlen($code) <= 255
                ? DB::table('registrations')
                    ->join('voting_subjects', 'voting_subjects.event_id', '=', 'registrations.event_id')
                    ->where('voting_subjects.slug', $slug)
                    ->where('voting_subjects.status', VotingSubject::STATUS_ACTIVE)
                    ->where('registrations.status', 'confirmed')
                    ->where('registrations.registration_code', Str::upper(trim($code)))
                    ->first(['registrations.id', 'voting_subjects.id as subject_id'])
                : null;

            // A venue's shared IP must not pool eligible voters' attempts. Code
            // guesses still share a bounded bucket, separate from valid votes.
            $identity = $registration
                ? ['registration', $registration->subject_id, $registration->id]
                : ['invalid', $slug, $request->ip()];

            return Limit::perMinute(10)->by($this->votingThrottleKey($identity));
        });
    }

    private function votingSubjectSlug(Request $request): string
    {
        $subject = $request->route('subject');

        return Str::lower($subject instanceof VotingSubject ? $subject->slug : (string) $subject);
    }

    private function votingThrottleKey(array $identity): string
    {
        // Keep registration identities and IP addresses out of cache keys.
        return hash_hmac('sha256', json_encode($identity), (string) config('app.key'));
    }
}
