{{-- resources/views/home/partials/hero-demo-modal.blade.php --}}
<div
    x-data="{ open: false }"
    @open-hero-demo.window="open = true; $nextTick(() => $refs.video && $refs.video.play())"
    @keydown.escape.window="open = false; $refs.video && $refs.video.pause()"
    x-cloak
    id="hero-demo-modal"
>
    <div
        x-show="open"
        x-transition.opacity
        @click.self="open = false; $refs.video && $refs.video.pause()"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm p-4"
    >
        <div class="relative w-full max-w-4xl aspect-video rounded-xl overflow-hidden bg-black shadow-2xl">
            <button
                type="button"
                @click="open = false; $refs.video && $refs.video.pause()"
                class="absolute top-3 right-3 z-10 inline-flex h-8 w-8 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20 transition"
                aria-label="Close demo"
            >
                <x-ri-close-line class="w-4 h-4"/>
            </button>
            <video
                x-ref="video"
                class="w-full h-full"
                src="{{ asset('videos/hero-demo.mp4') }}"
                preload="none"
                playsinline
                controls
            ></video>
        </div>
    </div>
</div>
