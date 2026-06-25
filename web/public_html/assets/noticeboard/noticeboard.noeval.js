// GENERATED from dc-runtime/src/*.ts — do not edit. Rebuild with `cd dc-runtime && bun run build`.
"use strict";
(() => {
  var __defProp = Object.defineProperty;
  var __defNormalProp = (obj, key, value) => key in obj ? __defProp(obj, key, { enumerable: true, configurable: true, writable: true, value }) : obj[key] = value;
  var __publicField = (obj, key, value) => __defNormalProp(obj, typeof key !== "symbol" ? key + "" : key, value);

  // src/react.ts
  function getReact() {
    const R = window.React;
    if (!R) throw new Error("dc-runtime: window.React is not available yet");
    return R;
  }
  function getReactDOM() {
    const RD = window.ReactDOM;
    if (!RD) throw new Error("dc-runtime: window.ReactDOM is not available yet");
    return RD;
  }
  var h = ((...args) => getReact().createElement(
    ...args
  ));

  // src/parse.ts
  function parseDcDocument(doc) {
    const dc = doc.querySelector("x-dc");
    if (!dc) return null;
    const scriptEl = doc.querySelector("script[data-dc-script]");
    const { props, preview } = parseDataProps(
      scriptEl?.getAttribute("data-props") ?? null
    );
    return {
      template: dc.innerHTML,
      js: scriptEl ? scriptEl.textContent || "" : "",
      props,
      preview
    };
  }
  function parseDcText(src) {
    const openMatch = /<x-dc(?:\s[^>]*)?>/.exec(src);
    if (!openMatch) return null;
    const close = src.lastIndexOf("</x-dc>");
    if (close === -1 || close < openMatch.index) return null;
    const template = src.slice(openMatch.index + openMatch[0].length, close);
    const doc = new DOMParser().parseFromString(src, "text/html");
    const scriptEl = doc.querySelector("script[data-dc-script]");
    const { props, preview } = parseDataProps(
      scriptEl?.getAttribute("data-props") ?? null
    );
    return {
      template,
      js: scriptEl ? scriptEl.textContent || "" : "",
      props,
      preview
    };
  }
  function parseDataProps(raw) {
    if (!raw) return { props: null, preview: null };
    let parsed;
    try {
      parsed = JSON.parse(raw);
    } catch {
      return { props: null, preview: null };
    }
    if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) {
      return { props: null, preview: null };
    }
    const obj = parsed;
    const preview = obj.$preview && typeof obj.$preview === "object" ? obj.$preview : null;
    const rest = {};
    for (const k of Object.keys(obj)) {
      if (k[0] !== "$") rest[k] = obj[k];
    }
    return { props: Object.keys(rest).length ? rest : null, preview };
  }
  function dcNameFromPath(pathname) {
    let p = pathname || "";
    try {
      p = decodeURIComponent(p);
    } catch {
    }
    const base = p.split("/").pop() || "Root";
    return base.replace(/\.dc\.html$/, "").replace(/\.html?$/, "") || "Root";
  }

  // src/boot.ts
  var BASE_CSS = `
    .sc-placeholder{background:rgba(255,255,255,.3);border:1px solid rgba(0,0,0,.5);
      border-radius:2px;box-sizing:border-box;overflow:hidden}
    @keyframes sc-shine{0%{background-position:100% 50%}100%{background-position:0% 50%}}
    html.sc-dc-streaming .sc-placeholder,
    html.sc-dc-streaming .sc-interp.sc-missing{position:relative;
      background:color-mix(in srgb,currentColor 5%,transparent);
      border-color:transparent}
    html.sc-dc-streaming .sc-placeholder::before,
    html.sc-dc-streaming .sc-interp.sc-missing::before{content:'';
      position:absolute;inset:0;pointer-events:none;
      background:linear-gradient(90deg,rgba(217,119,87,0) 25%,rgba(247,225,211,.95) 37%,rgba(217,119,87,0) 63%);
      background-size:400% 100%;animation:sc-shine 1.4s ease infinite}
    html.sc-dc-streaming .sc-placeholder:nth-child(n+9 of .sc-placeholder)::before,
    html.sc-dc-streaming .sc-interp.sc-missing:nth-child(n+9 of .sc-interp.sc-missing)::before{animation:none;
      background:color-mix(in srgb,currentColor 8%,transparent)}
    .sc-placeholder-error{padding:4px 8px;font:11px/1.4 ui-monospace,monospace;
      color:rgba(0,0,0,.7);word-break:break-word}
    .sc-interp.sc-missing{display:inline-block;width:2em;height:1em;overflow:hidden;
      vertical-align:text-bottom;background:rgba(255,255,255,.3);border:1px solid rgba(0,0,0,.5);
      border-radius:2px;box-sizing:border-box;color:transparent;
      user-select:none}
    .sc-interp.sc-unresolved{font-family:ui-monospace,monospace;font-size:.85em;
      color:rgba(0,0,0,.5);background:rgba(0,0,0,.05);border-radius:3px;
      padding:0 3px}
    .sc-host.sc-has-error{position:relative}
    .sc-logic-error{position:absolute;top:8px;left:8px;z-index:2147483647;max-width:60ch;
      padding:6px 10px;background:#b00020;color:#fff;font:12px/1.4 ui-monospace,monospace;
      border-radius:4px;white-space:pre-wrap;pointer-events:none}
    /* Mirrors PRINT_BASELINE_CSS in apps/web deck-stage-export.ts \u2014 keep both
       in sync until dc-runtime regains a build step. */
    @media print {
      @page { margin: 0.5cm; }
      figure, table { break-inside: avoid; }
      #dc-root, #dc-root > .sc-host { height: auto; }
      *, *::before, *::after {
        print-color-adjust: exact; -webkit-print-color-adjust: exact;
        backdrop-filter: none !important; -webkit-backdrop-filter: none !important;
        animation-delay: -99s !important; animation-duration: .001s !important;
        animation-iteration-count: 1 !important; animation-fill-mode: both !important;
        animation-play-state: running !important; transition-duration: 0s !important;
      }
    }
  `;
  var FULL_PAGE_CSS = "html,body{height:100%;margin:0}#dc-root,#dc-root>.sc-host{height:100%}";
  function rootNameForDocument(doc, loc) {
    let bootPath = loc.pathname || "";
    if (!/\.dc\.html?$/i.test(safeDecode(bootPath))) {
      try {
        bootPath = new URL(doc.baseURI || "/").pathname;
      } catch {
      }
    }
    return dcNameFromPath(bootPath);
  }
  function safeDecode(s) {
    try {
      return decodeURIComponent(s);
    } catch {
      return s;
    }
  }
  function boot(runtime, doc = document) {
    const parsed = parseDcDocument(doc);
    if (!parsed) return null;
    const React = getReact();
    const rootName = rootNameForDocument(doc, location);
    runtime.markFetched(rootName);
    runtime.setRootName(rootName);
    runtime.adoptParsed(rootName, parsed);
    fetch(location.href).then((res) => res.ok ? res.text() : "").then((t) => {
      const raw = t ? parseDcText(t) : null;
      if (raw?.template) runtime.updateHtml(rootName, raw.template);
    }).catch(() => {
    });
    const dc = doc.querySelector("x-dc");
    const hostEl = doc.createElement("div");
    hostEl.id = "dc-root";
    dc.replaceWith(hostEl);
    if (!parsed.preview) {
      const s = doc.createElement("style");
      s.textContent = FULL_PAGE_CSS;
      doc.head.appendChild(s);
    }
    const Root = runtime.getDC(rootName);
    const entry = runtime.registry.get(rootName);
    function StandaloneRoot() {
      const [, setTick] = React.useState(0);
      React.useEffect(() => {
        const sub = () => setTick((n) => n + 1);
        entry.subs.add(sub);
        return () => {
          entry.subs.delete(sub);
        };
      }, []);
      return h(Root, entry.propOverrides || null);
    }
    const ReactDOM = getReactDOM();
    if (ReactDOM.createRoot)
      ReactDOM.createRoot(hostEl).render(h(StandaloneRoot));
    else ReactDOM.render(h(StandaloneRoot), hostEl);
    return rootName;
  }

  // src/expr.ts
  var IDENT_RE = /^[A-Za-z_$][A-Za-z0-9_$]*/;
  var NUMBER_RE = /^-?\d+(\.\d+)?$/;
  function resolve(vals, src) {
    const expr = String(src).trim();
    if (!expr) return void 0;
    if (expr[0] === "(" && expr[expr.length - 1] === ")" && parensWrapWhole(expr)) {
      return resolve(vals, expr.slice(1, -1));
    }
    const eq = findTopLevelEquality(expr);
    if (eq) {
      const lv = resolve(vals, expr.slice(0, eq.index));
      const rv = resolve(vals, expr.slice(eq.index + eq.op.length));
      switch (eq.op) {
        case "===":
          return lv === rv;
        case "!==":
          return lv !== rv;
        case "==":
          return lv == rv;
        default:
          return lv != rv;
      }
    }
    if (expr[0] === "!") return !resolve(vals, expr.slice(1));
    if (expr === "true") return true;
    if (expr === "false") return false;
    if (expr === "null") return null;
    if (expr === "undefined") return void 0;
    if (NUMBER_RE.test(expr)) return Number(expr);
    if (expr.length >= 2 && (expr[0] === '"' || expr[0] === "'") && expr[expr.length - 1] === expr[0]) {
      return expr.slice(1, -1);
    }
    return resolvePath(vals, expr);
  }
  function parensWrapWhole(expr) {
    let depth = 0;
    for (let i = 0; i < expr.length - 1; i++) {
      if (expr[i] === "(") depth++;
      else if (expr[i] === ")") {
        depth--;
        if (depth === 0) return false;
      }
    }
    return true;
  }
  function findTopLevelEquality(expr) {
    let depth = 0;
    for (let i = 0; i < expr.length; i++) {
      const c = expr[i];
      if (c === "[" || c === "(") depth++;
      else if (c === "]" || c === ")") depth--;
      else if (depth === 0 && (c === "=" || c === "!") && expr[i + 1] === "=") {
        if (i > 0 && (expr[i - 1] === "=" || expr[i - 1] === "!")) continue;
        if (!expr.slice(0, i).trim()) continue;
        const op = expr[i + 2] === "=" ? c + "==" : c + "=";
        return { index: i, op };
      }
    }
    return null;
  }
  function resolvePath(vals, expr) {
    const head = expr.match(IDENT_RE);
    if (!head) return void 0;
    let cur = vals == null ? void 0 : vals[head[0]];
    let i = head[0].length;
    while (i < expr.length) {
      if (expr[i] === ".") {
        const m = expr.slice(i + 1).match(IDENT_RE) || expr.slice(i + 1).match(/^\d+/);
        if (!m) return void 0;
        cur = cur == null ? void 0 : cur[m[0]];
        i += 1 + m[0].length;
      } else if (expr[i] === "[") {
        let depth = 1;
        let j = i + 1;
        while (j < expr.length && depth > 0) {
          if (expr[j] === "[") depth++;
          else if (expr[j] === "]") {
            depth--;
            if (depth === 0) break;
          }
          j++;
        }
        if (depth !== 0) return void 0;
        const key = resolve(vals, expr.slice(i + 1, j));
        cur = cur == null ? void 0 : cur[key];
        i = j + 1;
      } else {
        return void 0;
      }
    }
    return cur;
  }

  // src/encode.ts
  var CAMEL_ATTR = "sc-camel-";
  var RAW_WRAP = {
    select: "sc-raw-select",
    table: "sc-raw-table",
    tbody: "sc-raw-tbody",
    thead: "sc-raw-thead",
    tfoot: "sc-raw-tfoot",
    tr: "sc-raw-tr",
    td: "sc-raw-td",
    th: "sc-raw-th",
    caption: "sc-raw-caption"
  };
  var RAW_UNWRAP = Object.fromEntries(
    Object.entries(RAW_WRAP).map(([k, v]) => [v, k])
  );
  var EVENT_MAP = {
    onclick: "onClick",
    onchange: "onChange",
    oninput: "onInput",
    onsubmit: "onSubmit",
    onkeydown: "onKeyDown",
    onkeyup: "onKeyUp",
    onkeypress: "onKeyPress",
    onmousedown: "onMouseDown",
    onmouseup: "onMouseUp",
    onmouseenter: "onMouseEnter",
    onmouseleave: "onMouseLeave",
    onfocus: "onFocus",
    onblur: "onBlur",
    ondoubleclick: "onDoubleClick",
    oncontextmenu: "onContextMenu"
  };
  var ATTRS = `(?:[^>"']|"[^"]*"|'[^']*')*`;
  var IMPORT_SELF_CLOSE_RE = new RegExp(
    "<(x-import|dc-import)(" + ATTRS + ")/>",
    "gi"
  );
  var CAMEL_ATTR_RE = /(\s)([a-z]+[A-Z][A-Za-z0-9]*)(\s*=)/g;
  function encodeCase(html) {
    html = html.replace(
      IMPORT_SELF_CLOSE_RE,
      (_, t, a) => "<" + t + a + "></" + t + ">"
    );
    html = html.replace(/<helmet(\s|>)/gi, "<sc-helmet$1");
    html = html.replace(/<\/helmet\s*>/gi, "</sc-helmet>");
    html = html.replace(
      CAMEL_ATTR_RE,
      (_, sp, name, eq) => sp + CAMEL_ATTR + name.replace(/[A-Z]/g, (c) => "-" + c.toLowerCase()) + eq
    );
    for (const [real, alias] of Object.entries(RAW_WRAP)) {
      html = html.replace(
        new RegExp("(</?)" + real + "(?=[\\s>])", "gi"),
        "$1" + alias
      );
    }
    return html;
  }
  function kebabToCamel(s) {
    return s.replace(/-([a-z])/g, (_, c) => c.toUpperCase());
  }
  function cssToObj(css) {
    const o = {};
    for (const decl of css.split(";")) {
      const i = decl.indexOf(":");
      if (i < 0) continue;
      const prop = decl.slice(0, i).trim();
      o[prop.startsWith("--") ? prop : kebabToCamel(prop)] = decl.slice(i + 1).trim();
    }
    return o;
  }
  function compileAttr(raw) {
    const whole = raw.match(/^\s*\{\{([\s\S]+?)\}\}\s*$/);
    if (whole) {
      const path = whole[1];
      return (vals) => resolve(vals, path);
    }
    if (raw.includes("{{")) {
      const parts = raw.split(/\{\{([\s\S]+?)\}\}/g);
      return (vals) => parts.map((s, i) => i & 1 ? resolve(vals, s) ?? "" : s).join("");
    }
    return () => raw;
  }

  // src/compile.ts
  function collectProps(node, kind, host) {
    const propGetters = [];
    const pseudoClasses = [];
    let hintSize = null;
    for (const { name, value } of [...node.attributes]) {
      if (name === "sc-name" || name === "data-dc-tpl") continue;
      let key = name;
      if (key.startsWith(CAMEL_ATTR))
        key = kebabToCamel(key.slice(CAMEL_ATTR.length));
      if (key === "hint-size") {
        hintSize = value;
        continue;
      }
      if (key.startsWith("style-")) {
        pseudoClasses.push(host.pseudoClass(key.slice(6), value));
        continue;
      }
      if (kind !== "dom") {
        if (key.includes("-") && !(kind === "x-import" && (key.startsWith("aria-") || key.startsWith("data-"))))
          key = kebabToCamel(key);
      } else {
        if (key === "class") key = "className";
        else if (key === "for") key = "htmlFor";
        else if (key.startsWith("on"))
          key = EVENT_MAP[key] || "on" + key[2].toUpperCase() + key.slice(3);
      }
      propGetters.push([key, compileAttr(value)]);
    }
    return { propGetters, pseudoClasses, hintSize };
  }
  var HOST_STYLE_PROPS = /* @__PURE__ */ new Set([
    "position",
    "left",
    "right",
    "top",
    "bottom",
    "inset",
    "width",
    "height",
    "z-index",
    "transform"
  ]);
  function hostPositionStyle(style) {
    const all = typeof style === "string" ? cssToObj(style) : style != null && typeof style === "object" ? style : null;
    if (!all) return void 0;
    const out = {};
    for (const [k, v] of Object.entries(all)) {
      const kebab = k.replace(/[A-Z]/g, (c) => "-" + c.toLowerCase());
      if (HOST_STYLE_PROPS.has(kebab)) out[k] = v;
    }
    return Object.keys(out).length ? out : void 0;
  }
  function compileTemplate(html, host) {
    const tpl = document.createElement("template");
    //! nosemgrep: direct-inner-html-assignment
    tpl.innerHTML = encodeCase(html);
    let tplN = 0;
    (function stamp(node) {
      if (node.nodeType === Node.ELEMENT_NODE) {
        node.setAttribute("data-dc-tpl", String(tplN++));
      }
      for (const c of node.childNodes) stamp(c);
    })(tpl.content);
    const builders = walkChildren(tpl.content, host);
    const render = ((vals, ctx) => builders.map((b, i) => b(vals || {}, ctx, i)));
    render.__annotated = tpl.innerHTML;
    return render;
  }
  function walkChildren(node, host) {
    return [...node.childNodes].map((c) => walk(c, host)).filter((b) => b != null);
  }
  function walk(node, host) {
    if (node.nodeType === Node.TEXT_NODE) return walkText(node);
    if (node.nodeType !== Node.ELEMENT_NODE) return null;
    const el = node;
    const tag = el.tagName.toLowerCase();
    if (tag === "sc-for") return walkFor(el, host);
    if (tag === "sc-if") return walkIf(el, host);
    if (tag === "x-import") return walkXImport(el, host);
    if (tag === "sc-helmet") return host.helmet(el);
    if (tag === "dc-import") return walkComponent(el, host);
    return walkElement(el, host);
  }
  var warnedHoles = /* @__PURE__ */ new Set();
  function warnUnresolved(ctx, what) {
    const key = (ctx?.__name || "?") + "\0" + what;
    if (warnedHoles.has(key)) return;
    warnedHoles.add(key);
    console.warn("[dc-runtime] " + (ctx?.__name || "template") + ": " + what);
  }
  function walkText(node) {
    const txt = node.nodeValue ?? "";
    if (!txt.includes("{{")) {
      if (!txt.trim() && !txt.includes(" ")) return null;
      return () => txt;
    }
    const parts = txt.split(/\{\{([\s\S]+?)\}\}/g);
    return (vals, ctx, key) => h(
      getReact().Fragment,
      { key },
      ...parts.map((p, i) => {
        if (!(i & 1)) return p;
        const v = resolve(vals, p);
        if (v === void 0) {
          if (!ctx?.__streamingNow) {
            if (document.body?.hasAttribute("data-dc-editor-on")) {
              return h(
                "span",
                { key: i, className: "sc-interp sc-unresolved" },
                "{{ " + p.trim() + " }}"
              );
            }
            warnUnresolved(
              ctx,
              "{{ " + p.trim() + " }} never resolved \u2014 rendered as empty"
            );
            return null;
          }
          return h(
            "span",
            { key: i, className: "sc-interp sc-missing" },
            p.trim()
          );
        }
        if (getReact().isValidElement(v) || Array.isArray(v)) {
          return h(getReact().Fragment, { key: i }, v);
        }
        if (v === null || typeof v === "boolean") return null;
        return h("span", { key: i, className: "sc-interp" }, String(v));
      })
    );
  }
  function walkFor(el, host) {
    const listGet = compileAttr(el.getAttribute("list") || "");
    const asName = el.getAttribute("as") || "item";
    const hintN = parseInt(el.getAttribute("hint-placeholder-count") || "0", 10);
    const kids = walkChildren(el, host);
    const listSrc = el.getAttribute("list") || "";
    return (vals, ctx, key) => {
      let list = listGet(vals);
      if (!Array.isArray(list)) {
        if (!ctx?.__streamingNow) {
          if (list !== void 0 && list !== null) {
            warnUnresolved(
              ctx,
              'sc-for list="' + listSrc + '" is not an array (' + typeof list + ")"
            );
          }
          list = [];
        } else {
          list = hintN > 0 ? Array(hintN).fill(void 0) : [];
        }
      }
      return h(
        getReact().Fragment,
        { key },
        list.map((item, i) => {
          const sub = { ...vals, [asName]: item, $index: i };
          return h(
            getReact().Fragment,
            { key: i },
            kids.map((b, j) => b(sub, ctx, j))
          );
        })
      );
    };
  }
  function walkIf(el, host) {
    const valGet = compileAttr(el.getAttribute("value") || "");
    const hintRaw = el.getAttribute("hint-placeholder-val");
    const hintGet = hintRaw != null ? compileAttr(hintRaw) : null;
    const kids = walkChildren(el, host);
    return (vals, ctx, key) => {
      let v = valGet(vals);
      if (v === void 0 && hintGet && ctx?.__streamingNow) v = hintGet(vals);
      return v ? h(
        getReact().Fragment,
        { key },
        kids.map((b, j) => b(vals, ctx, j))
      ) : null;
    };
  }
  function walkComponent(el, host) {
    const name = el.getAttribute("name") || el.getAttribute("component") || "";
    el.removeAttribute("name");
    el.removeAttribute("component");
    const tplId = el.getAttribute("data-dc-tpl");
    const styleRaw = el.getAttribute("style");
    el.removeAttribute("style");
    const styleGet = styleRaw != null ? compileAttr(styleRaw) : null;
    const { propGetters, hintSize } = collectProps(el, "dc-import", host);
    const kids = walkChildren(el, host);
    return (vals, ctx, key) => {
      const props = {
        key,
        __hintSize: hintSize,
        __tplId: tplId,
        __hostStyle: styleGet ? hostPositionStyle(styleGet(vals)) : void 0
      };
      for (const [k, g] of propGetters) {
        const v = g(vals);
        if (k === "dcProps") {
          if (v && typeof v === "object") Object.assign(props, v);
          continue;
        }
        props[k] = v;
      }
      if (kids.length) props.children = kids.map((b, j) => b(vals, ctx, j));
      return h(host.component(name), props);
    };
  }
  function walkXImport(el, host) {
    const globalNameGet = compileAttr(
      el.getAttribute("component-from-global-scope") || ""
    );
    const exportNameGet = compileAttr(
      el.getAttribute("component") || el.getAttribute("name") || ""
    );
    const url = el.getAttribute("from") || el.getAttribute("src") || el.getAttribute("import") || "";
    const kind = /\.(jsx|tsx)(\?|#|$)/i.test(url) ? "jsx" : "js";
    const tplId = el.getAttribute("data-dc-tpl");
    const styleRaw = el.getAttribute("style");
    el.removeAttribute("style");
    const styleGet = styleRaw != null ? compileAttr(styleRaw) : null;
    const wrap = tplId != null || styleGet != null;
    const { propGetters, hintSize } = collectProps(el, "x-import", host);
    const hasContent = el.children.length > 0 || !!(el.textContent || "").trim();
    const kids = hasContent ? walkChildren(el, host) : [];
    const urlBindable = url.includes("{{");
    if (url && !urlBindable) host.loadExternal(kind, url);
    const evalName = (g, vals) => {
      const v = g(vals);
      const s = v == null ? "" : String(v);
      return s.includes("{{") ? "" : s;
    };
    return (vals, ctx, key) => {
      const globalName = evalName(globalNameGet, vals);
      const name = globalName || evalName(exportNameGet, vals);
      const C = !name || urlBindable ? null : globalName ? host.resolveExternalGlobal(url, globalName) : host.resolveExternal(url, name);
      const hostStyle = styleGet ? hostPositionStyle(styleGet(vals)) : void 0;
      const wrapper = wrap ? {
        key,
        className: "sc-host-x",
        "data-dc-tpl": tplId,
        style: hostStyle || { display: "contents" }
      } : null;
      if (!C) {
        const error = urlBindable ? "x-import `from` cannot contain {{ \u2026 }} \u2014 module URLs are resolved at parse time; use a literal URL" : host.resolveExternalError(url, name);
        const ph = host.placeholder({
          key: wrapper ? void 0 : key,
          name,
          hintSize,
          error
        });
        return wrapper ? h("div", wrapper, ph) : ph;
      }
      const props = wrapper ? {} : { key };
      let unresolvedHole = false;
      for (const [k, g] of propGetters) {
        if (k === "component" || k === "componentFromGlobalScope" || k === "from") {
          continue;
        }
        const v = g(vals);
        if (v === void 0) unresolvedHole = true;
        if (k === "dcProps") {
          if (v && typeof v === "object") Object.assign(props, v);
          continue;
        }
        props[k] = v;
      }
      if (unresolvedHole && ctx?.__htmlStreamingNow) {
        const ph = host.placeholder({
          key: wrapper ? void 0 : key,
          name,
          hintSize,
          error: null
        });
        return wrapper ? h("div", wrapper, ph) : ph;
      }
      if (kids.length) props.children = kids.map((b, j) => b(vals, ctx, j));
      return wrapper ? h("div", wrapper, h(C, props)) : h(C, props);
    };
  }
  function walkElement(el, host) {
    const realTag = RAW_UNWRAP[el.localName] || el.localName;
    const tplId = el.getAttribute("data-dc-tpl");
    const { propGetters, pseudoClasses } = collectProps(el, "dom", host);
    const kids = walkChildren(el, host);
    return (vals, ctx, key) => {
      const props = { key, "data-dc-tpl": tplId };
      for (const [k, g] of propGetters) {
        let v = g(vals);
        if (k === "style" && typeof v === "string") v = cssToObj(v);
        if ((k === "value" || k === "checked") && v === void 0) {
          v = k === "checked" ? false : "";
        }
        props[k] = v;
      }
      if (pseudoClasses.length) {
        props.className = [props.className, ...pseudoClasses].filter(Boolean).join(" ");
      }
      return h(realTag, props, ...kids.map((b, j) => b(vals, ctx, j)));
    };
  }

  // src/logic.ts
  var StreamableLogic = class {
    constructor(props) {
      __publicField(this, "props");
      __publicField(this, "state", {});
      /** Back-pointer to the wrapper component, installed after construction. */
      __publicField(this, "__host");
      this.props = props || {};
    }
    setState(update, cb) {
      this.__host && this.__host.__setLogicState(update, cb);
    }
    forceUpdate() {
      this.__host && this.__host.forceUpdate();
    }
    componentDidMount() {
    }
    componentDidUpdate(_prevProps) {
    }
    componentWillUnmount() {
    }
    /** The flat object the template renders against (merged over props). */
    renderVals() {
      return {};
    }
  };
  function evalDcLogic(src) {
    //! nosemgrep: eval-and-function-constructor
    const fn = new Function(
      "DCLogic",
      "StreamableLogic",
      "React",
      src + '\n;return (typeof Component!=="undefined"&&Component)||undefined;'
    );
    return fn(StreamableLogic, StreamableLogic, getReact());
  }

  // src/component.ts
  function shallowEqual(a, b) {
    if (!b) return false;
    const ak = Object.keys(a).filter((k) => k !== "children");
    const bk = Object.keys(b).filter((k) => k !== "children");
    if (ak.length !== bk.length) return false;
    for (const k of ak) if (a[k] !== b[k]) return false;
    return true;
  }
  function Placeholder({
    name,
    hintSize,
    streaming,
    error
  }) {
    const [w, hgt] = (hintSize || "100%,60px").split(",");
    return h(
      "div",
      {
        className: "sc-placeholder" + (streaming ? " sc-streaming" : ""),
        style: { width: w.trim(), height: hgt && hgt.trim() },
        title: name
      },
      error ? h(
        "div",
        { className: "sc-placeholder-error" },
        (name ? name + ": " : "") + error
      ) : null
    );
  }
  function hintToMin(hint) {
    if (!hint) return void 0;
    const [w, hgt] = hint.split(",");
    return { minWidth: w.trim(), minHeight: hgt && hgt.trim() };
  }
  function createComponentFactory(registry, ensureFetched) {
    const React = getReact();
    const AncestorContext = React.createContext([]);
    class StreamableComponent extends React.Component {
      constructor(props) {
        super(props);
        __publicField(this, "__name");
        __publicField(this, "__sub");
        __publicField(this, "__needsDidMount", false);
        /** Snapshot of the registry's streaming flags taken at render time —
         *  builders read it off the RenderCtx (this) to pick placeholder vs
         *  render-nothing for unresolved values. */
        __publicField(this, "__streamingNow", false);
        __publicField(this, "__htmlStreamingNow", false);
        /** When a construct throws, remember the (class, registry.ver, props)
         *  triple so render-time reconcile doesn't re-attempt it on every parent
         *  re-render. A registry bump (new class, template, external module
         *  resolving via bumpAll) changes `ver` and breaks the memo so an
         *  env-dependent constructor can self-heal. */
        __publicField(this, "__failedLogic", null);
        __publicField(this, "__failedUserProps", null);
        __publicField(this, "__failedVer", -1);
        /** Per-instance constructor error — kept here (not on the registry entry)
         *  so one instance's successful construct can't hide a sibling's failure,
         *  and a construct can never wipe an eval error `updateJs` recorded on
         *  `r.logicError`. */
        __publicField(this, "__ctorError", null);
        __publicField(this, "logic");
        this.__name = props.__name;
        this.state = { __v: 0, __err: null };
        this.__sub = () => {
          if (this.state.__err) this.setState({ __err: null });
          this.forceUpdate();
        };
        this.__makeLogic(registry.get(this.__name).Logic, null);
        ensureFetched(this.__name);
      }
      /** Error-boundary hook: a render crash anywhere in this DC's subtree
       *  (its own template, an x-import'd component, a child DC without its
       *  own deeper boundary) lands here instead of unmounting the page. */
      static getDerivedStateFromError(e) {
        return { __err: e instanceof Error && e.message ? e.message : String(e) };
      }
      componentDidCatch(e, info) {
        console.error(
          "[dc-runtime] render error in <" + this.__name + ">:",
          e,
          info?.componentStack || ""
        );
      }
      /** Instantiate the logic class (or the no-op base) and adopt `prevState`
       *  over its initial state — used both at mount and on hot-swap. */
      __makeLogic(Logic, prevState) {
        const L = Logic || StreamableLogic;
        try {
          this.logic = new L(this.__userProps());
          this.__failedLogic = null;
          this.__failedUserProps = null;
          this.__ctorError = null;
        } catch (e) {
          console.error(e);
          this.__failedLogic = Logic;
          this.__failedUserProps = this.__userProps();
          this.__failedVer = registry.get(this.__name).ver;
          this.__ctorError = this.__name + ": " + (e instanceof Error && e.message ? e.message : String(e));
          this.logic = new StreamableLogic(
            this.__userProps()
          );
        }
        this.logic.__host = this;
        if (prevState)
          this.logic.state = { ...this.logic.state || {}, ...prevState };
      }
      /** The props the author's logic + template see — internal __-prefixed
       *  wiring stripped. */
      __userProps() {
        const { __name, __hintSize, __tplId, __hostStyle, ...rest } = this.props;
        return rest;
      }
      __setLogicState(update, cb) {
        const prev = this.logic.state;
        const patch = typeof update === "function" ? update(prev) : update;
        this.logic.state = { ...prev, ...patch };
        this.setState((s) => ({ __v: s.__v + 1 }), cb);
      }
      /** Swap the logic instance when the registry's Logic class changed
       *  (streaming completion, hot reload). State carries over; didMount
       *  re-fires after the swap commits so refs exist. */
      __reconcileLogic() {
        const r = registry.get(this.__name);
        const Next = r.Logic;
        const Cur = this.logic.constructor;
        if (Next === Cur || !Next && Cur === StreamableLogic || Next === this.__failedLogic && r.ver === this.__failedVer && shallowEqual(this.__userProps(), this.__failedUserProps)) {
          return;
        }
        if (!this.__needsDidMount) {
          try {
            this.logic.componentWillUnmount();
          } catch (e) {
            console.error(e);
          }
        }
        this.__makeLogic(Next, this.logic.state);
        this.__needsDidMount = true;
      }
      componentDidMount() {
        registry.get(this.__name).subs.add(this.__sub);
        try {
          this.logic.componentDidMount();
        } catch (e) {
          console.error(e);
        }
      }
      componentDidUpdate(prevProps) {
        this.logic.props = this.__userProps();
        if (this.__needsDidMount) {
          if (this.state.__err || !registry.get(this.__name).tpl) return;
          this.__needsDidMount = false;
          try {
            this.logic.componentDidMount();
          } catch (e) {
            console.error(e);
          }
        } else {
          try {
            this.logic.componentDidUpdate(prevProps);
          } catch (e) {
            console.error(e);
          }
        }
      }
      componentWillUnmount() {
        registry.get(this.__name).subs.delete(this.__sub);
        if (!this.__needsDidMount) {
          try {
            this.logic.componentWillUnmount();
          } catch (e) {
            console.error(e);
          }
        }
      }
      render() {
        const r = registry.get(this.__name);
        const cls = "sc-host" + (r.htmlStreaming ? " sc-streaming-html" : "") + (r.jsStreaming ? " sc-streaming-js" : "");
        const hintStyle = r.htmlStreaming ? hintToMin(this.props.__hintSize) : void 0;
        const hostStyle = this.props.__hostStyle || hintStyle ? { ...hintStyle || {}, ...this.props.__hostStyle || {} } : void 0;
        const hostBase = {
          className: cls,
          style: hostStyle,
          "data-sc-name": this.__name,
          "data-dc-tpl": this.props.__tplId
        };
        const chain = Array.isArray(this.context) ? this.context : [];
        if (chain.includes(this.__name)) {
          const cycle = [
            ...chain.slice(chain.indexOf(this.__name)),
            this.__name
          ].join(" \u2192 ");
          return h(
            "div",
            { ...hostBase, className: cls + " sc-has-error" },
            h(Placeholder, {
              name: this.__name,
              hintSize: this.props.__hintSize,
              error: "circular import: " + cycle
            })
          );
        }
        if (this.state.__err) {
          return h(
            "div",
            { ...hostBase, className: cls + " sc-has-error" },
            h(
              "div",
              { className: "sc-logic-error", "data-omelette-chrome": "" },
              this.__name + ": " + this.state.__err
            ),
            h(Placeholder, {
              name: this.__name,
              hintSize: this.props.__hintSize,
              error: this.state.__err
            })
          );
        }
        this.__reconcileLogic();
        if (!r.tpl) {
          return h(
            "div",
            hostBase,
            h(Placeholder, { name: this.__name, hintSize: this.props.__hintSize })
          );
        }
        const userProps = this.__userProps();
        this.logic.props = userProps;
        let vals = userProps;
        let renderErr = r.logicError || this.__ctorError;
        try {
          vals = { ...userProps, ...this.logic.renderVals() || {} };
        } catch (e) {
          console.error(e);
          renderErr = this.__name + ".renderVals(): " + (e instanceof Error && e.message ? e.message : String(e));
        }
        this.__streamingNow = !!(r.htmlStreaming || r.jsStreaming);
        this.__htmlStreamingNow = !!r.htmlStreaming;
        return h(
          "div",
          { ...hostBase, className: cls + (renderErr ? " sc-has-error" : "") },
          renderErr && h(
            "div",
            { className: "sc-logic-error", "data-omelette-chrome": "" },
            renderErr
          ),
          h(
            AncestorContext.Provider,
            { value: [...chain, this.__name] },
            r.tpl(vals, this)
          )
        );
      }
    }
    __publicField(StreamableComponent, "contextType", AncestorContext);
    const named = /* @__PURE__ */ new Map();
    function getDC(name) {
      const hit = named.get(name);
      if (hit) return hit;
      function Dispatcher(p) {
        const [, setTick] = React.useState(0);
        React.useEffect(() => {
          const sub = () => setTick((n) => n + 1);
          registry.get(name).subs.add(sub);
          return () => {
            registry.get(name).subs.delete(sub);
          };
        }, []);
        ensureFetched(name);
        return h(StreamableComponent, { ...p, __name: name });
      }
      Dispatcher.displayName = name;
      named.set(name, Dispatcher);
      return Dispatcher;
    }
    return {
      getDC,
      StreamableComponent
    };
  }

  // src/external.ts
  var isCustomElementName = (n) => !n.includes(".") && n.includes("-");
  function isRenderableType(g) {
    if (typeof g === "function") return !isElementClass(g);
    return typeof g === "object" && g !== null && typeof g.$$typeof === "symbol";
  }
  function resolveDottedPath(root, name) {
    let cur = root;
    for (const seg of name.split(".")) {
      if (cur == null) return void 0;
      cur = cur[seg];
    }
    return cur;
  }
  var BABEL_URL = "https://unpkg.com/@babel/standalone@7.26.4/babel.min.js";
  var GLOBAL_POLL_INTERVAL_MS = 50;
  var GLOBAL_POLL_TIMEOUT_MS = 3e4;
  function createExternalModules(onResolved) {
    const cache = /* @__PURE__ */ new Map();
    let babelLoading = null;
    const reportedMissing = /* @__PURE__ */ new Map();
    const polling = /* @__PURE__ */ new Set();
    function ensureBabel() {
      if (window.Babel) return Promise.resolve();
      if (babelLoading) return babelLoading;
      babelLoading = new Promise((res, rej) => {
        const s = document.createElement("script");
        s.src = BABEL_URL;
        s.crossOrigin = "anonymous";
        s.onload = () => res();
        s.onerror = rej;
        document.head.appendChild(s);
      });
      return babelLoading;
    }
    function load(kind, url) {
      if (cache.has(url)) return;
      cache.set(url, null);
      console.info("[dc-runtime] x-import: loading", url, "(" + kind + ")");
      const ready = kind === "jsx" ? ensureBabel() : Promise.resolve();
      ready.then(() => fetch(url)).then((r) => {
        if (!r.ok) throw new Error("HTTP " + r.status);
        return r.text();
      }).then((src) => {
        const code = kind === "jsx" ? window.Babel.transform(src, {
          filename: url,
          presets: ["react", "typescript"]
        }).code : src;
        const module = { exports: {} };
        const before = new Set(Object.keys(window));
        //! nosemgrep: eval-and-function-constructor
        new Function("React", "module", "exports", "require", code)(
          getReact(),
          module,
          module.exports,
          () => ({})
        );
        const globals = {};
        for (const k of Object.keys(window)) {
          if (!before.has(k) && typeof window[k] === "function") {
            globals[k] = window[k];
          }
        }
        cache.set(url, { mod: module.exports, globals });
        console.info(
          "[dc-runtime] x-import: loaded",
          url,
          "\u2014 exports:",
          Object.keys(module.exports),
          "window globals:",
          Object.keys(globals)
        );
        onResolved();
      }).catch((e) => {
        cache.set(url, {
          mod: {},
          globals: {},
          error: "failed to load: " + (e instanceof Error && e.message ? e.message : String(e))
        });
        console.error(
          "[dc-runtime] x-import: FAILED to load",
          url,
          "(" + kind + ")",
          e
        );
        onResolved();
      });
    }
    function resolve2(url, name) {
      const entry = cache.get(url);
      if (!entry) return null;
      const { mod, globals } = entry;
      const C = mod && mod[name] || globals && globals[name] || typeof window !== "undefined" && window[name] || mod && mod.default;
      if (typeof C === "function") return C;
      const key = url + "\0" + name;
      if (!reportedMissing.has(key)) {
        reportedMissing.set(
          key,
          entry.error || 'no export named "' + name + '" (has: ' + Object.keys(mod).join(", ") + ")"
        );
        console.error(
          "[dc-runtime] x-import: module",
          url,
          "loaded but has no component named",
          JSON.stringify(name),
          "\u2014 available exports:",
          Object.keys(mod),
          "window globals:",
          Object.keys(globals),
          ". The module must `module.exports = {" + name + "}` or set `window." + name + "`."
        );
      }
      return null;
    }
    function waitForGlobal(name) {
      if (polling.has(name)) return;
      polling.add(name);
      const started = Date.now();
      const isCE = isCustomElementName(name);
      const tick = () => {
        const found = isCE ? customElements.get(name) : isRenderableType(resolveDottedPath(window, name));
        if (found) {
          polling.delete(name);
          onResolved();
          return;
        }
        if (Date.now() - started >= GLOBAL_POLL_TIMEOUT_MS) {
          console.warn(
            "[dc-runtime] x-import: global",
            JSON.stringify(name),
            "never appeared on window after " + GLOBAL_POLL_TIMEOUT_MS + "ms"
          );
          return;
        }
        setTimeout(tick, GLOBAL_POLL_INTERVAL_MS);
      };
      setTimeout(tick, GLOBAL_POLL_INTERVAL_MS);
    }
    function resolveGlobal(url, name) {
      const isCE = isCustomElementName(name);
      if (!url) {
        if (isCE) {
          if (customElements.get(name)) return name;
          waitForGlobal(name);
          return null;
        }
        const g2 = resolveDottedPath(window, name);
        if (isRenderableType(g2)) return g2;
        waitForGlobal(name);
        return null;
      }
      const entry = cache.get(url);
      if (!entry) return null;
      if (isCE && customElements.get(name)) return name;
      const g = entry.globals[name] ?? resolveDottedPath(window, name);
      if (isRenderableType(g)) return g;
      if (name.includes(".")) return null;
      const key = url + "\0global\0" + name;
      if (!reportedMissing.has(key)) {
        reportedMissing.set(key, null);
        if (isCE && !customElements.get(name)) {
          console.warn(
            "[dc-runtime] x-import:",
            url,
            "loaded but no custom element",
            JSON.stringify(name),
            "is registered and window." + name + " is not a function \u2014 rendering <" + name + "> as an unknown element."
          );
        }
      }
      return name;
    }
    function getError(url, name) {
      const entry = cache.get(url);
      if (entry?.error) return entry.error;
      return reportedMissing.get(url + "\0" + name) || null;
    }
    return { load, resolve: resolve2, resolveGlobal, getError };
  }
  function isElementClass(g) {
    try {
      return typeof g === "function" && typeof HTMLElement !== "undefined" && g.prototype instanceof HTMLElement;
    } catch {
      return false;
    }
  }

  // src/atomics.ts
  var ATOMIC_CSS = (
    // layout
    ".fx{display:flex}.col{display:flex;flex-direction:column}.grid{display:grid}.ac{align-items:center}.jc{justify-content:center}.jb{justify-content:space-between}.f1{flex:1}.noshrink{flex-shrink:0}.wrap{flex-wrap:wrap}.fw5{font-weight:500}.fw6{font-weight:600}.fw7{font-weight:700}.fw8{font-weight:800}.fs11{font-size:11px}.fs12{font-size:12px}.fs13{font-size:13px}.fs14{font-size:14px}.fs15{font-size:15px}.fs16{font-size:16px}.fs20{font-size:20px}.fs22{font-size:22px}.upper{text-transform:uppercase}.tc{text-align:center}.nowrap{white-space:nowrap}.gap8{gap:8px}.gap10{gap:10px}.gap12{gap:12px}.gap16{gap:16px}.gap24{gap:24px}.m0{margin:0}.mt8{margin-top:8px}.mt12{margin-top:12px}.mt16{margin-top:16px}.mb8{margin-bottom:8px}.mb12{margin-bottom:12px}.mb16{margin-bottom:16px}.posrel{position:relative}.posabs{position:absolute}.round{border-radius:50%}.ohide{overflow:hidden}.bbox{box-sizing:border-box}.pointer{cursor:pointer}.w100{width:100%}.b0{border:none}"
  );

  // src/helmet.ts
  var DESIGN_DOC_MODE_RE = /<meta\b[^>]*\bname\s*=\s*["']design_doc_mode["'][^>]*\b(?:content|value)\s*=\s*["'](\w+)["']/i;
  var CANVAS_BG = "#f0eee9";
  function createHelmetManager(doc, isStreaming) {
    const mounted = /* @__PURE__ */ new Set();
    const live = /* @__PURE__ */ new Map();
    let designDocMode = null;
    let canvasStyleEl = null;
    function postDesignMode(mode) {
      if (window.parent === window) return;
      try {
        window.parent.postMessage({ type: "__dc_design_mode", mode }, "*");
      } catch {
      }
    }
    function setDesignDocMode(mode) {
      if (mode === designDocMode) return;
      designDocMode = mode;
      postDesignMode(mode);
      if (mode === "canvas") {
        doc.documentElement.setAttribute("data-dc-canvas", "");
        canvasStyleEl = doc.createElement("style");
        canvasStyleEl.setAttribute("data-dc-canvas", "");
        canvasStyleEl.textContent = `html,body{background:${CANVAS_BG}}#dc-root>.sc-host{position:relative}`;
        doc.head.appendChild(canvasStyleEl);
      } else {
        doc.documentElement.removeAttribute("data-dc-canvas");
        canvasStyleEl?.remove();
        canvasStyleEl = null;
      }
    }
    window.addEventListener("message", (e) => {
      if (!designDocMode || (e.data && e.data.type) !== "__dc_probe") return;
      postDesignMode(designDocMode);
    });
    function compile(node) {
      const raw = [...node.children];
      const helmetClosed = node.nextSibling != null || node.parentNode?.nextSibling != null;
      if (node.hasAttribute("data-dc-atomics") && !mounted.has("__dc-atomics")) {
        mounted.add("__dc-atomics");
        const el = doc.createElement("style");
        el.id = "__dc-atomics";
        el.textContent = ATOMIC_CSS;
        doc.head.appendChild(el);
      }
      return (_vals, ctx) => {
        const name = ctx && ctx.__name || "";
        const streaming = !!(name && isStreaming(name));
        for (let i = 0; i < raw.length; i++) {
          const child = raw[i];
          const tag = child.tagName;
          const mayBePartial = streaming && !helmetClosed && i === raw.length - 1;
          if (tag === "SCRIPT") {
            if (mayBePartial) continue;
            const key = "SCRIPT|" + (child.getAttribute("src") || child.textContent || "");
            if (mounted.has(key)) continue;
            mounted.add(key);
            const el = doc.createElement("script");
            for (const { name: an, value } of [...child.attributes])
              el.setAttribute(an, value);
            if (child.textContent) el.textContent = child.textContent;
            doc.head.appendChild(el);
          } else if (tag === "LINK" || tag === "META") {
            if (mayBePartial) continue;
            const key = tag + "|" + (child.getAttribute("href") || child.getAttribute("src") || child.outerHTML);
            if (mounted.has(key)) continue;
            mounted.add(key);
            doc.head.appendChild(child.cloneNode(true));
          } else {
            const key = name + "|" + i;
            let el = live.get(key);
            if (!el || el.tagName !== tag) {
              if (el) el.remove();
              el = doc.createElement(tag.toLowerCase());
              live.set(key, el);
              doc.head.appendChild(el);
            }
            for (const { name: an, value } of [...child.attributes]) {
              if (el.getAttribute(an) !== value) el.setAttribute(an, value);
            }
            if (el.textContent !== child.textContent)
              el.textContent = child.textContent;
          }
        }
        return null;
      };
    }
    return { compile, setDesignDocMode };
  }

  // src/pseudo.ts
  function createPseudoSheet(doc) {
    let el = null;
    const cache = /* @__PURE__ */ new Map();
    let n = 0;
    return (pseudo, css) => {
      const k = pseudo + "|" + css;
      const hit = cache.get(k);
      if (hit) return hit;
      if (!el) {
        el = doc.createElement("style");
        doc.head.appendChild(el);
      }
      const cls = "scp" + (n++).toString(36);
      const sel = pseudo === "before" || pseudo === "after" ? "." + cls + "::" + pseudo : "." + cls + ":" + pseudo;
      el.sheet.insertRule(sel + "{" + css + "}", el.sheet.cssRules.length);
      cache.set(k, cls);
      return cls;
    };
  }

  // src/registry.ts
  function createRegistry() {
    const entries = /* @__PURE__ */ Object.create(null);
    function get(name) {
      return entries[name] || (entries[name] = {
        html: "",
        tpl: null,
        Logic: null,
        jsStreaming: false,
        htmlStreaming: false,
        ver: 0,
        subs: /* @__PURE__ */ new Set(),
        fetched: false
      });
    }
    function bump(name) {
      const r = get(name);
      r.ver++;
      for (const fn of r.subs) fn();
    }
    return {
      entries,
      get,
      bump,
      bumpAll() {
        for (const n in entries) bump(n);
      }
    };
  }

  // src/runtime.ts
  var COMPONENT_DIR = ".";
  function createRuntime(doc = document) {
    const registry = createRegistry();
    const pseudoClass = createPseudoSheet(doc);
    const helmet = createHelmetManager(
      doc,
      (name) => registry.get(name).htmlStreaming
    );
    const external = createExternalModules(() => registry.bumpAll());
    const factory = createComponentFactory(registry, ensureFetched);
    const host = {
      component: (name) => factory.getDC(name),
      placeholder: (props) => h(Placeholder, props),
      helmet: (node) => helmet.compile(node),
      loadExternal: (kind, url) => external.load(kind, url),
      resolveExternal: (url, name) => external.resolve(url, name),
      resolveExternalGlobal: (url, name) => external.resolveGlobal(url, name),
      resolveExternalError: (url, name) => external.getError(url, name),
      pseudoClass
    };
    function ensureFetched(name) {
      const r = registry.get(name);
      if (r.fetched) return;
      r.fetched = true;
      const url = COMPONENT_DIR + "/" + encodeURIComponent(name) + ".dc.html";
      fetch(url).then((res) => {
        if (!res.ok) {
          console.error(
            "[dc-runtime] sibling fetch for <" + name + "/> failed:",
            url,
            "returned",
            res.status,
            "\u2014 the reference renders as an empty placeholder."
          );
          return "";
        }
        return res.text();
      }).then((t) => {
        if (!t) return;
        const parsed = parseDcText(t);
        if (!parsed) {
          console.error(
            "[dc-runtime] sibling fetch for <" + name + "/>:",
            url,
            "has no <x-dc> block \u2014 not a Design Component."
          );
          return;
        }
        if (parsed.props) r.propsMeta = parsed.props;
        if (parsed.preview) r.preview = parsed.preview;
        if (parsed.template && !r.html) updateHtml(name, parsed.template);
        if (parsed.js && !r.Logic) updateJs(name, parsed.js);
      }).catch(
        (e) => console.error(
          "[dc-runtime] sibling fetch for <" + name + "/> threw:",
          url,
          e
        )
      );
    }
    let rootName = null;
    function updateHtml(name, html) {
      const r = registry.get(name);
      r.html = html;
      if (name === rootName) {
        const mode = DESIGN_DOC_MODE_RE.exec(html)?.[1] ?? null;
        if (mode || !r.htmlStreaming) helmet.setDesignDocMode(mode);
      }
      try {
        r.tpl = compileTemplate(html, host);
      } catch (e) {
        console.error("[dc-runtime] template compile FAILED for", name, e);
      }
      registry.bump(name);
    }
    function updateJs(name, src) {
      const r = registry.get(name);
      const seq = r.jsSeq = (r.jsSeq || 0) + 1;
      try {
        const Cls = evalDcLogic(src);
        if (r.jsSeq !== seq) return;
        if (typeof Cls !== "function") {
          r.logicError = name + ".dc.html: <script data-dc-script> must define `class Component extends DCLogic`";
        } else {
          r.logicError = null;
          r.Logic = Cls;
        }
      } catch (e) {
        if (r.jsSeq !== seq) return;
        console.error(
          "[dc-runtime] logic class eval FAILED for",
          name,
          "\u2014 the template renders with props only.",
          e
        );
        r.logicError = name + ": " + (e instanceof Error && e.message ? e.message : String(e));
      }
      registry.bump(name);
    }
    function setStreaming(name, kind, on) {
      const r = registry.get(name);
      if (kind === "html") r.htmlStreaming = !!on;
      else r.jsStreaming = !!on;
      let any = false;
      for (const n in registry.entries) {
        const e = registry.entries[n];
        if (e && (e.htmlStreaming || e.jsStreaming)) {
          any = true;
          break;
        }
      }
      doc.documentElement.classList.toggle("sc-dc-streaming", any);
      registry.bump(name);
    }
    function dcUpdate(name, kind, content, streaming) {
      if (streaming) registry.get(name).fetched = true;
      if (kind === "html") {
        setStreaming(name, "html", !!streaming);
        updateHtml(name, content);
      } else if (kind === "js") {
        setStreaming(name, "js", !!streaming);
        if (!streaming) updateJs(name, content);
      } else if (kind === "props") {
        const { props, preview } = parseDataProps(content);
        const r = registry.get(name);
        r.propsMeta = props ?? void 0;
        r.preview = preview;
        registry.bump(name);
      }
    }
    function setProps(name, overrides) {
      registry.get(name).propOverrides = overrides && typeof overrides === "object" ? { ...overrides } : null;
      registry.bump(name);
    }
    function adoptParsed(name, parsed) {
      if (!parsed) return;
      const r = registry.get(name);
      if (parsed.props) r.propsMeta = parsed.props;
      if (parsed.preview) r.preview = parsed.preview;
      if (parsed.template) updateHtml(name, parsed.template);
      if (parsed.js) updateJs(name, parsed.js);
    }
    return {
      registry,
      getDC: factory.getDC,
      updateHtml,
      updateJs,
      dcUpdate,
      setProps,
      adoptParsed,
      setRootName: (name) => {
        rootName = name;
      },
      markFetched: (name) => {
        registry.get(name).fetched = true;
      },
      annotatedTemplate: (name) => {
        const r = registry.get(name);
        return r.tpl && r.tpl.__annotated || null;
      },
      templateSource: (name) => registry.get(name).html || null,
      StreamableLogic
    };
  }

  // src/index.ts
  var REACT_URL = "https://unpkg.com/react@18.3.1/umd/react.production.min.js";
  var REACT_SRI = "sha384-DGyLxAyjq0f9SPpVevD6IgztCFlnMF6oW/XQGmfe+IsZ8TqEiDrcHkMLKI6fiB/Z";
  var REACT_DOM_URL = "https://unpkg.com/react-dom@18.3.1/umd/react-dom.production.min.js";
  var REACT_DOM_SRI = "sha384-gTGxhz21lVGYNMcdJOyq01Edg0jhn/c22nsx0kyqP0TxaV5WVdsSH1fSDUf5YJj1";
  function hideRawTemplate() {
    const s = document.createElement("style");
    s.textContent = "x-dc{display:none!important}";
    document.head.appendChild(s);
  }
  function loadScript(src, integrity) {
    return new Promise((resolve2, reject) => {
      //! nosemgrep: create-script-element
      const s = document.createElement("script");
      s.src = src;
      s.integrity = integrity;
      s.crossOrigin = "anonymous";
      s.async = false;
      s.onload = () => resolve2();
      s.onerror = () => reject(new Error(`failed to load ${src}`));
      document.head.appendChild(s);
    });
  }
  function loadReactUmd() {
    const w = window;
    if (w.React && w.ReactDOM) return Promise.resolve();
    return Promise.all([
      loadScript(REACT_URL, REACT_SRI),
      loadScript(REACT_DOM_URL, REACT_DOM_SRI)
    ]).then(() => void 0);
  }
  function init() {
    const runtime = createRuntime(document);
    let rootName = "Root";
    const baseCss = document.createElement("style");
    baseCss.textContent = BASE_CSS;
    document.head.prepend(baseCss);
    const notifyHost = () => {
      if (window.parent === window) return;
      const r = runtime.registry.entries[rootName];
      try {
        window.parent.postMessage(
          {
            type: "__dc_booted",
            rootName,
            propsMeta: r && r.propsMeta || null,
            preview: r && r.preview || null
          },
          "*"
        );
      } catch {
      }
    };
    const api = {
      __dcUpdate: (name, kind, content, streaming) => {
        runtime.dcUpdate(name, kind, content, streaming);
        if (name === rootName && !streaming && kind === "props") notifyHost();
      },
      __dcSetProps: (name, overrides) => runtime.setProps(name, overrides),
      /** Name of the component currently mounted as the page root — DC tools
       *  push their template-stream here when targeting "the open page". */
      __dcRootName: () => rootName,
      /** Editor bridge — the encoded, `data-dc-tpl`-annotated template source.
       *  The host editor parses this into its own template DOM so it can map a
       *  rendered node (carrying the same `data-dc-tpl`) back to the source
       *  node that emitted it. Returns the encoded form (`<sc-comp>`,
       *  `sc-camel-*` attrs); the editor decodes on serialize. */
      __dcAnnotatedTemplate: (name) => runtime.annotatedTemplate(name),
      /** Editor bridge — the *original* (decoded) template source. */
      __dcTemplateSource: (name) => runtime.templateSource(name),
      __dcBoot: () => {
        rootName = boot(runtime, document) ?? rootName;
        notifyHost();
      },
      __dcRegistry: runtime.registry.entries,
      getDC: (name) => runtime.getDC(name),
      // `DCLogic` is the documented base class name; `StreamableLogic` is the
      // implementation alias kept for any project that already references it.
      DCLogic: runtime.StreamableLogic,
      StreamableLogic: runtime.StreamableLogic
    };
    Object.assign(window, api);
    if (document.readyState !== "loading") api.__dcBoot();
    else document.addEventListener("DOMContentLoaded", () => api.__dcBoot());
  }
  hideRawTemplate();
  loadReactUmd().then(init).catch((err) => {
    console.error("[dc] failed to load React or boot:", err);
    throw err;
  });
})();


/* WebMS Noticeboard — EVAL-FREE bundle. No Function()/eval at runtime. */
(function () {
  "use strict";
  var ROOT = "Root";
  var TEMPLATE = "<helmet>\n  <link rel=\"preconnect\" href=\"https://fonts.googleapis.com\">\n  <link rel=\"preconnect\" href=\"https://fonts.gstatic.com\" crossorigin>\n  <link href=\"https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400;12..96,600;12..96,800&family=Instrument+Serif:ital@0;1&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap\" rel=\"stylesheet\">\n  <style>\n    *{ box-sizing:border-box; }\n    html,body{ margin:0; padding:0; }\n    body{ font-family:'IBM Plex Sans', system-ui, sans-serif; }\n    @keyframes drawerIn { from { transform:translateX(102%); } to { transform:translateX(0); } }\n    @keyframes modalIn { from { opacity:0; transform:translateY(14px) scale(.96); } to { opacity:1; transform:none; } }\n    @keyframes bobHint { 0%,100% { transform:translateY(0); } 50% { transform:translateY(3px); } }\n    ::selection{ background:#caa063; color:#1a130b; }\n  </style>\n</helmet>\n\n<template id=\"__bundler_thumbnail\"><svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 100 100\"><rect width=\"100\" height=\"100\" fill=\"#6e4a28\"/><rect x=\"14\" y=\"14\" width=\"72\" height=\"72\" rx=\"3\" fill=\"#caa063\"/><rect x=\"26\" y=\"24\" width=\"20\" height=\"26\" rx=\"1\" fill=\"#b5462a\" transform=\"rotate(-4 36 37)\"/><rect x=\"54\" y=\"26\" width=\"20\" height=\"28\" rx=\"1\" fill=\"#0f5e5a\" transform=\"rotate(3 64 40)\"/><rect x=\"30\" y=\"56\" width=\"20\" height=\"20\" rx=\"1\" fill=\"#d99a2b\" transform=\"rotate(2 40 66)\"/><circle cx=\"50\" cy=\"20\" r=\"2.5\" fill=\"#2a1f12\"/></svg></template>\n<div style=\"height:100vh; display:flex; flex-direction:column; overflow:hidden; background:{{ wallBg }}; background-image:radial-gradient(circle at 50% -10%, rgba(255,255,255,.05), transparent 55%);\">\n\n  <!-- HEADER -->\n  <div style=\"flex:none; display:flex; align-items:flex-end; justify-content:space-between; gap:20px; flex-wrap:wrap; max-width:1180px; width:100%; margin:0 auto; padding:30px 24px 20px;\">\n    <div>\n      <div style=\"font-family:'Bricolage Grotesque', sans-serif; font-weight:800; font-size:clamp(28px,4.6vw,48px); color:#f1e7d4; letter-spacing:-.025em; line-height:.95;\">{{ boardTitle }}</div>\n      <div style=\"font-family:'IBM Plex Mono', monospace; color:#caa980; font-size:12px; letter-spacing:.2em; text-transform:uppercase; margin-top:8px;\">{{ boardSubtitle }}</div>\n    </div>\n    <div style=\"display:flex; align-items:center; gap:14px; flex-wrap:wrap;\">\n      <div style=\"display:flex; align-items:center; gap:9px;\">\n        <span style=\"width:8px; height:8px; border-radius:2px; background:#9c7e57; flex:none;\"></span>\n        <input type=\"range\" min=\"210\" max=\"480\" step=\"10\" value=\"{{ sizeVal }}\" onInput=\"{{ onSize }}\" aria-label=\"Poster size\" style=\"width:104px; accent-color:#e8c98a; cursor:pointer;\" />\n        <span style=\"width:13px; height:13px; border-radius:3px; background:#9c7e57; flex:none;\"></span>\n      </div>\n      <label style=\"display:flex; align-items:center; gap:7px; font-family:'IBM Plex Sans', sans-serif; font-size:12px; color:#9c7e57; cursor:pointer; user-select:none;\">\n        <input type=\"checkbox\" checked=\"{{ reduceMotion }}\" onChange=\"{{ toggleMotion }}\" style=\"accent-color:#e8c98a; width:14px; height:14px; cursor:pointer;\" />\n        Reduce motion\n      </label>\n      <span style=\"font-family:'IBM Plex Mono', monospace; color:#9c7e57; font-size:12px; letter-spacing:.12em;\">{{ count }} PINNED</span>\n      <sc-if value=\"{{ adminAuthed }}\" hint-placeholder-val=\"{{ false }}\">\n        <button onClick=\"{{ openAdd }}\" style=\"font-family:'IBM Plex Sans', sans-serif; font-weight:600; font-size:13px; color:#1c150c; background:#e8c98a; border:none; padding:10px 16px; border-radius:999px; cursor:pointer; transition:transform .2s, background .2s;\" style-hover=\"transform:translateY(-2px); background:#f2d59b;\">+ Add notice</button>\n        <button onClick=\"{{ toggleAdmin }}\" style=\"font-family:'IBM Plex Sans', sans-serif; font-weight:600; font-size:13px; color:#e8c98a; background:transparent; border:1px solid rgba(232,201,138,.4); padding:10px 16px; border-radius:999px; cursor:pointer; transition:background .2s;\" style-hover=\"background:rgba(232,201,138,.12);\">Manage</button>\n        <sc-if value=\"{{ showSignOut }}\" hint-placeholder-val=\"{{ false }}\">\n          <button onClick=\"{{ signOut }}\" style=\"font-family:'IBM Plex Sans', sans-serif; font-size:13px; color:#9c7e57; background:transparent; border:none; padding:10px 8px; cursor:pointer;\">Sign out</button>\n        </sc-if>\n      </sc-if>\n      <sc-if value=\"{{ showSignIn }}\" hint-placeholder-val=\"{{ true }}\">\n        <button onClick=\"{{ openAuth }}\" style=\"display:flex; align-items:center; gap:7px; font-family:'IBM Plex Sans', sans-serif; font-weight:600; font-size:13px; color:#caa980; background:transparent; border:1px solid rgba(202,169,128,.35); padding:10px 16px; border-radius:999px; cursor:pointer; transition:background .2s;\" style-hover=\"background:rgba(202,169,128,.12);\">\n          <span style=\"display:inline-block; width:9px; height:7px; border:1.5px solid currentColor; border-radius:1.5px; position:relative; margin-top:3px;\"><span style=\"position:absolute; left:1px; bottom:5px; width:5px; height:5px; border:1.5px solid currentColor; border-bottom:none; border-radius:3px 3px 0 0;\"></span></span>\n          Admin\n        </button>\n      </sc-if>\n    </div>\n  </div>\n\n  <!-- BOARD -->\n  <div style=\"flex:1; min-height:0; width:100%; max-width:1180px; margin:0 auto; padding:0 18px; display:flex; flex-direction:column;\">\n\n    <sc-if value=\"{{ hasChips }}\" hint-placeholder-val=\"{{ false }}\">\n      <div style=\"flex:none; display:flex; align-items:center; gap:10px; padding:0 2px 14px; overflow-x:auto;\">\n        <sc-for list=\"{{ chips }}\" as=\"c\" hint-placeholder-count=\"5\">\n          <div onClick=\"{{ c.onClick }}\" style=\"{{ c.style }}\">{{ c.label }}</div>\n        </sc-for>\n        <span style=\"flex:1;\"></span>\n        <sc-if value=\"{{ adminHasPast }}\" hint-placeholder-val=\"{{ false }}\">\n          <label style=\"display:flex; align-items:center; gap:7px; flex:none; font-family:'IBM Plex Sans', sans-serif; font-size:12px; color:#9c7e57; cursor:pointer; user-select:none; white-space:nowrap;\">\n            <input type=\"checkbox\" checked=\"{{ showPastChk }}\" onChange=\"{{ toggleShowPast }}\" style=\"accent-color:#e8c98a; width:14px; height:14px; cursor:pointer;\" />\n            Show past ({{ pastCount }})\n          </label>\n        </sc-if>\n      </div>\n    </sc-if>\n\n    <div style=\"position:relative; flex:1; min-height:0; overflow:hidden; display:flex; flex-direction:column; border-radius:8px; background-color:#caa063; background-image:radial-gradient(circle at 18% 22%, rgba(255,255,255,.06), transparent 42%), radial-gradient(rgba(108,68,34,.20) 1px, transparent 1.5px), radial-gradient(rgba(156,104,56,.16) 1px, transparent 1.5px); background-size:auto, 7px 7px, 12px 12px; background-position:0 0, 0 0, 4px 6px; box-shadow:0 0 0 15px #6e4a28, 0 0 0 17px #553820, 0 34px 64px rgba(0,0,0,.55), inset 0 2px 26px rgba(60,34,10,.3);\">\n      <div style=\"position:absolute; top:0; left:0; right:0; height:42px; z-index:3; pointer-events:none; background:linear-gradient(to bottom, rgba(202,160,99,.96), rgba(202,160,99,0));\"></div>\n      <div ref=\"{{ setScrollerRef }}\" style=\"flex:1; min-height:0; overflow-y:auto; overflow-x:hidden; padding:34px 28px 40px;\">\n\n      <sc-if value=\"{{ isEmpty }}\" hint-placeholder-val=\"{{ false }}\">\n        <div style=\"text-align:center; padding:80px 20px; font-family:'IBM Plex Mono', monospace; color:#5c3e1f; font-size:14px; letter-spacing:.05em;\">{{ emptyMsg }}</div>\n      </sc-if>\n\n      <div style=\"display:grid; grid-template-columns:repeat(auto-fill, minmax({{ colWidthStyle }}, 1fr)); gap:38px 30px; align-items:start;\">\n        <sc-for list=\"{{ posters }}\" as=\"p\" hint-placeholder-count=\"8\">\n          <div style=\"position:relative; {{ p.wrapStyle }}\">\n            <div data-card-id=\"{{ p.id }}\" onClick=\"{{ p.onOpen }}\" style=\"position:relative; cursor:pointer; transform:{{ p.rotStyle }}; transition:transform .4s cubic-bezier(.22,1,.36,1), filter .4s; filter:drop-shadow(0 11px 18px rgba(0,0,0,.34));\" style-hover=\"transform:scale(1.035) rotate(0deg); filter:drop-shadow(0 20px 32px rgba(0,0,0,.45));\">\n\n              <sc-if value=\"{{ p.isPast }}\" hint-placeholder-val=\"{{ false }}\">\n                <div style=\"position:absolute; top:10px; left:-6px; z-index:7; background:#7c2218; color:#f6e3d8; font-family:'IBM Plex Mono', monospace; font-size:10px; font-weight:500; letter-spacing:.18em; padding:4px 10px; box-shadow:0 3px 6px rgba(0,0,0,.4);\">ENDED</div>\n              </sc-if>\n\n              <sc-if value=\"{{ p.isPin }}\" hint-placeholder-val=\"{{ true }}\">\n                <div style=\"position:absolute; left:50%; top:-9px; transform:translateX(-50%); z-index:6; width:18px; height:18px; border-radius:50%; background:radial-gradient(circle at 34% 30%, rgba(255,255,255,.85), transparent 42%), {{ p.pin }}; box-shadow:0 4px 6px rgba(0,0,0,.45), inset -1px -2px 3px rgba(0,0,0,.4);\"></div>\n              </sc-if>\n              <sc-if value=\"{{ p.isTape }}\" hint-placeholder-val=\"{{ false }}\">\n                <div style=\"position:absolute; left:-10px; top:7px; width:66px; height:22px; transform:rotate(-40deg); background:rgba(232,224,202,.5); box-shadow:0 1px 2px rgba(0,0,0,.2); z-index:6;\"></div>\n                <div style=\"position:absolute; right:-10px; top:7px; width:66px; height:22px; transform:rotate(40deg); background:rgba(232,224,202,.5); box-shadow:0 1px 2px rgba(0,0,0,.2); z-index:6;\"></div>\n              </sc-if>\n\n              <!-- generated text poster -->\n              <sc-if value=\"{{ p.noMedia }}\" hint-placeholder-val=\"{{ true }}\">\n                <div style=\"position:relative; container-type:inline-size; width:100%; aspect-ratio:{{ p.aspect }}; background:{{ p.bg }}; color:{{ p.fg }}; overflow:hidden; display:flex; flex-direction:column; padding:8cqw; box-shadow:inset 0 0 0 1px rgba(255,255,255,.07);\">\n                  <div style=\"position:absolute; right:-12cqw; top:-12cqw; width:40cqw; height:40cqw; border:.7cqw solid currentColor; border-radius:50%; opacity:.16;\"></div>\n                  <div style=\"font-family:'IBM Plex Mono', monospace; font-size:3.6cqw; letter-spacing:.22em; text-transform:uppercase; opacity:.82;\">{{ p.kicker }}</div>\n                  <div style=\"margin-top:auto;\">\n                    <div style=\"font-family:{{ p.fam }}; font-weight:{{ p.titleWeight }}; font-size:{{ p.titleCqw }}; line-height:1.0; letter-spacing:-.01em; text-wrap:balance;\">{{ p.title }}</div>\n                    <div style=\"height:.5cqw; width:18cqw; background:currentColor; opacity:.5; margin:5cqw 0;\"></div>\n                    <div style=\"font-family:'IBM Plex Mono', monospace; font-size:3.4cqw; line-height:1.55; opacity:.92;\">\n                      <div>{{ p.dateLabel }}</div>\n                      <div>{{ p.location }}</div>\n                    </div>\n                  </div>\n                </div>\n              </sc-if>\n\n              <!-- image poster -->\n              <sc-if value=\"{{ p.hasImage }}\" hint-placeholder-val=\"{{ false }}\">\n                <div style=\"position:relative; container-type:inline-size; width:100%; aspect-ratio:{{ p.aspect }}; overflow:hidden; background:#111; color:#fff; display:flex; align-items:flex-end;\">\n                  {{ p.imgEl }}\n                  <sc-if value=\"{{ p.showCaption }}\" hint-placeholder-val=\"{{ false }}\">\n                    <div style=\"position:absolute; inset:0; background:linear-gradient(to top, rgba(0,0,0,.82), rgba(0,0,0,.05) 56%);\"></div>\n                    <div style=\"position:relative; padding:8cqw;\">\n                      <div style=\"font-family:'IBM Plex Mono', monospace; font-size:3.4cqw; letter-spacing:.22em; text-transform:uppercase; opacity:.85;\">{{ p.kicker }}</div>\n                      <div style=\"font-family:{{ p.fam }}; font-weight:{{ p.titleWeight }}; font-size:{{ p.titleCqw }}; line-height:1.02; margin-top:2cqw; text-wrap:balance;\">{{ p.title }}</div>\n                      <div style=\"font-family:'IBM Plex Mono', monospace; font-size:3.2cqw; margin-top:3cqw; opacity:.9;\">{{ p.dateLabel }}</div>\n                    </div>\n                  </sc-if>\n                </div>\n              </sc-if>\n\n              <!-- video poster -->\n              <sc-if value=\"{{ p.hasVideo }}\" hint-placeholder-val=\"{{ false }}\">\n                <div style=\"position:relative; container-type:inline-size; width:100%; aspect-ratio:{{ p.aspect }}; overflow:hidden; background:#111; color:#fff; display:flex; align-items:flex-end;\">\n                  <video src=\"{{ p.image }}\" autoplay muted loop playsinline style=\"position:absolute; inset:0; width:100%; height:100%; object-fit:cover;\"></video>\n                  <div style=\"position:absolute; inset:0; background:linear-gradient(to top, rgba(0,0,0,.82), rgba(0,0,0,.05) 56%);\"></div>\n                  <div style=\"position:absolute; top:9cqw; right:9cqw; display:flex; align-items:center; gap:1.6cqw; font-family:'IBM Plex Mono', monospace; font-size:2.8cqw; letter-spacing:.1em; opacity:.92;\"><span style=\"width:0;height:0;border-left:3cqw solid currentColor;border-top:2cqw solid transparent;border-bottom:2cqw solid transparent;\"></span>VIDEO</div>\n                  <sc-if value=\"{{ p.showCaption }}\" hint-placeholder-val=\"{{ false }}\">\n                    <div style=\"position:absolute; inset:0; background:linear-gradient(to top, rgba(0,0,0,.82), rgba(0,0,0,.05) 56%);\"></div>\n                    <div style=\"position:relative; padding:8cqw;\">\n                      <div style=\"font-family:'IBM Plex Mono', monospace; font-size:3.4cqw; letter-spacing:.22em; text-transform:uppercase; opacity:.85;\">{{ p.kicker }}</div>\n                      <div style=\"font-family:{{ p.fam }}; font-weight:{{ p.titleWeight }}; font-size:{{ p.titleCqw }}; line-height:1.02; margin-top:2cqw; text-wrap:balance;\">{{ p.title }}</div>\n                      <div style=\"font-family:'IBM Plex Mono', monospace; font-size:3.2cqw; margin-top:3cqw; opacity:.9;\">{{ p.dateLabel }}</div>\n                    </div>\n                  </sc-if>\n                </div>\n              </sc-if>\n\n              <!-- canva poster: live design renders as the poster -->\n              <sc-if value=\"{{ p.isCanva }}\" hint-placeholder-val=\"{{ false }}\">\n                <div style=\"position:relative; container-type:inline-size; width:100%; aspect-ratio:{{ p.aspect }}; overflow:hidden; background:#0e1230; color:#fff;\">\n                  <sc-if value=\"{{ p.hasThumb }}\" hint-placeholder-val=\"{{ false }}\">\n                    {{ p.thumbEl }}\n                  </sc-if>\n                  <sc-if value=\"{{ p.canvaLive }}\" hint-placeholder-val=\"{{ true }}\">\n                    <div style=\"position:absolute; inset:0; background:linear-gradient(135deg, #7d2ae8 0%, #00c4cc 100%);\"></div>\n                    <iframe src=\"{{ p.canvaSrc }}\" loading=\"lazy\" scrolling=\"no\" tabindex=\"-1\" title=\"{{ p.title }}\" style=\"position:absolute; inset:0; width:100%; height:100%; border:0; pointer-events:none;\"></iframe>\n                  </sc-if>\n                  <div style=\"position:absolute; top:6cqw; right:6cqw; display:flex; align-items:center; gap:1.4cqw; padding:1.6cqw 3cqw; border-radius:99px; background:rgba(0,0,0,.5); backdrop-filter:blur(4px); font-family:'IBM Plex Mono', monospace; font-size:2.7cqw; letter-spacing:.12em;\"><span style=\"width:3cqw; height:3cqw; border-radius:50%; border:.7cqw solid currentColor;\"></span>CANVA</div>\n                  <sc-if value=\"{{ p.captionDate }}\" hint-placeholder-val=\"{{ false }}\">\n                    <div style=\"position:absolute; left:0; right:0; bottom:0; padding:9cqw 6cqw 5cqw; background:linear-gradient(to top, rgba(0,0,0,.78), rgba(0,0,0,0)); font-family:'IBM Plex Mono', monospace; font-size:3.1cqw; letter-spacing:.04em;\">{{ p.dateLabel }}</div>\n                  </sc-if>\n                </div>\n              </sc-if>\n\n            </div>\n          </div>\n        </sc-for>\n      </div>\n\n      <sc-if value=\"{{ scrollHint }}\" hint-placeholder-val=\"{{ false }}\">\n        <div onClick=\"{{ scrollToMore }}\" style=\"position:absolute; left:0; right:0; bottom:0; height:64px; z-index:4; display:flex; align-items:flex-end; justify-content:center; padding-bottom:10px; pointer-events:none; background:linear-gradient(to top, rgba(202,160,99,.96), rgba(202,160,99,0));\">\n          <div style=\"pointer-events:auto; display:flex; align-items:center; gap:8px; padding:7px 16px; border-radius:999px; background:#2a1f12; color:#f1e7d4; font-family:'IBM Plex Mono', monospace; font-size:11px; letter-spacing:.14em; text-transform:uppercase; box-shadow:0 6px 18px rgba(0,0,0,.4); cursor:pointer; animation:bobHint 1.6s ease-in-out infinite;\">Scroll for more <span style=\"display:inline-block; width:7px; height:7px; border-right:2px solid currentColor; border-bottom:2px solid currentColor; transform:rotate(45deg); margin-top:-3px;\"></span></div>\n        </div>\n      </sc-if>\n      </div>\n    </div>\n\n    <div style=\"flex:none; display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; padding:14px 8px 16px; font-family:'IBM Plex Mono', monospace; font-size:11px; letter-spacing:.08em; color:#8a6f4c;\">\n      <span>© 2026 {{ boardTitle }}. All notices posted by their organisers.</span>\n      <span style=\"display:flex; gap:18px;\">\n        <a href=\"#\" onClick=\"{{ noop }}\" style=\"color:#a98a5f; text-decoration:none;\" style-hover=\"color:#e8c98a;\">Terms of use</a>\n        <a href=\"#\" onClick=\"{{ noop }}\" style=\"color:#a98a5f; text-decoration:none;\" style-hover=\"color:#e8c98a;\">Privacy</a>\n        <a href=\"#\" onClick=\"{{ noop }}\" style=\"color:#a98a5f; text-decoration:none;\" style-hover=\"color:#e8c98a;\">Contact</a>\n      </span>\n    </div>\n  </div>\n\n  <!-- DETAIL OVERLAY -->\n  <sc-if value=\"{{ hasSelected }}\" hint-placeholder-val=\"{{ false }}\">\n    <div ref=\"{{ setBackdropRef }}\" onClick=\"{{ close }}\" style=\"position:fixed; inset:0; z-index:50; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:18px; padding:24px; background:rgba(16,11,6,.66); backdrop-filter:blur(11px); -webkit-backdrop-filter:blur(11px); perspective:1150px; perspective-origin:50% 45%;\">\n\n      <div onClick=\"{{ closeBtn }}\" style=\"position:fixed; top:18px; right:20px; width:44px; height:44px; border-radius:50%; display:flex; align-items:center; justify-content:center; background:rgba(255,255,255,.1); color:#f3ece0; font-size:24px; line-height:1; cursor:pointer; transition:background .2s; z-index:2;\" style-hover=\"background:rgba(255,255,255,.2);\">&times;</div>\n\n      <div ref=\"{{ setDetailRef }}\" onClick=\"{{ detailClick }}\" style=\"position:relative; flex:none; width:min(92vw, calc(80vh * {{ sel.ar }})); aspect-ratio:{{ sel.aspect }}; cursor:pointer; will-change:transform; perspective:1150px; filter:drop-shadow(0 36px 70px rgba(0,0,0,.6));\">\n       <div ref=\"{{ setPageRef }}\" style=\"position:relative; width:100%; height:100%; transform-origin:0% 50%; transform-style:preserve-3d; will-change:transform; box-shadow:0 0 0 1px rgba(0,0,0,.04);\">\n\n        <sc-if value=\"{{ sel.noMedia }}\" hint-placeholder-val=\"{{ true }}\">\n          <div style=\"position:relative; container-type:inline-size; width:100%; height:100%; background:{{ sel.bg }}; color:{{ sel.fg }}; overflow:hidden; display:flex; flex-direction:column; padding:8cqw; box-shadow:inset 0 0 0 1px rgba(255,255,255,.07);\">\n            <div style=\"position:absolute; right:-12cqw; top:-12cqw; width:40cqw; height:40cqw; border:.7cqw solid currentColor; border-radius:50%; opacity:.16;\"></div>\n            <div style=\"font-family:'IBM Plex Mono', monospace; font-size:3.6cqw; letter-spacing:.22em; text-transform:uppercase; opacity:.82;\">{{ sel.kicker }}</div>\n            <div style=\"margin-top:auto;\">\n              <div style=\"font-family:{{ sel.fam }}; font-weight:{{ sel.titleWeight }}; font-size:{{ sel.titleCqw }}; line-height:1.0; letter-spacing:-.01em; text-wrap:balance;\">{{ sel.title }}</div>\n              <div style=\"height:.5cqw; width:18cqw; background:currentColor; opacity:.5; margin:5cqw 0;\"></div>\n              <div style=\"font-family:'IBM Plex Mono', monospace; font-size:3.4cqw; line-height:1.55; opacity:.92;\">\n                <div>{{ sel.dateLabel }}</div>\n                <div>{{ sel.location }}</div>\n              </div>\n            </div>\n          </div>\n        </sc-if>\n\n        <sc-if value=\"{{ sel.hasImage }}\" hint-placeholder-val=\"{{ false }}\">\n          <div style=\"position:relative; container-type:inline-size; width:100%; height:100%; overflow:hidden; background:#111; color:#fff; display:flex; align-items:flex-end;\">\n            {{ sel.imgEl }}\n            <sc-if value=\"{{ sel.showCaption }}\" hint-placeholder-val=\"{{ false }}\">\n              <div style=\"position:absolute; inset:0; background:linear-gradient(to top, rgba(0,0,0,.82), rgba(0,0,0,.05) 56%);\"></div>\n              <div style=\"position:relative; padding:8cqw;\">\n                <div style=\"font-family:'IBM Plex Mono', monospace; font-size:3.4cqw; letter-spacing:.22em; text-transform:uppercase; opacity:.85;\">{{ sel.kicker }}</div>\n                <div style=\"font-family:{{ sel.fam }}; font-weight:{{ sel.titleWeight }}; font-size:{{ sel.titleCqw }}; line-height:1.02; margin-top:2cqw;\">{{ sel.title }}</div>\n                <div style=\"font-family:'IBM Plex Mono', monospace; font-size:3.2cqw; margin-top:3cqw; opacity:.9;\">{{ sel.dateLabel }}</div>\n              </div>\n            </sc-if>\n          </div>\n        </sc-if>\n\n        <sc-if value=\"{{ sel.hasVideo }}\" hint-placeholder-val=\"{{ false }}\">\n          <div style=\"position:relative; container-type:inline-size; width:100%; height:100%; overflow:hidden; background:#111; color:#fff; display:flex; align-items:flex-end;\">\n            <video src=\"{{ sel.image }}\" autoplay muted loop playsinline style=\"position:absolute; inset:0; width:100%; height:100%; object-fit:cover;\"></video>\n            <sc-if value=\"{{ sel.showCaption }}\" hint-placeholder-val=\"{{ false }}\">\n              <div style=\"position:absolute; inset:0; background:linear-gradient(to top, rgba(0,0,0,.8), rgba(0,0,0,.02) 56%);\"></div>\n              <div style=\"position:relative; padding:8cqw;\">\n                <div style=\"font-family:'IBM Plex Mono', monospace; font-size:3.4cqw; letter-spacing:.22em; text-transform:uppercase; opacity:.85;\">{{ sel.kicker }}</div>\n                <div style=\"font-family:{{ sel.fam }}; font-weight:{{ sel.titleWeight }}; font-size:{{ sel.titleCqw }}; line-height:1.02; margin-top:2cqw;\">{{ sel.title }}</div>\n                <div style=\"font-family:'IBM Plex Mono', monospace; font-size:3.2cqw; margin-top:3cqw; opacity:.9;\">{{ sel.dateLabel }}</div>\n              </div>\n            </sc-if>\n          </div>\n        </sc-if>\n\n        <sc-if value=\"{{ sel.isCanva }}\" hint-placeholder-val=\"{{ false }}\">\n          <div style=\"position:relative; width:100%; height:100%; overflow:hidden; background:#0e1230;\">\n            <iframe src=\"{{ sel.canvaSrc }}\" loading=\"lazy\" allowfullscreen allow=\"fullscreen\" style=\"position:absolute; inset:0; width:100%; height:100%; border:0;\"></iframe>\n          </div>\n        </sc-if>\n\n       </div>\n      </div>\n\n      <sc-if value=\"{{ showQR }}\" hint-placeholder-val=\"{{ false }}\">\n        <div onClick=\"{{ barStop }}\" style=\"flex:none; display:flex; flex-direction:column; align-items:center; gap:10px; background:#fff; padding:18px; border-radius:14px; box-shadow:0 18px 44px rgba(0,0,0,.5); animation:modalIn .25s ease both;\">\n          <div style=\"width:170px; height:170px; display:flex; align-items:center; justify-content:center;\">{{ qrEl }}</div>\n          <div style=\"font-family:'IBM Plex Mono', monospace; font-size:11px; letter-spacing:.12em; color:#5c3e1f; text-transform:uppercase;\">Scan to open this notice</div>\n        </div>\n      </sc-if>\n\n      <div ref=\"{{ setBarRef }}\" onClick=\"{{ barStop }}\" style=\"flex:none; display:flex; align-items:center; gap:12px; flex-wrap:wrap; justify-content:center; max-width:94vw;\">\n        <button onClick=\"{{ doShare }}\" style=\"display:flex; align-items:center; gap:8px; padding:11px 18px; border-radius:999px; border:none; background:#e8c98a; color:#1c150c; font-family:'IBM Plex Sans', sans-serif; font-weight:600; font-size:14px; cursor:pointer; transition:transform .2s, background .2s;\" style-hover=\"transform:translateY(-2px); background:#f2d59b;\">\n          <span style=\"position:relative; width:15px; height:15px; display:inline-block;\"><span style=\"position:absolute; left:6.5px; top:0; width:2px; height:9px; background:currentColor; border-radius:1px;\"></span><span style=\"position:absolute; left:4px; top:1px; width:6px; height:6px; border-left:2px solid currentColor; border-top:2px solid currentColor; transform:rotate(45deg);\"></span><span style=\"position:absolute; left:0; bottom:0; width:4px; height:7px; border:2px solid currentColor; border-top:none;\"></span><span style=\"position:absolute; right:0; bottom:0; width:4px; height:7px; border:2px solid currentColor; border-top:none;\"></span></span>\n          Share\n        </button>\n        <button onClick=\"{{ doDownload }}\" style=\"display:flex; align-items:center; gap:8px; padding:11px 18px; border-radius:999px; background:rgba(255,255,255,.12); color:#f3ece0; border:1px solid rgba(255,255,255,.2); font-family:'IBM Plex Sans', sans-serif; font-weight:600; font-size:14px; cursor:pointer; transition:background .2s;\" style-hover=\"background:rgba(255,255,255,.22);\">\n          <span style=\"position:relative; width:15px; height:15px; display:inline-block;\"><span style=\"position:absolute; left:6.5px; top:0; width:2px; height:8px; background:currentColor; border-radius:1px;\"></span><span style=\"position:absolute; left:3.5px; top:4px; width:6px; height:6px; border-right:2px solid currentColor; border-bottom:2px solid currentColor; transform:rotate(45deg);\"></span><span style=\"position:absolute; left:0; bottom:0; width:15px; height:2px; background:currentColor; border-radius:1px;\"></span></span>\n          {{ downloadLabel }}\n        </button>\n        <button onClick=\"{{ toggleQR }}\" style=\"display:flex; align-items:center; gap:8px; padding:11px 18px; border-radius:999px; background:rgba(255,255,255,.12); color:#f3ece0; border:1px solid rgba(255,255,255,.2); font-family:'IBM Plex Sans', sans-serif; font-weight:600; font-size:14px; cursor:pointer; transition:background .2s;\" style-hover=\"background:rgba(255,255,255,.22);\">\n          <span style=\"position:relative; width:15px; height:15px; display:inline-block;\"><span style=\"position:absolute; left:0; top:0; width:5px; height:5px; border:2px solid currentColor;\"></span><span style=\"position:absolute; right:0; top:0; width:5px; height:5px; border:2px solid currentColor;\"></span><span style=\"position:absolute; left:0; bottom:0; width:5px; height:5px; border:2px solid currentColor;\"></span><span style=\"position:absolute; right:1px; bottom:1px; width:3px; height:3px; background:currentColor;\"></span></span>\n          QR\n        </button>\n        <span style=\"font-family:'IBM Plex Mono', monospace; color:#cbb79a; font-size:12px; letter-spacing:.1em; padding-left:4px;\">{{ sel.hint }}</span>\n      </div>\n    </div>\n  </sc-if>\n\n  <!-- ADMIN SIGN-IN -->\n  <sc-if value=\"{{ authOpen }}\" hint-placeholder-val=\"{{ false }}\">\n    <div onClick=\"{{ closeAuth }}\" style=\"position:fixed; inset:0; z-index:70; display:flex; align-items:center; justify-content:center; padding:24px; background:rgba(12,8,4,.62); backdrop-filter:blur(8px);\">\n      <div onClick=\"{{ stop }}\" style=\"width:min(360px,94vw); background:#1c1610; border:1px solid rgba(255,255,255,.08); border-radius:14px; padding:28px; animation:modalIn .3s cubic-bezier(.22,1,.36,1) both;\">\n        <div style=\"font-family:'Bricolage Grotesque', sans-serif; font-weight:800; font-size:22px; color:#f1e7d4;\">Admin sign-in</div>\n        <div style=\"font-family:'IBM Plex Sans', sans-serif; font-size:13px; color:#9c7e57; margin-top:6px;\">Only admins can add or manage notices.</div>\n        <input type=\"password\" value=\"{{ authInput }}\" onInput=\"{{ onAuthInput }}\" onKeyDown=\"{{ onAuthKey }}\" placeholder=\"Password\" style=\"width:100%; margin-top:18px; padding:12px 14px; background:#100c08; border:1px solid rgba(255,255,255,.14); border-radius:9px; color:#f1e7d4; font-family:'IBM Plex Sans', sans-serif; font-size:14px;\" />\n        <sc-if value=\"{{ authError }}\" hint-placeholder-val=\"{{ false }}\">\n          <div style=\"color:#e08a6a; font-family:'IBM Plex Mono', monospace; font-size:12px; margin-top:8px;\">Incorrect password.</div>\n        </sc-if>\n        <div style=\"display:flex; gap:10px; margin-top:18px;\">\n          <button onClick=\"{{ submitAuth }}\" style=\"flex:1; padding:12px; background:#e8c98a; color:#1c150c; border:none; border-radius:9px; font-family:'IBM Plex Sans', sans-serif; font-weight:600; font-size:14px; cursor:pointer;\">Sign in</button>\n          <button onClick=\"{{ closeAuth }}\" style=\"padding:12px 16px; background:transparent; color:#9c7e57; border:1px solid rgba(255,255,255,.12); border-radius:9px; font-family:'IBM Plex Sans', sans-serif; font-size:14px; cursor:pointer;\">Cancel</button>\n        </div>\n      </div>\n    </div>\n  </sc-if>\n\n  <!-- ADMIN DRAWER -->\n  <sc-if value=\"{{ adminOpen }}\" hint-placeholder-val=\"{{ false }}\">\n    <div onClick=\"{{ closeAdmin }}\" style=\"position:fixed; inset:0; z-index:60; background:rgba(12,8,4,.5);\">\n      <div onClick=\"{{ stop }}\" style=\"position:absolute; top:0; right:0; height:100%; width:min(440px,94vw); background:#1c1610; box-shadow:-20px 0 50px rgba(0,0,0,.5); display:flex; flex-direction:column; animation:drawerIn .4s cubic-bezier(.22,1,.36,1) both;\">\n\n        <div style=\"display:flex; align-items:center; justify-content:space-between; padding:22px 24px; border-bottom:1px solid rgba(255,255,255,.08);\">\n          <div style=\"font-family:'Bricolage Grotesque', sans-serif; font-weight:800; font-size:20px; color:#f1e7d4;\">{{ adminTitle }}</div>\n          <div onClick=\"{{ closeAdmin }}\" style=\"color:#caa980; font-size:26px; line-height:1; cursor:pointer;\">&times;</div>\n        </div>\n\n        <div style=\"flex:1; overflow-y:auto; padding:22px 24px;\">\n          <label style=\"display:block; font-family:'IBM Plex Mono',monospace; font-size:11px; letter-spacing:.12em; text-transform:uppercase; color:#9c7e57; margin-bottom:6px;\">Title</label>\n          <input value=\"{{ f_title }}\" onInput=\"{{ onTitle }}\" placeholder=\"Event name\" style=\"width:100%; padding:11px 13px; margin-bottom:16px; background:#100c08; border:1px solid rgba(255,255,255,.12); border-radius:8px; color:#f1e7d4; font-family:'IBM Plex Sans',sans-serif; font-size:14px;\" />\n\n          <label style=\"display:block; font-family:'IBM Plex Mono',monospace; font-size:11px; letter-spacing:.12em; text-transform:uppercase; color:#9c7e57; margin-bottom:6px;\">Sub-label / kicker</label>\n          <input value=\"{{ f_kicker }}\" onInput=\"{{ onKicker }}\" placeholder=\"e.g. Live Music\" style=\"width:100%; padding:11px 13px; margin-bottom:16px; background:#100c08; border:1px solid rgba(255,255,255,.12); border-radius:8px; color:#f1e7d4; font-family:'IBM Plex Sans',sans-serif; font-size:14px;\" />\n\n          <label style=\"display:block; font-family:'IBM Plex Mono',monospace; font-size:11px; letter-spacing:.12em; text-transform:uppercase; color:#9c7e57; margin-bottom:6px;\">Category (filter)</label>\n          <select value=\"{{ f_category }}\" onChange=\"{{ onCategory }}\" style=\"width:100%; padding:11px 13px; margin-bottom:16px; background:#100c08; border:1px solid rgba(255,255,255,.12); border-radius:8px; color:#f1e7d4; font-family:'IBM Plex Sans',sans-serif; font-size:14px;\">\n            <sc-for list=\"{{ catOptions }}\" as=\"c\" hint-placeholder-count=\"9\">\n              <option value=\"{{ c }}\">{{ c }}</option>\n            </sc-for>\n          </select>\n\n          <label style=\"display:block; font-family:'IBM Plex Mono',monospace; font-size:11px; letter-spacing:.12em; text-transform:uppercase; color:#9c7e57; margin-bottom:6px;\">When</label>\n          <div style=\"display:flex; gap:8px; margin-bottom:10px;\">\n            <button onClick=\"{{ setOnce }}\" style=\"{{ f_onceStyle }}\">One-off date</button>\n            <button onClick=\"{{ setWeekly }}\" style=\"{{ f_weeklyStyle }}\">Repeats weekly</button>\n          </div>\n          <div style=\"display:flex; gap:10px; margin-bottom:16px;\">\n            <sc-if value=\"{{ f_isOnce }}\" hint-placeholder-val=\"{{ true }}\">\n              <input type=\"date\" value=\"{{ f_dateVal }}\" onInput=\"{{ onDateVal }}\" style=\"flex:2; padding:10px 12px; background:#100c08; border:1px solid rgba(255,255,255,.12); border-radius:8px; color:#f1e7d4; font-family:'IBM Plex Sans',sans-serif; font-size:14px; color-scheme:dark;\" />\n            </sc-if>\n            <sc-if value=\"{{ f_isWeekly }}\" hint-placeholder-val=\"{{ false }}\">\n              <select value=\"{{ f_weekday }}\" onChange=\"{{ onWeekday }}\" style=\"flex:2; padding:10px 12px; background:#100c08; border:1px solid rgba(255,255,255,.12); border-radius:8px; color:#f1e7d4; font-family:'IBM Plex Sans',sans-serif; font-size:14px;\">\n                <option value=\"1\">Mondays</option>\n                <option value=\"2\">Tuesdays</option>\n                <option value=\"3\">Wednesdays</option>\n                <option value=\"4\">Thursdays</option>\n                <option value=\"5\">Fridays</option>\n                <option value=\"6\">Saturdays</option>\n                <option value=\"0\">Sundays</option>\n              </select>\n            </sc-if>\n            <input type=\"time\" value=\"{{ f_timeVal }}\" onInput=\"{{ onTimeVal }}\" style=\"flex:1; padding:10px 12px; background:#100c08; border:1px solid rgba(255,255,255,.12); border-radius:8px; color:#f1e7d4; font-family:'IBM Plex Sans',sans-serif; font-size:14px; color-scheme:dark;\" />\n          </div>\n\n          <label style=\"display:block; font-family:'IBM Plex Mono',monospace; font-size:11px; letter-spacing:.12em; text-transform:uppercase; color:#9c7e57; margin-bottom:6px;\">Location</label>\n          <input value=\"{{ f_location }}\" onInput=\"{{ onLocation }}\" placeholder=\"Venue / address\" style=\"width:100%; padding:11px 13px; margin-bottom:16px; background:#100c08; border:1px solid rgba(255,255,255,.12); border-radius:8px; color:#f1e7d4; font-family:'IBM Plex Sans',sans-serif; font-size:14px;\" />\n\n          <label style=\"display:block; font-family:'IBM Plex Mono',monospace; font-size:11px; letter-spacing:.12em; text-transform:uppercase; color:#9c7e57; margin-bottom:6px;\">Event page link <span style=\"color:#6e5638; text-transform:none; letter-spacing:0;\">— optional</span></label>\n          <input value=\"{{ f_link }}\" onInput=\"{{ onLink }}\" placeholder=\"https://…  (leave blank if the poster is enough)\" style=\"width:100%; padding:11px 13px; margin-bottom:16px; background:#100c08; border:1px solid rgba(255,255,255,.12); border-radius:8px; color:#f1e7d4; font-family:'IBM Plex Sans',sans-serif; font-size:14px;\" />\n\n          <label style=\"display:block; font-family:'IBM Plex Mono',monospace; font-size:11px; letter-spacing:.12em; text-transform:uppercase; color:#9c7e57; margin-bottom:8px;\">Poster — image, video or Canva</label>\n          <label style=\"display:flex; align-items:center; justify-content:center; gap:8px; padding:14px; margin-bottom:10px; background:#100c08; border:1.5px dashed rgba(232,201,138,.3); border-radius:8px; color:#caa980; font-family:'IBM Plex Sans',sans-serif; font-size:13px; cursor:pointer;\">\n            <span style=\"font-size:16px;\">↑</span> Upload image or video\n            <input type=\"file\" accept=\"image/*,video/*\" onChange=\"{{ onFile }}\" style=\"display:none;\" />\n          </label>\n          <sc-if value=\"{{ f_hasMedia }}\" hint-placeholder-val=\"{{ false }}\">\n            <div style=\"display:flex; align-items:center; gap:10px; margin-bottom:10px; padding:8px 12px; background:rgba(46,74,47,.25); border:1px solid rgba(120,170,120,.25); border-radius:8px;\">\n              <span style=\"color:#9cd49c; font-family:'IBM Plex Mono',monospace; font-size:12px; flex:1;\">✓ {{ f_mediaLabel }} attached</span>\n              <span onClick=\"{{ clearMedia }}\" style=\"color:#caa980; font-size:12px; cursor:pointer;\">Remove</span>\n            </div>\n          </sc-if>\n          <input value=\"{{ f_urlValue }}\" onInput=\"{{ onImage }}\" placeholder=\"…or paste an image / video URL\" style=\"width:100%; padding:11px 13px; margin-bottom:10px; background:#100c08; border:1px solid rgba(255,255,255,.12); border-radius:8px; color:#f1e7d4; font-family:'IBM Plex Sans',sans-serif; font-size:14px;\" />\n          <textarea value=\"{{ f_canvaCode }}\" onInput=\"{{ onCanvaCode }}\" placeholder=\"…or paste a Canva embed code  —  in Canva: Share → More → ‹›Embed → Copy\" rows=\"3\" style=\"width:100%; padding:11px 13px; margin-bottom:8px; background:#100c08; border:1px solid rgba(124,42,232,.45); border-radius:8px; color:#f1e7d4; font-family:'IBM Plex Mono',monospace; font-size:12px; line-height:1.45; resize:vertical;\"></textarea>\n          <sc-if value=\"{{ f_isCanva }}\" hint-placeholder-val=\"{{ false }}\">\n            <div style=\"display:flex; align-items:center; gap:8px; margin-bottom:8px; padding:8px 12px; background:rgba(124,42,232,.16); border:1px solid rgba(124,42,232,.4); border-radius:8px; font-family:'IBM Plex Mono',monospace; font-size:12px; color:#cdb4f0;\"><span style=\"width:9px; height:9px; border-radius:50%; border:2px solid currentColor;\"></span>Canva design embedded — it will render live as the poster.</div>\n            <input value=\"{{ f_thumb }}\" onInput=\"{{ onThumb }}\" placeholder=\"Static board thumbnail image URL (optional — shown instead of live embed on the board)\" style=\"width:100%; padding:11px 13px; margin-bottom:8px; background:#100c08; border:1px solid rgba(124,42,232,.4); border-radius:8px; color:#f1e7d4; font-family:'IBM Plex Sans',sans-serif; font-size:14px;\" />\n          </sc-if>\n          <div style=\"font-family:'IBM Plex Sans',sans-serif; font-size:11px; color:#6e5638; margin-bottom:14px; line-height:1.5;\">Leave everything empty for a designed text poster. For Canva, paste the full embed code (the design must be shareable — “Anyone with the link can view”). The live design renders as the poster, both on the board and in full view. Uploaded files are saved in this browser; for large videos a hosted URL is more reliable.</div>\n\n          <sc-if value=\"{{ f_hasAnyMedia }}\" hint-placeholder-val=\"{{ false }}\">\n            <label style=\"display:flex; align-items:flex-start; gap:10px; margin-bottom:18px; padding:11px 13px; background:#100c08; border:1px solid rgba(255,255,255,.12); border-radius:8px; cursor:pointer;\">\n              <input type=\"checkbox\" checked=\"{{ f_overlay }}\" onChange=\"{{ onOverlay }}\" style=\"accent-color:#e8c98a; width:16px; height:16px; margin-top:1px; flex:none; cursor:pointer;\" />\n              <span style=\"font-family:'IBM Plex Sans',sans-serif; font-size:13px; color:#e8d8bd; line-height:1.45;\">Overlay title &amp; date on the poster<br><span style=\"font-size:11px; color:#9c7e57;\">Leave off if your image / video / Canva already shows this info (recommended for finished posters).</span></span>\n            </label>\n          </sc-if>\n\n          <div style=\"display:flex; gap:14px; margin-bottom:6px;\">\n            <div style=\"flex:1;\">\n              <label style=\"display:block; font-family:'IBM Plex Mono',monospace; font-size:11px; letter-spacing:.12em; text-transform:uppercase; color:#9c7e57; margin-bottom:6px;\">Shape</label>\n              <select value=\"{{ f_aspect }}\" onChange=\"{{ onAspect }}\" style=\"width:100%; padding:10px; background:#100c08; border:1px solid rgba(255,255,255,.12); border-radius:8px; color:#f1e7d4; font-family:'IBM Plex Sans',sans-serif; font-size:13px;\">\n                <option value=\"4/5\">Portrait</option>\n                <option value=\"3/4\">Tall</option>\n                <option value=\"5/7\">Extra tall</option>\n                <option value=\"1/1\">Square</option>\n                <option value=\"4/3\">Landscape</option>\n              </select>\n            </div>\n            <div style=\"flex:1;\">\n              <label style=\"display:block; font-family:'IBM Plex Mono',monospace; font-size:11px; letter-spacing:.12em; text-transform:uppercase; color:#9c7e57; margin-bottom:6px;\">Typeface</label>\n              <select value=\"{{ f_serifVal }}\" onChange=\"{{ onSerif }}\" style=\"width:100%; padding:10px; background:#100c08; border:1px solid rgba(255,255,255,.12); border-radius:8px; color:#f1e7d4; font-family:'IBM Plex Sans',sans-serif; font-size:13px;\">\n                <option value=\"sans\">Grotesque</option>\n                <option value=\"serif\">Serif</option>\n              </select>\n            </div>\n          </div>\n          <div style=\"margin-top:14px;\">\n            <label style=\"display:block; font-family:'IBM Plex Mono',monospace; font-size:11px; letter-spacing:.12em; text-transform:uppercase; color:#9c7e57; margin-bottom:8px;\">Colour (text posters)</label>\n            <div style=\"display:flex; flex-wrap:wrap; gap:10px;\">\n              <sc-for list=\"{{ swatches }}\" as=\"sw\" hint-placeholder-count=\"8\">\n                <div onClick=\"{{ sw.onClick }}\" style=\"width:30px; height:30px; border-radius:50%; cursor:pointer; background:{{ sw.bg }}; box-shadow:{{ sw.ring }};\"></div>\n              </sc-for>\n            </div>\n          </div>\n        </div>\n\n        <div style=\"display:flex; gap:10px; padding:18px 24px; border-top:1px solid rgba(255,255,255,.08);\">\n          <button onClick=\"{{ saveForm }}\" style=\"flex:1; padding:12px; background:#e8c98a; color:#1c150c; border:none; border-radius:8px; font-family:'IBM Plex Sans',sans-serif; font-weight:600; font-size:14px; cursor:pointer;\">{{ saveLabel }}</button>\n          <sc-if value=\"{{ editing }}\" hint-placeholder-val=\"{{ false }}\">\n            <button onClick=\"{{ deleteCurrent }}\" style=\"padding:12px 16px; background:transparent; color:#e08a6a; border:1px solid rgba(224,138,106,.4); border-radius:8px; font-family:'IBM Plex Sans',sans-serif; font-weight:600; font-size:14px; cursor:pointer;\">Delete</button>\n          </sc-if>\n        </div>\n\n        <div style=\"max-height:32vh; overflow-y:auto; padding:6px 24px 22px; border-top:1px solid rgba(255,255,255,.08);\">\n          <div style=\"font-family:'IBM Plex Mono',monospace; font-size:11px; letter-spacing:.12em; text-transform:uppercase; color:#9c7e57; margin:14px 0 10px;\">On the board ({{ count }})</div>\n          <sc-for list=\"{{ posters }}\" as=\"p\" hint-placeholder-count=\"4\">\n            <div style=\"display:flex; align-items:center; gap:12px; padding:9px 0; border-bottom:1px solid rgba(255,255,255,.05);\">\n              <div style=\"width:16px; height:16px; border-radius:4px; flex:none; background:{{ p.bg }};\"></div>\n              <div style=\"flex:1; min-width:0;\">\n                <div style=\"color:#f1e7d4; font-size:13px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;\">{{ p.title }}</div>\n                <div style=\"color:#7c6243; font-family:'IBM Plex Mono',monospace; font-size:11px;\">{{ p.dateLabel }}</div>\n              </div>\n              <div onClick=\"{{ p.onEdit }}\" style=\"color:#caa980; font-size:12px; cursor:pointer; padding:4px 8px;\">Edit</div>\n              <div onClick=\"{{ p.onDelete }}\" style=\"color:#9c6a52; font-size:12px; cursor:pointer; padding:4px 8px;\">Remove</div>\n            </div>\n          </sc-for>\n        </div>\n      </div>\n    </div>\n  </sc-if>\n\n  <!-- TOAST -->\n  <sc-if value=\"{{ toast }}\" hint-placeholder-val=\"{{ false }}\">\n    <div style=\"position:fixed; left:50%; bottom:30px; transform:translateX(-50%); z-index:90; background:#1c1610; color:#f1e7d4; padding:11px 18px; border-radius:999px; font-family:'IBM Plex Sans', sans-serif; font-size:13px; box-shadow:0 12px 34px rgba(0,0,0,.5); border:1px solid rgba(255,255,255,.1); animation:modalIn .25s ease both;\">{{ toast }}</div>\n  </sc-if>\n\n</div>";
  var PROPS_JSON = "{\n  \"$preview\": { \"width\": 1280, \"height\": 860 },\n  \"boardTitle\": { \"editor\": \"text\", \"default\": \"Community Noticeboard\", \"tsType\": \"string\" },\n  \"boardSubtitle\": { \"editor\": \"text\", \"default\": \"What's on · pinned this week\", \"tsType\": \"string\" },\n  \"columnWidth\": { \"editor\": \"int\", \"default\": 330, \"min\": 200, \"max\": 480, \"step\": 10, \"tsType\": \"number\" },\n  \"wallTone\": { \"editor\": \"enum\", \"default\": \"Warm walnut\", \"options\": [\"Warm walnut\", \"Cool slate\", \"Charcoal\"], \"tsType\": \"string\" },\n  \"adminMode\": { \"editor\": \"enum\", \"default\": \"Demo password (standalone)\", \"options\": [\"Demo password (standalone)\", \"Host-controlled login\", \"Always admin (trusted iframe)\", \"Read-only (public embed)\"], \"tsType\": \"string\" },\n  \"adminPassword\": { \"editor\": \"text\", \"default\": \"admin\", \"tsType\": \"string\" }\n}";
  function defineComponent(DCLogic, StreamableLogic, React) {
class Component extends DCLogic {
  state = { posters: null, selected: null, adminOpen: false, editingId: null, form: this.blankForm(),
            adminAuthed: false, authOpen: false, authInput: '', authError: false, size: null, reduceMotion: false, toast: '',
            filter: 'All', showPast: false, showQR: false, scrollHint: false };

  cats() { return ['Music','Market','Workshop','Community','Family','Sport','Arts','Fundraiser','Other']; }

  blankForm() { return { title:'', kicker:'', category:'Community', schedule:'once', date:'', weekday:'6', time:'', location:'', link:'', colorIndex:0, aspect:'4/5', serif:false, image:'', mediaType:'image', canva:'', canvaCode:'', thumb:'', uploaded:false, overlay:false }; }

  // ---- Canva embeds ----
  isCanvaInput(v) { return /canva\.com\/design\//i.test(String(v || '')); }
  // Accepts a full Canva embed snippet (Share → More → Embed) OR a plain design
  // link. Returns { src, aspect } — src is the /view?embed url, aspect derived
  // from the embed wrapper's padding-top (height ÷ width), else ''.
  parseCanva(raw) {
    let v = String(raw || '').trim();
    let src = '';
    const m = v.match(/src=["']([^"']*canva\.com\/design\/[^"']+)["']/i);
    if (m) { src = m[1]; }
    else { const u = v.match(/https?:\/\/[^\s"']*canva\.com\/design\/[^\s"']+/i); if (u) src = u[0]; }
    if (!src) { return { src: '', aspect: '' }; }
    let url = src.split('?')[0].replace(/\/$/, '');
    if (!/\/(view|watch)$/i.test(url)) { url += '/view'; }
    src = url + '?embed';
    let aspect = '';
    const pt = v.match(/padding-top:\s*([\d.]+)%/i);
    if (pt) { const r = parseFloat(pt[1]); if (r > 0) { aspect = (100 / r).toFixed(4); } }
    return { src: src, aspect: aspect };
  }
  normalizeCanva(raw) { return this.parseCanva(raw).src; }
  onCanvaCode(e) {
    const raw = e.target.value;
    const p = this.parseCanva(raw);
    this.setState(s => ({ form: { ...s.form, canvaCode: raw, canva: p.src, mediaType: p.src ? 'canva' : s.form.mediaType, image: p.src ? '' : s.form.image, uploaded: p.src ? false : s.form.uploaded, aspect: p.aspect || s.form.aspect } }));
  }
  canvaViewUrl(p) { return (p.canva || '').replace(/\?embed.*$/, '').replace(/\/watch$/, '/view'); }

  // ---- expiry / archive ----
  isPast(p) {
    if (p.schedule !== 'once' || !p.date) return false;
    const d = new Date(p.date + 'T' + (p.time || '23:59'));
    if (isNaN(d)) return false;
    return d.getTime() < Date.now();
  }

  // ---- QR (CueRCode adapter + fallback) ----
  qrSrc(text) {
    const h = this.host();
    if (h && typeof h.qrUrl === 'function') { try { const r = h.qrUrl(text); if (r) return r; } catch(e) {} }
    if (typeof window !== 'undefined' && window.CueRCode && typeof window.CueRCode.toURL === 'function') {
      try { const r = window.CueRCode.toURL(text); if (r) return r; } catch(e) {}
    }
    return 'https://api.qrserver.com/v1/create-qr-code/?size=320x320&margin=8&data=' + encodeURIComponent(text);
  }

  scheduleMeta(p) {
    const WD = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    const FWD = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    const MO = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    const BIG = 8.64e15;
    const t12 = (t) => { if (!t) return ''; const a = t.split(':'); let H = +a[0], M = +a[1] || 0; const ap = H < 12 ? 'am' : 'pm'; let h = H % 12; if (h === 0) h = 12; return h + ':' + String(M).padStart(2,'0') + ap; };
    if (p.schedule === 'weekly' && p.weekday != null && p.weekday !== '') {
      const wd = +p.weekday;
      const now = new Date();
      const d = new Date(now.getFullYear(), now.getMonth(), now.getDate());
      let diff = (wd - d.getDay() + 7) % 7;
      if (diff === 0 && p.time) { const a = p.time.split(':'); const mins = (+a[0]) * 60 + (+a[1] || 0); if (now.getHours() * 60 + now.getMinutes() > mins) diff = 7; }
      d.setDate(d.getDate() + diff);
      if (p.time) { const a = p.time.split(':'); d.setHours(+a[0], +a[1] || 0, 0, 0); }
      return { label: 'Every ' + FWD[wd] + (p.time ? ' · ' + t12(p.time) : ''), sort: d.getTime() };
    }
    if (p.schedule === 'once' && p.date) {
      const d = new Date(p.date + 'T' + (p.time || '00:00'));
      if (!isNaN(d)) return { label: WD[d.getDay()] + ' ' + d.getDate() + ' ' + MO[d.getMonth()] + (p.time ? ' · ' + t12(p.time) : ''), sort: d.getTime() };
    }
    return { label: p.date || '', sort: BIG };
  }

  componentDidMount() {
    let saved = null;
    try { saved = JSON.parse(localStorage.getItem('pinboard.posters.v1')); } catch(e) {}
    let size = null;
    try { const s = parseInt(localStorage.getItem('pinboard.size'), 10); if (s) size = s; } catch(e) {}
    if (!size) size = this.props.columnWidth ?? 330;
    let rm = null;
    try { const v = localStorage.getItem('pinboard.reducemotion'); if (v === '1') rm = true; else if (v === '0') rm = false; } catch(e) {}
    if (rm === null) { try { rm = window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch(e) { rm = false; } }
    try {
      this._mq = window.matchMedia('(prefers-reduced-motion: reduce)');
      this._mqL = (e) => { let stored = null; try { stored = localStorage.getItem('pinboard.reducemotion'); } catch(_) {} if (stored == null) this.setState({ reduceMotion: e.matches }); };
      this._mq.addEventListener('change', this._mqL);
    } catch(e) {}
    this.setState({ posters: (saved && saved.length) ? saved : this.seed(), adminAuthed: this.resolveAdmin(), size, reduceMotion: rm }, () => this.tryDeepLink());
    // host datastore (WebMS-Intra / WordPress) overrides local data when provided
    const hb = this.host();
    if (hb && typeof hb.load === 'function') {
      try { Promise.resolve(hb.load()).then(list => { if (Array.isArray(list)) this.setState({ posters: list }, () => this.tryDeepLink()); }).catch(() => {}); } catch(e) {}
    }
    this._key = (e) => {
      if (e.key === 'Escape') {
        if (this.state.selected) this.close();
        else if (this.state.authOpen) this.setState({ authOpen:false });
        else if (this.state.adminOpen) this.setState({ adminOpen:false });
      }
    };
    window.addEventListener('keydown', this._key);
  }
  componentWillUnmount() { window.removeEventListener('keydown', this._key); if (this._mq && this._mqL) { try { this._mq.removeEventListener('change', this._mqL); } catch(e) {} } }

  toggleMotion() { const v = !this.state.reduceMotion; this.setState({ reduceMotion:v }); try { localStorage.setItem('pinboard.reducemotion', v ? '1' : '0'); } catch(e) {} }

  bindScroller(el) {
    if (this.scroller === el) return;
    if (this.scroller && this._scrollL) this.scroller.removeEventListener('scroll', this._scrollL);
    this.scroller = el;
    if (!el) return;
    this._scrollL = () => this.updateScrollHint();
    el.addEventListener('scroll', this._scrollL, { passive: true });
    requestAnimationFrame(() => this.updateScrollHint());
  }
  updateScrollHint() {
    const el = this.scroller; if (!el) return;
    const show = (el.scrollHeight - el.clientHeight - el.scrollTop) > 28;
    if (show !== this.state.scrollHint) this.setState({ scrollHint: show });
  }

  componentDidUpdate() {
    document.body.style.overflow = (this.state.selected || this.state.adminOpen || this.state.authOpen) ? 'hidden' : '';
    if (this._flipIn && this.detailRef) { this._flipIn = false; this.flipIn(); }
    this.updateScrollHint();
  }

  palette() {
    return [
      { bg:'#0f5e5a', fg:'#f3ece0', pin:'#e8b04b' },
      { bg:'#b5462a', fg:'#f6ece0', pin:'#f0c14b' },
      { bg:'#d99a2b', fg:'#2a1c08', pin:'#7a3b1f' },
      { bg:'#4f2540', fg:'#f1e6ec', pin:'#d98aae' },
      { bg:'#2b4a2f', fg:'#eef0e2', pin:'#d9b14b' },
      { bg:'#20324f', fg:'#e9eef5', pin:'#e0a13c' },
      { bg:'#ece2cc', fg:'#241a10', pin:'#b5462a' },
      { bg:'#c8503f', fg:'#f7ece2', pin:'#f0c14b' }
    ];
  }

  seed() {
    return [
      { id:'p1', title:'Riverside Jazz Nights', kicker:'Live Music', category:'Music', schedule:'once', date:'2026-07-10', time:'20:00', location:'The Old Granary', link:'https://example.com/jazz', colorIndex:0, aspect:'4/5', serif:true },
      { id:'p2', title:'Saturday Farmers Market', kicker:'Weekly Market', category:'Market', schedule:'weekly', weekday:6, time:'09:00', location:'Market Square', link:'https://example.com/market', colorIndex:4, aspect:'3/4', serif:false },
      { id:'p3', title:'Open Mic Poetry', kicker:'Spoken Word', category:'Arts', schedule:'once', date:'2026-07-02', time:'19:30', location:'Bramble Café', link:'https://example.com/poetry', colorIndex:3, aspect:'1/1', serif:true },
      { id:'p4', title:'Outdoor Cinema', kicker:'Summer Screenings', category:'Arts', schedule:'once', date:'2026-07-18', time:'21:00', location:'Memorial Park', link:'https://example.com/cinema', colorIndex:5, aspect:'5/7', serif:false },
      { id:'p5', title:'Pottery for Beginners', kicker:'Workshop', category:'Workshop', schedule:'once', date:'2026-07-12', time:'10:00', location:'The Clay Studio', link:'https://example.com/pottery', colorIndex:1, aspect:'4/5', serif:false },
      { id:'p6', title:'Canal Litter Pick', kicker:'Volunteer', category:'Community', schedule:'once', date:'2026-07-05', time:'11:00', location:'Canal Towpath', link:'https://example.com/litter', colorIndex:6, aspect:'3/4', serif:false },
      { id:'p7', title:'Vintage & Vinyl Fair', kicker:'Market', category:'Market', schedule:'once', date:'2026-07-26', time:'10:00', location:'Town Hall', link:'https://example.com/vinyl', colorIndex:7, aspect:'4/5', serif:true },
      { id:'p8', title:'Yoga in the Park', kicker:'Wellbeing', category:'Sport', schedule:'weekly', weekday:3, time:'07:00', location:'Greenfield Common', link:'https://example.com/yoga', colorIndex:0, aspect:'1/1', serif:false },
      { id:'p9', title:'Charity Quiz Night', kicker:'Fundraiser', category:'Fundraiser', schedule:'once', date:'2026-07-17', time:'20:00', location:'The Red Lion', link:'https://example.com/quiz', colorIndex:2, aspect:'3/4', serif:false },
      { id:'p10', title:"Kids' Storytime", kicker:'Family', category:'Family', schedule:'weekly', weekday:2, time:'10:30', location:'Public Library', link:'https://example.com/story', colorIndex:4, aspect:'4/5', serif:true }
    ];
  }

  save(posters) {
    const h = this.host();
    if (h && typeof h.save === 'function') {
      try { Promise.resolve(h.save(posters)).catch(() => {}); return true; } catch(e) { return false; }
    }
    try { localStorage.setItem('pinboard.posters.v1', JSON.stringify(posters)); return true; }
    catch(e) { alert('Could not save — the poster file may be too large for browser storage. Try a hosted image/video URL instead.'); return false; }
  }

  // ---- integration seam ----
  // adminMode prop decides who may manage the board:
  //   'password' (default) — standalone demo password gate
  //   'host'                — trust window.NoticeboardHost.isAdmin (e.g. WebMS-Intra App::isSiteAdmin())
  //   'open'                — always admin (trusted intranet iframe)
  //   'readonly'            — public embed, no admin UI at all
  // window.NoticeboardHost (optional) may also provide load()/save(posters) to
  // back the board with a real datastore instead of localStorage.
  modeKey() {
    const h = this.host();
    const m = (h && h.mode) ? h.mode : this.props.adminMode;
    if (m === 'readonly' || m === 'Read-only (public embed)') return 'readonly';
    if (m === 'open' || m === 'Always admin (trusted iframe)') return 'open';
    if (m === 'host' || m === 'Host-controlled login') return 'host';
    return 'password';
  }
  host() { return (typeof window !== 'undefined' && window.NoticeboardHost) ? window.NoticeboardHost : null; }
  resolveAdmin() {
    const mk = this.modeKey();
    if (mk === 'readonly') return false;
    if (mk === 'open') return true;
    if (mk === 'host') { const h = this.host(); return !!(h && h.isAdmin); }
    try { return sessionStorage.getItem('pinboard.admin') === '1'; } catch(e) { return false; }
  }

  hash(s) { s = String(s); let h = 0; for (let i=0;i<s.length;i++) h = (h*31 + s.charCodeAt(i)) >>> 0; return h; }

  isVideo(p) {
    if (p.mediaType === 'video') return true;
    if (p.mediaType === 'image') return false;
    return /\.(mp4|webm|ogg|ogv|mov|m4v)(\?|#|$)/i.test(p.image || '') || /^data:video\//i.test(p.image || '');
  }

  display(p) {
    const pal = this.palette();
    const c = pal[(p.colorIndex||0) % pal.length];
    const h = this.hash(p.id);
    const len = (p.title||'').length;
    const cqw = len < 14 ? 13 : len < 22 ? 11 : len < 32 ? 9.5 : 8;
    const isCanva = p.mediaType === 'canva' && !!(p.canva && p.canva.trim());
    const hasThumb = isCanva && !!(p.thumb && p.thumb.trim());
    const hasMedia = !isCanva && !!(p.image && p.image.trim());
    const vid = hasMedia && this.isVideo(p);
    const past = this.isPast(p);
    const rot = (h % 7) - 3;
    const ap = (p.aspect || '4/5').split('/');
    const ar = (parseFloat(ap[0]) || 4) / (parseFloat(ap[1]) || 5);
    const sm = this.scheduleMeta(p);
    return {
      ...p,
      _sort: sm.sort,
      bg: (hasMedia || isCanva) ? '#111' : c.bg,
      fg: (hasMedia || isCanva) ? '#fff' : c.fg,
      pin: c.pin,
      hasVideo: vid,
      hasImage: hasMedia && !vid,
      isCanva: isCanva,
      hasThumb: hasThumb,
      canvaPlaceholder: isCanva && !hasThumb,
      canvaLive: isCanva && !hasThumb,
      canvaSrc: p.canva || '',
      showCaption: !!p.overlay,
      captionDate: !!p.overlay && !!sm.label,
      thumb: p.thumb || '',
      imgEl: (hasMedia && !vid) ? React.createElement('img', { src: p.image, alt: p.title || '', loading: 'lazy', style: { position:'absolute', inset:0, width:'100%', height:'100%', objectFit:'cover', display:'block' } }) : null,
      thumbEl: hasThumb ? React.createElement('img', { src: p.thumb, alt: p.title || '', loading: 'lazy', style: { position:'absolute', inset:0, width:'100%', height:'100%', objectFit:'cover', display:'block' } }) : null,
      noMedia: !hasMedia && !isCanva,
      category: p.category || 'Other',
      isPast: past,
      wrapStyle: past ? 'opacity:.6; filter:grayscale(.45);' : '',
      titleCqw: cqw + 'cqw',
      fam: p.serif ? "'Instrument Serif', Georgia, serif" : "'Bricolage Grotesque', system-ui, sans-serif",
      titleWeight: p.serif ? '400' : '800',
      rot,
      rotStyle: 'rotate(' + rot + 'deg)',
      isPin: (h % 2) === 0,
      isTape: (h % 2) === 1,
      aspect: p.aspect || '4/5',
      ar: ar,
      dateLabel: sm.label,
      location: p.location || '',
      kicker: p.kicker || '',
      hint: (p.link && p.link.trim()) ? 'TAP POSTER → EVENT PAGE   ·   TAP OUTSIDE TO CLOSE' : 'TAP OUTSIDE TO CLOSE'
    };
  }

  displaySel() { return this.state.selected ? this.display(this.state.selected) : null; }

  open(p, el) { this.openRect(p, el.getBoundingClientRect()); }
  openRect(p, rect) { this._first = rect; this._flipIn = true; this.setState({ selected: p, showQR: false }); }
  toggleQR() { this.setState(s => ({ showQR: !s.showQR })); }
  openById(id) {
    const p = (this.state.posters || []).find(x => x.id === id);
    if (!p) return false;
    const el = document.querySelector('[data-card-id="' + id + '"]');
    if (el) this.open(p, el);
    else this.openRect(p, { left: innerWidth / 2 - 30, top: innerHeight / 2 - 40, width: 60, height: 80 });
    return true;
  }
  tryDeepLink() {
    if (this._deepDone) return;
    let id = null;
    try { const u = new URL(location.href); id = u.searchParams.get('notice'); if (!id && location.hash.indexOf('notice-') > -1) id = location.hash.split('notice-')[1]; } catch(e) {}
    if (id && this.openById(id)) this._deepDone = true;
  }
  shareUrl() {
    try { const u = new URL(location.href); u.hash = ''; u.search = ''; u.searchParams.set('notice', this.state.selected.id); return u.href; } catch(e) { return location.href; }
  }
  share() {
    const sel = this.state.selected; if (!sel) return;
    const url = this.shareUrl();
    if (navigator.share) { navigator.share({ title: sel.title || 'Noticeboard', url }).catch(() => {}); return; }
    if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(url).then(() => this.flash('Link copied to clipboard')).catch(() => this.flash(url)); }
    else this.flash(url);
  }
  slug(s) { return (String(s || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')) || 'notice'; }
  download() {
    const sel = this.state.selected; if (!sel) return;
    const d = this.display(sel);
    if (d.isCanva) { const u = this.canvaViewUrl(sel); if (u) window.open(u, '_blank'); return; }
    if (d.hasImage || d.hasVideo) {
      const ext = d.hasVideo ? '.mp4' : '.jpg';
      fetch(sel.image).then(r => r.blob()).then(b => {
        const o = URL.createObjectURL(b); const a = document.createElement('a');
        a.href = o; a.download = this.slug(sel.title) + ext; document.body.appendChild(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(o), 5000); this.flash('Downloading…');
      }).catch(() => window.open(sel.image, '_blank'));
      return;
    }
    // text poster → downloadable calendar event
    const sm = this.scheduleMeta(sel);
    if (!sm.sort || sm.sort >= 8e15) { this.flash('No date to add'); return; }
    const pad = (n) => String(n).padStart(2, '0');
    const fmt = (dt) => dt.getFullYear() + pad(dt.getMonth() + 1) + pad(dt.getDate()) + 'T' + pad(dt.getHours()) + pad(dt.getMinutes()) + '00';
    const esc = (s) => String(s || '').replace(/([,;\\])/g, '\\$1').replace(/\n/g, '\\n');
    const start = new Date(sm.sort); const end = new Date(sm.sort + 3600000);
    const ics = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Noticeboard//EN', 'BEGIN:VEVENT', 'UID:' + sel.id + '@noticeboard', 'DTSTART:' + fmt(start), 'DTEND:' + fmt(end), 'SUMMARY:' + esc(sel.title), sel.location ? 'LOCATION:' + esc(sel.location) : '', sel.link ? 'DESCRIPTION:' + esc(sel.link) : '', 'END:VEVENT', 'END:VCALENDAR'].filter(Boolean).join('\r\n');
    const b = new Blob([ics], { type: 'text/calendar' }); const o = URL.createObjectURL(b); const a = document.createElement('a');
    a.href = o; a.download = this.slug(sel.title) + '.ics'; document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(o), 5000); this.flash('Calendar event downloaded');
  }
  flash(msg) { this.setState({ toast: msg }); clearTimeout(this._toastT); this._toastT = setTimeout(() => this.setState({ toast: '' }), 2200); }

  flipIn() {
    if (this.state.reduceMotion) {
      if (this.detailRef) this.detailRef.animate([{ opacity:0, transform:'scale(.97)' }, { opacity:1, transform:'scale(1)' }], { duration:200, easing:'ease' });
      if (this.backdropRef) this.backdropRef.animate([{ opacity:0 }, { opacity:1 }], { duration:180, easing:'ease' });
      if (this.barRef) this.barRef.animate([{ opacity:0 }, { opacity:1 }], { duration:280, easing:'ease' });
      return;
    }
    const first = this._first, last = this.detailRef.getBoundingClientRect();
    const dx = (first.left + first.width/2) - (last.left + last.width/2);
    const dy = (first.top + first.height/2) - (last.top + last.height/2);
    const sc = first.width / last.width;
    const d = this.displaySel(); const rot = d ? d.rot : 0;
    // outer layer: fly + scale from the board to centre (no rotation)
    this.detailRef.animate(
      [
        { transform:`translate(${dx}px,${dy}px) scale(${sc}) rotateZ(${rot}deg)`, offset:0 },
        { transform:`translate(${dx*0.06}px,${dy*0.06}px) scale(${sc + (1-sc)*0.94}) rotateZ(0deg)`, offset:0.84 },
        { transform:'translate(0,0) scale(1) rotateZ(0deg)', offset:1 }
      ],
      { duration:1080, easing:'cubic-bezier(.22,.68,.24,1)' }
    );
    // inner layer: full page-turn hinged on the left edge, with soft paper flop
    if (this.pageRef) this.pageRef.animate(
      [
        { transform:'rotateY(-360deg) rotateZ(0deg) rotateX(0deg) scaleY(1)',     offset:0 },
        { transform:'rotateY(-298deg) rotateZ(8deg) rotateX(-10deg) scaleY(.985)', offset:0.18 },
        { transform:'rotateY(-216deg) rotateZ(-7deg) rotateX(9deg) scaleY(1.025)', offset:0.38 },
        { transform:'rotateY(-150deg) rotateZ(6deg) rotateX(-6deg) scaleY(.99)',   offset:0.54 },
        { transform:'rotateY(-72deg) rotateZ(-5deg) rotateX(5deg) scaleY(1.015)',  offset:0.72 },
        { transform:'rotateY(-14deg) rotateZ(4deg) rotateX(-4deg) scaleY(.995)',   offset:0.85 },
        { transform:'rotateY(7deg) rotateZ(-2.5deg) rotateX(2.5deg) scaleY(1)',    offset:0.93 },
        { transform:'rotateY(0deg) rotateZ(0deg) rotateX(0deg) scaleY(1)',         offset:1 }
      ],
      { duration:1080, easing:'linear' }
    );
    if (this.backdropRef) this.backdropRef.animate([{ opacity:0 }, { opacity:1 }], { duration:420, easing:'ease' });
    if (this.barRef) this.barRef.animate([{ opacity:0 }, { opacity:0, offset:0.6 }, { opacity:1 }], { duration:1100, easing:'ease' });
  }

  close() {
    const sel = this.state.selected;
    if (!sel || !this.detailRef) { this.setState({ selected:null }); return; }
    try { const u = new URL(location.href); if (u.searchParams.has('notice')) { u.searchParams.delete('notice'); history.replaceState({}, '', u.pathname + (u.search ? u.search : '') + u.hash); } } catch(e) {}
    this._deepDone = true;
    if (this.state.reduceMotion) {
      if (this.backdropRef) this.backdropRef.animate([{ opacity:1 }, { opacity:0 }], { duration:170, easing:'ease' });
      this.detailRef.animate([{ opacity:1 }, { opacity:0, transform:'scale(.98)' }], { duration:170, easing:'ease' });
      this._closing = true;
      const fin = () => { if (this._closing) { this._closing = false; this.setState({ selected:null }); } };
      setTimeout(fin, 180);
      return;
    }
    const card = document.querySelector('[data-card-id="' + sel.id + '"]');
    const last = this.detailRef.getBoundingClientRect();
    let target = card ? card.getBoundingClientRect()
                      : { left: innerWidth/2 - last.width/2, top: -last.height, width: last.width, height: last.height };
    const dx = (target.left + target.width/2) - (last.left + last.width/2);
    const dy = (target.top + target.height/2) - (last.top + last.height/2);
    const sc = target.width / last.width;
    const d = this.displaySel(); const rot = d ? d.rot : 0;
    const a = this.detailRef.animate(
      [
        { transform:'translate(0,0) scale(1) rotateZ(0deg)', opacity:1 },
        { transform:`translate(${dx}px,${dy}px) scale(${sc}) rotateZ(${rot}deg)`, opacity:0.15 }
      ],
      { duration:760, easing:'cubic-bezier(.4,0,.3,1)' }
    );
    if (this.pageRef) this.pageRef.animate(
      [
        { transform:'rotateY(0deg) rotateZ(0deg) rotateX(0deg) scaleY(1)',      offset:0 },
        { transform:'rotateY(28deg) rotateZ(-4deg) rotateX(5deg) scaleY(.99)',  offset:0.14 },
        { transform:'rotateY(120deg) rotateZ(6deg) rotateX(-7deg) scaleY(1.02)',offset:0.4 },
        { transform:'rotateY(210deg) rotateZ(-6deg) rotateX(8deg) scaleY(.99)', offset:0.6 },
        { transform:'rotateY(312deg) rotateZ(5deg) rotateX(-5deg) scaleY(1.01)',offset:0.82 },
        { transform:'rotateY(360deg) rotateZ(0deg) rotateX(0deg) scaleY(1)',    offset:1 }
      ],
      { duration:760, easing:'linear' }
    );
    if (this.backdropRef) this.backdropRef.animate([{ opacity:1 }, { opacity:0 }], { duration:700, easing:'ease' });
    this._closing = true;
    const done = () => { if (this._closing) { this._closing = false; this.setState({ selected:null }); } };
    a.onfinish = done;
    setTimeout(done, 790);
  }

  visit() { const sel = this.state.selected; if (sel && sel.link && sel.link.trim()) window.open(sel.link, '_blank'); }

  // ---- auth ----
  openAuth() { this.setState({ authOpen:true, authInput:'', authError:false }); }
  // Admin password resolution (standalone 'password' mode):
  //   1. window.NoticeboardHost.password   (set in your deploy wrapper)
  //   2. localStorage 'pinboard.password'   (set via "Change password" in-app)
  //   3. adminPassword prop                 (Tweaks / data-prop)
  //   4. 'admin'                            (last-resort default)
  adminPassword() {
    const h = this.host();
    if (h && typeof h.password === 'string' && h.password) return h.password;
    try { const s = localStorage.getItem('pinboard.password'); if (s) return s; } catch(e) {}
    if (this.props.adminPassword) return this.props.adminPassword;
    return 'admin';
  }
  submitAuth() {
    const pw = this.state.authInput;
    const h = this.host();
    // Server-validated password (shared deployments): host.checkPassword(pw) → Promise<bool|{token}>
    if (h && typeof h.checkPassword === 'function') {
      this.setState({ authBusy:true, authError:false });
      Promise.resolve(h.checkPassword(pw)).then((ok) => {
        if (ok) {
          if (ok && ok.token) { try { sessionStorage.setItem('pinboard.token', ok.token); } catch(e) {} }
          try { sessionStorage.setItem('pinboard.admin', '1'); } catch(e) {}
          this.setState({ adminAuthed:true, authOpen:false, authInput:'', authBusy:false });
        } else { this.setState({ authError:true, authBusy:false }); }
      }).catch(() => this.setState({ authError:true, authBusy:false }));
      return;
    }
    if (pw === this.adminPassword()) {
      try { sessionStorage.setItem('pinboard.admin', '1'); } catch(e) {}
      this.setState({ adminAuthed:true, authOpen:false, authInput:'' });
    } else { this.setState({ authError:true }); }
  }
  signOut() { try { sessionStorage.removeItem('pinboard.admin'); sessionStorage.removeItem('pinboard.token'); } catch(e) {} this.setState({ adminAuthed:false, adminOpen:false }); }

  // ---- form ----
  setField(name) { return (e) => { const v = e.target.value; this.setState(s => ({ form:{ ...s.form, [name]:v } })); }; }
  setColor(i) { this.setState(s => ({ form:{ ...s.form, colorIndex:i } })); }
  setSerif(e) { const v = e.target.value === 'serif'; this.setState(s => ({ form:{ ...s.form, serif:v } })); }
  setImageUrl(e) {
    const v = e.target.value;
    if (this.isCanvaInput(v)) {
      const c = this.parseCanva(v);
      this.setState(s => ({ form:{ ...s.form, canva:c.src, canvaCode:v, mediaType:'canva', image:'', uploaded:false, aspect: c.aspect || (s.form.aspect === '4/5' ? '4/3' : s.form.aspect) } }));
      return;
    }
    const t = /\.(mp4|webm|ogg|ogv|mov|m4v)(\?|#|$)/i.test(v) ? 'video' : 'image';
    this.setState(s => ({ form:{ ...s.form, image:v, canva:'', mediaType:t, uploaded:false } }));
  }
  onThumb(e) { const v = e.target.value; this.setState(s => ({ form:{ ...s.form, thumb:v } })); }
  handleFile(e) {
    const f = e.target.files && e.target.files[0];
    if (!f) return;
    const isVid = (f.type || '').startsWith('video');
    e.target.value = '';
    const host = this.host();
    // Shared deployment: push the file to the server, store only its URL.
    if (host && typeof host.uploadFile === 'function') {
      this.setState(s => ({ form:{ ...s.form, uploading:true } }));
      Promise.resolve(host.uploadFile(f)).then((url) => {
        this.setState(s => ({ form:{ ...s.form, image:url, mediaType: isVid ? 'video' : 'image', uploaded:true, uploading:false } }));
      }).catch((err) => {
        this.setState(s => ({ form:{ ...s.form, uploading:false } }));
        alert('Upload failed: ' + (err && err.message ? err.message : 'try a hosted URL instead'));
      });
      return;
    }
    // Standalone/local: inline as a data URL (fine for images; large videos may
    // exceed browser storage — a hosted URL or the server backend is better).
    const reader = new FileReader();
    reader.onload = () => { this.setState(s => ({ form:{ ...s.form, image:reader.result, mediaType: isVid ? 'video' : 'image', uploaded:true } })); };
    reader.onerror = () => alert('Could not read that file — try a smaller file or a hosted URL.');
    reader.readAsDataURL(f);
  }
  clearMedia() { this.setState(s => ({ form:{ ...s.form, image:'', canva:'', canvaCode:'', thumb:'', uploaded:false, mediaType:'image' } })); }

  openAdd() { if (!this.state.adminAuthed) { if (this.modeKey() === 'password') this.openAuth(); return; } this.setState({ adminOpen:true, editingId:null, form:this.blankForm() }); }
  toggleAdmin() { if (!this.state.adminAuthed) { if (this.modeKey() === 'password') this.openAuth(); return; } this.setState(s => ({ adminOpen:!s.adminOpen })); }
  openEdit(p) { this.setState({ adminOpen:true, editingId:p.id, form:{ title:p.title, kicker:p.kicker||'', category:p.category||'Community', schedule:p.schedule||'once', date:p.schedule==='once'?(p.date||''):'', weekday:String(p.weekday!=null?p.weekday:6), time:p.time||'', location:p.location||'', link:p.link||'', colorIndex:p.colorIndex||0, aspect:p.aspect||'4/5', serif:!!p.serif, image:p.image||'', mediaType:p.mediaType || (this.isVideo(p)?'video':'image'), canva:p.canva||'', canvaCode:p.canvaCode||p.canva||'', thumb:p.thumb||'', uploaded: /^data:/i.test(p.image||''), overlay:!!p.overlay } }); }
  saveForm() {
    if (!this.state.adminAuthed) return;
    const f = this.state.form;
    if (!f.title.trim()) return;
    const rec = { title:f.title, kicker:f.kicker, category:f.category, schedule:f.schedule, date:f.schedule==='once'?f.date:'', weekday:Number(f.weekday), time:f.time, location:f.location, link:f.link, colorIndex:f.colorIndex, aspect:f.aspect, serif:f.serif, image:f.image, mediaType:f.mediaType, canva:f.canva, canvaCode:f.canvaCode, thumb:f.thumb, overlay:f.overlay };
    let posters = (this.state.posters || []).slice();
    if (this.state.editingId) posters = posters.map(p => p.id === this.state.editingId ? { ...p, ...rec } : p);
    else posters.push({ id:'p' + Date.now(), ...rec });
    if (this.save(posters)) this.setState({ posters, editingId:null, form:this.blankForm() });
  }
  remove(id) {
    const posters = (this.state.posters || []).filter(p => p.id !== id);
    this.setState(s => ({ posters, editingId: s.editingId === id ? null : s.editingId, form: s.editingId === id ? this.blankForm() : s.form }));
    this.save(posters);
  }

  renderVals() {
    const tones = { 'Warm walnut':'#241c14', 'Cool slate':'#222629', 'Charcoal':'#16140f' };
    const wallBg = tones[this.props.wallTone] || '#241c14';
    const all = (this.state.posters || []).map((p, i) => {
      const d = this.display(p);
      d._i = i;
      d.onOpen = (e) => this.open(p, e.currentTarget);
      d.onEdit = () => this.openEdit(p);
      d.onDelete = () => this.remove(p.id);
      return d;
    });
    const upcoming = all.filter(d => !d.isPast);
    const past = all.filter(d => d.isPast);
    const present = [];
    upcoming.forEach(d => { if (present.indexOf(d.category) < 0) present.push(d.category); });
    const chipCats = ['All'].concat(this.cats().filter(c => present.indexOf(c) >= 0));
    const chips = chipCats.map(c => ({
      label: c, key: c, active: this.state.filter === c,
      onClick: () => this.setState({ filter: c }),
      style: 'padding:7px 14px; border-radius:999px; font-family:\'IBM Plex Sans\',sans-serif; font-size:13px; font-weight:600; cursor:pointer; white-space:nowrap; border:1px solid; transition:background .2s, color .2s; ' +
        (this.state.filter === c ? 'background:#e8c98a; color:#1c150c; border-color:#e8c98a;' : 'background:rgba(241,231,212,.08); color:#cbb085; border-color:rgba(202,169,128,.28);')
    }));
    const showPast = this.state.adminAuthed && this.state.showPast;
    let visible = showPast ? all.slice() : upcoming.slice();
    if (this.state.filter !== 'All') visible = visible.filter(d => d.category === this.state.filter);
    visible.sort((a, b) => ((a.isPast ? 1 : 0) - (b.isPast ? 1 : 0)) || (a._sort - b._sort) || (a._i - b._i));
    const posters = visible;
    const swatches = this.palette().map((c, i) => ({
      ...c, i,
      onClick: () => this.setColor(i),
      ring: this.state.form.colorIndex === i ? '0 0 0 2px #1c1610, 0 0 0 4px #f1e7d4' : '0 0 0 1px rgba(255,255,255,.15)'
    }));
    const f = this.state.form;
    return {
      loaded: this.state.posters != null,
      isEmpty: this.state.posters != null && posters.length === 0,
      emptyMsg: (this.state.filter !== 'All') ? ('No ' + this.state.filter + ' notices right now.') : 'No notices pinned yet.',
      posters,
      count: posters.length,
      chips,
      hasChips: chips.length > 1,
      pastCount: past.length,
      showPastChk: this.state.showPast,
      hasPast: past.length > 0,
      adminHasPast: this.state.adminAuthed && past.length > 0,
      toggleShowPast: () => this.setState(s => ({ showPast: !s.showPast })),
      setScrollerRef: (el) => this.bindScroller(el),
      scrollHint: this.state.scrollHint,
      scrollToMore: () => { if (this.scroller) this.scroller.scrollBy({ top: this.scroller.clientHeight * 0.8, behavior: this.state.reduceMotion ? 'auto' : 'smooth' }); },
      showQR: this.state.showQR,
      toggleQR: (e) => { if (e) e.stopPropagation(); this.toggleQR(); },
      qrEl: (this.state.selected && this.state.showQR)
        ? React.createElement('img', { src: this.qrSrc(this.shareUrl()), alt: 'QR code linking to this notice', style: { width: 170, height: 170, display: 'block', imageRendering: 'pixelated' } })
        : null,
      boardTitle: this.props.boardTitle ?? 'Community Noticeboard',
      boardSubtitle: this.props.boardSubtitle ?? "What's on · pinned this week",
      colWidthStyle: (this.state.size ?? this.props.columnWidth ?? 330) + 'px',
      sizeVal: this.state.size ?? this.props.columnWidth ?? 330,
      onSize: (e) => { const v = +e.target.value; this.setState({ size:v }); try { localStorage.setItem('pinboard.size', String(v)); } catch(err) {} },
      wallBg,
      hasSelected: !!this.state.selected,
      reduceMotion: this.state.reduceMotion,
      toggleMotion: () => this.toggleMotion(),
      noop: (e) => e.preventDefault(),
      sel: this.displaySel(),
      close: () => this.close(),
      closeBtn: (e) => { e.stopPropagation(); this.close(); },
      detailClick: (e) => { e.stopPropagation(); this.visit(); },
      setDetailRef: (el) => { this.detailRef = el; },
      setPageRef: (el) => { this.pageRef = el; },
      setBackdropRef: (el) => { this.backdropRef = el; },
      setBarRef: (el) => { this.barRef = el; },
      toast: this.state.toast,
      doShare: (e) => { e.stopPropagation(); this.share(); },
      doDownload: (e) => { e.stopPropagation(); this.download(); },
      barStop: (e) => e.stopPropagation(),
      downloadLabel: (() => { if (!this.state.selected) return 'Download'; const d = this.display(this.state.selected); if (d.isCanva) return 'Open in Canva'; return (d.hasImage || d.hasVideo) ? 'Download' : 'Add to calendar'; })(),
      // auth
      adminAuthed: this.state.adminAuthed,
      showSignIn: this.modeKey() === 'password' && !this.state.adminAuthed,
      showSignOut: this.modeKey() === 'password' && this.state.adminAuthed,
      authOpen: this.state.authOpen,
      authInput: this.state.authInput,
      authError: this.state.authError,
      openAuth: () => this.openAuth(),
      closeAuth: () => this.setState({ authOpen:false }),
      submitAuth: () => this.submitAuth(),
      signOut: () => this.signOut(),
      onAuthInput: (e) => this.setState({ authInput:e.target.value, authError:false }),
      onAuthKey: (e) => { if (e.key === 'Enter') this.submitAuth(); },
      // admin drawer
      adminOpen: this.state.adminOpen,
      adminTitle: this.state.editingId ? 'Edit notice' : 'Add a notice',
      saveLabel: this.state.editingId ? 'Save changes' : 'Pin to board',
      editing: !!this.state.editingId,
      deleteCurrent: () => this.remove(this.state.editingId),
      toggleAdmin: () => this.toggleAdmin(),
      openAdd: () => this.openAdd(),
      closeAdmin: () => this.setState({ adminOpen:false }),
      stop: (e) => e.stopPropagation(),
      swatches,
      saveForm: () => this.saveForm(),
      onFile: (e) => this.handleFile(e),
      clearMedia: () => this.clearMedia(),
      f_hasMedia: !!((f.image && f.image.trim()) || (f.canva && f.canva.trim())),
      f_mediaLabel: f.mediaType === 'canva' ? 'Canva design' : (f.mediaType === 'video' ? 'Video' : 'Image'),
      f_urlValue: f.uploaded ? '' : (f.mediaType === 'canva' ? f.canva : f.image),
      f_title:f.title, f_kicker:f.kicker, f_location:f.location, f_link:f.link, f_aspect:f.aspect,
      f_category: f.category,
      catOptions: this.cats(),
      onCategory: this.setField('category'),
      f_isCanva: f.mediaType === 'canva' && !!(f.canva && f.canva.trim()),
      f_hasAnyMedia: !!((f.image && f.image.trim()) || (f.canva && f.canva.trim())),
      f_overlay: !!f.overlay,
      onOverlay: (e) => { const v = e.target.checked; this.setState(s => ({ form:{ ...s.form, overlay:v } })); },
      f_canvaCode: f.canvaCode || '',
      onCanvaCode: (e) => this.onCanvaCode(e),
      f_thumb: f.thumb,
      onThumb: (e) => this.onThumb(e),
      f_serifVal: f.serif ? 'serif' : 'sans',
      f_isOnce: f.schedule === 'once', f_isWeekly: f.schedule === 'weekly',
      f_dateVal: f.date, f_timeVal: f.time, f_weekday: String(f.weekday),
      f_onceStyle: 'flex:1; padding:10px; border-radius:8px; font-family:\'IBM Plex Sans\',sans-serif; font-size:13px; font-weight:600; cursor:pointer; border:1px solid rgba(255,255,255,.14); ' + (f.schedule === 'once' ? 'background:#e8c98a; color:#1c150c;' : 'background:transparent; color:#caa980;'),
      f_weeklyStyle: 'flex:1; padding:10px; border-radius:8px; font-family:\'IBM Plex Sans\',sans-serif; font-size:13px; font-weight:600; cursor:pointer; border:1px solid rgba(255,255,255,.14); ' + (f.schedule === 'weekly' ? 'background:#e8c98a; color:#1c150c;' : 'background:transparent; color:#caa980;'),
      setOnce: () => this.setState(s => ({ form:{ ...s.form, schedule:'once' } })),
      setWeekly: () => this.setState(s => ({ form:{ ...s.form, schedule:'weekly' } })),
      onDateVal: this.setField('date'), onTimeVal: this.setField('time'), onWeekday: this.setField('weekday'),
      onTitle:this.setField('title'), onKicker:this.setField('kicker'),
      onLocation:this.setField('location'), onLink:this.setField('link'), onImage:(e)=>this.setImageUrl(e),
      onAspect:this.setField('aspect'), onSerif:(e)=>this.setSerif(e)
    };
  }
}
    return Component;
  }
  function ready(){return typeof window.getDC==="function"&&window.React&&window.ReactDOM&&window.__dcRegistry&&window.DCLogic;}
  function mount(){
    var React=window.React,ReactDOM=window.ReactDOM;
    var host=(window.NoticeboardHost&&typeof window.NoticeboardHost==="object")?window.NoticeboardHost:null;
    window.__dcUpdate(ROOT,"html",TEMPLATE,false);
    if(PROPS_JSON){try{window.__dcUpdate(ROOT,"props",PROPS_JSON,false);}catch(e){}}
    var entry=window.__dcRegistry[ROOT];
    entry.fetched=true;
    entry.Logic=defineComponent(window.DCLogic,window.DCLogic,window.React);
    entry.logicError=null;
    if(host&&host.props&&typeof host.props==="object"){window.__dcSetProps(ROOT,host.props);}
    var mountEl=document.getElementById("noticeboard-root")||document.body;
    var Root=window.getDC(ROOT);
    function StandaloneRoot(){
      var t=React.useState(0),setTick=t[1];
      React.useEffect(function(){var sub=function(){setTick(function(n){return n+1;});};entry.subs.add(sub);return function(){entry.subs.delete(sub);};},[]);
      return React.createElement(Root,entry.propOverrides||null);
    }
    if(ReactDOM.createRoot)ReactDOM.createRoot(mountEl).render(React.createElement(StandaloneRoot));
    else ReactDOM.render(React.createElement(StandaloneRoot),mountEl);
  }
  function waitThenMount(){
    if(ready()){mount();return;}
    var n=0,iv=setInterval(function(){if(ready()){clearInterval(iv);mount();}else if(++n>600){clearInterval(iv);console.error("[noticeboard] runtime/React not ready");}},25);
  }
  if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",waitThenMount);
  else waitThenMount();
})();
