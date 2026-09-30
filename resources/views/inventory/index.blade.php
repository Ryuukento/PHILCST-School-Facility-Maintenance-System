@extends('layouts.app')

@section('title', 'Inventory - SFMS')

@section('content')
<div class="container">
    <div class="card">
        <div class="card-header">
            <h2>Inventory</h2>
        </div>
        <div class="card-body">
            <div id="inventory-list">Loading inventory...</div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', async () => {
    try {
        const response = await fetch(window.API_BASE_URL + '/items', {
            credentials: 'include',
            headers: { 'X-CSRF-TOKEN': window.CSRF_TOKEN }
        });
        
        const data = await response.json();
        const items = data?.data?.data ?? [];
        if (data.success && Array.isArray(items) && items.length) {
            const html = items.map(i => `
                <div style="padding: 1rem; border-bottom: 1px solid #eee;">
                    <h4>${i.name || 'Item'}</h4>
                    <p>Quantity: ${i.quantity || 0} | Status: ${i.status || 'N/A'}</p>
                </div>
            `).join('');
            document.getElementById('inventory-list').innerHTML = html;
        } else {
            document.getElementById('inventory-list').innerHTML = '<p>No inventory items found.</p>';
        }
    } catch (error) {
        console.error('Error:', error);
        document.getElementById('inventory-list').innerHTML = '<p>Error loading inventory.</p>';
    }
});
</script>
@endsection
