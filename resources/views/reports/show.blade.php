@extends('layouts.app')

@section('title', 'Report Detail - SFMS')

@section('content')
<div class="container">
    <div class="card">
        <div class="card-header">
            <h2>Report Detail</h2>
            <a href="{{ url('/reports') }}" class="btn btn-secondary">Back to Reports</a>
        </div>
        <div class="card-body">
            <div id="report-detail">
                <p>Loading report...</p>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
const reportId = '{{ $id }}';
document.addEventListener('DOMContentLoaded', async () => {
    try {
        const response = await fetch(window.API_BASE_URL + '/reports/' + reportId, {
            credentials: 'include',
            headers: { 'X-CSRF-TOKEN': window.CSRF_TOKEN }
        });
        
        const data = await response.json();
        
        if (data.success && data.data && data.data.report) {
            const r = data.data.report;
            document.getElementById('report-detail').innerHTML = `
                <h3>${r.title || 'Untitled'}</h3>
                <p>${r.description || ''}</p>
                <p><strong>Status:</strong> ${r.status || 'N/A'}</p>
                <p><strong>Priority:</strong> ${r.priority || 'N/A'}</p>
                <p><strong>Created:</strong> ${r.created_at || 'N/A'}</p>
            `;
        } else {
            document.getElementById('report-detail').innerHTML = '<p>Report not found.</p>';
        }
    } catch (error) {
        console.error('Error:', error);
        document.getElementById('report-detail').innerHTML = '<p>Error loading report.</p>';
    }
});
</script>
@endsection
