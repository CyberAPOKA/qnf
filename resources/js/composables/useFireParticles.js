import { onUnmounted } from 'vue';

const COLORS = ['#ff3b00', '#ff6a00', '#ff9500', '#ffcc00', '#ffee00', '#fff2a0'];

function rand(min, max) {
    return Math.random() * (max - min) + min;
}

function createParticle(w, h) {
    return {
        x: rand(0, w),
        y: h + rand(0, 10),
        vx: rand(-0.3, 0.3),
        vy: rand(-0.2, -0.8),
        r: rand(1.5, 4),
        opacity: rand(0.5, 1),
        color: COLORS[Math.floor(rand(0, COLORS.length))],
        life: 0,
        maxLife: rand(100, 280),
        drift: rand(-0.015, 0.015),
    };
}

export function useFireParticles() {
    let canvasInstances = [];
    let animFrameId = null;

    function animate() {
        for (const inst of canvasInstances) {
            const { ctx, particles, w, h } = inst;
            ctx.clearRect(0, 0, w, h);

            for (let i = particles.length - 1; i >= 0; i--) {
                const p = particles[i];
                p.life++;
                p.x += p.vx + Math.sin(p.life * 0.05) * p.drift * 10;
                p.y += p.vy;
                p.vx += p.drift;

                const lifeRatio = p.life / p.maxLife;
                const alpha = lifeRatio < 0.1
                    ? p.opacity * (lifeRatio / 0.1)
                    : p.opacity * (1 - lifeRatio);

                if (p.life >= p.maxLife || alpha <= 0) {
                    particles[i] = createParticle(w, h);
                    continue;
                }

                const shrink = 1 - lifeRatio * 0.5;

                ctx.beginPath();
                ctx.arc(p.x, p.y, p.r * shrink, 0, Math.PI * 2);
                ctx.fillStyle = p.color;
                ctx.globalAlpha = Math.max(0, alpha);
                ctx.fill();

                // Glow
                ctx.beginPath();
                ctx.arc(p.x, p.y, p.r * shrink * 2.5, 0, Math.PI * 2);
                ctx.fillStyle = p.color;
                ctx.globalAlpha = Math.max(0, alpha * 0.15);
                ctx.fill();
            }

            ctx.globalAlpha = 1;
        }

        animFrameId = requestAnimationFrame(animate);
    }

    /**
     * Attach fire particles to elements matching a selector inside a wrapper.
     * @param {HTMLElement} wrapper - The positioned parent
     * @param {string} selector - CSS selector for target elements
     * @param {number} particleCount - Number of particles per element
     */
    function init(wrapper, selector, particleCount = 30) {
        destroy();
        if (!wrapper) return;

        const elements = wrapper.querySelectorAll(selector);
        if (!elements.length) return;

        const wrapperRect = wrapper.getBoundingClientRect();

        elements.forEach((el) => {
            const rect = el.getBoundingClientRect();
            const canvas = document.createElement('canvas');
            const dpr = window.devicePixelRatio || 1;
            const w = rect.width;
            const h = rect.height;

            canvas.width = w * dpr;
            canvas.height = h * dpr;
            canvas.style.cssText = `
                position: absolute;
                top: ${rect.top - wrapperRect.top}px;
                left: ${rect.left - wrapperRect.left}px;
                width: ${w}px;
                height: ${h}px;
                pointer-events: none;
                z-index: 4;
            `;

            const ctx = canvas.getContext('2d');
            ctx.scale(dpr, dpr);

            wrapper.appendChild(canvas);

            const particles = [];
            for (let j = 0; j < particleCount; j++) {
                const p = createParticle(w, h);
                p.life = rand(0, p.maxLife);
                particles.push(p);
            }

            canvasInstances.push({ canvas, ctx, particles, w, h });
        });

        animate();
    }

    function destroy() {
        if (animFrameId) {
            cancelAnimationFrame(animFrameId);
            animFrameId = null;
        }
        for (const { canvas } of canvasInstances) {
            canvas.remove();
        }
        canvasInstances = [];
    }

    onUnmounted(destroy);

    return { init, destroy };
}
