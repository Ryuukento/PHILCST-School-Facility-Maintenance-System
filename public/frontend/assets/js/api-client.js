/**
 * API Client
 * Handles all API requests to the backend
 */

class APIClient {
    constructor(baseURL = '/School_Facility_Maintenance_System/api') {
        this.baseURL = baseURL;
        this.headers = {
            'Content-Type': 'application/json',
        };
    }
    
    /**
     * Make an API request
     */
    async request(endpoint, method = 'GET', data = null) {
        const url = `${this.baseURL}/${endpoint}`;
        const options = {
            method,
            headers: this.headers,
        };
        
        if (data) {
            options.body = JSON.stringify(data);
        }
        
        try {
            const response = await fetch(url, options);
            const result = await response.json();
            
            if (!response.ok) {
                throw new Error(result.message || 'API request failed');
            }
            
            return result;
        } catch (error) {
            console.error('API Error:', error);
            throw error;
        }
    }
    
    /**
     * Authentication endpoints
     */
    async login(email, password) {
        return this.request('auth?action=login', 'POST', { email, password });
    }
    
    async logout() {
        return this.request('auth?action=logout', 'POST');
    }
    
    async register(userData) {
        return this.request('auth?action=register', 'POST', userData);
    }
    
    /**
     * Report endpoints
     */
    async createReport(reportData) {
        return this.request('reports?action=create', 'POST', reportData);
    }
    
    async updateReport(reportId, reportData) {
        return this.request(`reports?action=update&report_id=${reportId}`, 'POST', reportData);
    }
    
    async getReport(reportId) {
        return this.request(`reports?action=get&report_id=${reportId}`, 'GET');
    }
}

// Global API client instance
const api = new APIClient();
