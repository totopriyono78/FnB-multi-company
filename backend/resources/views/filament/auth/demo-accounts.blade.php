@php
    use App\Filament\Demo\DemoAccounts;
    $accounts = DemoAccounts::enabled() ? DemoAccounts::all() : [];
@endphp

@if ($accounts !== [])
    <section class="fnb-demo" aria-labelledby="fnb-demo-title">
        <div class="fnb-demo__head">
            <h2 id="fnb-demo-title" class="fnb-demo__title">Akun demo</h2>
            <p class="fnb-demo__hint">
                Klik salah satu akun untuk langsung masuk. Password semua akun:
                <code class="fnb-demo__code">{{ DemoAccounts::PASSWORD }}</code>
            </p>
        </div>

        <ul class="fnb-demo__list" role="list">
            @foreach ($accounts as $account)
                <li>
                    <button
                        type="button"
                        class="fnb-demo__item"
                        wire:click="loginAsDemo(@js($account['email']))"
                        wire:loading.attr="disabled"
                        wire:target="loginAsDemo,authenticate"
                        aria-label="Masuk sebagai {{ $account['name'] }}, {{ $account['role'] }}"
                    >
                        <span class="fnb-demo__main">
                            <span class="fnb-demo__name">{{ $account['name'] }}</span>
                            <span class="fnb-demo__role">{{ $account['role'] }} · {{ $account['company'] }}</span>
                            <span class="fnb-demo__email">{{ $account['email'] }}</span>
                        </span>
                        <span class="fnb-demo__meta">
                            @if ($account['pin'])
                                <span class="fnb-demo__pin">PIN {{ $account['pin'] }}</span>
                            @endif
                            <x-filament::icon icon="heroicon-m-arrow-right" class="fnb-demo__icon" />
                        </span>
                    </button>
                </li>
            @endforeach
        </ul>

        <p class="fnb-demo__note">Daftar ini hanya muncul di mode demo (FNB_DEMO_LOGIN=true).</p>
    </section>
@endif
