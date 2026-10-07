@props(['active'])

@php
$classes = ($active ?? false)
            ? 'block w-full ps-3 pe-4 py-2 border-l-4 border-brand-400 text-start text-base font-medium text-white bg-brand-600/20 focus:outline-none focus:text-white focus:bg-brand-600/30 focus:border-brand-300 transition duration-150 ease-in-out'
            : 'block w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-slate-300 hover:text-white hover:bg-white/5 hover:border-brand-400 focus:outline-none focus:text-white focus:bg-white/5 focus:border-brand-400 transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
