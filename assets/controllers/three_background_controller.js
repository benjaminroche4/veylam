/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import {
    TRAIL_LENGTH,
    WAVE_COUNT,
    POINTS_VERTEX_SHADER,
    POINTS_FRAGMENT_SHADER,
    SCREEN_VERTEX_SHADER,
    BACKGROUND_FRAGMENT_SHADER,
    OVERLAY_FRAGMENT_SHADER,
} from '../shaders/terrain.js';

export default class extends Controller {
    static targets = ['canvas'];

    async connect() {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        const THREE = await import('three');
        if (!this.element.isConnected) {
            return;
        }
        this.THREE = THREE;

        this.isMobile = window.matchMedia('(pointer: coarse)').matches || window.innerWidth < 768;
        this.density = this.isMobile ? 90 : 150;

        this.renderer = new THREE.WebGLRenderer({ canvas: this.canvasTarget, antialias: false });
        this.renderer.setClearColor(0x0a0a0a, 1);

        this.scene = new THREE.Scene();

        this.camera = new THREE.PerspectiveCamera(55, 1, 0.1, 200);
        this.camera.position.set(0, 10, 40);
        this.camera.lookAt(0, 0, 0);

        this.trail = Array.from({ length: TRAIL_LENGTH }, () => new THREE.Vector3(0, 0, 0));
        this.waves = Array.from({ length: WAVE_COUNT }, () => new THREE.Vector4(0, 0, -100, 0));
        this.waveIndex = 0;

        this.uniforms = {
            u_time: { value: 0 },
            u_intro: { value: 0 },
            u_mouse: { value: new THREE.Vector2(0, 0) },
            u_resolution: { value: new THREE.Vector2(1, 1) },
            u_trail: { value: this.trail },
            u_waves: { value: this.waves },
        };

        this.#buildScene(THREE);

        this.mouse = { x: 0, y: 0 };
        this.smoothedMouse = { x: 0, y: 0 };
        this.lastTrailPoint = { x: 0, y: 0 };
        this.startedAt = performance.now();
        this.nextAmbientWaveAt = this.startedAt + 12000 + Math.random() * 10000;
        this.lastPointerAt = this.startedAt;
        this.lastClickAt = 0;
        this.visible = true;
        this.focused = true;
        this.contextLost = false;
        this.frameSkip = false;
        this.rafId = null;
        this.revealed = false;
        // Adaptive quality: sample real frame times early on, downgrade once if the GPU struggles
        this.perf = { lastAt: 0, samples: 0, total: 0, settled: false };

        this.#bindEvents();

        this.#resize();
        this.#loop();
    }

    disconnect() {
        if (this.rafId !== null) {
            cancelAnimationFrame(this.rafId);
            this.rafId = null;
        }
        window.removeEventListener('pointermove', this.onPointerMove);
        window.removeEventListener('pointerdown', this.onPointerDown);
        window.removeEventListener('deviceorientation', this.onOrientation);
        window.removeEventListener('blur', this.onBlur);
        window.removeEventListener('focus', this.onFocus);
        this.canvasTarget.removeEventListener('webglcontextlost', this.onContextLost);
        this.canvasTarget.removeEventListener('webglcontextrestored', this.onContextRestored);
        this.resizeObserver?.disconnect();
        this.intersectionObserver?.disconnect();

        for (const mesh of [this.points, this.backgroundMesh, this.overlayMesh]) {
            if (mesh) {
                mesh.geometry.dispose();
                mesh.material.dispose();
            }
        }
        this.glow?.material.dispose();
        this.glowTexture?.dispose();
        this.glow = null;
        this.glowTexture = null;
        if (this.renderer) {
            this.renderer.dispose();
            this.renderer.forceContextLoss();
        }
        this.renderer = null;
        this.scene = null;
        this.points = null;
        this.backgroundMesh = null;
        this.overlayMesh = null;
    }

