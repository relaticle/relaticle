@props([
    'light' => null,
    'dots' => false,
    'frame' => true,
])

<div {{ $attributes->class(['relative isolate', 'overflow-hidden ring-1 ring-gray-950/5 dark:ring-white/10' => $frame]) }}>
    @if($dots)
        <div class="bg-dots pointer-events-none absolute inset-0 -z-10 [mask-image:radial-gradient(60%_70%_at_50%_45%,#000_40%,transparent_100%)]" aria-hidden="true"></div>
    @endif

    @if($light === 'seam')
        <div class="pointer-events-none absolute inset-x-[15%] top-0 h-px bg-[linear-gradient(90deg,transparent,#5D54E8,transparent)]" aria-hidden="true"></div>
        <div class="glow-indigo pointer-events-none absolute left-1/2 top-0 -z-10 h-80 w-[70%] -translate-x-1/2 -translate-y-1/2 rounded-full blur-3xl" aria-hidden="true"></div>
    @elseif($light === 'corners')
        <div class="glow-violet pointer-events-none absolute -bottom-24 -left-24 -z-10 h-96 w-[34rem] rounded-full blur-3xl" aria-hidden="true"></div>
        <div class="glow-indigo pointer-events-none absolute -right-20 -top-24 -z-10 h-96 w-[38rem] rounded-full blur-3xl" aria-hidden="true"></div>
        <div class="glow-sky pointer-events-none absolute bottom-0 right-1/4 -z-10 h-64 w-[26rem] translate-y-1/3 rounded-full blur-3xl" aria-hidden="true"></div>
    @elseif($light === 'inside')
        <div class="glow-violet pointer-events-none absolute -left-32 -top-40 -z-10 h-[30rem] w-[44rem] rounded-full blur-3xl" aria-hidden="true"></div>
        <div class="glow-indigo pointer-events-none absolute -right-24 top-1/4 -z-10 h-[34rem] w-[46rem] rounded-full blur-3xl" aria-hidden="true"></div>
        <div class="glow-sky pointer-events-none absolute -bottom-32 left-1/3 -z-10 h-80 w-[36rem] rounded-full blur-3xl" aria-hidden="true"></div>
    @elseif($light === 'around')
        <div class="glow-indigo pointer-events-none absolute left-1/2 top-1/2 -z-10 h-[110%] w-[130%] -translate-x-1/2 -translate-y-1/2 rounded-full blur-[72px]" aria-hidden="true"></div>
        <div class="glow-violet pointer-events-none absolute -left-16 top-1/4 -z-10 h-2/3 w-3/4 rounded-full blur-[72px]" aria-hidden="true"></div>
    @elseif($light === 'center')
        <div class="glow-indigo pointer-events-none absolute left-1/2 top-1/2 -z-10 h-2/3 w-[60%] -translate-x-1/2 -translate-y-1/2 rounded-full blur-3xl" aria-hidden="true"></div>
    @endif

    {{ $slot }}
</div>
