import "./ViteRexBadge.module.css";
import classes from "./ViteRexBadge.module.css";

async function copyToClipboard(text) {
	if (navigator.clipboard?.writeText) {
		try {
			await navigator.clipboard.writeText(text);
			return true;
		} catch {
			// fall through to legacy fallback (Clipboard API requires secure context)
		}
	}
	const ta = document.createElement("textarea");
	ta.value = text;
	ta.setAttribute("readonly", "");
	ta.style.position = "absolute";
	ta.style.left = "-9999px";
	document.body.appendChild(ta);
	ta.select();
	let ok = false;
	try {
		ok = document.execCommand("copy");
	} catch {
		ok = false;
	}
	document.body.removeChild(ta);
	return ok;
}

const scriptTag = document.getElementById("viterex-badge-script");

if (!scriptTag) {
	console.warn('ViteRexBadge: No script tag with ID "viterex-badge-script" found.');
} else {
	const version = scriptTag.getAttribute("data-version") || "unknown";
	const rexVersion = scriptTag.getAttribute("data-rex-version") || "unknown";
	const gitBranch = scriptTag.getAttribute("data-git-branch") || "n/a";
	const stage = scriptTag.getAttribute("data-stage") || "dev";
	const viteRunning = scriptTag.getAttribute("data-vite-running") === "true";
	const viteUrl = scriptTag.getAttribute("data-vite-url") || "";
	const csrfToken = scriptTag.getAttribute("data-csrf-token") || "";

	console.log(`ViteRex v${version} | stage: ${stage} | vite: ${viteRunning ? viteUrl : "off"}`);

	const stageClass = classes[stage] || classes.dev;
	const branchSafe = gitBranch !== "main" && gitBranch !== "master";

	const extrasTemplate = document.getElementById("viterex-badge-extras");
	const extrasHtml = extrasTemplate ? extrasTemplate.innerHTML : "";

	const viteCell = viteRunning
		? `<button type="button" class="${classes.viteUrl}" data-url="${viteUrl}" title="Click to copy">${viteUrl}</button>`
		: `<span class="${classes.viteOff}">vite off</span>`;

	const badge = document.createElement("div");
	badge.id = "viterex-badge";
	badge.className = [classes.wrapper, stageClass, branchSafe ? classes.branchAlert : ""]
		.filter(Boolean)
		.join(" ");
	badge.title = `ViteRex • ${stage} • vite ${viteRunning ? "running" : "off"}`;

	badge.innerHTML = `
		<div class="${classes.badge}">
			<div class="${classes.label}"><span><b>Vite</b>Rex</span><span class="${classes.version}">${version}</span></div>
			<div class="${classes.infoWrapper}">
				<span class="${classes.label}">${stage}</span>
				<span class="${classes.dot}"></span>
				<span class="${classes.branch}">${gitBranch}</span>
			</div>
			<div class="${classes.infoWrapper}">${viteCell}</div>
			<div class="${classes.label}"><span><b>R</b></span><span class="${classes.version}">${rexVersion}</span></div>
			<button type="button" class="${classes.clearCache}" title="Clear Redaxo cache">clear cache</button>
		</div>
		${extrasHtml ? `<div class="${classes.extras}">${extrasHtml}</div>` : ""}
	`;

	document.body.appendChild(badge);

	const viteUrlBtn = badge.querySelector(`.${classes.viteUrl}`);
	if (viteUrlBtn) {
		viteUrlBtn.addEventListener("click", async (event) => {
			event.stopPropagation();
			const url = viteUrlBtn.getAttribute("data-url");
			if (!url) return;
			const ok = await copyToClipboard(url);
			const original = viteUrlBtn.textContent;
			viteUrlBtn.textContent = ok ? "copied" : "copy failed";
			setTimeout(() => {
				viteUrlBtn.textContent = original;
			}, 1200);
		});
	}

	const clearBtn = badge.querySelector(`.${classes.clearCache}`);
	if (clearBtn) {
		clearBtn.addEventListener("click", async (event) => {
			event.stopPropagation();
			const originalText = clearBtn.textContent;
			clearBtn.disabled = true;
			clearBtn.textContent = "…";
			try {
				const form = new FormData();
				form.set("viterex_clear_cache", "1");
				form.set("_csrf_token", csrfToken);
				const response = await fetch(window.location.pathname, {
					method: "POST",
					body: form,
					credentials: "same-origin",
				});
				const data = await response.json().catch(() => ({ ok: false }));
				clearBtn.textContent = data.ok ? "✓ cleared" : "✗ failed";
			} catch (error) {
				console.warn("ViteRexBadge: clear cache failed", error);
				clearBtn.textContent = "✗ error";
			}
			setTimeout(() => {
				clearBtn.textContent = originalText;
				clearBtn.disabled = false;
			}, 1500);
		});
	}

	badge.addEventListener("click", (event) => {
		if (event.target.closest("button")) return;
		badge.classList.toggle(classes.expanded);
	});
}
