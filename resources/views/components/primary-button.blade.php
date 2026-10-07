<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center justify-center px-6 py-2.5 bg-brand-600 hover:bg-brand-500 active:bg-brand-700 border border-transparent rounded-lg font-semibold text-sm text-white uppercase tracking-widest shadow-lg shadow-brand-600/30 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