    #buildScene(THREE) {
        // Background gradient + horizon glow + sky dust, behind everything
        this.backgroundMesh = new THREE.Mesh(
            new THREE.PlaneGeometry(2, 2),
            new THREE.ShaderMaterial({
                uniforms: { u_time: this.uniforms.u_time, u_resolution: this.uniforms.u_resolution },
                vertexShader: SCREEN_VERTEX_SHADER,
                fragmentShader: BACKGROUND_FRAGMENT_SHADER,
                depthTest: false,
                depthWrite: false,
            })
        );
        this.backgroundMesh.renderOrder = -1;
        this.backgroundMesh.frustumCulled = false;
        this.scene.add(this.backgroundMesh);

        // Horizon light: a real additive sprite living in the scene's depth, behind the
        // terrain. It shifts with the camera parallax, which a painted gradient cannot do.
        this.glowTexture = this.#buildGlowTexture(THREE);
        this.glow = new THREE.Sprite(new THREE.SpriteMaterial({
            map: this.glowTexture,
            color: 0xb9c1cd,
            blending: THREE.AdditiveBlending,
            transparent: true,
            opacity: 0.14,
            depthTest: false,
            depthWrite: false,
        }));
        this.glow.position.set(0, 2, -70);
        this.glow.scale.set(160, 34, 1);
        this.glow.renderOrder = 0;
        this.scene.add(this.glow);

        // Particle terrain
        const material = new THREE.ShaderMaterial({
            uniforms: this.uniforms,
            vertexShader: POINTS_VERTEX_SHADER,
            fragmentShader: POINTS_FRAGMENT_SHADER,
            transparent: true,
            depthWrite: false,
        });
        this.points = new THREE.Points(this.#buildGrid(THREE, this.density), material);
        this.points.renderOrder = 1;
        this.scene.add(this.points);

        // Film grain + vignette overlay, above everything
        this.overlayMesh = new THREE.Mesh(
            new THREE.PlaneGeometry(2, 2),
            new THREE.ShaderMaterial({
                uniforms: { u_time: this.uniforms.u_time },
                vertexShader: SCREEN_VERTEX_SHADER,
                fragmentShader: OVERLAY_FRAGMENT_SHADER,
                transparent: true,
                depthTest: false,
                depthWrite: false,
            })
        );
        this.overlayMesh.renderOrder = 10;
        this.overlayMesh.frustumCulled = false;
        this.scene.add(this.overlayMesh);
    }

    #bindEvents() {
        this.onPointerMove = (event) => {
            const rect = this.element.getBoundingClientRect();
            // Map the cursor to world XZ coordinates on the particle plane
            this.mouse.x = ((event.clientX - rect.left) / rect.width - 0.5) * 70;
            this.mouse.y = ((event.clientY - rect.top) / rect.height - 0.5) * 60;
            this.lastPointerAt = performance.now();
        };
        window.addEventListener('pointermove', this.onPointerMove, { passive: true });

        this.onPointerDown = (event) => {
            // Cooldown between clicks: spam-clicking floods the wave pool
            const now = performance.now();
            if (now - this.lastClickAt < 700) {
                return;
            }
            this.lastClickAt = now;

            const rect = this.element.getBoundingClientRect();
            const x = ((event.clientX - rect.left) / rect.width - 0.5) * 70;
            const z = ((event.clientY - rect.top) / rect.height - 0.5) * 60;
            this.#spawnWave(x, z, 1.1);
        };
        window.addEventListener('pointerdown', this.onPointerDown, { passive: true });

        // On touch devices the device tilt drives the halo (only where no permission prompt is needed)
        this.onOrientation = (event) => {
            if (event.gamma === null || event.beta === null) {
                return;
            }
            this.mouse.x = Math.max(-1, Math.min(1, event.gamma / 30)) * 30;
            this.mouse.y = Math.max(-1, Math.min(1, (event.beta - 45) / 30)) * 25;
        };
        if (this.isMobile && typeof DeviceOrientationEvent !== 'undefined' && typeof DeviceOrientationEvent.requestPermission !== 'function') {
            window.addEventListener('deviceorientation', this.onOrientation, { passive: true });
        }

