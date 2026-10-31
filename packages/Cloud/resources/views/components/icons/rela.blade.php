@props([])

{{-- Rela nav icon. The Relaticle logomark (hub + three satellites) standing
     upright as isometric line art alongside its neighbours: the 2D mark on a
     vertical plane of the set's 2:1 projection, extruded, hidden lines
     removed. Satellites are re-placed at fixed edge gaps from an enlarged hub
     so the mark keeps the 2D logo's hub-dominant hierarchy through the
     projection's foreshortening; stroke-width 2.2 (not the set's 2.5) matches
     the neighbours' rendered line weight, since this viewBox is shorter than
     theirs. Color via currentColor, size via {{ $attributes }}. --}}

<svg viewBox="0 0 82.3 100" fill="none" xmlns="http://www.w3.org/2000/svg" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" {{ $attributes }}>
    <path d="M49.9 72.31A24.08 14.88 -121.7 1 0 24.59 31.35A24.08 14.88 -121.7 1 0 49.9 72.31Z" stroke="currentColor" stroke-linejoin="round"/>
    <path d="M18.68 28.85A12.75 7.88 -121.7 1 0 5.28 7.16A12.75 7.88 -121.7 1 0 18.68 28.85Z" stroke="currentColor" stroke-linejoin="round"/>
    <path d="M69.84 55.12A9.91 6.13 -121.7 1 0 59.41 38.25A9.91 6.13 -121.7 1 0 69.84 55.12Z" stroke="currentColor" stroke-linejoin="round"/>
    <path d="M67.86 96.65A14.16 8.75 -121.7 1 0 52.97 72.56A14.16 8.75 -121.7 1 0 67.86 96.65Z" stroke="currentColor" stroke-linejoin="round"/>
    <path d="M16.28 29.63L20.78 35.67" stroke="currentColor" stroke-linejoin="round"/>
    <path d="M25.83 30.68L21.32 24.64" stroke="currentColor" stroke-linejoin="round"/>
    <path d="M57.23 42.56L49.9 43.94" stroke="currentColor" stroke-linejoin="round"/>
    <path d="M54.3 54.14L61.63 52.76" stroke="currentColor" stroke-linejoin="round"/>
    <path d="M55.58 71.7L53.4 68.61" stroke="currentColor" stroke-linejoin="round"/>
    <path d="M48.07 73.22L50.25 76.3" stroke="currentColor" stroke-linejoin="round"/>
    <path d="M68.27 96.47L75.41 92.89L76.97 91.62L77.87 90.22L78.51 88.33L78.74 86.35L78.63 84.18L78.01 81.27L76.87 78.33L75.28 75.52L73.33 72.97L70.94 70.7L68.39 69.02L65.82 68.04L63.4 67.83L61.18 64.68L62.18 62.31L62.75 59.72L62.95 56.88L62.75 53.82" stroke="currentColor" stroke-linejoin="round"/>
    <path d="M70.24 54.93L77.64 51.22L78.49 50.5L79.14 49.54L79.73 47.51L79.7 45.08L79.09 42.59L77.89 39.98L76.21 37.61L74.22 35.69L72.06 34.37L70.06 33.8L68.84 33.79L67.75 34.08L60.42 37.73" stroke="currentColor" stroke-linejoin="round"/>
    <path d="M57.77 40.02L54.75 35.96L52.17 33.13L49.11 30.47L45.93 28.38L42.71 26.92L39.29 26.1L36.35 26.09L33.65 26.8L29.09 20.7L29.25 18.5L28.93 15.94L28.14 13.29L26.9 10.68L24.25 7L22.68 5.47L21.01 4.22L17.93 2.79L16.3 2.51L14.91 2.58L13.53 3.02L6.46 6.55" stroke="currentColor" stroke-linejoin="round"/>
</svg>
