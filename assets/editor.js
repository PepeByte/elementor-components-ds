(($) => {
	// Without this registration Elementor treats the instance as a plain widget:
	// children get no live views and edits only appear after a reload.
	$(window).on("elementor/nested-element-type-loaded", () => {
		elementor.elementsManager.registerElementType(
			new (class extends elementor.modules.elements.types.NestedElementBase {
				getType() {
					return "component_instance";
				}
			})(),
		);
	});

	const shadowState = new Map();
	const syncing = new Set();
	const BATCH_LIMIT = 50;
	const guardIds = [
		"ec-component-create-lock",
		"ec-component-delete-lock",
		"ec-component-duplicate-lock",
		"ec-component-move-lock",
	];

	function toast(message) {
		$(".ec-toast").remove();
		const $toast = $('<div class="ec-toast" role="status" />')
			.text(message)
			.appendTo(document.body);
		requestAnimationFrame(() => $toast.addClass("ec-toast--visible"));
		setTimeout(() => $toast.remove(), 4000);
	}

	function currentContainer(view) {
		try {
			if (view && view.getContainer) {
				return view.getContainer();
			}
			const current = elementor.getCurrentElement?.();
			return current?.getContainer ? current.getContainer() : null;
		} catch (error) {
			console.error("Elementor Components could not resolve the element.", error);
			return null;
		}
	}

	function componentAncestor(container, includeSelf = false) {
		let current = includeSelf ? container : container?.parent;
		while (current?.model) {
			if (current.model.get("widgetType") === "component_instance") {
				return current;
			}
			current = current.parent;
		}
		return null;
	}

	function saveAsComponent(view) {
		const container = currentContainer(view);
		if (!container) {
			toast("Select an element first, then right-click → Save as Component.");
			return;
		}

		const title = window.prompt("Component name:");
		if (!title) {
			return;
		}

		$.post(ElementorComponents.ajaxUrl, {
			action: "ec_save_component",
			nonce: ElementorComponents.nonce,
			title,
			elements: JSON.stringify(container.model.toJSON({ remove: ["default"] })),
		})
			.done((response) => {
				toast(
					response?.success
						? `Component "${title}" saved. Insert it with Component Instance.`
						: response?.data?.message || "Could not save component.",
				);
			})
			.fail(() => toast("Could not save component (request failed)."));
	}

	["widget", "section", "container", "column"].forEach((elementType) => {
		elementor.hooks.addFilter(
			`elements/${elementType}/contextMenuGroups`,
			(groups, view) => {
				const container = currentContainer(view);
				if (container && componentAncestor(container)) {
					// Everything inside an instance is managed by the parent component.
					return [];
				}
				groups.push({
					name: "ec-components",
					actions: [
						{
							name: "ec-save-as-component",
							icon: "eicon-star",
							title: "Save as Component",
							isEnabled: () => true,
							callback: () => saveAsComponent(view),
						},
					],
				});
				return groups;
			},
		);
	});

	function guardOptions() {
		return {
			callbacks: Object.fromEntries(guardIds.map((id) => [id, false])),
		};
	}

	function commandContainers(args) {
		return [
			...(args?.containers || []),
			...(args?.container ? [args.container] : []),
			...(args?.target ? [args.target] : []),
		].filter(Boolean);
	}

	function registerGuard(command, id, blocks, message) {
		class ComponentGuard extends $e.modules.hookData.Dependency {
			getCommand() {
				return command;
			}
			getId() {
				return id;
			}
			getConditions(args) {
				return blocks(args);
			}
			apply() {
				toast(message || "Component structure is controlled by its parent.");
				return false;
			}
		}
		new ComponentGuard().register();
	}

	function registerStructureGuards() {
		if (!$e?.modules?.hookData?.Dependency) {
			console.error("Elementor Components: command hooks are unavailable.");
			return;
		}

		registerGuard("document/elements/create", guardIds[0], (args) =>
			commandContainers(args).some(
				(container) =>
					componentAncestor(container, true) ||
					container.model?.get("widgetType") === "component_instance",
			),
		);
		["delete", "duplicate", "move"].forEach((action, index) => {
			registerGuard(`document/elements/${action}`, guardIds[index + 1], (args) =>
				commandContainers(args).some((container) => componentAncestor(container)),
			);
		});
	}

	function walkModels(collection, callback) {
		collection?.each?.((model) => {
			callback(model);
			walkModels(model.get("elements"), callback);
		});
	}

	function findInstances() {
		const instances = [];
		walkModels(elementor.elements, (model) => {
			if (model.get("widgetType") === "component_instance") {
				const container = elementor.getContainer(model.get("id"));
				if (container) {
					instances.push(container);
				}
			}
		});
		return instances;
	}

	function replaceShadow(instance, elements) {
		const history = elementor.documents.getCurrent()?.history;
		const wasActive = history?.getActive();
		const children = instance.model.get("elements");
		const current = children?.map?.((model) => elementor.getContainer(model.get("id"))) || [];

		if (history) {
			history.setActive(false);
		}
		try {
			current.filter(Boolean).forEach((container) => {
				$e.run("document/elements/delete", {
					container,
					options: guardOptions(),
				});
			});
			elements.forEach((model) => {
				$e.run("document/elements/create", {
					container: instance,
					model,
					options: { ...guardOptions(), edit: false },
				});
			});
		} finally {
			if (history) {
				history.setActive(wasActive);
			}
		}
	}

	function collectLockedIds(elements, ids = []) {
		(elements || []).forEach((element) => {
			if ((element?.elType || "") !== "widget" && element?.id) {
				ids.push(String(element.id));
			}
			collectLockedIds(element?.elements, ids);
		});
		return ids;
	}

	function refreshLockedStyles() {
		const doc = elementor.$previewContents?.[0];
		if (!doc?.head) {
			return;
		}
		let style = doc.getElementById("ec-locked-elements");
		if (!style) {
			style = doc.createElement("style");
			style.id = "ec-locked-elements";
			doc.head.appendChild(style);
		}
		const selectors = [];
		shadowState.forEach((state) => {
			(state.lockedIds || []).forEach((id) => {
				const clean = id.replace(/[^A-Za-z0-9_-]/g, "");
				if (clean) {
					selectors.push(
						`.elementor-element-${clean} > .elementor-element-overlay`,
						`.elementor-element-${clean} > .elementor-editor-element-settings`,
						`.elementor-element-${clean} > .elementor-empty-view`,
					);
				}
			});
		});
		style.textContent = selectors.length
			? `${selectors.join(",\n")} { display: none !important; }`
			: "";
	}

	function rememberShadow(instance, data) {
		const reverse = Object.fromEntries(
			Object.entries(data.map || {}).map(([source, runtime]) => [runtime, source]),
		);
		shadowState.set(instance.id, {
			map: data.map || {},
			reverse,
			patch: data.overrides || { version: 1, widgets: {} },
			parentVersion: data.parent_version || "",
			sourceSettings: data.sourceSettings || {},
			policies: data.policies || {},
			lockedIds: collectLockedIds(data.elements),
		});
		instance.settings.set({
			ec_overrides: JSON.stringify(data.overrides || { version: 1, widgets: {} }),
			ec_shadow_map: JSON.stringify(data.map || {}),
			ec_parent_version: data.parent_version || "",
		});
		refreshLockedStyles();
	}

	function componentIdOf(instance) {
		return parseInt(instance.settings.get("component_id"), 10) || 0;
	}

	function applyShadow(instance, data) {
		rememberShadow(instance, data);
		// Absent when the server confirmed the children already match the parent:
		// keep the live views instead of deleting and recreating them.
		if (data.elements) {
			replaceShadow(instance, data.elements);
		}
		instance.view?.$el?.find(".ec-empty").remove();
	}

	// Explicit sync (Sync Parent Now, component change): always rebuilds.
	function syncInstance(instance) {
		const componentId = componentIdOf(instance);
		if (!componentId || syncing.has(instance.id)) {
			return;
		}

		syncing.add(instance.id);
		$.post(ElementorComponents.ajaxUrl, {
			action: "ec_component_shadow",
			nonce: ElementorComponents.shadowNonce,
			consumer_id: ElementorComponents.consumerId,
			component_id: componentId,
			instance_id: instance.id,
			overrides: instance.settings.get("ec_overrides") || "",
		})
			.done((response) => {
				if (!response?.success) {
					toast(response?.data?.message || "Could not synchronize component.");
					return;
				}
				applyShadow(instance, response.data);
			})
			.fail(() => toast("Could not synchronize component (request failed)."))
			.always(() => syncing.delete(instance.id));
	}

	// Page load: one request per chunk of instances instead of one per instance.
	// Instances that are already current only have their editor state restored.
	function syncInstances(instances) {
		const pending = instances.filter((instance) => componentIdOf(instance) && !syncing.has(instance.id));
		for (let start = 0; start < pending.length; start += BATCH_LIMIT) {
			const chunk = pending.slice(start, start + BATCH_LIMIT);
			chunk.forEach((instance) => syncing.add(instance.id));

			$.post(ElementorComponents.ajaxUrl, {
				action: "ec_component_shadow_batch",
				nonce: ElementorComponents.shadowNonce,
				consumer_id: ElementorComponents.consumerId,
				instances: JSON.stringify(
					chunk.map((instance) => ({
						instance_id: instance.id,
						component_id: componentIdOf(instance),
						overrides: instance.settings.get("ec_overrides") || "",
						parent_version: instance.settings.get("ec_parent_version") || "",
						has_children: !!instance.model.get("elements")?.length,
					})),
				),
			})
				.done((response) => {
					if (!response?.success) {
						toast(response?.data?.message || "Could not synchronize components.");
						return;
					}
					let failure = "";
					chunk.forEach((instance) => {
						const result = response.data?.results?.[instance.id];
						if (result?.data) {
							applyShadow(instance, result.data);
						} else {
							failure = failure || result?.error || "Could not synchronize component.";
						}
					});
					if (failure) {
						toast(failure);
					}
				})
				.fail(() => toast("Could not synchronize components (request failed)."))
				.always(() => chunk.forEach((instance) => syncing.delete(instance.id)));
		}
	}

	function sameValue(left, right) {
		return JSON.stringify(left) === JSON.stringify(right);
	}

	function extractWidgetPatch(parent, shadow, allowed) {
		const patch = {};
		const parentGlobals = parent.__globals__ || {};
		const shadowGlobals = shadow.__globals__ || {};
		const parentDynamic = parent.__dynamic__ || {};
		const shadowDynamic = shadow.__dynamic__ || {};

		allowed.forEach((key) => {
			const parentHas = Object.hasOwn(parent, key);
			const shadowHas = Object.hasOwn(shadow, key);
			const metadataCleared =
				(Object.hasOwn(parentGlobals, key) && !Object.hasOwn(shadowGlobals, key)) ||
				(Object.hasOwn(parentDynamic, key) && !Object.hasOwn(shadowDynamic, key));
			if (shadowHas && (!parentHas || !sameValue(shadow[key], parent[key]) || metadataCleared)) {
				(patch.values ||= {})[key] = shadow[key];
			} else if (metadataCleared) {
				(patch.values ||= {})[key] = "";
			}
			if (Object.hasOwn(shadowGlobals, key) && !sameValue(shadowGlobals[key], parentGlobals[key])) {
				(patch.globals ||= {})[key] = shadowGlobals[key];
			}
			if (Object.hasOwn(shadowDynamic, key) && !sameValue(shadowDynamic[key], parentDynamic[key])) {
				(patch.dynamic ||= {})[key] = shadowDynamic[key];
			}
		});
		return patch;
	}

	function captureWidgetIntent(container) {
		const instance = componentAncestor(container);
		const state = instance && shadowState.get(instance.id);
		const sourceId = state?.reverse?.[container.id];
		if (!state || !sourceId || !state.policies[sourceId]) {
			return;
		}

		const values = extractWidgetPatch(
			state.sourceSettings[sourceId] || {},
			container.settings.toJSON(),
			state.policies[sourceId].allowed || [],
		);
		if (Object.keys(values).length) {
			state.patch.widgets[sourceId] = {
				widgetType: container.model.get("widgetType") || container.model.get("elType"),
				...values,
			};
		} else {
			delete state.patch.widgets[sourceId];
		}
		instance.settings.set("ec_overrides", JSON.stringify(state.patch));
	}

	function resetControl(container, control) {
		const instance = componentAncestor(container);
		const state = instance && shadowState.get(instance.id);
		const sourceId = state?.reverse?.[container.id];
		if (!state || !sourceId) {
			return;
		}

		const parent = state.sourceSettings[sourceId] || {};
		const globals = { ...(container.settings.get("__globals__") || {}) };
		const dynamic = { ...(container.settings.get("__dynamic__") || {}) };
		Object.hasOwn(parent.__globals__ || {}, control)
			? (globals[control] = parent.__globals__[control])
			: delete globals[control];
		Object.hasOwn(parent.__dynamic__ || {}, control)
			? (dynamic[control] = parent.__dynamic__[control])
			: delete dynamic[control];

		$e.run("document/elements/settings", {
			container,
			settings: {
				[control]: Object.hasOwn(parent, control) ? parent[control] : "",
				__globals__: globals,
				__dynamic__: dynamic,
			},
		});
		["values", "globals", "dynamic"].forEach((bucket) => {
			delete state.patch.widgets[sourceId]?.[bucket]?.[control];
		});
		captureWidgetIntent(container);
		applyPanelPolicy(container);
	}

	function controlSelector(name) {
		return `.elementor-control-${name.replace(/[^A-Za-z0-9_-]/g, "")}`;
	}

	function applyPanelPolicy(container) {
		$(".ec-component-policy-hidden").removeClass("ec-component-policy-hidden");
		$(".ec-reset-control").remove();
		$(document.body).removeClass("ec-component-shadow-selected ec-component-container-selected");

		const instance = componentAncestor(container);
		const state = instance && shadowState.get(instance.id);
		const sourceId = state?.reverse?.[container?.id];
		const policy = sourceId && state?.policies?.[sourceId];
		if (!policy) {
			return;
		}

		$(document.body).addClass("ec-component-shadow-selected");

		if ("widget" !== container.model.get("elType")) {
			// Containers inside an instance: only the Style tab may vary here.
			$(document.body).addClass("ec-component-container-selected");
			try {
				$e.route("panel/editor/style");
			} catch (error) {
				console.error("Elementor Components could not open the Style tab.", error);
			}
		}
		[...(policy.denied || []), ...(policy.denied_sections || [])].forEach((name) => {
			$(controlSelector(name)).addClass("ec-component-policy-hidden");
		});
		(policy.allowed || []).forEach((name) => {
			const widgetPatch = state.patch.widgets[sourceId] || {};
			const overridden = ["values", "globals", "dynamic"].some((bucket) =>
				Object.hasOwn(widgetPatch[bucket] || {}, name),
			);
			if (!overridden) {
				return;
			}
			const $control = $(controlSelector(name));
			if ($control.length) {
				$("<button type=\"button\" class=\"ec-reset-control\">Reset to parent</button>")
					.on("click", () => resetControl(container, name))
					.appendTo($control.find(".elementor-control-content").first());
			}
		});
	}

	window.addEventListener("elementor/commands/run/after", ({ detail }) => {
		if (detail?.command !== "document/elements/settings") {
			return;
		}
		commandContainers(detail.args).forEach((container) => {
			if (container.model?.get("widgetType") === "component_instance") {
				if (Object.hasOwn(detail.args.settings || {}, "component_id")) {
					setTimeout(() => {
						if (parseInt(container.settings.get("component_id"), 10) || 0) {
							syncInstance(container);
						} else {
							replaceShadow(container, []);
							shadowState.delete(container.id);
							refreshLockedStyles();
						}
					}, 0);
				}
				return;
			}
			captureWidgetIntent(container);
		});
	});

	function openComponentEditor(id) {
		const url = new URL(ElementorComponents.editBase + id, window.location.origin);
		if (!id || url.origin !== window.location.origin) {
			toast("Pick a component first.");
			return;
		}
		const link = document.createElement("a");
		link.href = url.href;
		link.target = "_blank";
		link.rel = "noopener";
		link.click();
	}

	function componentId(event) {
		const container = event?.container || currentContainer();
		return container ? componentIdOf(container) : 0;
	}

	elementor.channels.editor.on("ec:edit-parent-component", (event) => {
		openComponentEditor(componentId(event));
	});
	elementor.channels.editor.on("ec:sync-component", (event) => {
		const container = event?.container || currentContainer();
		if (container) {
			syncInstance(container);
		}
	});
	["widget", "container", "section", "column"].forEach((elementType) => {
		elementor.hooks.addAction(`panel/open_editor/${elementType}`, (_panel, _model, view) => {
			setTimeout(() => applyPanelPolicy(currentContainer(view)), 0);
		});
	});

	registerStructureGuards();
	// document:loaded, preview:loaded and the timer below all land here; instances
	// already hydrated or in flight are skipped, so a page syncs once, not three times.
	const syncAll = () => {
		refreshLockedStyles();
		syncInstances(findInstances().filter((instance) => !shadowState.has(instance.id)));
	};
	elementor.on("document:loaded", syncAll);
	elementor.on("preview:loaded", syncAll);
	setTimeout(syncAll, 500);
})(jQuery);
