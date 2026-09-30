(function(window){
    const Components = {};

    function resolveAppUrl(path) {
        const raw = String(path || '');
        if (/^(?:https?:)?\/\//i.test(raw)) {
            return raw;
        }

        // Guard against double-resolution: callers sometimes pass a URL that
        // has already been built with window.SFMS_PUBLIC_URL(). Re-running it
        // through SFMS_PUBLIC_URL() a second time would prepend the app base
        // path twice (e.g. /SFMS/SFMS/api/...). If raw already starts with
        // the configured base path, treat it as already resolved.
        const basePath = String(window.SFMS_BASE_PATH || '').replace(/\/$/, '');
        if (basePath !== '' && raw.startsWith(basePath)) {
            return raw;
        }

        if (window.SFMS_PUBLIC_URL) {
            return window.SFMS_PUBLIC_URL(raw);
        }

        return raw;
    }

    async function fetchJson(url, options = {}) {
        const requestUrl = resolveAppUrl(url);
        // TEMP DEBUG — remove once caller identified
        if (typeof console !== 'undefined' && typeof console.trace === 'function') {
            console.trace('[fetchJson] input=', url, '| resolved=', requestUrl);
        }
        const response = await fetch(requestUrl, options);
        const rawText = await response.text();
        const contentType = String(response.headers.get('content-type') || '').toLowerCase();
        const trimmed = rawText.trim();

        let data = null;
        if (trimmed !== '') {
            const looksJson = contentType.includes('application/json') || /^[\[{]/.test(trimmed);
            if (!looksJson) {
                console.error('SFMS fetchJson received non-JSON response', {
                    url: requestUrl,
                    status: response.status,
                    contentType,
                    preview: trimmed.slice(0, 300),
                });
                throw new Error('The server returned an unexpected non-JSON response.');
            }

            try {
                data = JSON.parse(trimmed);
            } catch (error) {
                console.error('SFMS fetchJson received malformed JSON', {
                    url: requestUrl,
                    status: response.status,
                    contentType,
                    preview: trimmed.slice(0, 300),
                });
                throw new Error('The server returned malformed JSON.');
            }
        }

        return { response, data };
    }

    // Helper to safely extract items array from various backend shapes
    function extractItemsFromResponse(json) {
        if (!json) return [];
        if (Array.isArray(json)) return json;
        if (json.data) {
            if (Array.isArray(json.data.buildings)) return json.data.buildings;
            if (Array.isArray(json.data.departments)) return json.data.departments;
            if (Array.isArray(json.data.rooms)) return json.data.rooms;
            if (Array.isArray(json.data.users)) return json.data.users;
            if (Array.isArray(json.data.items)) return json.data.items;
            if (Array.isArray(json.data.reports)) return json.data.reports;
            // TASK 13 PHASE 8 (Repair retirement) — the `repairs` probe was
            // removed from this chain (and from the unwrapped chain below).
            // The only payload that ever carried a `repairs` key was the
            // deleted RepairController's index response; every other key in
            // both chains belongs to an endpoint that still exists, so the
            // fallthrough order for all remaining shapes is unchanged.
            if (Array.isArray(json.data.receipts)) return json.data.receipts;
            if (Array.isArray(json.data.data)) return json.data.data; // paginator
        }
        if (Array.isArray(json.departments)) return json.departments;
        if (Array.isArray(json.rooms)) return json.rooms;
        if (Array.isArray(json.users)) return json.users;
        if (Array.isArray(json.reports)) return json.reports;
        return [];
    }

    Components._instances = [];
    Components._clickBound = false;

    function pruneDisconnectedInstances() {
        Components._instances = Components._instances.filter((inst) => {
            return inst && inst.input && inst.input.isConnected && inst.container && inst.container.isConnected;
        });
    }

    class SearchableSelect {
        constructor(opts){
            this.input = document.getElementById(opts.inputId);
            this.hidden = document.getElementById(opts.hiddenId);
            this.endpoint = opts.endpoint;
            this.displayKey = opts.displayKey || 'name';
            this.pageSize = opts.pageSize || 50;
            this.debounceMs = opts.debounceMs || 250;
            this.onSelect = typeof opts.onSelect === 'function' ? opts.onSelect : null;
            this.list = null;
            this.timeout = null;
            this.init();
        }

        init(){
            if(!this.input) return;
            const container = document.createElement('div'); container.style.position='relative';
            this.input.parentNode.insertBefore(container, this.input); container.appendChild(this.input);
            this.container = container;
            this.list = document.createElement('div'); this.list.className='search-dropdown'; this.list.style.cssText='position:absolute;left:0;right:0;z-index:1000;background:#1a1230;border:1px solid rgba(168,139,250,0.22);color:#f0eaff;max-height:220px;overflow:auto;display:none;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,0.4)'; container.appendChild(this.list);
            this.input.addEventListener('input', (e)=> this.onInput(e));
            this.input.addEventListener('focus', (e)=> this.onInput(e));

            // Register instance and bind a single document click handler once
            pruneDisconnectedInstances();
            Components._instances.push(this);
            if (!Components._clickBound) {
                document.addEventListener('click', (e)=>{
                    pruneDisconnectedInstances();
                    Components._instances.forEach(inst => {
                        try {
                            if (!inst.container.contains(e.target)) inst.list.style.display = 'none';
                        } catch (err) {
                            // ignore if instance removed
                        }
                    });
                });
                Components._clickBound = true;
            }
        }

        destroy(){
            if (this.timeout) {
                clearTimeout(this.timeout);
                this.timeout = null;
            }
            if (this.list && this.list.parentNode) {
                this.list.parentNode.removeChild(this.list);
            }
            Components._instances = Components._instances.filter((inst) => inst !== this);
        }

        async onInput(e){
            const q = this.input.value.trim();
            if(this.timeout) clearTimeout(this.timeout);
            this.timeout = setTimeout(()=>this.fetchAndRender(q), this.debounceMs);
        }

        async fetchAndRender(q){
            try{
                const separator = this.endpoint.includes('?') ? '&' : '?';
                const url = this.endpoint + separator + 'q=' + encodeURIComponent(q) + '&per_page=' + this.pageSize + '&page=1';
                const { data: json } = await fetchJson(url, {
                    credentials: 'include',
                    headers: { 'Accept': 'application/json' }
                });
                const items = extractItemsFromResponse(json);
                this.renderList(items);
            }catch(e){
                console.error('SearchableSelect fetch error', e);
                this.renderList([]);
            }
        }

        renderList(items){
            this.list.innerHTML = '';
            if(!items || items.length === 0){ this.list.style.display='none'; return; }
            items.forEach(it=>{
                const row = document.createElement('div'); row.className='search-item'; row.style.padding='8px'; row.style.cursor='pointer';
                row.textContent = (it[this.displayKey] || '') + ((it.code || it.asset_code) ? (' — ' + (it.code || it.asset_code)) : '');
                row.addEventListener('click', ()=>{ this.input.value = it[this.displayKey] || ''; this.hidden.value = it.id || it.department_id || it.user_id || it.room_id || ''; this.list.style.display='none'; if (this.onSelect) this.onSelect(it); });
                this.list.appendChild(row);
            });
            this.list.style.display = 'block';
        }
    }

    Components.SearchableSelect = SearchableSelect;
    Components.fetchJson = fetchJson;
    Components.resolveAppUrl = resolveAppUrl;
    // UI_BROWSER_DIALOG_REPLACEMENT — Components.toast/confirm/alert are thin,
    // page-facing wrappers around the single reusable UI.systemConfirm /
    // UI.systemAlert / UI.toast modal system defined in utils.js. utils.js is
    // loaded on every page before this file (see includes/footer.php), so
    // `window.UI` is always present here; no window.alert/window.confirm
    // fallback is used.
    Components.toast = function(message, type='info', duration=4000){ UI.toast(message, type, duration); };
    Components.confirm = function(message, yesLabel='Yes', noLabel='No', variant='danger'){ return UI.systemConfirm(message, yesLabel, noLabel, variant); };
    Components.alert = function(message, type='info'){ return UI.systemAlert(message, type); };

    Components.setLoading = function(button, loading=true){ if(!button) return; if(loading){ button.dataset.origText = button.textContent; button.disabled = true; button.textContent = 'Please wait...'; } else { if(button.dataset.origText) button.textContent = button.dataset.origText; button.disabled = false; } };

    Components.setFieldError = function(fieldId, message){ const el = document.getElementById(fieldId); const errId = fieldId + '_error'; let err = document.getElementById(errId); if(!err){ err = document.createElement('div'); err.id = errId; err.className = 'form-error'; err.style.color = '#b91c1c'; err.style.fontSize = '12px'; el.parentNode.appendChild(err); } err.textContent = message; };
    Components.clearFieldError = function(fieldId){ const err = document.getElementById(fieldId + '_error'); if(err) err.remove(); };

    window.Components = Components;
})(window);
