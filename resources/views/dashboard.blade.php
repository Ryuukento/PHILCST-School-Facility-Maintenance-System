@extends('layouts.app')

@section('title', 'Dashboard - SFMS')

@section('content')
<div class="container">
    <div class="card">
        <div class="card-header">
            <div>
                <h2>Dashboard</h2>
            </div>
        </div>
        <div class="card-body">
            <!-- Summary Cards Grid -->
            <div class="summary-cards-grid">
                <div class="summary-card">
                    <div class="summary-card-content">
                        <h3 class="summary-card-title">Total reports</h3>
                        <div class="summary-card-value" id="stat-total">-</div>
                        <p class="summary-card-desc">all reports in the system</p>
                    </div>
                </div>

                <div class="summary-card">
                    <div class="summary-card-content">
                        <h3 class="summary-card-title">Reports today</h3>
                        <div class="summary-card-value" id="stat-today">-</div>
                        <p class="summary-card-desc">submitted today</p>
                    </div>
                </div>

                <div class="summary-card">
                    <div class="summary-card-content">
                        <h3 class="summary-card-title">Pending tasks</h3>
                        <div class="summary-card-value" id="stat-pending">-</div>
                        <p class="summary-card-desc">needs attention</p>
                    </div>
                </div>

                <div class="summary-card">
                    <div class="summary-card-content">
                        <h3 class="summary-card-title">In progress</h3>
                        <div class="summary-card-value" id="stat-in-progress">-</div>
                        <p class="summary-card-desc">on track</p>
                    </div>
                </div>
            </div>

            <!-- Recent Reports -->
            <div style="margin-top: 2rem;">
                <h3>Recent Reports</h3>
                <div id="recent-reports" style="margin-top: 1rem;">
                    <p>Loading...</p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', async () => {
    try {
        // Fetch dashboard stats
        const statsResponse = await fetch(window.API_BASE_URL + '/dashboard/stats', {
            credentials: 'include',
            headers: {
                'X-CSRF-TOKEN': window.CSRF_TOKEN
            }
        });
        
        const statsData = await statsResponse.json();
        
        if (statsData.success && statsData.data) {
            document.getElementById('stat-total').textContent = statsData.data.total_reports || 0;
            document.getElementById('stat-today').textContent = statsData.data.reports_today || 0;
            document.getElementById('stat-pending').textContent = statsData.data.pending || 0;
            document.getElementById('stat-in-progress').textContent = statsData.data.in_progress || 0;
        }
        
        // Fetch recent reports
        const reportsResponse = await fetch(window.API_BASE_URL + '/reports', {
            credentials: 'include',
            headers: {
                'X-CSRF-TOKEN': window.CSRF_TOKEN
            }
        });
        
        const reportsData = await reportsResponse.json();
        
        const reports = reportsData?.data?.data ?? [];
        if (reportsData.success && Array.isArray(reports)) {
            const recentReports = reports.slice(0, 5);
            const html = recentReports.map(r => `
                <div style="padding: 1rem; border-bottom: 1px solid #eee;">
                    <h4>${r.title || 'Untitled'}</h4>
                    <p>${r.description?.substring(0, 100) || ''}</p>
                    <small style="color: #666;">Status: ${r.status || 'N/A'}</small>
                </div>
            `).join('');
            document.getElementById('recent-reports').innerHTML = recentReports.length ? html : '<p>No reports found</p>';
        } else {
            document.getElementById('recent-reports').innerHTML = '<p>No reports found</p>';
        }
    } catch (error) {
        console.error('Error loading dashboard:', error);
        document.getElementById('recent-reports').innerHTML = '<p>Error loading reports</p>';
    }
});
</script>
@endsection
