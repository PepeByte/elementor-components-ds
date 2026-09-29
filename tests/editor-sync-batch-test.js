/**
 * Editor sync test: loads the real assets/editor.js into a sandbox with a fake
 * Elementor and asserts on the requests it makes.
 *
 *   node tests/editor-sync-batch-test.js
 *
 * Proves a page sends ONE batched request (not one per instance, not once per
 * load event), that children the server confirmed current are not deleted and
 * recreated, and that failed instances are retried while healthy ones are not.
 */
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const source = fs.readFileSync(path.join(__dirname, "..", "assets", "editor.js"), "utf8");

function chain() {
	const proxy = new Proxy(function () {}, {
		get: () => proxy,
		apply: () => proxy,
	});
	return proxy;
}

function setup(instanceCount, initial = {}) {
	const posts = []; // { data, resolve, reject }
	const commands = []; // [command, args]
	const toasts = [];
	const handlers = {};
	const timers = [];
	const settingsWrites = [];

	function post(url, data) {
		const request = { data, done: [], fail: [], always: [] };
		const api = {
			done: (cb) => (request.done.push(cb), api),
			fail: (cb) => (request.fail.push(cb), api),
			always: (cb) => (request.always.push(cb), api),
		};
		request.resolve = (response) => {
			request.done.forEach((cb) => cb(response));
			request.always.forEach((cb) => cb());
		};
		request.reject = () => {
			request.fail.forEach((cb) => cb());
			request.always.forEach((cb) => cb());
		};
		posts.push(request);
		return api;
	}

	const $ = new Proxy(function () {}, {
		get: (_t, key) => (key === "post" ? post : chain()),
		apply: () => chain(),
	});

	function collection(items) {
		return { length: items.length, each: (cb) => items.forEach(cb), map: (cb) => items.map(cb) };
	}

	const containers = {};
	const models = [];
	for (let i = 0; i < instanceCount; i++) {
		const id = `inst${i}`;
		const spec = initial[id] || {};
		const children = (spec.children || []).map((childId) => ({ id: childId, get: (k) => (k === "elements" ? collection([]) : childId) }));
		const values = {
			component_id: String(spec.component_id ?? 123),
			ec_overrides: "",
			ec_parent_version: spec.version || "",
		};
		const model = {
			get: (key) => ({ widgetType: "component_instance", id, elements: collection(children) })[key],
		};
		containers[id] = {
			id,
			model,
			settings: {
				get: (key) => values[key],
				set: (patch) => (Object.assign(values, patch), settingsWrites.push([id, patch])),
			},
			view: { $el: { find: () => ({ remove() {} }) } },
		};
		models.push(model);
	}
	// elements collection of the document; walkModels recurses via get("elements").
	const elements = collection(models);

	class Dependency {
		register() {}
	}

	const elementor = {
		elements,
		getContainer: (id) => containers[id],
		hooks: { addFilter() {}, addAction() {} },
		channels: { editor: { on() {} } },
		documents: { getCurrent: () => ({ history: { getActive: () => true, setActive() {} } }) },
		on: (event, cb) => (handlers[event] = cb),
	};

	const sandbox = {
		jQuery: $,
		elementor,
		$e: {
			modules: { hookData: { Dependency } },
			run: (command, args) => commands.push([command, args]),
			route() {},
		},
		ElementorComponents: { ajaxUrl: "/ajax", shadowNonce: "n", consumerId: 7, nonce: "x", editBase: "/e?" },
		window: { addEventListener() {} },
		document: { body: {} },
		console,
		requestAnimationFrame: (cb) => cb(),
		setTimeout: (cb, ms) => timers.push({ cb, ms }),
		URL,
		Object,
		JSON,
		parseInt,
		Set,
		Map,
	};
	// toast() is exercised through the real code; capture its message via $ text().
	sandbox.jQuery = new Proxy($, {
		get: (target, key) => (key === "post" ? post : chain()),
		apply: (_t, _this, args) => {
			if (typeof args[0] === "string" && args[0].startsWith("<div") && args[0].includes("ec-toast")) {
				const node = { text: (m) => (toasts.push(m), node), appendTo: () => ({ addClass() {} }) };
				return node;
			}
			return chain();
		},
	});
	vm.createContext(sandbox);
	vm.runInContext(source, sandbox, { filename: "editor.js" });

	return {
		posts,
		commands,
		toasts,
		settingsWrites,
		fire: (event) => handlers[event](),
		runTimers: () => timers.splice(0).forEach((t) => t.cb()),
	};
}

