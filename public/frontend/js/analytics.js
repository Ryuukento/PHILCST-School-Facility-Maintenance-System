/* Analytics dashboard orchestrator: fetch, render, export, refresh */
/*
 * TASK 13 PHASE 8 (Repair retirement) — this file is NOT loaded by any page
 * (no <script src> anywhere in the app references it), but it is deliberately
 * NOT deleted. The brief permits deletion only if it is "truly unused AND
 * Repair-only"; it is unused but it is not Repair-only — it also drives the
 * overview, top-requested and inventory-health surfaces. So only the Repair
 * portions were stripped, which is what satisfies the regression requirement
 * that no remaining application code calls a deleted Repair API.
 *
 * Removed here: the `topRepaired` selector (GET /api/analytics/top-repaired,
 * a route this task deletes). `topRequested` directly above is the Inventory
 * metric and is a different endpoint that stays.
 */
(function(){
    const selectors = {
        overview: '/School_Facility_Maintenance_System/api/analytics/overview',
        topRequested: '/School_Facility_Maintenance_System/api/analytics/top-requested',
        inventoryHealth: '/School_Facility_Maintenance_System/api/analytics/inventory-health'
    };

    function persistFilters(filters){
        try { localStorage.setItem('sfms.analytics.filters', JSON.stringify(filters)); } catch(e){}
    }
    function loadFilters(){
        try { return JSON.parse(localStorage.getItem('sfms.analytics.filters')||'{}'); } catch(e){return {};}
    }

    async function loadAll(){
        const filters = loadFilters();
        // Overview
        try{
            const ov = await SFMSApi.fetchJson(selectors.overview);
            const data = ov.data || {};
            // TASK 13 PHASE 8 — `repair_trends` is no longer returned by
            // AnalyticsService::overview(), so the repair series and the
            // #repairTrendChart line chart it fed were removed. The
            // `damaged_trends` series below is a Damage Report metric, not a
            // Repair Request one, and is untouched — it also supplies the
            // shared `labels` axis, which is why the axis is unaffected.
            const damaged = data.damaged_trends||[];
            const labels = damaged.map(r=>r.ym);
            const damagedVals = damaged.map(r=>r.cnt);

            const dCanvas = document.getElementById('damagedTrendChart'); if (dCanvas){ dCanvas.style.display='block'; SFMSCharts.renderLineChart(dCanvas.getContext('2d'), labels, damagedVals, 'Damaged Reports'); document.getElementById('damagedSkeleton')?.remove(); }
        } catch (e){ console.error('overview load failed', e); }

        // Top requested
        try{
            const tr = await SFMSApi.fetchJson(selectors.topRequested);
            const list = (tr.data && tr.data.top_requested) || [];
            if (list.length){ const labels = list.map(x=>x.name); const vals = list.map(x=>x.total_requested); const c=document.getElementById('topRequestedChart'); c.style.display='block'; SFMSCharts.renderBarChart(c.getContext('2d'), labels, vals, 'Top Requested'); document.querySelector('#topRequestedWrap .ui-empty-state')?.remove(); }
        } catch(e){ console.error('top requested failed', e); }

        // TASK 13 PHASE 8 — the "Top repaired" block was removed here. It was
        // the only caller of GET /api/analytics/top-repaired (backed by the
        // deleted AnalyticsService::topRepairedItems()) and rendered into
        // #topRepairedChart. The "Top requested" block directly above is the
        // Inventory/Dispatch metric and is untouched.

        // Health
        try{
            const ih = await SFMSApi.fetchJson(selectors.inventoryHealth);
            const h = ih.data || {};
            if (h.total_items !== undefined){ const el = document.getElementById('inventoryHealth'); el.style.display='block'; el.innerHTML = `<div><strong>Total:</strong> ${h.total_items}</div><div><strong>Low:</strong> ${h.low_stock_count} (${h.low_stock_percent}%)</div><div><strong>Out:</strong> ${h.out_of_stock_count}</div>`; document.querySelector('#inventoryHealthWrap .ui-empty-state')?.remove(); }
        } catch(e){ console.error('inventory health failed', e); }
    }

    function wireButtons(){
        document.querySelectorAll('[data-analytics-export]').forEach(btn=>{
            btn.addEventListener('click', async function(){
                const what = this.getAttribute('data-analytics-export');
                try{
                    let payload = [];
                    const filters = (function(){ try{ return JSON.parse(localStorage.getItem('sfms.analytics.filters')||'{}'); }catch(e){return {};}})();
                    if (what === 'top-requested'){
                        const res = await SFMSApi.fetchJson('/School_Facility_Maintenance_System/api/analytics/top-requested'); payload = res.data.top_requested || [];
                    // TASK 13 PHASE 8 — the `what === 'top-repaired'` export
                    // branch was removed here; it fetched the deleted
                    // /api/analytics/top-repaired endpoint. The 'top-requested'
                    // and 'overview' branches are unrelated and remain.
                    } else if (what === 'overview'){
                        const res = await SFMSApi.fetchJson('/School_Facility_Maintenance_System/api/analytics/overview'); payload = res.data || {};
                    }
                    SFMSExport.downloadCsv('analytics-' + what, payload, { meta: { exported_at: new Date().toISOString(), filters: JSON.stringify(filters) } });
                } catch (e){
                    console.error('export failed', e);
                    // UI_BROWSER_DIALOG_REPLACEMENT — use the shared in-app
                    // modal instead of window.alert(); Components/UI are
                    // loaded globally via includes/footer.php on any page
                    // that includes this script.
                    Components.alert('Export failed: ' + (e.message||e));
                }
            });
        });

        const refreshBtn = document.getElementById('analyticsRefreshBtn');
        if (refreshBtn) refreshBtn.addEventListener('click', function(){ loadAll(); });
    }

    // Auto-refresh every 2 minutes in background
    let autoTimer = null;
    function startAutoRefresh(){ autoTimer = setInterval(()=>{ loadAll(); }, 120000); }

    // Init
    function init(){ wireButtons(); loadAll(); startAutoRefresh(); }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
