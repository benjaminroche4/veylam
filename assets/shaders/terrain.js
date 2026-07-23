// GLSL sources for the homepage Three.js particle terrain
// (see assets/controllers/three_background_controller.js)

export const TRAIL_LENGTH = 12;
export const WAVE_COUNT = 4;

export const POINTS_VERTEX_SHADER = `
uniform float u_time;
uniform float u_intro;
uniform vec2 u_mouse;
uniform vec3 u_trail[${TRAIL_LENGTH}];
uniform vec4 u_waves[${WAVE_COUNT}];

attribute float a_random;

varying float v_elevation;
varying float v_halo;
varying float v_random;
varying float v_fog;
varying float v_screenY;

float hash(vec2 p) {
    return fract(sin(dot(p, vec2(127.1, 311.7))) * 43758.5453123);
}

float noise(vec2 p) {
    vec2 i = floor(p);
    vec2 f = fract(p);
    vec2 u = f * f * (3.0 - 2.0 * f);
    return mix(
        mix(hash(i), hash(i + vec2(1.0, 0.0)), u.x),
        mix(hash(i + vec2(0.0, 1.0)), hash(i + vec2(1.0, 1.0)), u.x),
        u.y
    );
}

float fbm(vec2 p) {
    float value = 0.0;
    float amplitude = 0.5;
    for (int i = 0; i < 3; i++) {
        value += amplitude * noise(p);
        p = p * 2.0 + 13.0;
        amplitude *= 0.5;
    }
    return value;
}

void main() {
    vec3 pos = position;

    float t = u_time * 0.12;

    // Slow global breathing so the scene never looks exactly the same (~20s cycle)
    float breathe = 0.85 + 0.15 * sin(u_time * 0.314);

    float elevation = fbm(pos.xz * 0.06 + vec2(t, -t * 0.6)) * 6.0 * breathe;

    // The cursor lifts the terrain around it. The effect fades out close to the
    // camera (high z) so foreground points never fill the screen
    float proximityFade = 1.0 - smoothstep(0.0, 30.0, pos.z);
    float d = distance(pos.xz, u_mouse);
    float halo = exp(-d * d * 0.0034) * proximityFade;
    elevation += halo * 1.2;

    // Fading memory of the cursor's path
    float trailGlow = 0.0;
    for (int i = 0; i < ${TRAIL_LENGTH}; i++) {
        vec3 tp = u_trail[i];
        float td = distance(pos.xz, tp.xy);
        trailGlow += exp(-td * td * 0.006) * tp.z;
    }
    trailGlow *= proximityFade;
    elevation += trailGlow * 0.7;

    // Expanding ripples (clicks + rare ambient waves)
    for (int i = 0; i < ${WAVE_COUNT}; i++) {
        vec4 w = u_waves[i];
        float age = u_time - w.z;
        if (age > 0.0 && age < 7.0) {
            float wd = distance(pos.xz, w.xy);
            float radius = age * 9.0;
            elevation += exp(-pow(wd - radius, 2.0) * 0.12)
                * sin((wd - radius) * 1.4)
                * w.w * exp(-age * 0.7) * proximityFade;
        }
    }

    // Intro: the terrain rises from flat
    elevation *= u_intro;

    pos.y = elevation;

    vec4 mvPosition = modelViewMatrix * vec4(pos, 1.0);
    gl_Position = projectionMatrix * mvPosition;

    // Point size stays stable (no halo/trail term): size pulsing reads as flicker.
    // Capped so foreground points stay discreet
    gl_PointSize = min((140.0 / -mvPosition.z) * (0.6 + a_random), 5.0);

    v_elevation = elevation / 8.5;
    v_halo = halo + trailGlow * 0.5;
    v_random = a_random;
    v_fog = 1.0 - exp(-0.0009 * mvPosition.z * mvPosition.z);
    v_screenY = gl_Position.y / gl_Position.w;
}
`;

export const POINTS_FRAGMENT_SHADER = `
varying float v_elevation;
varying float v_halo;
varying float v_random;
varying float v_fog;
varying float v_screenY;

void main() {
    // Soft anti-aliased edge: hard-cut sprites shimmer when the terrain moves slowly
    float r = length(gl_PointCoord - 0.5);
    float alpha = 1.0 - smoothstep(0.32, 0.5, r);

    // Points dissolve very slightly at the bottom edge of the screen
    alpha *= smoothstep(-1.0, -0.84, v_screenY);

    if (alpha < 0.01) {
        discard;
    }

    vec3 dark = vec3(0.13);
    // Cool grey-blue in the highlights: barely visible, but it signs the palette
    vec3 light = vec3(0.55, 0.58, 0.64);
    vec3 color = mix(dark, light, smoothstep(0.0, 1.0, v_elevation));
    color *= 0.75 + v_random * 0.5;
    // Cool-white spotlight under the cursor, kept subtle
    color += v_halo * vec3(0.30, 0.32, 0.37);

    // Manual exponential fog toward the background color
    color = mix(color, vec3(0.039), clamp(v_fog, 0.0, 1.0));

    gl_FragColor = vec4(color, alpha);
}
`;

export const SCREEN_VERTEX_SHADER = `
varying vec2 v_uv;
void main() {
    v_uv = uv;
    gl_Position = vec4(position.xy, 0.9999, 1.0);
}
`;

export const BACKGROUND_FRAGMENT_SHADER = `
uniform float u_time;
uniform vec2 u_resolution;

varying vec2 v_uv;

void main() {
    // Vertical gradient, top to bottom: near-black at the top, very slightly dark grey at the bottom.
    // The horizon light itself is a real additive sprite placed in the 3D scene (see controller).
    vec3 top = vec3(0.022, 0.022, 0.025);
    vec3 bottom = vec3(0.062, 0.064, 0.070);
    vec3 color = mix(top, bottom, smoothstep(1.0, 0.0, v_uv.y));

    gl_FragColor = vec4(color, 1.0);
}
`;

export const OVERLAY_FRAGMENT_SHADER = `
uniform float u_time;

varying vec2 v_uv;

float hash(vec2 p) {
    return fract(sin(dot(p, vec2(127.1, 311.7))) * 43758.5453123);
}

void main() {
    // Animated film grain + cinematic vignette, drawn over the whole scene
    float grain = hash(gl_FragCoord.xy + fract(u_time) * 100.0);
    // Vignette on the sides and top only: no darkening toward the bottom of the frame
    vec2 offset = v_uv - vec2(0.5);
    offset.y = max(offset.y, 0.0);
    float vignette = smoothstep(0.35, 0.85, length(offset));
    float alpha = clamp(vignette * 0.5 + (grain - 0.5) * 0.07, 0.0, 1.0);
    gl_FragColor = vec4(vec3(0.0), alpha);
}
`;
