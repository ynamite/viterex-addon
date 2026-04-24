import fs from "node:fs";

export default function hotFilePlugin({ hotFilePath }) {
	return {
		name: "viterex-hot-file",
		configureServer(server) {
			server.httpServer?.once("listening", () => {
				const address = server.httpServer.address();
				if (!address || typeof address === "string") {
					return;
				}
				const host = address.address === "::" || address.address === "0.0.0.0" ? "localhost" : address.address;
				const protocol = server.config.server.https ? "https" : "http";
				fs.writeFileSync(hotFilePath, `${protocol}://${host}:${address.port}`);
			});
			const clean = () => {
				if (fs.existsSync(hotFilePath)) {
					fs.unlinkSync(hotFilePath);
				}
			};
			process.on("exit", clean);
			process.on("SIGINT", () => {
				clean();
				process.exit();
			});
			process.on("SIGTERM", () => {
				clean();
				process.exit();
			});
		},
	};
}
