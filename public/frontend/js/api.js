window.SFMSApi = (function(){
    const defaultOpts = { retries: 2, retryDelay: 500 };

    async function delay(ms){ return new Promise(r=>setTimeout(r, ms)); }

    async function fetchJson(url, opts = {}){
        opts = Object.assign({}, defaultOpts, opts);
        let attempts = 0;
        let lastErr = null;
        while(attempts <= opts.retries){
            try{
                const res = await fetch(url, opts.fetchOptions || {});
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const json = await res.json();
                return json;
            } catch (e){
                lastErr = e;
                attempts++;
                if (attempts > opts.retries) break;
                await delay(opts.retryDelay);
            }
        }
        throw lastErr;
    }

    return { fetchJson };
})();