        // Full pause when the window loses focus
        this.onBlur = () => {
            this.focused = false;
        };
        this.onFocus = () => {
            this.focused = true;
            if (this.rafId === null) {
                this.#loop();
            }
        };
        window.addEventListener('blur', this.onBlur);
        window.addEventListener('focus', this.onFocus);

        // GPU reset (sleep, driver crash, long-inactive tab): show the CSS fallback,
        // then resume rendering if the browser restores the context
        this.onContextLost = (event) => {
            event.preventDefault();
            this.contextLost = true;
            this.canvasTarget.classList.add('opacity-0');
        };
        this.onContextRestored = () => {
            this.contextLost = false;
            this.revealed = false;
            if (this.rafId === null) {
                this.#loop();
            }
        };
        this.canvasTarget.addEventListener('webglcontextlost', this.onContextLost);
        this.canvasTarget.addEventListener('webglcontextrestored', this.onContextRestored);

        this.resizeObserver = new ResizeObserver(() => this.#resize());
        this.resizeObserver.observe(this.element);

        this.intersectionObserver = new IntersectionObserver(([entry]) => {
            this.visible = entry.isIntersecting;
            if (this.visible && this.rafId === null) {
                this.#loop();
            }
        });
        this.intersectionObserver.observe(this.element);
    }

