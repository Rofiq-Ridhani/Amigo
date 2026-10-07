<x-guest-layout>
    <!-- Session Status -->
    <x-auth-session-status class="mb-6 p-4 bg-brand-500/10 border border-brand-500/30 rounded-lg text-brand-300 text-sm" :status="session('status')" />

    <div class="mb-8">
        <h2 class="text-2xl font-bold text-white mb-1.5">Welcome back</h2>
        <p class="text-slate-400 text-sm">Sign in to continue to ChatApp</p>
    </div>

    <form method="POST" action="{{ route('login') }}" class="space-y-5">
        @csrf

        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Email Address')" />
            <x-text-input id="email" class="block mt-1.5 w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" placeholder="you@example.com" />
            <x-input-error :messages="$errors->get('email')" class="mt-2 text-red-400 text-xs" />
        </div>

        <!-- Password -->
        <div>
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" class="block mt-1.5 w-full" type="password" name="password" required autocomplete="current-password" placeholder="••••••••" />
            <x-input-error :messages="$errors->get('password')" class="mt-2 text-red-400 text-xs" />
        </div>

        <!-- Remember Me -->
        <div class="flex items-center">
            <input id="remember_me" type="checkbox" class="w-4 h-4 rounded border-white/20 bg-white/5 text-brand-600 shadow-sm focus:ring-2 focus:ring-brand-500 focus:ring-offset-0 cursor-pointer" name="remember">
            <label for="remember_me" class="ms-2 text-sm text-slate-300 cursor-pointer">
                {{ __('Remember me') }}
            </label>
        </div>

        <!-- Actions -->
        <div class="flex items-center justify-between pt-2">
            @if (Route::has('password.request'))
                <a class="text-sm text-brand-400 hover:text-brand-300 rounded-md focus:outline-none focus:ring-2 focus:ring-brand-500 transition" href="{{ route('password.request') }}">
                    {{ __('Forgot password?') }}
                </a>
            @endif

            <x-primary-button>
                {{ __('Sign in') }}
            </x-primary-button>
        </div>

        <!-- Register Link -->
        <div class="text-center pt-4 border-t border-white/10">
            <p class="text-sm text-slate-400">
                Don't have an account?
                <a href="{{ route('register') }}" class="text-brand-400 hover:text-brand-300 font-semibold transition">
                    Sign up
                </a>
            </p>
        </div>
    </form>
</x-guest-layout>
