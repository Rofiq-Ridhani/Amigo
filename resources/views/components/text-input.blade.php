@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'border border-white/10 bg-white/5 text-white placeholder-slate-500 focus:border-brand-500 focus:ring-brand-500/50 rounded-lg shadow-sm transition px-4 py-2.5']) }}>
