/**
 * ViteRex — Vite plugin (Laravel-vite-plugin-style).
 *
 * Shipped INSIDE the addon at `viterex-addon/assets/viterex-vite-plugin.js`.
 * Redaxo's package manager auto-copies the addon's `assets/` tree to
 * `<frontend>/assets/addons/viterex/` on every (re)install — so this file
 * lands at the location the user's `vite.config.js` imports from.
 *
 * Default usage (scaffolded vite.config.js):
 *
 *   import { defineConfig } from "vite";
 *   import tailwindcss from "@tailwindcss/vite";
 *   import viterex, { fixTailwindFullReload } from "./public/assets/addons/viterex/viterex-vite-plugin.js";
 *
 *   export default defineConfig({
 *     plugins: [tailwindcss(), fixTailwindFullReload(), viterex()],
 *   });
 *
 * Customizing:
 *
 *   viterex({ input: ["src/admin/main.js"] })       // override entries
 *   viterex({ refresh: ["src/templates/**\/*.php"] }) // narrower live-reload
 *   viterex({ refresh: false })                      // disable live-reload
 *   viterex({ detectTls: false })                    // skip mkcert auto-detect
 *   viterex({ injectConfig: false })                 // ESCAPE HATCH: keep only hot-file
 *
 * Anything you put in your own `defineConfig({...})` wins via Vite's mergeConfig.
 */
import fs from "node:fs";
import path from "node:path";
import browserslist from "browserslist";
import { browserslistToTargets } from "lightningcss";
import liveReload from "vite-plugin-live-reload";
import { viteStaticCopy } from "vite-plugin-static-copy";

const STRUCTURE_PATH_CANDIDATES = [
	"var/data/addons/viterex/structure.json", // modern (ydeploy)
	"redaxo/data/addons/viterex/structure.json", // classic, theme
];

let exitHandlersBound = false;

function loadStructureJson(cwd = process.cwd()) {
	for (const rel of STRUCTURE_PATH_CANDIDATES) {
		const abs = path.resolve(cwd, rel);
		if (fs.existsSync(abs)) {
			try {
				return JSON.parse(fs.readFileSync(abs, "utf8"));
			} catch (e) {
				throw new Error(`[viterex] Failed to parse ${rel}: ${e.message}`);
			}
		}
	}
	throw new Error(
		"[viterex] structure.json not found at var/data/addons/viterex/ or redaxo/data/addons/viterex/. " +
			"Open the Redaxo backend → AddOns → ViteRex → Settings, save the form to seed it.",
	);
}

function detectTlsCerts(cwd) {
	const key = path.resolve(cwd, "localhost+2-key.pem");
	const cert = path.resolve(cwd, "localhost+2.pem");
	if (fs.existsSync(key) && fs.existsSync(cert)) {
		return { key: fs.readFileSync(key), cert: fs.readFileSync(cert) };
	}
	return null;
}

function resolveCopyTargets(structure) {
	const dirs = (structure.copy_dirs || "img")
		.split(",")
		.map((d) => d.trim())
		.filter(Boolean);
	const sourceRel = (structure.assets_source_dir || "src/assets").replace(/^\/+|\/+$/g, "");
	return dirs.map((dir) => ({
		src: `${sourceRel}/${dir}/*`,
		dest: `${structure.assets_sub_dir}/${dir}`,
	}));
}

function hotFilePlugin(hotFilePath) {
	return {
		name: "viterex:hot-file",
		configureServer(server) {
			server.httpServer?.once("listening", () => {
				const address = server.httpServer.address();
				if (!address || typeof address === "string") return;
				const host = address.address === "::" || address.address === "0.0.0.0" ? "localhost" : address.address;
				const protocol = server.config.server.https ? "https" : "http";
				fs.writeFileSync(hotFilePath, `${protocol}://${host}:${address.port}`);
			});

			if (!exitHandlersBound) {
				const clean = () => {
					if (fs.existsSync(hotFilePath)) fs.rmSync(hotFilePath);
				};
				process.on("exit", clean);
				process.on("SIGINT", () => process.exit());
				process.on("SIGTERM", () => process.exit());
				process.on("SIGHUP", () => process.exit());
				exitHandlersBound = true;
			}
		},
	};
}

