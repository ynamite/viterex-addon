import { readFileSync } from "fs";
import { resolve } from "path";
import yaml from "js-yaml";
import { defineConfig } from "vite";

// Read version from package.yml (single source of truth)
function getVersionFromPackageYml() {
	try {
		const packageYmlPath = resolve(__dirname, "package.yml");
		const packageYmlContent = readFileSync(packageYmlPath, "utf8");
		const packageData = yaml.load(packageYmlContent);
		return packageData.version || "1.0.0";
	} catch (error) {
		console.warn("Warning: Could not read version from package.yml:", error.message);
		return "1.0.0";
	}
}

const ADDON_VERSION = getVersionFromPackageYml();
console.log(`🏷️  Building ViteRex Badge v${ADDON_VERSION}`);

export default defineConfig({
	root: "./assets-src",
	base: "./",
	build: {
		// Dedicated `badge/` subfolder so emptyOutDir: true doesn't nuke the
		// committed `viterex-vite-plugin.js` and `dev-server-index.html` files
		// that ship to user projects via Redaxo's addon-asset auto-copy.
		outDir: "../assets/badge",
		emptyOutDir: true,
		sourcemap: true,
		rollupOptions: {
			input: {
				"viterex-badge": resolve(__dirname, "assets-src/viterex-badge.js"),
			},
			output: {
				assetFileNames: "[name].[ext]",
				entryFileNames: "[name].js",
				chunkFileNames: "[name]-[hash].js",
			},
		},
		target: ["es2017", "chrome60", "firefox60", "safari11"],
	},
	css: {
		postcss: "./postcss.config.js",
	},
	server: {
		host: "localhost",
		port: 5173,
		open: false,
	},
	define: {
		__VERSION__: JSON.stringify(ADDON_VERSION),
	},
});
