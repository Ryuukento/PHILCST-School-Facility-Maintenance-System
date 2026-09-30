@extends('layouts.app')

@section('title', 'Create Report - SFMS')

@section('content')
<div class="container">
    <div class="card">
        <div class="card-header">
            <h2>Create New Report</h2>
        </div>
        <div class="card-body">
            <form id="create-report-form">
                <div class="form-group">
                    <label for="title">Title</label>
                    <input type="text" id="title" name="title" required>
                </div>
                
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" required></textarea>
                </div>
                
                <div class="form-group">
                    <label for="priority">Priority</label>
                    <select id="priority" name="priority">
                        <option value="low">Low</option>
                        <option value="medium" selected>Medium</option>
                        <option value="high">High</option>
                        <option value="urgent">Urgent</option>
                    </select>
                </div>
                
                <button type="submit" class="btn btn-primary">Create Report</button>
                <a href="{{ url('/reports') }}" class="btn btn-secondary">Cancel</a>
            </form>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
document.getElementById('create-report-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const data = {
        title: document.getElementById('title').value,
        description: document.getElementById('description').value,
        priority: document.getElementById('priority').value
    };
    
    try {
        const response = await fetch(window.API_BASE_URL + '/reports', {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': window.CSRF_TOKEN
            },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('Report created successfully!');
            window.location.href = '/reports';
        } else {
            alert(result.message || 'Error creating report');
        }
    } catch (error) {
        alert('Error: ' + error.message);
    }
});
</script>
@endsection
