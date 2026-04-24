import fs from "node:fs";
import path from "node:path";
import { defineConfig, loadEnv, mergeConfig } from "vite";
import liveReload from "vite-plugin-live-reload";
import hotFilePlugin from "./hotfile-plugin.js";

const STRUCTURE_JSON_REL = "redaxo/data/addons/viterex/structure.json";

function loadStructure(cwd) {
	const structurePath = path.resolve(cwd, STRUCTURE_JSON_REL);
	if (fs.existsSync(structurePath)) {
		try {
			return JSON.parse(fs.readFileSync(structurePath, "utf8"));
		} catch (error) {
			console.warn(`[viterex] Could not parse ${STRUCTURE_JSON_REL}: ${error.message}`);
		}
	}
	return fallbackStructure(cwd);
}

function fallbackStructure(cwd) {
	if (fs.existsSync(path.resolve(cwd, "public/index.php"))) {
		const publicFs = path.resolve(cwd, "public") + "/";
		return {
			structure: "modern",
			publicFsPath: publicFs,
			buildFsPath: path.resolve(cwd, "public/assets/addons/viterex"),
			buildUrlPath: "/assets/addons/viterex",
			hotFilePath: path.resolve(publicFs, ".hot"),
		};
	}
	if (fs.existsSync(path.resolve(cwd, "theme/public"))) {
		const publicFs = path.resolve(cwd, "theme/public") + "/";
		return {
			structure: "theme",
			publicFsPath: publicFs,
			buildFsPath: path.resolve(cwd, "theme/public/assets/addons/viterex"),
			buildUrlPath: "/assets/addons/viterex",
			hotFilePath: path.resolve(publicFs, ".hot"),
		};
	}
	return {
		structure: "classic",
		publicFsPath: cwd + "/",
		buildFsPath: path.resolve(cwd, "assets/addons/viterex"),
		buildUrlPath: "/assets/addons/viterex",
		hotFilePath: path.resolve(cwd, ".hot"),
	};
}

function defaultEntryFor(structureName) {
	switch (structureName) {
		case "classic":
			return "assets/js/Main.js";
		case "theme":
			return "theme/src/assets/js/Main.js";
		default:
			return "src/assets/js/Main.js";
	}
}

function liveReloadGlobs(cwd, structureName) {
	const mediaExt = "{svg,png,jpg,jpeg,webp,avif,gif,woff,woff2}";
	switch (structureName) {
		case "modern":
			return [
				`${cwd}/src/templates/**/*.php`,
				`${cwd}/src/modules/**/*.php`,
				`${cwd}/src/addons/**/fragments/**/*.php`,
				`${cwd}/src/addons/**/lib/**/*.php`,
				`${cwd}/src/assets/**/*.${mediaExt}`,
				`${cwd}/var/cache/addons/{structure,url}/**`,
			];
		case "theme":
			return [
				`${cwd}/theme/private/templates/**/*.php`,
				`${cwd}/theme/private/modules/**/*.php`,
				`${cwd}/theme/private/fragments/**/*.php`,
				`${cwd}/theme/src/assets/**/*.${mediaExt}`,
				`${cwd}/redaxo/var/cache/addons/{structure,url}/**`,
			];
		default:
			return [
				`${cwd}/assets/**/*.${mediaExt}`,
				`${cwd}/redaxo/data/addons/structure/**`,
				`${cwd}/redaxo/var/cache/addons/{structure,url}/**`,
			];
	}
}

function resolveHttps(cwd, env) {
	if (env.VITE_HTTPS !== "true") {
		return null;
	}
	const key = path.resolve(cwd, "localhost+2-key.pem");
	const cert = path.resolve(cwd, "localhost+2.pem");
	if (!fs.existsSync(key) || !fs.existsSync(cert)) {
		console.warn("[viterex] VITE_HTTPS=true but cert files missing; falling back to http. Run: mkcert localhost 127.0.0.1 ::1");
		return null;
	}
	return { key: fs.readFileSync(key), cert: fs.readFileSync(cert) };
}

export function defineViterexConfig(userConfig = {}) {
	return defineConfig(({ mode }) => {
		const cwd = process.cwd();
		const env = loadEnv(mode, cwd, "");
		const structure = loadStructure(cwd);
		const entry = env.VITE_ENTRY_POINT
			? env.VITE_ENTRY_POINT.replace(/^\//, "")
			: defaultEntryFor(structure.structure);
		const https = resolveHttps(cwd, env);

		const baseConfig = {
			plugins: [
				hotFilePlugin({ hotFilePath: structure.hotFilePath }),
				liveReload(liveReloadGlobs(cwd, structure.structure), { alwaysReload: true }),
			],
			resolve: {
				alias: [{ find: "@", replacement: path.resolve(cwd, path.dirname(entry)) }],
			},
			css: {
				transformer: "lightningcss",
			},
			build: {
				outDir: structure.buildFsPath,
				assetsDir: "",
				emptyOutDir: true,
				manifest: true,
				cssMinify: "lightningcss",
				rollupOptions: {
					input: [path.resolve(cwd, entry)],
				},
			},
			server: {
				host: "127.0.0.1",
				port: Number(env.VITE_DEV_SERVER_PORT) || 5173,
				...(https ? { https } : {}),
			},
		};

		return mergeConfig(baseConfig, userConfig);
	});
}
