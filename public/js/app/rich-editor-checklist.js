// \0filament-tiptap:@tiptap/core
var e = window.FilamentRichEditor.tiptap.core.Extension, t = window.FilamentRichEditor.tiptap.core.Node, n = window.FilamentRichEditor.tiptap.core.getRenderedAttributes, r = window.FilamentRichEditor.tiptap.core.mergeAttributes, i = window.FilamentRichEditor.tiptap.core.parseIndentedBlocks, a = window.FilamentRichEditor.tiptap.core.renderNestedMarkdownContent, o = window.FilamentRichEditor.tiptap.core.wrappingInputRule, s = window.FilamentRichEditor.tiptap.pmModel.Fragment, c = (e, t, n) => {
	let { selection: r } = e;
	if (!r.empty) return null;
	let { $from: i } = r;
	if (!i.parent.isTextblock || i.parentOffset !== i.parent.content.size) return null;
	let a = -1;
	for (let e = i.depth; e > 0; --e) if (i.node(e).type.name === t) {
		a = e;
		break;
	}
	if (a < 0) return null;
	let o = i.node(a), s = i.index(a);
	if (s + 1 >= o.childCount) return null;
	let c = o.child(s + 1);
	if (!n.includes(c.type.name)) return null;
	let l = e.schema.nodes[t], u = !1;
	if (c.forEach((e) => {
		e.type === l && e.childCount > 1 && (u = !0);
	}), !u) return null;
	let d = e.doc.resolve(i.after()).nodeAfter;
	if (!d || !n.includes(d.type.name)) return null;
	let f = [];
	return d.forEach((e) => {
		f.push(e);
	}), f.length === 0 ? null : {
		listItemDepth: a,
		nestedList: d,
		nestedListPos: i.after(),
		insertPos: i.after(a),
		items: f
	};
}, l = (e, t, n, r) => {
	let i = c(e, n, r);
	if (!i) return !1;
	let { selection: a } = e, { nestedList: o, nestedListPos: l, insertPos: u, items: d } = i, f = e.tr;
	f.delete(l, l + o.nodeSize);
	let p = f.mapping.map(u);
	return f.insert(p, s.from(d)), f.setSelection(a.map(f.doc, f.mapping)), t && t(f), !0;
}, u = (e, t, n) => l(e.state, e.view.dispatch, t, n), d = (t, n) => e.create({
	name: `${t}BranchingDeleteKeymap`,
	priority: 101,
	addKeyboardShortcuts() {
		let e = () => u(this.editor, t, n);
		return {
			Delete: e,
			"Mod-Delete": e
		};
	}
}), f = /^\s*(\[([( |x])?\])\s$/, p = "position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0", m = (e, t, n) => {
	var r;
	return (n == null || (r = n.checkboxLabel) == null ? void 0 : r.call(n, e, t)) || `Task item checkbox for ${e.textContent || "empty task item"}`;
}, h = t.create({
	name: "taskItem",
	addOptions() {
		return {
			nested: !1,
			HTMLAttributes: {},
			taskListTypeName: "taskList",
			a11y: void 0
		};
	},
	content() {
		return this.options.nested ? "paragraph block*" : "paragraph+";
	},
	defining: !0,
	addAttributes() {
		return { checked: {
			default: !1,
			keepOnSplit: !1,
			parseHTML: (e) => {
				let t = e.getAttribute("data-checked");
				return t === "" || t === "true";
			},
			renderHTML: (e) => ({ "data-checked": e.checked })
		} };
	},
	parseHTML() {
		return [{
			tag: `li[data-type="${this.name}"]`,
			priority: 51,
			contentElement: (e) => e.querySelector("div") ?? e
		}];
	},
	renderHTML({ node: e, HTMLAttributes: t }) {
		return [
			"li",
			r(this.options.HTMLAttributes, t, { "data-type": this.name }),
			[
				"label",
				["input", {
					type: "checkbox",
					checked: e.attrs.checked ? "checked" : null
				}],
				["span"]
			],
			["div", 0]
		];
	},
	parseMarkdown: (e, t) => {
		let n = [];
		if (n.push(e.tokens && e.tokens.length > 0 ? t.createNode("paragraph", {}, t.parseInline(e.tokens)) : e.text ? t.createNode("paragraph", {}, [t.createNode("text", { text: e.text })]) : t.createNode("paragraph", {}, [])), e.nestedTokens && e.nestedTokens.length > 0) {
			let r = t.parseChildren(e.nestedTokens);
			n.push(...r);
		}
		return t.createNode("taskItem", { checked: e.checked || !1 }, n);
	},
	renderMarkdown: (e, t) => a(e, t, `- [${e.attrs?.checked ? "x" : " "}] `, void 0, { indentMainContent: !0 }),
	addExtensions() {
		return this.options.nested ? [d(this.name, [this.options.taskListTypeName])] : [];
	},
	addKeyboardShortcuts() {
		let e = {
			Enter: () => this.editor.commands.splitListItem(this.name),
			"Shift-Tab": () => this.editor.commands.liftListItem(this.name)
		};
		return this.options.nested ? {
			...e,
			Tab: () => this.editor.commands.sinkListItem(this.name)
		} : e;
	},
	addNodeView() {
		return ({ node: e, HTMLAttributes: t, getPos: r, editor: i }) => {
			let a = document.createElement("li"), o = document.createElement("label"), s = document.createElement("span"), c = document.createElement("input"), l = document.createElement("div");
			s.style.cssText = p;
			let u = (e) => {
				let t = m(e, e.attrs.checked, this.options.a11y);
				c.setAttribute("aria-label", t), s.textContent = t;
			};
			u(e), o.contentEditable = "false", c.type = "checkbox", c.addEventListener("mousedown", (e) => e.preventDefault()), c.addEventListener("change", (t) => {
				if (!i.isEditable && !this.options.onReadOnlyChecked) {
					c.checked = !c.checked;
					return;
				}
				let { checked: n } = t.target;
				i.isEditable && typeof r == "function" && i.chain().focus(void 0, { scrollIntoView: !1 }).command(({ tr: e }) => {
					let t = r();
					if (typeof t != "number") return !1;
					let i = e.doc.nodeAt(t);
					return e.setNodeMarkup(t, void 0, {
						...i?.attrs,
						checked: n
					}), !0;
				}).run(), !i.isEditable && this.options.onReadOnlyChecked && (this.options.onReadOnlyChecked(e, n) || (c.checked = !c.checked));
			}), Object.entries(this.options.HTMLAttributes).forEach(([e, t]) => {
				a.setAttribute(e, t);
			}), a.dataset.checked = e.attrs.checked, c.checked = e.attrs.checked, o.append(c, s), a.append(o, l), Object.entries(t).forEach(([e, t]) => {
				a.setAttribute(e, t);
			});
			let d = new Set(Object.keys(t));
			return {
				dom: a,
				contentDOM: l,
				update: (e) => {
					if (e.type !== this.type) return !1;
					a.dataset.checked = e.attrs.checked, c.checked = e.attrs.checked, u(e);
					let t = i.extensionManager.attributes, r = n(e, t), o = new Set(Object.keys(r)), s = this.options.HTMLAttributes;
					return d.forEach((e) => {
						o.has(e) || (e in s ? a.setAttribute(e, s[e]) : a.removeAttribute(e));
					}), Object.entries(r).forEach(([e, t]) => {
						t == null ? e in s ? a.setAttribute(e, s[e]) : a.removeAttribute(e) : a.setAttribute(e, t);
					}), d = o, !0;
				}
			};
		};
	},
	addInputRules() {
		return [o({
			find: f,
			type: this.type,
			getAttributes: (e) => ({ checked: e[e.length - 1] === "x" })
		})];
	}
}), g = t.create({
	name: "taskList",
	addOptions() {
		return {
			itemTypeName: "taskItem",
			HTMLAttributes: {}
		};
	},
	group: "block list",
	content() {
		return `${this.options.itemTypeName}+`;
	},
	parseHTML() {
		return [{
			tag: `ul[data-type="${this.name}"]`,
			priority: 51
		}];
	},
	renderHTML({ HTMLAttributes: e }) {
		return [
			"ul",
			r(this.options.HTMLAttributes, e, { "data-type": this.name }),
			0
		];
	},
	parseMarkdown: (e, t) => t.createNode("taskList", {}, t.parseChildren(e.items || [])),
	renderMarkdown: (e, t) => e.content ? t.renderChildren(e.content, "\n") : "",
	markdownTokenizer: {
		name: "taskList",
		level: "block",
		start(e) {
			let t = e.match(/^\s*[-+*]\s+\[([ xX])\]\s+/)?.index;
			return t === void 0 ? -1 : t;
		},
		tokenize(e, t, n) {
			let r = (e) => {
				let t = i(e, {
					itemPattern: /^(\s*)([-+*])\s+\[([ xX])\]\s+(.*)$/,
					extractItemData: (e) => ({
						indentLevel: e[1].length,
						mainContent: e[4],
						checked: e[3].toLowerCase() === "x"
					}),
					createToken: (e, t) => ({
						type: "taskItem",
						raw: "",
						mainContent: e.mainContent,
						indentLevel: e.indentLevel,
						checked: e.checked,
						text: e.mainContent,
						tokens: n.inlineTokens(e.mainContent),
						nestedTokens: t
					}),
					customNestedParser: r
				}, n);
				if (t) {
					let r = {
						type: "taskList",
						raw: t.raw,
						items: t.items
					}, i = e.slice(t.raw.length);
					return i.trim() ? [r, ...n.blockTokens(i)] : [r];
				}
				return n.blockTokens(e);
			}, a = i(e, {
				itemPattern: /^(\s*)([-+*])\s+\[([ xX])\]\s+(.*)$/,
				extractItemData: (e) => ({
					indentLevel: e[1].length,
					mainContent: e[4],
					checked: e[3].toLowerCase() === "x"
				}),
				createToken: (e, t) => ({
					type: "taskItem",
					raw: "",
					mainContent: e.mainContent,
					indentLevel: e.indentLevel,
					checked: e.checked,
					text: e.mainContent,
					tokens: n.inlineTokens(e.mainContent),
					nestedTokens: t
				}),
				customNestedParser: r
			}, n);
			if (a) return {
				type: "taskList",
				raw: a.raw,
				items: a.items
			};
		}
	},
	markdownOptions: { indentsContent: !0 },
	addCommands() {
		return { toggleTaskList: () => ({ commands: e }) => e.toggleList(this.name, this.options.itemTypeName) };
	},
	addKeyboardShortcuts() {
		return { "Mod-Shift-9": () => this.editor.commands.toggleTaskList() };
	}
}), _ = e.create({
	name: "checklist",
	addExtensions() {
		return [g, h.configure({ nested: !1 })];
	}
});
export { _ as default };
