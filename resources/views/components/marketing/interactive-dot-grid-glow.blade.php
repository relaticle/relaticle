@props([
    'spacing' => 20,
    'dotOffset' => 2,
    'dotSize' => 1.2,
    'radius' => 140,
    'maxScale' => 1.4,
])

{{--
    Draws ONLY the mouse-proximity glow highlight on top of the existing static
    SVG dot pattern in this partial — it never redraws the base dots themselves,
    so the original grid (tile size, dot position/size, opacity, color) stays
    pixel-identical to the pre-existing design. Geometry here (spacing/dotOffset/
    dotSize) must match the sibling <pattern> tile (x/y/width/height, circle
    cx/cy/r) exactly so glow highlights land on the real dots underneath.
--}}
<div
    x-data="dotGridGlow({ spacing: {{ $spacing }}, dotOffset: {{ $dotOffset }}, dotSize: {{ $dotSize }}, radius: {{ $radius }}, maxScale: {{ $maxScale }} })"
    x-init="init()"
    class="absolute inset-0"
    aria-hidden="true"
>
    <canvas x-ref="canvas" class="absolute inset-0 w-full h-full"></canvas>
</div>

<script>
    function dotGridGlow(config) {
        return {
            spacing: config.spacing,
            dotOffset: config.dotOffset,
            baseDotSize: config.dotSize,
            radius: config.radius,
            maxScale: config.maxScale,
            reducedMotion: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
            isTouch: window.matchMedia('(hover: none), (pointer: coarse)').matches,
            ctx: null,
            dpr: 1,
            width: 0,
            height: 0,
            dots: [],
            pointer: { x: -9999, y: -9999, active: false },
            rafId: null,
            glowColor: null,

            init() {
                this.ctx = this.$refs.canvas.getContext('2d');
                this.readColor();
                this.resize();

                new ResizeObserver(() => this.resize()).observe(this.$root);

                if (!this.isTouch) {
                    this.$root.style.pointerEvents = 'auto';
                    this.$el.addEventListener('mousemove', (e) => this.onPointerMove(e));
                    this.$el.addEventListener('mouseleave', () => this.onPointerLeave());
                } else {
                    this.$root.style.pointerEvents = 'auto';
                    this.$el.addEventListener('touchstart', (e) => this.onTouch(e), { passive: true });
                    this.$el.addEventListener('touchmove', (e) => this.onTouch(e), { passive: true });
                    this.$el.addEventListener('touchend', () => this.onPointerLeave());
                }

                new MutationObserver(() => this.readColor()).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

                this.loop();
            },

            readColor() {
                var styles = getComputedStyle(document.documentElement);
                var primary = styles.getPropertyValue('--color-primary').trim();
                this.glowColor = this.resolveColor(primary) || [124, 58, 237];
            },

            resolveColor(value) {
                if (!value) return null;
                var probe = document.createElement('div');
                probe.style.color = value;
                document.body.appendChild(probe);
                var rgb = getComputedStyle(probe).color.match(/\d+(\.\d+)?/g);
                document.body.removeChild(probe);
                return rgb ? rgb.slice(0, 3).map(Number) : null;
            },

            resize() {
                var rect = this.$root.getBoundingClientRect();
                this.dpr = Math.min(window.devicePixelRatio || 1, 2);
                this.width = rect.width;
                this.height = rect.height;
                this.$refs.canvas.width = this.width * this.dpr;
                this.$refs.canvas.height = this.height * this.dpr;
                this.ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0);
                // Anchor the grid phase to this block's position on the page so the
                // glow dots land exactly on top of the sibling SVG pattern's dots,
                // which tile from the SAME shared coordinate space (userSpaceOnUse
                // starts at each block's own 0,0 — matching offsetLeft/offsetTop here).
                this.buildGrid();
            },

            buildGrid() {
                this.dots = [];
                var cols = Math.ceil(this.width / this.spacing) + 1;
                var rows = Math.ceil(this.height / this.spacing) + 1;
                for (var row = 0; row < rows; row++) {
                    for (var col = 0; col < cols; col++) {
                        this.dots.push({
                            x: col * this.spacing + this.dotOffset,
                            y: row * this.spacing + this.dotOffset,
                            scale: 1,
                            targetScale: 1,
                            glow: 0,
                            targetGlow: 0,
                        });
                    }
                }
            },

            onPointerMove(e) {
                var rect = this.$root.getBoundingClientRect();
                this.pointer.x = e.clientX - rect.left;
                this.pointer.y = e.clientY - rect.top;
                this.pointer.active = true;
            },

            onTouch(e) {
                if (!e.touches || !e.touches.length) return;
                var rect = this.$root.getBoundingClientRect();
                this.pointer.x = e.touches[0].clientX - rect.left;
                this.pointer.y = e.touches[0].clientY - rect.top;
                this.pointer.active = true;
            },

            onPointerLeave() {
                this.pointer.active = false;
            },

            loop() {
                this.update();
                this.draw();
                this.rafId = requestAnimationFrame(() => this.loop());
            },

            update() {
                var px = this.pointer.x, py = this.pointer.y;
                var active = this.pointer.active;
                var radius = this.radius;
                var lerpSpeed = this.reducedMotion ? 1 : 0.18;

                for (var i = 0; i < this.dots.length; i++) {
                    var dot = this.dots[i];

                    if (active) {
                        var dx = dot.x - px;
                        var dy = dot.y - py;
                        var dist = Math.sqrt(dx * dx + dy * dy);
                        var falloff = Math.max(0, 1 - dist / radius);
                        falloff = falloff * falloff;
                        dot.targetGlow = falloff;
                        dot.targetScale = 1 + (this.maxScale - 1) * falloff;
                    } else {
                        dot.targetGlow = 0;
                        dot.targetScale = 1;
                    }

                    dot.glow += (dot.targetGlow - dot.glow) * lerpSpeed;
                    dot.scale += (dot.targetScale - dot.scale) * lerpSpeed;
                }
            },

            draw() {
                var ctx = this.ctx;
                ctx.clearRect(0, 0, this.width, this.height);

                for (var i = 0; i < this.dots.length; i++) {
                    var dot = this.dots[i];
                    if (dot.glow < 0.01) continue;

                    var r = this.baseDotSize * dot.scale;
                    var alpha = dot.glow * 0.85;

                    ctx.beginPath();
                    ctx.arc(dot.x, dot.y, r, 0, Math.PI * 2);
                    ctx.fillStyle = 'rgba(' + this.glowColor[0] + ',' + this.glowColor[1] + ',' + this.glowColor[2] + ',' + alpha.toFixed(3) + ')';
                    ctx.fill();
                }
            },

            destroy() {
                if (this.rafId) cancelAnimationFrame(this.rafId);
            },
        };
    }
</script>