    #buildGlowTexture(THREE) {
        // Soft radial falloff drawn once on a canvas; multiple stops approximate a
        // gaussian so the glow has no visible edge
        const size = 256;
        const canvas = document.createElement('canvas');
        canvas.width = size;
        canvas.height = size;
        const ctx = canvas.getContext('2d');
        const gradient = ctx.createRadialGradient(size / 2, size / 2, 0, size / 2, size / 2, size / 2);
        gradient.addColorStop(0, 'rgba(255, 255, 255, 1)');
        gradient.addColorStop(0.35, 'rgba(255, 255, 255, 0.45)');
        gradient.addColorStop(0.65, 'rgba(255, 255, 255, 0.12)');
        gradient.addColorStop(1, 'rgba(255, 255, 255, 0)');
        ctx.fillStyle = gradient;
        ctx.fillRect(0, 0, size, size);
        return new THREE.CanvasTexture(canvas);
    }

    #buildGrid(THREE, density) {
        const size = 90;
        const count = density * density;
        const positions = new Float32Array(count * 3);
        const randoms = new Float32Array(count);
        let i = 0;
        for (let row = 0; row < density; row++) {
            for (let column = 0; column < density; column++) {
                positions[i++] = (column / (density - 1) - 0.5) * size;
                positions[i++] = 0;
                positions[i++] = (row / (density - 1) - 0.5) * size;
                randoms[row * density + column] = Math.random();
            }
        }
        const geometry = new THREE.BufferGeometry();
        geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
        geometry.setAttribute('a_random', new THREE.BufferAttribute(randoms, 1));
        return geometry;
    }

    #spawnWave(x, z, amplitude) {
        const wave = this.waves[this.waveIndex];
        wave.set(x, z, (performance.now() - this.startedAt) / 1000, amplitude);
        this.waveIndex = (this.waveIndex + 1) % WAVE_COUNT;
    }

    #resize() {
        if (!this.renderer) {
            return;
        }
        const dpr = Math.min(window.devicePixelRatio || 1, this.isMobile ? 1.25 : 1.5);
        const width = this.element.clientWidth;
        const height = this.element.clientHeight;
        this.renderer.setPixelRatio(dpr);
        this.renderer.setSize(width, height, false);
        this.uniforms.u_resolution.value.set(width * dpr, height * dpr);
        this.camera.aspect = width / Math.max(height, 1);
        this.camera.updateProjectionMatrix();
    }

    #samplePerformance(now) {
        if (this.perf.settled) {
            return;
        }
        const dt = this.perf.lastAt > 0 ? now - this.perf.lastAt : 0;
        this.perf.lastAt = now;

        // Skip warmup (shader compile) and frames interrupted by tab switches
        if (now - this.startedAt < 1000 || dt <= 0 || dt > 100) {
            return;
        }

        this.perf.samples++;
        this.perf.total += dt;
        if (this.perf.samples < 90) {
            return;
        }

        this.perf.settled = true;
        const average = this.perf.total / this.perf.samples;
        if (average > 22) {
            // Below ~45fps: rebuild the grid at a lower density, once
            const reduced = Math.round(this.density * 0.6);
            const geometry = this.#buildGrid(this.THREE, reduced);
            this.points.geometry.dispose();
            this.points.geometry = geometry;
            this.density = reduced;
        }
    }

    #loop() {
        if (!this.renderer || !this.visible || !this.focused || this.contextLost) {
            this.rafId = null;
            return;
        }

        const now = performance.now();

        // After 10s without pointer activity, drop to ~30fps (every other frame);
        // back to 60fps instantly on the next mouse move
        if (now - this.lastPointerAt > 10000) {
            this.frameSkip = !this.frameSkip;
            if (this.frameSkip) {
                this.rafId = requestAnimationFrame(() => this.#loop());
                return;
            }
        }

        this.#samplePerformance(now);

        const elapsed = (now - this.startedAt) / 1000;

        this.smoothedMouse.x += (this.mouse.x - this.smoothedMouse.x) * 0.06;
        this.smoothedMouse.y += (this.mouse.y - this.smoothedMouse.y) * 0.06;

        // Fading trail. The head (index 0) follows the cursor continuously; it is frozen
        // into the history once the cursor has moved far enough, and a new head takes over.
        for (const point of this.trail) {
            point.z *= 0.955;
        }
        const dx = this.smoothedMouse.x - this.lastTrailPoint.x;
        const dy = this.smoothedMouse.y - this.lastTrailPoint.y;
        if (dx * dx + dy * dy > 1.0) {
            const recycled = this.trail.pop();
            this.trail.unshift(recycled);
            this.lastTrailPoint.x = this.smoothedMouse.x;
            this.lastTrailPoint.y = this.smoothedMouse.y;
        }
        // The head crossfades in with distance from the last frozen point, so freezing
        // a point never doubles the elevation (that read as a periodic pulse)
        const hx = this.smoothedMouse.x - this.lastTrailPoint.x;
        const hy = this.smoothedMouse.y - this.lastTrailPoint.y;
        const headStrength = 0.55 * Math.min(Math.sqrt(hx * hx + hy * hy), 1);
        this.trail[0].set(this.smoothedMouse.x, this.smoothedMouse.y, headStrength);

        // Rare ambient wave crossing the terrain
        if (now >= this.nextAmbientWaveAt) {
            this.#spawnWave((Math.random() - 0.5) * 60, (Math.random() - 0.5) * 50, 0.8);
            this.nextAmbientWaveAt = now + 15000 + Math.random() * 15000;
        }

        // Intro: terrain rises while the camera eases in (~2.5s, ease-out cubic)
        const introProgress = Math.min(elapsed / 2.5, 1);
        const eased = 1 - Math.pow(1 - introProgress, 3);
        this.uniforms.u_intro.value = eased;

        // Camera: intro dolly + subtle parallax toward the cursor, with inertia
        const targetX = this.smoothedMouse.x * 0.04;
        const targetY = 10 - this.smoothedMouse.y * 0.03;
        this.camera.position.x += (targetX - this.camera.position.x) * 0.04;
        this.camera.position.y += (targetY - this.camera.position.y) * 0.04;
        this.camera.position.z = 40 - 8 * eased;
        this.camera.lookAt(0, 0, 0);

        this.uniforms.u_time.value = elapsed;
        this.uniforms.u_mouse.value.set(this.smoothedMouse.x, this.smoothedMouse.y);

        // The horizon light breathes very slowly, and rises with the intro
        this.glow.material.opacity = 0.14 * eased * (0.88 + 0.12 * Math.sin(elapsed * 0.08));

        this.renderer.render(this.scene, this.camera);

        if (!this.revealed) {
            this.revealed = true;
            this.canvasTarget.classList.remove('opacity-0');
        }

        this.rafId = requestAnimationFrame(() => this.#loop());
    }
}
