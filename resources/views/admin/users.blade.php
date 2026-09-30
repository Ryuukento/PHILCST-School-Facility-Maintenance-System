@extends('layouts.app')

@section('title', 'Users - SFMS')

@section('content')
<div class="container">
    <div class="card">
        <div class="card-header">
            <h2>User Management</h2>
        </div>
        <div class="card-body">
            <div id="users-list">Loading users...</div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', async () => {
    try {
        const response = await fetch(window.API_BASE_URL + '/users', {
            credentials: 'include',
            headers: { 'X-CSRF-TOKEN': window.CSRF_TOKEN }
        });
        
        const data = await response.json();
        if (data.success && data.data && data.data.users) {
            const html = data.data.users.map(u => `
                <div style="padding: 1rem; border-bottom: 1px solid #eee;">
                    <h4>${u.full_name || u.email}</h4>
                    <p>Email: ${u.email} | Role: ${u.role || 'N/A'} | Status: ${u.status || 'N/A'}</p>
                </div>
            `).join('');
            document.getElementById('users-list').innerHTML = html;
        } else {
            document.getElementById('users-list').innerHTML = '<p>No users found.</p>';
        }
    } catch (error) {
        console.error('Error:', error);
        document.getElementById('users-list').innerHTML = '<p>Error loading users.</p>';
    }
});
</script>
@endsection
