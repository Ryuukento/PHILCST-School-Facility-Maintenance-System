window.SFMSExport = {
    downloadCsv: function(filenameBase, payload, options){
        options = options || {};
        const ts = (new Date()).toISOString().slice(0,19).replace(/[:T]/g,'-');
        const name = `${filenameBase}-${ts}.csv`;
        const rows = [];
        if (options.meta){
            Object.keys(options.meta).forEach(k => rows.push(`# ${k}: ${options.meta[k]}`));
            rows.push('');
        }
        if (Array.isArray(payload)){
            if (payload.length === 0){
                rows.push('No data');
            } else {
                const keys = Array.from(new Set(payload.flatMap(o => Object.keys(o))));
                rows.push(keys.join(','));
                payload.forEach(o => rows.push(keys.map(k => '"' + String(o[k] ?? '').replace(/"/g,'""') + '"').join(',')));
            }
        } else if (typeof payload === 'object'){
            const keys = Object.keys(payload);
            rows.push(keys.join(','));
            rows.push(keys.map(k => '"' + String(payload[k]).replace(/"/g,'""') + '"').join(','));
        } else {
            rows.push('value'); rows.push('"' + String(payload).replace(/"/g,'""') + '"');
        }

        const csv = '\uFEFF' + rows.join('\n');
        const blob = new Blob([csv], {type: 'text/csv;charset=utf-8;'});
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a'); a.href = url; a.download = name; document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
    },
    printContent: function(html){
        const w = window.open('', '_blank');
        w.document.write(html);
        w.document.close();
        w.focus();
        w.print();
    }
};
