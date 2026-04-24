import fs from "node:fs";
import path from "node:path";
import { defineConfig, loadEnv, mergeConfig } from "vite";
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
		const entry = env.VITE_ENTRY_POINT ? env.VITE_ENTRY_POINT.replace(/^\//, "") : "src/Main.js";
		const https = resolveHttps(cwd, env);

		const baseConfig = {
			plugins: [hotFilePlugin({ hotFilePath: structure.hotFilePath })],
			resolve: {
				alias: [{ find: "@", replacement: path.resolve(cwd, "src") }],
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