/**
 * Main plugin entry. Returns an array of Vite plugins.
 *
 * @param {object} [options]
 * @param {string[]} [options.input] - Override entry points (default: [css_entry, js_entry] from structure.json).
 * @param {boolean|string[]} [options.refresh=true] - Live-reload globs. true = use refresh_globs from structure.json; false = disabled; array = override.
 * @param {boolean} [options.detectTls=true] - Auto-detect mkcert localhost+2 certs at project root when https_enabled is on.
 * @param {boolean} [options.injectConfig=true] - Inject build/server/css/resolve via Vite's config() hook. Set false to keep ONLY side effects (hot file).
 */
export default function viterex(options = {}) {
	const { input, refresh = true, detectTls = true, injectConfig = true } = options;
	const structure = loadStructureJson();
	const cwd = process.cwd();

	// All structure.json paths are relative to project root; resolve to absolutes here.
	const hotFileFs = path.resolve(cwd, structure.hot_file || ".vite-hot");
	const outDirFs = path.resolve(cwd, structure.out_dir || "public/dist");
	const assetsSourceFs = path.resolve(cwd, structure.assets_source_dir || "src/assets");

	const resolvedInputs = input
		? input.map((p) => path.resolve(cwd, p))
		: [path.resolve(cwd, structure.css_entry), path.resolve(cwd, structure.js_entry)];

	let refreshGlobs = null;
	if (refresh === true) {
		refreshGlobs = structure.refresh_globs
			? structure.refresh_globs
					.split("\n")
					.map((g) => g.trim())
					.filter(Boolean)
			: [];
	} else if (Array.isArray(refresh)) {
		refreshGlobs = refresh;
	}

	const https = detectTls && structure.https_enabled === true ? detectTlsCerts(cwd) : null;

	const plugins = [hotFilePlugin(hotFileFs)];

	if (injectConfig) {
		const buildUrlPath = "/" + (structure.build_url_path || "/dist").replace(/^\/+|\/+$/g, "");

		plugins.push({
			name: "viterex",
			config(userConfig, { mode }) {
				return {
					publicDir: userConfig.publicDir ?? false,
					base: userConfig.base ?? (mode === "production" ? `${buildUrlPath}/` : "/"),
					css: {
						transformer: userConfig.css?.transformer ?? "lightningcss",
						lightningcss: userConfig.css?.lightningcss ?? {
							targets: browserslistToTargets(browserslist()),
						},
					},
					build: {
						outDir: userConfig.build?.outDir ?? outDirFs,
						assetsDir: userConfig.build?.assetsDir ?? structure.assets_sub_dir,
						emptyOutDir: userConfig.build?.emptyOutDir ?? true,
						manifest: userConfig.build?.manifest ?? true,
						cssMinify: userConfig.build?.cssMinify ?? "lightningcss",
						checks: { pluginTimings: false },
						rollupOptions: {
							input: userConfig.build?.rollupOptions?.input ?? resolvedInputs,
						},
					},
					server: {
						host: userConfig.server?.host ?? "127.0.0.1",
						cors: userConfig.server?.cors ?? { origin: structure.host_url },
						...(https ? { https: userConfig.server?.https ?? https } : {}),
					},
					resolve: {
						alias: userConfig.resolve?.alias ?? [{ find: "@", replacement: assetsSourceFs }],
					},
				};
			},
		});

		const copyTargets = resolveCopyTargets(structure);
		if (copyTargets.length > 0) {
			plugins.push(viteStaticCopy({ targets: copyTargets }));
		}
	}

	if (refreshGlobs && refreshGlobs.length > 0) {
		plugins.push(liveReload(refreshGlobs, { alwaysReload: true }));
	}

	return plugins;
}

/**
 * Tailwind 4 workaround for tailwindlabs/tailwindcss#19670.
 * Removes @tailwindcss/vite:generate:serve plugin's hotUpdate hook so a real
 * full-reload occurs instead of the partial that breaks CSS imports.
 *
 * Use only when you have @tailwindcss/vite in your plugins array.
 */
export function fixTailwindFullReload() {
	return {
		name: "fix-tailwind-full-reload",
		configResolved(config) {
			const plugin = config.plugins.find((p) => p.name === "@tailwindcss/vite:generate:serve");
			delete plugin?.hotUpdate;
		},
	};
}