const requestOf = (post) => JSON.parse(post.data.instances);
const creates = (env) => env.commands.filter(([name]) => name === "document/elements/create");
const deletes = (env) => env.commands.filter(([name]) => name === "document/elements/delete");

// --- Case 1: three load triggers, three instances -> exactly one request ---

{
	const env = setup(3, {
		inst0: { version: "", children: [] }, // stale, nothing rendered
		inst1: { version: "v2", children: ["c1"] }, // current, has children
		inst2: { version: "old", children: ["c2"] }, // stale, has children
	});
	env.fire("document:loaded");
	env.fire("preview:loaded");
	env.runTimers(); // the 500ms fallback

	assert.equal(env.posts.length, 1, "load triggers must share one request");
	assert.equal(env.posts[0].data.action, "ec_component_shadow_batch");
	assert.equal(env.posts[0].data.consumer_id, 7);
	const sent = requestOf(env.posts[0]);
	assert.deepEqual(
		sent.map((i) => i.instance_id),
		["inst0", "inst1", "inst2"],
	);
	assert.deepEqual(
		sent.map((i) => i.has_children),
		[false, true, true],
	);
	assert.equal(sent[1].parent_version, "v2");
	assert.equal(sent[1].component_id, 123);

	// Server: inst0 stale (elements), inst1 current (no elements), inst2 failed.
	env.posts[0].resolve({
		success: true,
		data: {
			results: {
				inst0: { data: { elements: [{ id: "n1", elType: "container" }], map: {}, overrides: { version: 1, widgets: {} }, parent_version: "v2" } },
				inst1: { data: { map: { a: "b" }, overrides: { version: 1, widgets: {} }, parent_version: "v2" } },
				inst2: { error: "Invalid component." },
			},
		},
	});

	assert.equal(creates(env).length, 1, "only the stale instance gets new children");
	assert.equal(creates(env)[0][1].container.id, "inst0");
	assert.equal(deletes(env).length, 0, "no existing child was touched");
	assert.ok(env.settingsWrites.some(([id]) => id === "inst1"), "current instance must still restore its editor state");
	assert.deepEqual(env.toasts, ["Invalid component."], "a failed instance is reported once");

	// --- Case 2: later load events retry only what failed ---
	env.fire("preview:loaded");
	assert.equal(env.posts.length, 2, "only the failed instance is retried");
	assert.deepEqual(
		requestOf(env.posts[1]).map((i) => i.instance_id),
		["inst2"],
	);
}

// --- Case 3: instances already in flight are not requested twice ---

{
	const env = setup(2);
	env.fire("document:loaded");
	env.fire("preview:loaded"); // before the first response arrives
	assert.equal(env.posts.length, 1, "in-flight instances were requested again");
}

// --- Case 4: large pages are chunked to the server's limit ---

{
	const env = setup(120);
	env.fire("document:loaded");
	assert.deepEqual(
		env.posts.map((p) => requestOf(p).length),
		[50, 50, 20],
		"120 instances must go out as 50/50/20",
	);
}

// --- Case 5: request failure shows one message and allows a retry ---

{
	const env = setup(2);
	env.fire("document:loaded");
	env.posts[0].reject();
	assert.equal(env.toasts.length, 1);
	env.fire("preview:loaded");
	assert.equal(env.posts.length, 2, "instances must be retried after a failed request");
}

// --- Case 6: instances without a component are never sent ---

{
	const env = setup(2, { inst0: { component_id: 0 } });
	env.fire("document:loaded");
	assert.deepEqual(
		requestOf(env.posts[0]).map((i) => i.instance_id),
		["inst1"],
	);
}

console.log("editor sync batching: ok");
