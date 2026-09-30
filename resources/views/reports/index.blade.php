@extends('layouts.app')

@section('title', 'Reports - SFMS')

@section('content')
<div class="container">
    <div class="card">
        <div class="card-header">
            <div>
                <h2>Maintenance Reports</h2>
            </div>
            <div>
                <a href="{{ url('/reports/create') }}" class="btn btn-primary">Create Report</a>
            </div>
        </div>
        <div class="card-body">
            <div id="reports-list">
                <p>Loading reports...</p>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', async () => {
    try {
        const response = await fetch(window.API_BASE_URL + '/reports', {
            credentials: 'include',
            headers: { 'X-CSRF-TOKEN': window.CSRF_TOKEN }
        });
        
        const data = await response.json();
        
        const reports = data?.data?.data ?? [];
        if (data.success && Array.isArray(reports) && reports.length) {
            const html = reports.map(r => `
                <div style="padding: 1rem; border-bottom: 1px solid #eee;">
                    <h4><a href="/reports/${r.report_id}">${r.title || 'Untitled'}</a></h4>
                    <p>${r.description?.substring(0, 200) || ''}</p>
                    <small style="color: #666;">Status: ${r.status || 'N/A'} | Created: ${r.created_at || 'N/A'}</small>
                </div>
            `).join('');
            document.getElementById('reports-list').innerHTML = html;
        } else {
            document.getElementById('reports-list').innerHTML = '<p>No reports found</p>';
        }
    } catch (error) {
        console.error('Error loading reports:', error);
        document.getElementById('reports-list').innerHTML = '<p>Error loading reports</p>';
    }
});
</script>
@endsection
